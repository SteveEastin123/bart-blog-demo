<?php
/**
 * Natural-language search interpretation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maps a reader's question to the plugin's controlled search vocabulary. */
final class AI_Interpreter {
	private const API_URL               = 'https://api.openai.com/v1/responses';
	private const DEFAULT_MODEL         = 'gpt-5.4-mini';
	private const MAX_QUESTION_LEN      = 800;
	private const CACHE_SECONDS         = DAY_IN_SECONDS;
	private const PROMPT_VERSION        = '18';
	private const REFINE_PROMPT_VERSION = '1';
	private const MAX_REFINE_POSTS      = 200;
	private const MAX_REFINED_RESULTS   = 25;

	/** Returns the configured model identifier for reporting. */
	public static function model_id(): string {
		return self::model();
	}

	/** Returns the interpretation prompt version for reporting. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION;
	}

	/** Returns the refinement prompt version for analytics. */
	public static function refine_prompt_version(): string {
		return self::REFINE_PROMPT_VERSION;
	}

	/**
	 * Returns whether server-side AI credentials are available.
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Interprets a question as no more than four approved search terms.
	 *
	 * @param string $question   Reader's natural-language question.
	 * @param string $request_id Correlation identifier for analytics.
	 * @return array{question:string,terms:list<array{label:string,mode:string}>,cache_hit:bool}|WP_Error Interpretation or error.
	 */
	public function interpret( string $question, string $request_id = '' ) {
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' )
			? mb_substr( $question, 0, self::MAX_QUESTION_LEN )
			: substr( $question, 0, self::MAX_QUESTION_LEN );
		$question = $this->normalize_question_phrasing( $question );
		if ( '' === trim( $question ) ) {
			return new WP_Error( 'ehrman_ai_empty_question', __( 'Enter a question to interpret.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_configured() ) {
			return new WP_Error( 'ehrman_ai_not_configured', __( 'AI question interpretation is not configured on this site.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}

		$import_checksum = get_option( 'ehrman_discovery_import_checksum', '' );
		$cache_key       = 'ebd_ai_' . hash(
			'sha256',
			Search_Service::normalize( $question ) . '|' . self::model() . '|' . self::PROMPT_VERSION . '|' . ( is_scalar( $import_checksum ) ? (string) $import_checksum : '' )
		);
		$vocabulary      = $this->vocabulary();
		$cached          = get_transient( $cache_key );
		$cached          = $this->cached_result( $cached );
		if ( null !== $cached ) {
			AI_Usage::record_cache_hit( self::model(), $request_id );
			$cached['terms']     = $this->compatible_terms( $this->prefer_topic_labels( $cached['terms'], $vocabulary['topics'] ) );
			$cached['cache_hit'] = true;
			return $cached;
		}

		$request   = $this->request_payload( $question, $vocabulary );
		$body_json = wp_json_encode( $request );
		if ( ! is_string( $body_json ) ) {
			AI_Usage::record_failure( self::model(), 'request_error', $request_id );
			return new WP_Error( 'ehrman_ai_request_error', __( 'The question could not be prepared for interpretation.', 'ehrman-blog-discovery' ), array( 'status' => 500 ) );
		}
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 40,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => $body_json,
			)
		);
		if ( is_wp_error( $response ) ) {
			AI_Usage::record_failure( self::model(), 'unavailable', $request_id );
			return new WP_Error( 'ehrman_ai_unavailable', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			AI_Usage::record_failure( self::model(), 'response_error', $request_id );
			return new WP_Error( 'ehrman_ai_response_error', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		/**
		 * Decoded API response.
		 *
		 * @var array<string,mixed> $body
		 */
		if ( $status < 200 || $status >= 300 ) {
			AI_Usage::record_response( $body, false, 'response_error', $request_id );
			return new WP_Error( 'ehrman_ai_response_error', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		$decoded = json_decode( $this->output_text( $body ), true );
		if ( ! is_array( $decoded ) ) {
			AI_Usage::record_response( $body, false, 'invalid_output', $request_id );
			return new WP_Error( 'ehrman_ai_invalid_output', __( 'The interpretation service returned an invalid response.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		/**
		 * Decoded structured output.
		 *
		 * @var array<string,mixed> $decoded
		 */

		$entities = $this->validated_entities( $decoded['named_entities'] ?? array(), $vocabulary['keywords'] );
		$terms    = $this->validated_terms( $decoded['terms'] ?? array(), $vocabulary );
		$terms    = $this->prefer_topic_labels( $this->merge_terms( $entities, $terms ), $vocabulary['topics'] );
		$result   = array(
			'question'  => $question,
			'terms'     => $this->compatible_terms( $terms ),
			'cache_hit' => false,
		);
		if ( empty( $result['terms'] ) ) {
			AI_Usage::record_response( $body, false, 'no_terms', $request_id );
			return new WP_Error( 'ehrman_ai_no_terms', __( 'No matching topics or keywords were identified. Try rephrasing the question.', 'ehrman-blog-discovery' ), array( 'status' => 422 ) );
		}
		AI_Usage::record_response( $body, true, '', $request_id );
		$cached_result = $result;
		unset( $cached_result['cache_hit'] );
		set_transient( $cache_key, $cached_result, self::CACHE_SECONDS );
		return $result;
	}

	/**
	 * Selects the posts that most directly address a reader's question.
	 *
	 * @param string                    $question   Reader's original question.
	 * @param list<array<string,mixed>> $posts      Posts returned by the interpreted search.
	 * @param string                    $request_id Correlation identifier for analytics.
	 * @return array{post_ids:list<string>,candidate_count:int,cache_hit:bool,usage:array<string,mixed>}|WP_Error Refined post identifiers or error.
	 */
	public function refine( string $question, array $posts, string $request_id = '' ) {
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' )
			? mb_substr( $question, 0, self::MAX_QUESTION_LEN )
			: substr( $question, 0, self::MAX_QUESTION_LEN );
		if ( '' === trim( $question ) || empty( $posts ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_configured() ) {
			return new WP_Error( 'ehrman_ai_not_configured', __( 'AI search refinement is not configured on this site.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}

		$candidates = array();
		foreach ( array_slice( $posts, 0, self::MAX_REFINE_POSTS ) as $post ) {
			$id          = Database::text( $post['id'] ?? null );
			$title       = sanitize_text_field( Database::text( $post['title'] ?? null ) );
			$description = sanitize_text_field( Database::text( $post['description'] ?? null ) );
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$candidates[] = array(
				'id'          => $id,
				'title'       => $title,
				'description' => $description,
			);
		}
		if ( empty( $candidates ) ) {
			return new WP_Error( 'ehrman_ai_refine_empty', __( 'There are no search results to refine.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}

		$cache_key = 'ebd_ai_refine_' . hash(
			'sha256',
			Search_Service::normalize( $question ) . '|' . (string) wp_json_encode( $candidates ) . '|' . self::model() . '|' . self::REFINE_PROMPT_VERSION
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && is_array( $cached['post_ids'] ?? null ) ) {
			AI_Usage::record_cache_hit( self::model(), $request_id );
			return array(
				'post_ids'        => Database::strings( $cached['post_ids'] ),
				'candidate_count' => count( $candidates ),
				'cache_hit'       => true,
				'usage'           => array(),
			);
		}

		$payload = $this->refine_payload( $question, $candidates );
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			AI_Usage::record_failure( self::model(), 'refine_request_error', $request_id );
			return new WP_Error( 'ehrman_ai_refine_error', __( 'The search results could not be prepared for refinement.', 'ehrman-blog-discovery' ), array( 'status' => 500 ) );
		}
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			AI_Usage::record_failure( self::model(), 'refine_unavailable', $request_id );
			return new WP_Error( 'ehrman_ai_refine_unavailable', __( 'The search results could not be refined. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			AI_Usage::record_failure( self::model(), 'refine_response_error', $request_id );
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
		$decoded = json_decode( $this->output_text( $body ), true );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['selected_ids'] ?? null ) ) {
			AI_Usage::record_response( $body, false, 'refine_invalid_output', $request_id );
			return new WP_Error( 'ehrman_ai_refine_invalid', __( 'The refinement service returned an invalid response.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$allowed  = array_fill_keys( array_column( $candidates, 'id' ), true );
		$post_ids = array();
		foreach ( $decoded['selected_ids'] as $id ) {
			$id = is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '';
			if ( '' !== $id && isset( $allowed[ $id ] ) && ! in_array( $id, $post_ids, true ) ) {
				$post_ids[] = $id;
			}
			if ( count( $post_ids ) >= self::MAX_REFINED_RESULTS ) {
				break;
			}
		}
		AI_Usage::record_response( $body, true, '', $request_id );
		set_transient( $cache_key, array( 'post_ids' => $post_ids ), self::CACHE_SECONDS );
		return array(
			'post_ids'        => $post_ids,
			'candidate_count' => count( $candidates ),
			'cache_hit'       => false,
			'usage'           => AI_Usage::response_metrics( $body ),
		);
	}

	/**
	 * Builds the structured post-refinement request.
	 *
	 * @param string                                                 $question   Reader question.
	 * @param list<array{id:string,title:string,description:string}> $candidates Candidate post metadata.
	 * @return array<string,mixed> Responses API payload.
	 */
	private function refine_payload( string $question, array $candidates ): array {
		$instructions = 'Filter blog posts for direct relevance to the reader\'s exact question. '
			. 'Use only the supplied titles and descriptions. Retain a post only when its metadata indicates that a substantial part of the post directly addresses the requested subject. '
			. 'Exclude posts that merely mention the subject, provide surrounding background, address one incidental detail, or match only a broad vocabulary label. '
			. 'For a request for a summary or overview, retain only posts that broadly cover the requested text or subject; exclude posts limited to authorship, one passage, one episode, one textual variant, or one narrow theological issue. '
			. 'Prefer precision over quantity. Select no more than 25 posts, ordered from most to least relevant. Return only supplied post IDs.';
		return array(
			'model'             => self::model(),
			'reasoning'         => array( 'effort' => 'low' ),
			'instructions'      => $instructions,
			'input'             => "Reader question:\n{$question}\n\nCandidate posts:\n" . wp_json_encode( $candidates ),
			'max_output_tokens' => 800,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_refined_posts',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'selected_ids' ),
						'properties'           => array(
							'selected_ids' => array(
								'type'     => 'array',
								'maxItems' => self::MAX_REFINED_RESULTS,
								'items'    => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Validates a cached interpretation payload.
	 *
	 * @param mixed $cached Raw cached value.
	 * @return array{question:string,terms:list<array{label:string,mode:string}>}|null
	 */
	private function cached_result( $cached ): ?array {
		if ( ! is_array( $cached ) || ! is_string( $cached['question'] ?? null ) || ! is_array( $cached['terms'] ?? null ) ) {
			return null;
		}
		$terms = array();
		foreach ( $cached['terms'] as $term ) {
			if ( ! is_array( $term ) || ! is_string( $term['label'] ?? null ) || ! is_string( $term['mode'] ?? null ) ) {
				return null;
			}
			$terms[] = array(
				'label' => $term['label'],
				'mode'  => $term['mode'],
			);
		}
		return array(
			'question' => $cached['question'],
			'terms'    => $terms,
		);
	}

	/**
	 * Builds the approved topic and keyword vocabulary.
	 *
	 * @return array{topics:list<array{name:string,description:string}>,keywords:list<string>}
	 */
	private function vocabulary(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT t.name,t.description FROM {$tables['topics']} t "
			. "WHERE t.display_in_browser=1 AND t.name<>'Ignore' ORDER BY t.name";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally.
		$topic_rows  = Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
		$topics      = array_map(
			static fn( array $row ): array => array(
				'name'        => Database::text( $row['name'] ?? null ),
				'description' => Database::text( $row['description'] ?? null ),
			),
			$topic_rows
		);
		$keyword_sql = "SELECT label FROM {$tables['keywords']} ORDER BY label";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$keywords = Database::strings( $wpdb->get_col( $keyword_sql ) );
		return array(
			'topics'   => $topics,
			'keywords' => $keywords,
		);
	}

	/**
	 * Creates a structured-output Responses API request.
	 *
	 * @param string                                                                          $question Reader question.
	 * @param array{topics:list<array{name:string,description:string}>,keywords:list<string>} $vocabulary Approved vocabulary.
	 * @return array<string,mixed>
	 */
	private function request_payload( string $question, array $vocabulary ): array {
		$instructions = 'You interpret questions for a curated biblical-studies blog search. '
			. 'Resolve obvious spelling errors from context before selecting vocabulary labels. '
			. 'When a question explicitly names two people or texts and asks about their comparison, relationship, agreement, disagreement, or influence, preserve both named subjects as separate search requirements. Do not replace them with a broader methodological topic. Do not add a methodological topic unless the question explicitly names that method. '
			. 'When Matthew, Mark, Luke, or John are used as shorthand for their Gospels in such a literary relationship, select the corresponding Gospel topics and do not repeat the shorthand names in named_entities. '
			. 'Copy every person or text explicitly named in the question into named_entities when an exact keyword label exists. Never substitute a broader topic for an explicit named entity. '
			. 'For example, a question asking what Paul said, knew, or believed must include Paul in named_entities when Paul is an approved keyword. Apply the same rule to every explicitly named person or text. '
			. 'Select the smallest number of additional terms needed. Across named_entities and terms, use no more than four total vocabulary labels. '
			. 'Normally select no more than one primary topic. A relational question explicitly involving two named subjects may use two specific topics when each topic directly represents one of those subjects. Represent other supporting concepts with keywords when appropriate. '
			. 'List terms from most to least important. Every additional term must express a distinct requirement in the question; do not add broad background topics. '
			. 'Prefer a topic when it directly represents a major subject in the question. '
			. 'Use keywords for important supporting people, texts, places, or ideas. Do not repeat named_entities in terms. '
			. 'Do not select a topic and keyword that express substantially the same search concept. '
			. 'Treat broad and narrow topics about the same aspect of the question as alternatives, not separate requirements. Choose the most specific topic whose description directly covers the question; do not combine it with a broader topic covering the same concept. '
			. 'Select a topic only when the reader question falls within the scope stated in its description. Do not select a topic merely because its name contains a related person or term. Topic descriptions define firm selection boundaries. '
			. 'Never invent, rename, or alter a label. '
			. 'If the same label exists as both a topic and keyword, select it as a topic. '
			. 'Return only labels copied exactly from the vocabulary.';
		$input        = "Controlled vocabulary:\n" . wp_json_encode( $vocabulary ) . "\n\nReader question:\n{$question}";
		return array(
			'model'             => self::model(),
			'reasoning'         => array( 'effort' => 'low' ),
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => 500,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_search_interpretation',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'named_entities', 'terms' ),
						'properties'           => array(
							'named_entities' => array(
								'type'     => 'array',
								'maxItems' => Search_Service::MAX_TERMS,
								'items'    => array( 'type' => 'string' ),
							),
							'terms'          => array(
								'type'     => 'array',
								'maxItems' => Search_Service::MAX_TERMS,
								'items'    => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'label', 'type' ),
									'properties'           => array(
										'label' => array( 'type' => 'string' ),
										'type'  => array(
											'type' => 'string',
											'enum' => array( 'topic', 'keyword' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Corrects a common omitted-preposition form before caching and interpretation.
	 *
	 * @param string $question Sanitized reader question.
	 */
	private function normalize_question_phrasing( string $question ): string {
		if ( ! preg_match( '/\bwhat\s+changes\s+did\b/i', $question ) ) {
			return $question;
		}
		$corrected = preg_replace(
			'/\bmake\s+(?:to\s+)?(?:the\s+)?(?:gospel\s+of\s+)?(matthew|mark|luke|john)\b/i',
			'make to $1',
			$question,
			1
		);
		return is_string( $corrected ) ? $corrected : $question;
	}

	/**
	 * Extracts text from a Responses API payload.
	 *
	 * @param array<string,mixed> $body Decoded API response body.
	 * @return string Structured output text, or an empty string.
	 */
	private function output_text( array $body ): string {
		if ( is_string( $body['output_text'] ?? null ) ) {
			return $body['output_text'];
		}
		foreach ( is_array( $body['output'] ?? null ) ? $body['output'] : array() as $output ) {
			if ( ! is_array( $output ) ) {
				continue;
			}
			foreach ( is_array( $output['content'] ?? null ) ? $output['content'] : array() as $content ) {
				if ( ! is_array( $content ) ) {
					continue;
				}
				if ( is_string( $content['text'] ?? null ) ) {
					return $content['text'];
				}
			}
		}
		return '';
	}

	/**
	 * Validates model-selected terms against exact database labels.
	 *
	 * @param mixed                                                                           $raw Raw terms.
	 * @param array{topics:list<array{name:string,description:string}>,keywords:list<string>} $vocabulary Approved vocabulary.
	 * @return list<array{label:string,mode:string}>
	 */
	private function validated_terms( $raw, array $vocabulary ): array {
		$topics = array();
		foreach ( $vocabulary['topics'] as $topic ) {
			$topics[ Search_Service::normalize( $topic['name'] ) ] = $topic['name'];
		}
		$keywords = array();
		foreach ( $vocabulary['keywords'] as $keyword ) {
			$keywords[ Search_Service::normalize( $keyword ) ] = $keyword;
		}
		$terms       = array();
		$seen        = array();
		$topic_count = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $term ) {
			if ( ! is_array( $term ) || ! is_scalar( $term['label'] ?? null ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $term['label'] );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $topics[ $normalized ] ) ) {
				if ( $topic_count >= 2 ) {
					continue;
				}
				$terms[] = array(
					'label' => $topics[ $normalized ],
					'mode'  => Search_Service::TERM_MODE_TOPIC,
				);
				++$topic_count;
			} elseif ( isset( $keywords[ $normalized ] ) ) {
				$terms[] = array(
					'label' => $keywords[ $normalized ],
					'mode'  => Search_Service::TERM_MODE_KEYWORD,
				);
			} else {
				continue;
			}
			$seen[ $normalized ] = true;
			if ( count( $terms ) >= Search_Service::MAX_TERMS ) {
				break;
			}
		}
		return $terms;
	}

	/**
	 * Validates explicitly named entities against exact keyword labels.
	 *
	 * @param mixed             $raw      Raw named entities.
	 * @param array<int,string> $keywords Approved keywords.
	 * @return list<array{label:string,mode:string}> Valid entities.
	 */
	private function validated_entities( $raw, array $keywords ): array {
		$approved = array();
		foreach ( $keywords as $keyword ) {
			$approved[ Search_Service::normalize( $keyword ) ] = (string) $keyword;
		}
		$entities = array();
		$seen     = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entity ) {
			if ( ! is_scalar( $entity ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $entity );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) || ! isset( $approved[ $normalized ] ) ) {
				continue;
			}
			$entities[]          = array(
				'label' => $approved[ $normalized ],
				'mode'  => Search_Service::TERM_MODE_KEYWORD,
			);
			$seen[ $normalized ] = true;
		}
		return $entities;
	}

	/**
	 * Combines prioritized entity and interpreted terms without duplicates.
	 *
	 * @param list<array{label:string,mode:string}> $entities Explicit entities.
	 * @param list<array{label:string,mode:string}> $terms Interpreted terms.
	 * @return list<array{label:string,mode:string}> Combined bounded terms.
	 */
	private function merge_terms( array $entities, array $terms ): array {
		$merged = array();
		$seen   = array();
		foreach ( array_merge( $entities, $terms ) as $term ) {
			$normalized = Search_Service::normalize( $term['label'] );
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$merged[]            = $term;
			$seen[ $normalized ] = true;
			if ( count( $merged ) >= Search_Service::MAX_TERMS ) {
				break;
			}
		}
		return $merged;
	}

	/**
	 * Promotes a selected keyword to topic mode when an identical topic exists.
	 *
	 * @param list<array{label:string,mode:string}>       $terms  Selected terms.
	 * @param list<array{name:string,description:string}> $topics Approved topics.
	 * @return list<array{label:string,mode:string}> Topic-preferred terms.
	 */
	private function prefer_topic_labels( array $terms, array $topics ): array {
		$topic_labels = array();
		foreach ( $topics as $topic ) {
			$topic_labels[ Search_Service::normalize( $topic['name'] ) ] = $topic['name'];
		}
		foreach ( $terms as &$term ) {
			$normalized = Search_Service::normalize( $term['label'] );
			if ( isset( $topic_labels[ $normalized ] ) ) {
				$term['label'] = $topic_labels[ $normalized ];
				$term['mode']  = Search_Service::TERM_MODE_TOPIC;
			}
		}
		unset( $term );
		return $terms;
	}

	/**
	 * Keeps only model-selected terms that produce a useful AND search.
	 *
	 * Terms arrive in relevance order. A later term is retained whenever the
	 * combined AND search preserves at least one result.
	 *
	 * @param list<array{label:string,mode:string}> $terms Validated terms.
	 * @return list<array{label:string,mode:string}> Compatible terms.
	 */
	private function compatible_terms( array $terms ): array {
		if ( count( $terms ) < 2 ) {
			return $terms;
		}

		$search   = new Search_Service();
		$accepted = array( $terms[0] );

		foreach ( array_slice( $terms, 1 ) as $term ) {
			$trial        = array_merge( $accepted, array( $term ) );
			$trial_terms  = array_column( $trial, 'label' );
			$trial_modes  = array_column( $trial, 'mode' );
			$trial_result = $search->search( $trial_terms, 'ranked', '', '', 1, 1, $trial_modes );
			$trial_count  = $trial_result['count'];

			if ( $trial_count > 0 ) {
				$accepted = $trial;
			}
		}

		return $accepted;
	}

	/** Returns the API key without exposing it to the browser. */
	private static function api_key(): string {
		if ( defined( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' ) ) {
			$key = constant( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' );
			return is_scalar( $key ) ? trim( (string) $key ) : '';
		}
		return trim( (string) getenv( 'OPENAI_API_KEY' ) );
	}

	/** Returns the configured interpretation model. */
	private static function model(): string {
		if ( defined( 'EHRMAN_DISCOVERY_AI_MODEL' ) ) {
			$model = constant( 'EHRMAN_DISCOVERY_AI_MODEL' );
			return is_scalar( $model ) ? self::sanitize_model( (string) $model ) : self::DEFAULT_MODEL;
		}
		$model = trim( (string) getenv( 'EHRMAN_DISCOVERY_AI_MODEL' ) );
		return '' === $model ? self::DEFAULT_MODEL : self::sanitize_model( $model );
	}

	/**
	 * Returns a model identifier containing only provider-supported characters.
	 *
	 * @param string $model Configured model identifier.
	 * @return string Sanitized model identifier.
	 */
	private static function sanitize_model( string $model ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', trim( $model ) );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}
}
