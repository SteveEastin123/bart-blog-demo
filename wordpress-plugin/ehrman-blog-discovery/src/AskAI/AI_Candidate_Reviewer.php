<?php
/**
 * AI-assisted candidate-post review.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reviews candidate titles and summaries against a reader query. */
final class AI_Candidate_Reviewer {
	private const API_URL          = 'https://api.openai.com/v1/responses';
	private const MAX_QUESTION_LEN = 800;
	private const CACHE_SECONDS    = DAY_IN_SECONDS;
	private const PROMPT_VERSION   = '4';
	private const MAX_CANDIDATES   = 200;
	private const MAX_RESULTS      = 25;
	private const GROUPING_TIERS   = 'tiers';
	private const GROUPING_ORDERED = 'ordered';
	private const TIER_DIRECT      = 'direct';
	private const TIER_RELATED     = 'related';
	private const TIER_BACKGROUND  = 'background';
	private const RELEVANCE_TIERS  = array( self::TIER_DIRECT, self::TIER_RELATED, self::TIER_BACKGROUND );

	/**
	 * Creates a candidate reviewer with server-side provider configuration.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $model   Configured model identifier.
	 */
	public function __construct(
		private string $api_key,
		private string $model
	) {}

	/** Returns the configured result-grouping mode. */
	public static function result_grouping(): string {
		if ( defined( 'EHRMAN_DISCOVERY_AI_RESULT_GROUPING' ) ) {
			$grouping = constant( 'EHRMAN_DISCOVERY_AI_RESULT_GROUPING' );
			return is_scalar( $grouping ) ? self::sanitize_result_grouping( (string) $grouping ) : self::GROUPING_TIERS;
		}
		return self::sanitize_result_grouping( (string) getenv( 'EHRMAN_DISCOVERY_AI_RESULT_GROUPING' ) );
	}

	/** Returns the candidate-review prompt version for analytics. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION . '-' . self::result_grouping();
	}

	/**
	 * Selects the posts that most directly address a reader's question.
	 *
	 * @param string                    $question   Reader's original question.
	 * @param list<array<string,mixed>> $posts      Candidate posts.
	 * @param string                    $request_id Correlation identifier for analytics.
	 * @return array{post_ids:list<string>,post_tiers:array<string,string>,candidate_count:int,cache_hit:bool,usage:array<string,mixed>}|WP_Error Reviewed post identifiers or error.
	 */
	public function review( string $question, array $posts, string $request_id = '' ) {
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' )
			? mb_substr( $question, 0, self::MAX_QUESTION_LEN )
			: substr( $question, 0, self::MAX_QUESTION_LEN );
		if ( '' === trim( $question ) || empty( $posts ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		if ( '' === $this->api_key ) {
			return new WP_Error( 'ehrman_ai_not_configured', __( 'AI search refinement is not configured on this site.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}

		$candidates = $this->candidates( $posts );
		if ( empty( $candidates ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}

		$cache_key = 'ebd_ai_refine_' . hash(
			'sha256',
			Search_Service::normalize( $question ) . '|' . (string) wp_json_encode( $candidates ) . '|' . $this->model . '|' . self::prompt_version() . '|' . AI_Usage::cache_version()
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && is_array( $cached['post_ids'] ?? null ) ) {
			$post_ids = Database::strings( $cached['post_ids'] );
			AI_Usage::record_cache_hit( $this->model, $request_id );
			return array(
				'post_ids'        => $post_ids,
				'post_tiers'      => $this->cached_post_tiers( $cached['post_tiers'] ?? array(), $post_ids ),
				'candidate_count' => count( $candidates ),
				'cache_hit'       => true,
				'usage'           => array(),
			);
		}

		$encoded = wp_json_encode( $this->request_payload( $question, $candidates ) );
		if ( ! is_string( $encoded ) ) {
			AI_Usage::record_failure( $this->model, 'refine_request_error', $request_id );
			return new WP_Error( 'ehrman_ai_refine_error', __( 'The search results could not be prepared for refinement.', 'ehrman-blog-discovery' ), array( 'status' => 500 ) );
		}
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			AI_Usage::record_failure( $this->model, 'refine_unavailable', $request_id );
			return new WP_Error( 'ehrman_ai_refine_unavailable', __( 'The search results could not be refined. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			AI_Usage::record_failure( $this->model, 'refine_response_error', $request_id );
			return new WP_Error( 'ehrman_ai_refine_error', __( 'The search results could not be refined. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		/**
		 * Decoded API response.
		 *
		 * @var array<string,mixed> $body
		 */
		if ( $status < 200 || $status >= 300 ) {
			AI_Usage::record_response( $body, false, 'refine_response_error', $request_id );
			return new WP_Error( 'ehrman_ai_refine_error', __( 'The search results could not be refined. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		$decoded = Database::associative_row( json_decode( self::output_text( $body ), true ) );
		$parsed  = null !== $decoded ? $this->parse_reviewed_posts( $decoded, $candidates ) : null;
		if ( null === $parsed ) {
			AI_Usage::record_response( $body, false, 'refine_invalid_output', $request_id );
			return new WP_Error( 'ehrman_ai_refine_invalid', __( 'The refinement service returned an invalid response.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		AI_Usage::record_response( $body, true, '', $request_id );
		set_transient( $cache_key, $parsed, self::CACHE_SECONDS );
		return array(
			'post_ids'        => $parsed['post_ids'],
			'post_tiers'      => $parsed['post_tiers'],
			'candidate_count' => count( $candidates ),
			'cache_hit'       => false,
			'usage'           => AI_Usage::response_metrics( $body ),
		);
	}

	/**
	 * Converts result rows to bounded candidate metadata.
	 *
	 * @param list<array<string,mixed>> $posts Candidate posts.
	 * @return list<array{id:string,title:string,summary:string}> Candidate metadata.
	 */
	private function candidates( array $posts ): array {
		$candidates = array();
		foreach ( array_slice( $posts, 0, self::MAX_CANDIDATES ) as $post ) {
			$id      = Database::text( $post['id'] ?? null );
			$title   = sanitize_text_field( Database::text( $post['title'] ?? null ) );
			$summary = sanitize_text_field( Database::text( $post['search_summary'] ?? null ) );
			if ( '' === $summary ) {
				$summary = sanitize_text_field( Database::text( $post['description'] ?? null ) );
			}
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$candidates[] = array(
				'id'      => $id,
				'title'   => $title,
				'summary' => $summary,
			);
		}
		return $candidates;
	}

	/**
	 * Builds the structured candidate-review request.
	 *
	 * @param string                                             $question   Reader question.
	 * @param list<array{id:string,title:string,summary:string}> $candidates Candidate post metadata.
	 * @return array<string,mixed> Responses API payload.
	 */
	private function request_payload( string $question, array $candidates ): array {
		if ( self::GROUPING_TIERS === self::result_grouping() ) {
			$instructions = 'Evaluate blog posts for relevance to the reader\'s exact question. '
				. 'Use only the supplied titles and summaries. Retain a post only when its metadata indicates that a substantial part of the post directly addresses the requested subject or provides genuinely useful context. '
				. 'Exclude posts that merely mention the subject, address one incidental detail, or match only a broad vocabulary label. '
				. 'For a request for a summary or overview, retain only posts that broadly cover the requested text or subject; exclude posts limited to authorship, one passage, one episode, one textual variant, or one narrow theological issue. '
				. 'Prefer precision over quantity. Select no more than 25 posts and return only supplied post IDs. '
				. 'Classify each selected post into exactly one relevance tier. '
				. 'Use direct when the title or summary indicates that the post substantially answers the exact question. '
				. 'Use related when the post materially addresses an important part of the question but does not directly answer the whole question. '
				. 'Use background only when the post provides genuinely useful context after the direct and related posts; do not use it for incidental matches. '
				. 'Order posts from strongest to weakest within each tier.';
			$schema       = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'selected_posts' ),
				'properties'           => array(
					'selected_posts' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_RESULTS,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'id', 'relevance_tier' ),
							'properties'           => array(
								'id'             => array( 'type' => 'string' ),
								'relevance_tier' => array(
									'type' => 'string',
									'enum' => self::RELEVANCE_TIERS,
								),
							),
						),
					),
				),
			);
		} else {
			$instructions = 'Filter blog posts for direct relevance to the reader\'s exact question. '
				. 'Use only the supplied titles and summaries. Retain a post only when its metadata indicates that a substantial part of the post directly addresses the requested subject. '
				. 'Exclude posts that merely mention the subject, provide surrounding background, address one incidental detail, or match only a broad vocabulary label. '
				. 'For a request for a summary or overview, retain only posts that broadly cover the requested text or subject; exclude posts limited to authorship, one passage, one episode, one textual variant, or one narrow theological issue. '
				. 'Prefer precision over quantity. Select no more than 25 posts, ordered from most to least relevant. Return only supplied post IDs.';
			$schema       = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'selected_ids' ),
				'properties'           => array(
					'selected_ids' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_RESULTS,
						'items'    => array( 'type' => 'string' ),
					),
				),
			);
		}
		return array(
			'model'             => $this->model,
			'reasoning'         => array( 'effort' => 'low' ),
			'instructions'      => $instructions,
			'input'             => "Reader question:\n{$question}\n\nCandidate posts:\n" . wp_json_encode( $candidates ),
			'max_output_tokens' => 800,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_refined_posts',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);
	}

	/**
	 * Validates and orders the structured candidate-review response.
	 *
	 * @param array<string,mixed>                                $decoded    Decoded structured output.
	 * @param list<array{id:string,title:string,summary:string}> $candidates Candidate post metadata.
	 * @return array{post_ids:list<string>,post_tiers:array<string,string>}|null
	 */
	private function parse_reviewed_posts( array $decoded, array $candidates ): ?array {
		$allowed = array_fill_keys( array_column( $candidates, 'id' ), true );
		if ( self::GROUPING_ORDERED === self::result_grouping() ) {
			if ( ! is_array( $decoded['selected_ids'] ?? null ) ) {
				return null;
			}
			$post_ids = array();
			foreach ( $decoded['selected_ids'] as $id ) {
				$id = is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '';
				if ( '' !== $id && isset( $allowed[ $id ] ) && ! in_array( $id, $post_ids, true ) ) {
					$post_ids[] = $id;
				}
				if ( count( $post_ids ) >= self::MAX_RESULTS ) {
					break;
				}
			}
			return array(
				'post_ids'   => $post_ids,
				'post_tiers' => array(),
			);
		}

		if ( ! is_array( $decoded['selected_posts'] ?? null ) ) {
			return null;
		}
		$buckets    = array_fill_keys( self::RELEVANCE_TIERS, array() );
		$post_tiers = array();
		foreach ( $decoded['selected_posts'] as $selected ) {
			if ( ! is_array( $selected ) || ! is_scalar( $selected['id'] ?? null ) || ! is_scalar( $selected['relevance_tier'] ?? null ) ) {
				return null;
			}
			$id   = sanitize_text_field( (string) $selected['id'] );
			$tier = sanitize_key( (string) $selected['relevance_tier'] );
			if ( ! isset( $allowed[ $id ] ) || ! in_array( $tier, self::RELEVANCE_TIERS, true ) || isset( $post_tiers[ $id ] ) ) {
				continue;
			}
			$buckets[ $tier ][] = $id;
			$post_tiers[ $id ]  = $tier;
		}
		$post_ids = array();
		foreach ( self::RELEVANCE_TIERS as $tier ) {
			foreach ( $buckets[ $tier ] as $id ) {
				$post_ids[] = $id;
				if ( count( $post_ids ) >= self::MAX_RESULTS ) {
					break 2;
				}
			}
		}
		$post_tiers = array_intersect_key( $post_tiers, array_fill_keys( $post_ids, true ) );
		return array(
			'post_ids'   => $post_ids,
			'post_tiers' => $post_tiers,
		);
	}

	/**
	 * Validates cached tier labels against the cached post IDs.
	 *
	 * @param mixed $cached_tiers Raw cached tier map.
	 * @param array $post_ids     Cached post identifiers.
	 * @phpstan-param list<string> $post_ids
	 * @return array<string,string>
	 */
	private function cached_post_tiers( $cached_tiers, array $post_ids ): array {
		if ( ! is_array( $cached_tiers ) ) {
			return array();
		}
		$allowed = array_fill_keys( $post_ids, true );
		$tiers   = array();
		foreach ( $cached_tiers as $id => $tier ) {
			$id   = sanitize_text_field( (string) $id );
			$tier = is_scalar( $tier ) ? sanitize_key( (string) $tier ) : '';
			if ( isset( $allowed[ $id ] ) && in_array( $tier, self::RELEVANCE_TIERS, true ) ) {
				$tiers[ $id ] = $tier;
			}
		}
		return $tiers;
	}

	/**
	 * Extracts text from a Responses API payload.
	 *
	 * @param array<string,mixed> $body Decoded API response body.
	 * @return string Structured output text, or an empty string.
	 */
	private static function output_text( array $body ): string {
		if ( is_string( $body['output_text'] ?? null ) ) {
			return $body['output_text'];
		}
		foreach ( is_array( $body['output'] ?? null ) ? $body['output'] : array() as $output ) {
			if ( ! is_array( $output ) ) {
				continue;
			}
			foreach ( is_array( $output['content'] ?? null ) ? $output['content'] : array() as $content ) {
				if ( is_array( $content ) && is_string( $content['text'] ?? null ) ) {
					return $content['text'];
				}
			}
		}
		return '';
	}

	/**
	 * Normalizes the grouping switch and defaults to relevance tiers.
	 *
	 * @param string $grouping Configured grouping value.
	 */
	private static function sanitize_result_grouping( string $grouping ): string {
		return self::GROUPING_ORDERED === strtolower( trim( $grouping ) ) ? self::GROUPING_ORDERED : self::GROUPING_TIERS;
	}
}
