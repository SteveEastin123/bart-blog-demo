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
	private const API_URL           = 'https://api.openai.com/v1/responses';
	private const DEFAULT_MODEL     = 'gpt-5.4-mini';
	private const MAX_QUESTION_LEN  = 800;
	private const CACHE_SECONDS     = DAY_IN_SECONDS;
	private const PROMPT_VERSION    = '21';
	private const FOCUSED_MAX_TERMS = 2;
	private const STRATEGY_FOCUSED  = 'focused';
	private const STRATEGY_LEGACY   = 'legacy';

	/**
	 * Controlled-vocabulary term selector.
	 *
	 * @var AI_Term_Selector
	 */
	private AI_Term_Selector $term_selector;

	/**
	 * Shared title-and-summary candidate reviewer.
	 *
	 * @var AI_Candidate_Reviewer
	 */
	private AI_Candidate_Reviewer $candidate_reviewer;

	/**
	 * Creates the interpreter and its focused collaborators.
	 *
	 * @param AI_Candidate_Reviewer|null $candidate_reviewer Optional candidate reviewer for testing or integration.
	 */
	public function __construct( ?AI_Candidate_Reviewer $candidate_reviewer = null ) {
		$this->term_selector      = new AI_Term_Selector();
		$this->candidate_reviewer = $candidate_reviewer ?? new AI_Candidate_Reviewer( self::api_key(), self::model() );
	}

	/** Returns the configured model identifier for reporting. */
	public static function model_id(): string {
		return self::model();
	}

	/** Returns the interpretation prompt version for reporting. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION . '-' . self::term_strategy();
	}

	/** Returns the configured interpretation strategy. */
	public static function term_strategy(): string {
		if ( defined( 'EHRMAN_DISCOVERY_AI_TERM_STRATEGY' ) ) {
			$strategy = constant( 'EHRMAN_DISCOVERY_AI_TERM_STRATEGY' );
			return is_scalar( $strategy ) ? self::sanitize_term_strategy( (string) $strategy ) : self::STRATEGY_FOCUSED;
		}
		return self::sanitize_term_strategy( (string) getenv( 'EHRMAN_DISCOVERY_AI_TERM_STRATEGY' ) );
	}

	/** Returns the configured AI result-grouping mode. */
	public static function result_grouping(): string {
		return AI_Candidate_Reviewer::result_grouping();
	}

	/** Returns the refinement prompt version for analytics. */
	public static function refine_prompt_version(): string {
		return AI_Candidate_Reviewer::prompt_version();
	}

	/**
	 * Returns whether server-side AI credentials are available.
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Interprets a question using approved search terms.
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
			Search_Service::normalize( $question ) . '|' . self::model() . '|' . self::prompt_version() . '|' . ( is_scalar( $import_checksum ) ? (string) $import_checksum : '' ) . '|' . AI_Usage::cache_version()
		);
		$vocabulary      = $this->vocabulary();
		$cached          = get_transient( $cache_key );
		$cached          = $this->cached_result( $cached );
		if ( null !== $cached ) {
			AI_Usage::record_cache_hit( self::model(), $request_id );
			$cached_terms        = $this->term_selector->prefer_topic_labels( $cached['terms'], $vocabulary['topics'] );
			$cached_terms        = $this->term_selector->reconcile_gospel_comparison( $question, $cached_terms, $vocabulary['topics'], self::max_interpreted_terms() );
			$cached['terms']     = $this->term_selector->bounded_terms( $this->term_selector->compatible_terms( $cached_terms ), self::max_interpreted_terms() );
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

		$max_terms = self::max_interpreted_terms();
		$entities  = $this->term_selector->validated_entities( $decoded['named_entities'] ?? array(), $vocabulary['keywords'], $max_terms );
		$terms     = $this->term_selector->validated_terms( $decoded['terms'] ?? array(), $vocabulary, $max_terms );
		$terms     = $this->term_selector->prefer_topic_labels( $this->term_selector->merge_terms( $entities, $terms, $max_terms ), $vocabulary['topics'] );
		$terms     = $this->term_selector->reconcile_gospel_comparison( $question, $terms, $vocabulary['topics'], $max_terms );
		$result    = array(
			'question'  => $question,
			'terms'     => $this->term_selector->bounded_terms( $this->term_selector->compatible_terms( $terms ), $max_terms ),
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
	 * @return array{post_ids:list<string>,post_tiers:array<string,string>,candidate_count:int,cache_hit:bool,usage:array<string,mixed>}|WP_Error Refined post identifiers or error.
	 */
	public function refine( string $question, array $posts, string $request_id = '' ) {
		return $this->candidate_reviewer->review( $question, $posts, $request_id );
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
		$focused      = self::STRATEGY_FOCUSED === self::term_strategy();
		$max_terms    = self::max_interpreted_terms();
		$instructions = $focused
			? 'You interpret questions for a curated biblical-studies blog search that uses AND semantics. '
				. 'Resolve obvious spelling errors from context before selecting vocabulary labels. '
				. 'Select no more than two vocabulary labels total, ordered from most to least important. Use one label whenever one specific topic and its description capture the reader\'s request. '
				. 'Add a second label only when it represents a separate, essential subject that matching posts must also address. Do not add a broad background topic, a merely helpful ranking concept, or a named person or text already implied by the selected topic. '
				. 'For a comparison or relationship between two explicitly named people or texts, preserve both specific subjects unless one existing topic directly covers the complete relationship. '
				. 'When Matthew, Mark, Luke, or John are shorthand for their Gospels in a literary comparison, select the corresponding Gospel topics. Do not add a methodological topic unless the question explicitly asks about that method. '
				. 'Prefer a topic when its description directly covers a major subject in the question. Use a keyword only for an essential person, text, place, or idea not already represented by a selected topic. '
				. 'When one topic description explicitly covers both the named subject and the issue being asked about, choose that topic instead of a source text, person keyword, or broader contextual topic that might also contain relevant evidence. For example, use Women in Pauline Traditions for a question about Paul\'s teaching on women in church, and Judas Iscariot for a question about Judas\' betrayal or death. '
				. 'Do not select a topic and keyword that express substantially the same concept. Treat broad and narrow topics about the same aspect as alternatives and choose the most specific applicable topic. '
				. 'Topic descriptions define firm selection boundaries; do not select a topic merely because its name contains a related word. '
				. 'Never invent, rename, or alter a label. If the same label exists as both a topic and keyword, select it as a topic. Return only labels copied exactly from the vocabulary.'
			: 'You interpret questions for a curated biblical-studies blog search. '
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
		$properties   = array(
			'terms' => array(
				'type'     => 'array',
				'maxItems' => $max_terms,
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
		);
		$required     = array( 'terms' );
		if ( ! $focused ) {
			$properties = array(
				'named_entities' => array(
					'type'     => 'array',
					'maxItems' => $max_terms,
					'items'    => array( 'type' => 'string' ),
				),
				'terms'          => $properties['terms'],
			);
			$required   = array( 'named_entities', 'terms' );
		}
		return array(
			'model'             => self::model(),
			'reasoning'         => array( 'effort' => 'low' ),
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => $focused ? 350 : 500,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_search_interpretation',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => $required,
						'properties'           => $properties,
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

	/** Returns the interpretation term limit for the configured strategy. */
	private static function max_interpreted_terms(): int {
		return self::STRATEGY_FOCUSED === self::term_strategy() ? self::FOCUSED_MAX_TERMS : Search_Service::MAX_TERMS;
	}

	/**
	 * Normalizes the strategy switch and defaults safely to focused mode.
	 *
	 * @param string $strategy Configured strategy value.
	 */
	private static function sanitize_term_strategy( string $strategy ): string {
		return self::STRATEGY_LEGACY === strtolower( trim( $strategy ) ) ? self::STRATEGY_LEGACY : self::STRATEGY_FOCUSED;
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
