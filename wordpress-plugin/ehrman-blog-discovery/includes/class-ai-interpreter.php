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
	private const API_URL          = 'https://api.openai.com/v1/responses';
	private const DEFAULT_MODEL    = 'gpt-5.4-mini';
	private const MAX_QUESTION_LEN = 800;
	private const CACHE_SECONDS    = DAY_IN_SECONDS;
	private const PROMPT_VERSION   = '16';

	/** Returns the configured model identifier for reporting. */
	public static function model_id(): string {
		return self::model();
	}

	/** Returns the interpretation prompt version for reporting. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION;
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
	 * @param string $question Reader's natural-language question.
	 * @return array{question:string,terms:list<array{label:string,mode:string}>}|WP_Error Interpretation or error.
	 */
	public function interpret( string $question ) {
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' )
			? mb_substr( $question, 0, self::MAX_QUESTION_LEN )
			: substr( $question, 0, self::MAX_QUESTION_LEN );
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
			AI_Usage::record_cache_hit( self::model() );
			$cached['terms'] = $this->compatible_terms( $this->prefer_topic_labels( $cached['terms'], $vocabulary['topics'] ) );
			return $cached;
		}

		$request   = $this->request_payload( $question, $vocabulary );
		$body_json = wp_json_encode( $request );
		if ( ! is_string( $body_json ) ) {
			AI_Usage::record_failure( self::model(), 'request_error' );
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
			AI_Usage::record_failure( self::model(), 'unavailable' );
			return new WP_Error( 'ehrman_ai_unavailable', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			AI_Usage::record_failure( self::model(), 'response_error' );
			return new WP_Error( 'ehrman_ai_response_error', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		/**
		 * Decoded API response.
		 *
		 * @var array<string,mixed> $body
		 */
		if ( $status < 200 || $status >= 300 ) {
			AI_Usage::record_response( $body, false, 'response_error' );
			return new WP_Error( 'ehrman_ai_response_error', __( 'The question could not be interpreted. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		$decoded = json_decode( $this->output_text( $body ), true );
		if ( ! is_array( $decoded ) ) {
			AI_Usage::record_response( $body, false, 'invalid_output' );
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
			'question' => $question,
			'terms'    => $this->compatible_terms( $terms ),
		);
		if ( empty( $result['terms'] ) ) {
			AI_Usage::record_response( $body, false, 'no_terms' );
			return new WP_Error( 'ehrman_ai_no_terms', __( 'No matching topics or keywords were identified. Try rephrasing the question.', 'ehrman-blog-discovery' ), array( 'status' => 422 ) );
		}
		AI_Usage::record_response( $body, true );
		set_transient( $cache_key, $result, self::CACHE_SECONDS );
		return $result;
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
			. 'Copy every person or text explicitly named in the question into named_entities when an exact keyword label exists. Never substitute a broader topic for an explicit named entity. '
			. 'For example, a question asking what Paul said, knew, or believed must include Paul in named_entities when Paul is an approved keyword. Apply the same rule to every explicitly named person or text. '
			. 'Select the smallest number of additional terms needed. Across named_entities and terms, use no more than four total vocabulary labels. '
			. 'Select no more than one primary topic. Represent additional supporting concepts with keywords when appropriate. '
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
		$terms          = array();
		$seen           = array();
		$topic_selected = false;
		foreach ( is_array( $raw ) ? $raw : array() as $term ) {
			if ( ! is_array( $term ) || ! is_scalar( $term['label'] ?? null ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $term['label'] );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $topics[ $normalized ] ) ) {
				if ( $topic_selected ) {
					continue;
				}
				$terms[]        = array(
					'label' => $topics[ $normalized ],
					'mode'  => Search_Service::TERM_MODE_TOPIC,
				);
				$topic_selected = true;
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
