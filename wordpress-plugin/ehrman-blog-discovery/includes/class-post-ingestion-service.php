<?php
/**
 * Administrator post-ingestion analysis and persistence.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares, validates, approves, and indexes administrator-submitted posts.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 * @phpstan-type AnalysisMetrics array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
 * @phpstan-type AnalysisResult array{proposal:Proposal,metrics:AnalysisMetrics}
 */
final class Post_Ingestion_Service {
	private const API_URL                   = 'https://api.openai.com/v1/responses';
	private const DEFAULT_MODEL             = 'gpt-5.6-sol';
	private const PROMPT_VERSION            = '8';
	private const INITIAL_MAX_OUTPUT_TOKENS = 16000;
	private const RETRY_MAX_OUTPUT_TOKENS   = 32000;
	private const MAX_POST_LENGTH           = 120000;
	private const MAX_TITLE_LENGTH          = 1000;
	private const HOUSE_STYLE_OPENING_VERBS = array(
		'addresses',
		'analyzes',
		'announces',
		'answers',
		'applies',
		'argues',
		'asks',
		'assesses',
		'begins',
		'catalogs',
		'celebrates',
		'challenges',
		'clarifies',
		'collects',
		'compares',
		'concludes',
		'connects',
		'considers',
		'continues',
		'contrasts',
		'corrects',
		'criticizes',
		'critiques',
		'defines',
		'defends',
		'demonstrates',
		'describes',
		'develops',
		'discusses',
		'distinguishes',
		'evaluates',
		'examines',
		'explains',
		'explores',
		'frames',
		'gives',
		'highlights',
		'identifies',
		'interprets',
		'introduces',
		'investigates',
		'invites',
		'lists',
		'marks',
		'offers',
		'outlines',
		'places',
		'presents',
		'previews',
		'profiles',
		'proposes',
		'provides',
		'questions',
		'raises',
		'reassesses',
		'recalls',
		'recommends',
		'reconsiders',
		'reconstructs',
		'recounts',
		'rejects',
		'reflects',
		'remembers',
		'reminds',
		'reports',
		'reposts',
		'reproduces',
		'responds',
		'retells',
		'returns',
		'reviews',
		'revisits',
		'seeks',
		'shares',
		'shows',
		'sketches',
		'summarizes',
		'surveys',
		'traces',
		'uses',
	);
	private const STATUS_ERROR              = 'error';
	private const STATUS_HELD               = 'held';
	private const STATUS_READY              = 'ready';
	private const STATUS_APPROVED           = 'approved';

	/** Returns whether the dedicated ingestion project key is configured. */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/** Returns the configured ingestion model. */
	public static function model_id(): string {
		$value = defined( 'EHRMAN_INGESTION_OPENAI_MODEL' )
			? constant( 'EHRMAN_INGESTION_OPENAI_MODEL' )
			: getenv( 'EHRMAN_INGESTION_OPENAI_MODEL' );
		$model = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $model ) {
			return self::DEFAULT_MODEL;
		}
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', $model );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}

	/** Returns the editorial-prompt version recorded with each draft. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION;
	}

	/** Returns the active post source-of-truth mode. */
	public static function post_source(): string {
		$value = defined( 'EHRMAN_DISCOVERY_POST_SOURCE' )
			? constant( 'EHRMAN_DISCOVERY_POST_SOURCE' )
			: getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
		return 'mysql' === strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) ) ? 'mysql' : 'json';
	}

	/** Returns whether approval may modify the live discovery index. */
	public static function database_is_authoritative(): bool {
		return 'mysql' === self::post_source();
	}

	/**
	 * Validates post metadata, stores a pending draft, and analyzes its full text.
	 *
	 * @param array<string,mixed> $input   Submitted metadata and full text.
	 * @param int                 $user_id Administrator user identifier.
	 * @return array<string,mixed>|WP_Error Stored draft or validation error.
	 */
	public function analyze( array $input, int $user_id ) {
		$validated = $this->validate_submission( $input );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'ehrman_ingestion_not_configured',
				__( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before analyzing a post.', 'ehrman-blog-discovery' )
			);
		}

		$duplicate = $this->duplicate_message( $validated['source_wp_id'], $validated['url'] );
		if ( '' !== $duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $duplicate );
		}

		$taxonomy      = $this->vocabulary();
		$taxonomy_json = wp_json_encode( $taxonomy );
		$taxonomy_hash = hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' );
		$now           = current_time( 'mysql', true );
		$draft_table   = Database::tables()['ingestion_drafts'];
		$wpdb          = Database::client();
		$inserted      = $wpdb->insert(
			$draft_table,
			array(
				'status'           => 'analyzing',
				'source_wp_id'     => $validated['source_wp_id'],
				'title'            => $validated['title'],
				'url'              => $validated['url'],
				'url_hash'         => hash( 'sha256', $validated['url'], true ),
				'author'           => $validated['author'],
				'date_text'        => $validated['date_text'],
				'published_at'     => $validated['published_at'],
				'post_text'        => $validated['post_text'],
				'model'            => self::model_id(),
				'prompt_version'   => self::PROMPT_VERSION,
				'taxonomy_version' => $taxonomy_hash,
				'created_by'       => max( 0, $user_id ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The ingestion draft could not be stored.', 'ehrman-blog-discovery' ) );
		}
		$draft_id = (int) $wpdb->insert_id;

		$result = $this->request_analysis( $validated, $taxonomy, $taxonomy_hash, $user_id );
		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$draft_table,
				array(
					'status'        => self::STATUS_ERROR,
					'error_message' => $result->get_error_message(),
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return $this->draft( $draft_id ) ?? $result;
		}

		$proposal_json = wp_json_encode( $result['proposal'] );
		$metrics       = $result['metrics'];
		$wpdb->update(
			$draft_table,
			array(
				'status'              => $result['proposal']['status'],
				'proposal_json'       => is_string( $proposal_json ) ? $proposal_json : '',
				'response_id'         => $metrics['response_id'],
				'input_tokens'        => $metrics['input_tokens'],
				'cached_input_tokens' => $metrics['cached_input_tokens'],
				'output_tokens'       => $metrics['output_tokens'],
				'reasoning_tokens'    => $metrics['reasoning_tokens'],
				'estimated_cost_usd'  => number_format( $metrics['estimated_cost_usd'], 8, '.', '' ),
				'error_message'       => null,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The analyzed draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Repeats analysis for a stored draft after a temporary failure or requested revision.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function reanalyze( int $draft_id ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'ehrman_ingestion_not_configured',
				__( 'Configure EHRMAN_INGESTION_OPENAI_API_KEY before reanalyzing a post.', 'ehrman-blog-discovery' )
			);
		}
		if ( '' === trim( Database::text( $draft['post_text'] ?? null ) ) ) {
			return new WP_Error( 'ehrman_ingestion_missing_text', __( 'The draft no longer contains full post text.', 'ehrman-blog-discovery' ) );
		}

		$taxonomy      = $this->vocabulary();
		$taxonomy_json = wp_json_encode( $taxonomy );
		$taxonomy_hash = hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' );
		$input         = array(
			'source_wp_id' => Database::integer( $draft['source_wp_id'] ?? null ),
			'title'        => Database::text( $draft['title'] ?? null ),
			'url'          => Database::text( $draft['url'] ?? null ),
			'author'       => Database::text( $draft['author'] ?? null ),
			'date_text'    => Database::text( $draft['date_text'] ?? null ),
			'published_at' => Database::text( $draft['published_at'] ?? null ),
			'post_text'    => Database::text( $draft['post_text'] ?? null ),
		);
		$result        = $this->request_analysis( $input, $taxonomy, $taxonomy_hash, Database::integer( $draft['created_by'] ?? null ) );
		if ( is_wp_error( $result ) ) {
			Database::client()->update(
				Database::tables()['ingestion_drafts'],
				array(
					'status'        => self::STATUS_ERROR,
					'error_message' => $result->get_error_message(),
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return $this->draft( $draft_id ) ?? $result;
		}

		$proposal_json = wp_json_encode( $result['proposal'] );
		$metrics       = $result['metrics'];
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'status'              => $result['proposal']['status'],
				'proposal_json'       => is_string( $proposal_json ) ? $proposal_json : '',
				'model'               => self::model_id(),
				'prompt_version'      => self::PROMPT_VERSION,
				'taxonomy_version'    => $taxonomy_hash,
				'response_id'         => $metrics['response_id'],
				'input_tokens'        => $metrics['input_tokens'],
				'cached_input_tokens' => $metrics['cached_input_tokens'],
				'output_tokens'       => $metrics['output_tokens'],
				'reasoning_tokens'    => $metrics['reasoning_tokens'],
				'estimated_cost_usd'  => number_format( $metrics['estimated_cost_usd'], 8, '.', '' ),
				'error_message'       => null,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The reanalyzed draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Stores administrator revisions without modifying the live index.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $input    Revised proposal fields.
	 * @return array<string,mixed>|WP_Error Updated draft or error.
	 */
	public function save_proposal( int $draft_id, array $input ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		$proposal = $this->proposal_from_input( $input );
		$checked  = $this->validate_proposal( $proposal, true, false, Database::text( $draft['title'] ?? null ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$encoded = wp_json_encode( $checked );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The revised proposal could not be encoded.', 'ehrman-blog-discovery' ) );
		}
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'status'        => $checked['status'],
				'proposal_json' => $encoded,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The revised draft could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Applies an approved proposal to the live MySQL search index.
	 *
	 * @param int  $draft_id            Draft identifier.
	 * @param bool $approve_new_keywords Explicit approval for proposed new keywords.
	 * @return array<string,mixed>|WP_Error Approved draft or error.
	 * @throws RuntimeException When an approval row cannot be persisted.
	 */
	public function approve( int $draft_id, bool $approve_new_keywords ) {
		if ( ! self::database_is_authoritative() ) {
			return new WP_Error(
				'ehrman_ingestion_json_authoritative',
				__( 'Approval is disabled while authoritative post data comes from JSON. Set EHRMAN_DISCOVERY_POST_SOURCE=mysql only after the source-of-truth handoff.', 'ehrman-blog-discovery' )
			);
		}
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The pending ingestion draft was not found.', 'ehrman-blog-discovery' ) );
		}
		$proposal = $this->decode_proposal( Database::text( $draft['proposal_json'] ?? null ) );
		$checked  = $this->validate_proposal( $proposal, false, false, Database::text( $draft['title'] ?? null ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		if ( self::STATUS_READY !== $checked['status'] ) {
			return new WP_Error( 'ehrman_ingestion_held', __( 'Assign a suitable existing topic and mark the proposal ready before approval.', 'ehrman-blog-discovery' ) );
		}
		if ( ! empty( $checked['newSecondaryKeywords'] ) && ! $approve_new_keywords ) {
			return new WP_Error( 'ehrman_ingestion_new_keywords', __( 'Explicitly approve the proposed new secondary keywords before applying this post.', 'ehrman-blog-discovery' ) );
		}

		$current_taxonomy = $this->vocabulary();
		$current_json     = wp_json_encode( $current_taxonomy );
		$current_hash     = hash( 'sha256', is_string( $current_json ) ? $current_json : '' );
		$approved_json    = wp_json_encode( $checked );
		if ( ! is_string( $approved_json ) ) {
			return new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved proposal could not be encoded.', 'ehrman-blog-discovery' ) );
		}
		if ( ! hash_equals( Database::text( $draft['taxonomy_version'] ?? null ), $current_hash ) ) {
			return new WP_Error( 'ehrman_ingestion_taxonomy_changed', __( 'The topic or keyword vocabulary changed after analysis. Reanalyze the draft before approval.', 'ehrman-blog-discovery' ) );
		}

		$source_wp_id = Database::integer( $draft['source_wp_id'] ?? null );
		$url          = Database::text( $draft['url'] ?? null );
		$duplicate    = $this->duplicate_message( $source_wp_id, $url );
		if ( '' !== $duplicate ) {
			return new WP_Error( 'ehrman_ingestion_duplicate', $duplicate );
		}

		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ehrman_ingestion_transaction', __( 'The approval transaction could not be started.', 'ehrman-blog-discovery' ) );
		}
		try {
			$post_id = $this->insert_approved_post( $draft, $checked, $current_taxonomy );
			$updated = $wpdb->update(
				$tables['ingestion_drafts'],
				array(
					'status'           => self::STATUS_APPROVED,
					'proposal_json'    => $approved_json,
					'post_text'        => null,
					'error_message'    => null,
					'approved_at'      => current_time( 'mysql', true ),
					'approved_post_id' => $post_id,
					'embedding_status' => in_array( 'Ignore', $checked['topics'], true ) ? 'not_applicable' : 'pending',
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				throw new RuntimeException( 'The ingestion audit record could not be finalized.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The approval transaction could not be committed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ehrman_ingestion_apply_failed', sanitize_text_field( $error->getMessage() ) );
		}

		AI_Usage::invalidate_search_caches();
		if ( ! in_array( 'Ignore', $checked['topics'], true ) ) {
			$this->generate_embedding( $draft_id, $source_wp_id );
		}
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The approved audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Retries vector generation for an approved post.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|WP_Error Updated audit record or error.
	 */
	public function retry_embedding( int $draft_id ) {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED !== $draft['status'] ) {
			return new WP_Error( 'ehrman_ingestion_missing_draft', __( 'The approved ingestion record was not found.', 'ehrman-blog-discovery' ) );
		}
		$this->generate_embedding( $draft_id, Database::integer( $draft['source_wp_id'] ?? null ) );
		$stored = $this->draft( $draft_id );
		return null === $stored
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The embedding audit record could not be reloaded.', 'ehrman-blog-discovery' ) )
			: $stored;
	}

	/**
	 * Deletes a pending draft and its retained full text.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public function discard( int $draft_id ): bool {
		$draft = $this->draft( $draft_id );
		if ( null === $draft || self::STATUS_APPROVED === $draft['status'] ) {
			return false;
		}
		return false !== Database::client()->delete( Database::tables()['ingestion_drafts'], array( 'id' => $draft_id ), array( '%d' ) );
	}

	/**
	 * Returns one ingestion draft.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return array<string,mixed>|null Draft record.
	 */
	public function draft( int $draft_id ): ?array {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $draft_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		return Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
	}

	/**
	 * Returns recent pending and approved ingestion records.
	 *
	 * @return list<array<string,mixed>> Draft records.
	 */
	public function drafts(): array {
		$table = Database::tables()['ingestion_drafts'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is internal and no values are interpolated.
		return Database::associative_rows( Database::client()->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC,id DESC LIMIT 100", ARRAY_A ) );
	}

	/**
	 * Returns approved vocabulary for review controls.
	 *
	 * @phpstan-return Vocabulary
	 */
	public function vocabulary(): array {
		$wpdb            = Database::client();
		$tables          = Database::tables();
		$topic_alias_sql = "SELECT topic_id,label FROM {$tables['topic_aliases']} ORDER BY topic_id,label";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is internal.
		$topic_alias_rows = Database::associative_rows( $wpdb->get_results( $topic_alias_sql, ARRAY_A ) );
		$topic_aliases    = array();
		foreach ( $topic_alias_rows as $row ) {
			$topic_id = Database::integer( $row['topic_id'] ?? null );
			$label    = Database::text( $row['label'] ?? null );
			if ( $topic_id > 0 && '' !== $label ) {
				$topic_aliases[ $topic_id ][] = $label;
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is internal.
		$topic_rows  = Database::associative_rows( $wpdb->get_results( "SELECT id,name,description FROM {$tables['topics']} ORDER BY CASE WHEN name='Ignore' THEN 1 ELSE 0 END,name", ARRAY_A ) );
		$topics      = array_map(
			static function ( array $row ) use ( $topic_aliases ): array {
				$id = Database::integer( $row['id'] ?? null );
				return array(
					'id'          => $id,
					'name'        => Database::text( $row['name'] ?? null ),
					'description' => Database::text( $row['description'] ?? null ),
					'aliases'     => array_values( array_unique( $topic_aliases[ $id ] ?? array() ) ),
				);
			},
			$topic_rows
		);
		$keyword_sql = "SELECT k.id,k.label,k.normalized,COUNT(pk.post_id) usage_count FROM {$tables['keywords']} k "
			. "LEFT JOIN {$tables['post_keywords']} pk ON pk.keyword_id=k.id GROUP BY k.id,k.label,k.normalized ORDER BY k.label";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are internal.
		$keyword_rows = Database::associative_rows( $wpdb->get_results( $keyword_sql, ARRAY_A ) );
		$keywords     = array_map(
			static fn( array $row ): array => array(
				'id'         => Database::integer( $row['id'] ?? null ),
				'label'      => Database::text( $row['label'] ?? null ),
				'normalized' => Database::text( $row['normalized'] ?? null ),
				'count'      => Database::integer( $row['usage_count'] ?? null ),
			),
			$keyword_rows
		);
		return array(
			'topics'   => $topics,
			'keywords' => $keywords,
		);
	}

	/**
	 * Builds and sends one structured editorial-analysis request.
	 *
	 * @param array<string,mixed> $post          Validated post.
	 * @param array<string,mixed> $taxonomy      Vocabulary.
	 * @param string              $taxonomy_hash Taxonomy fingerprint.
	 * @param int                 $user_id       Administrator identifier.
	 * @phpstan-param ValidatedPost $post
	 * @phpstan-param Vocabulary $taxonomy
	 * @phpstan-return AnalysisResult|WP_Error
	 */
	private function request_analysis( array $post, array $taxonomy, string $taxonomy_hash, int $user_id ) {
		$limits             = array( self::INITIAL_MAX_OUTPUT_TOKENS, self::RETRY_MAX_OUTPUT_TOKENS );
		$combined_metrics   = null;
		$last_invalid_error = null;
		foreach ( $limits as $attempt => $max_output_tokens ) {
			$payload = $this->request_payload( $post, $taxonomy, $taxonomy_hash, $user_id, $max_output_tokens );
			$encoded = wp_json_encode( $payload );
			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'ehrman_ingestion_request_error', __( 'The post could not be prepared for analysis.', 'ehrman-blog-discovery' ) );
			}
			$response = wp_remote_post(
				self::API_URL,
				array(
					'timeout' => 180,
					'headers' => array(
						'Authorization' => 'Bearer ' . self::api_key(),
						'Content-Type'  => 'application/json',
					),
					'body'    => $encoded,
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ehrman_ingestion_unavailable', __( 'The ingestion analysis service is temporarily unavailable.', 'ehrman-blog-discovery' ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			$body   = Database::associative_row( json_decode( wp_remote_retrieve_body( $response ), true ) );
			if ( null === $body || $status < 200 || $status >= 300 ) {
				return new WP_Error( 'ehrman_ingestion_response_error', __( 'The ingestion analysis service returned an error.', 'ehrman-blog-discovery' ) );
			}
			$metrics          = $this->response_metrics( $body );
			$combined_metrics = null === $combined_metrics ? $metrics : $this->combine_metrics( $combined_metrics, $metrics );
			$output           = Database::associative_row( json_decode( $this->output_text( $body ), true ) );
			$proposal         = null !== $output ? $this->validate_proposal( $output, true, true, $post['title'] ) : null;
			if ( is_array( $proposal ) ) {
				return array(
					'proposal' => $proposal,
					'metrics'  => $combined_metrics,
				);
			}

			$last_invalid_error = is_wp_error( $proposal ) ? $proposal : null;
			$incomplete         = Database::associative_row( $body['incomplete_details'] ?? null );
			$reason             = sanitize_key( Database::text( $incomplete['reason'] ?? null ) );
			if ( 'max_output_tokens' === $reason && 0 === $attempt ) {
				continue;
			}
			if ( 'max_output_tokens' === $reason ) {
				return new WP_Error(
					'ehrman_ingestion_output_limit',
					__( 'The ingestion analysis reached the expanded response limit before completing structured data.', 'ehrman-blog-discovery' )
				);
			}
			break;
		}

		$message = is_wp_error( $last_invalid_error )
			? $last_invalid_error->get_error_message()
			: __( 'The ingestion analysis service returned invalid structured data.', 'ehrman-blog-discovery' );
		return new WP_Error( 'ehrman_ingestion_invalid_output', $message );
	}

	/**
	 * Creates the Responses API payload.
	 *
	 * @param array<string,mixed> $post          Validated post.
	 * @param array<string,mixed> $taxonomy      Vocabulary.
	 * @param string              $taxonomy_hash Taxonomy fingerprint.
	 * @param int                 $user_id       Administrator identifier.
	 * @param int                 $max_output_tokens Maximum generated-token allowance.
	 * @return array<string,mixed> API request payload.
	 * @phpstan-param ValidatedPost $post
	 * @phpstan-param Vocabulary $taxonomy
	 */
	private function request_payload( array $post, array $taxonomy, string $taxonomy_hash, int $user_id, int $max_output_tokens = self::INITIAL_MAX_OUTPUT_TOKENS ): array {
		$instructions  = implode(
			"\n\n",
			array(
				'Analyze the complete Bart Ehrman Blog post for a curated search index. Use the full text, not the title, excerpt, source categories, or generated tags alone. Return only the requested structured fields.',
				'DESCRIPTION: Write one natural sentence, generally 18-23 words and always under 30. Begin with an active, present-tense verb and state the post’s central argument or purpose without merely repeating the title. Do not begin with Ehrman, Bart, This post, The post, an author name, a quotation, or a question word.',
				'SEARCH SUMMARY: Write normally three clear sentences and about 60-80 words. Begin with an active, present-tense verb and a declarative statement. State the central inquiry or argument first, the principal evidence or reasoning next, and the conclusion or implication last. Include concrete names, texts, events, or claims when they materially improve semantic retrieval. Do not begin with an author name or a question, and do not infer content absent from the supplied text.',
				'CLASSIFICATION GOAL: Preserve every distinct central subject while eliminating redundant and incidental labels. Do not aim for either many labels or few labels. Select the complete, nonredundant topic set plus every genuinely useful supporting keyword. Assign every existing topic that represents a primary, sustained subject or a substantial independent section. Do not omit a specific topic merely because a broader topic or keyword partially covers it.',
				'TOPIC DECISION PROCESS: First identify the distinct subjects developed across the complete post. Treat a multipart post’s substantial independent sections separately. For each subject, compare all plausible existing topic names and descriptions and choose the closest label. Add another topic only when it represents a separate central subject or substantial section that the selected topics do not already cover. Prefer a specific topic for a specific central subject. Keep a broader topic alongside it only when the broader topic covers other substantial material; never let an umbrella topic hide a distinct major section merely to reduce the count. Never invent or recommend a topic.',
				'TOPIC ELIGIBILITY TEST: Include a topic only when all three conditions hold: (1) it is discussed throughout the post or within a substantial independent section; (2) it is necessary to describe what that material is principally about; and (3) a reader browsing that topic would reasonably expect this post among the results. Mere mention, contextual background, or use as a source, example, or comparison does not pass this test.',
				'TOPIC COVERAGE CHECK: Before selecting keywords, identify internally every central subject and substantial independent section in the complete post. Verify that each is represented by its closest suitable existing topic. Reconsider any central subject represented only by a keyword, especially when that keyword matches or closely corresponds to an existing topic or topic alias. Do not output this internal checklist.',
				'TOPIC RATIONALES: For every selected topic, return exactly one topicRationales object using the canonical topic name and one short sentence explaining which primary, sustained material or substantial independent section passes the topic eligibility test. Return an empty topicRationales array only when no topic is selected. These rationales are review evidence, not additional topics or keyword justifications.',
				'SUBJECTS VERSUS EVIDENCE: A topic states what the post investigates, explains, or argues. A biblical book, author, person, text, event, or concept used chiefly as evidence, comparison, or example is not thereby a topic. It may be a secondary keyword when materially useful for retrieval. Assign a named biblical-book topic when the book’s composition, distinctive presentation, theology, interpretation, textual history, or reliability is itself a sustained inquiry, not simply because passages from it support another question.',
				'SECONDARY KEYWORDS: Select the smallest useful set of existing labels that makes the post discoverable beyond its assigned topics. Keep a label only if all three conditions hold: (1) the post discusses it beyond a passing reference; (2) it materially contributes to the argument, narrative, evidence, or a substantial section; and (3) a reader searching for that label alone would reasonably expect this post among useful results. Omit background and promotional details, incidental examples, and labels inferred only from the analytical method or genre. Do not add a person, text, place, event, or concept merely because it is named or quoted. A repeatedly used source may qualify when it carries the argument. Reject duplicates and labels equivalent to an assigned topic or any supplied topic alias. There is no fixed limit, but recheck an unusually long list term by term; more labels are not inherently better.',
				'NEW KEYWORDS: Check the entire existing vocabulary for the exact term, variants, and reasonable synonyms. Propose a new keyword only when the concept is central or materially supporting, likely to be reused for search, and lacks an adequate existing label. Do not create a new umbrella term that merely restates the title or central question when existing topics and keywords already retrieve it. An existing person or author keyword normally makes a new keyword for a cited work unnecessary unless the work is independently central and broadly reusable. Put new labels only in newSecondaryKeywords and explain each one in reviewNotes.',
				'EDGE CASES: Use Ignore only for genuinely administrative, promotional, or nonsubstantive posts. An interview, podcast, video, broadcast, or documentary introduction remains substantive for Media Interviews and Videos even when no transcript is supplied; advertised subjects can be keywords but should not become topics without substantive text. If a substantive post has no suitable existing topic, return held with no topics and explain why in reviewNotes.',
				'FINAL CHECK: Confirm that every distinct central subject has a topic; every topic adds coverage not supplied by a closer selected topic; sources and examples have not been promoted into topics; every keyword passes all three search-result expectation conditions and is nonredundant; every new keyword is unavoidable; and both prose fields follow the established opening and length style.',
			)
		);
		$taxonomy_json = wp_json_encode( $taxonomy );
		$metadata_json = wp_json_encode(
			array(
				'wpId'   => $post['source_wp_id'],
				'title'  => $post['title'],
				'url'    => $post['url'],
				'author' => $post['author'],
				'date'   => $post['date_text'],
			)
		);
		$input         = "Existing vocabulary (topic descriptions govern topic scope):\n"
			. ( is_string( $taxonomy_json ) ? $taxonomy_json : '{}' )
			. "\n\nPost metadata:\n"
			. ( is_string( $metadata_json ) ? $metadata_json : '{}' )
			. "\n\nComplete post text:\n"
			. $post['post_text'];
		return array(
			'model'             => self::model_id(),
			'reasoning'         => array( 'effort' => self::reasoning_effort() ),
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => $max_output_tokens,
			'store'             => false,
			'prompt_cache_key'  => 'ehrman-ingestion-v' . self::PROMPT_VERSION . '-' . substr( $taxonomy_hash, 0, 32 ),
			'safety_identifier' => hash_hmac( 'sha256', (string) max( 0, $user_id ), wp_salt( 'auth' ) ),
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_post_ingestion',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'description', 'searchSummary', 'topics', 'topicRationales', 'secondaryKeywords', 'newSecondaryKeywords', 'status', 'reviewNotes' ),
						'properties'           => array(
							'description'          => array( 'type' => 'string' ),
							'searchSummary'        => array( 'type' => 'string' ),
							'topics'               => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'topicRationales'      => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'topic', 'rationale' ),
									'properties'           => array(
										'topic'     => array( 'type' => 'string' ),
										'rationale' => array( 'type' => 'string' ),
									),
								),
							),
							'secondaryKeywords'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'newSecondaryKeywords' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'status'               => array(
								'type' => 'string',
								'enum' => array( self::STATUS_READY, self::STATUS_HELD ),
							),
							'reviewNotes'          => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Validates required post metadata and full text.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @phpstan-return ValidatedPost|WP_Error
	 */
	private function validate_submission( array $input ) {
		$source_wp_id = Database::integer( $input['source_wp_id'] ?? null );
		$title        = sanitize_text_field( Database::text( $input['title'] ?? null ) );
		$url          = esc_url_raw( Database::text( $input['url'] ?? null ), array( 'http', 'https' ) );
		$author       = sanitize_text_field( Database::text( $input['author'] ?? null ) );
		$date         = $this->parse_date( Database::text( $input['date'] ?? null ) );
		$post_text    = trim( wp_strip_all_tags( Database::text( $input['post_text'] ?? null ) ) );
		$post_text    = preg_replace( "/\r\n?|\n/", "\n", $post_text );
		$post_text    = is_string( $post_text ) ? $post_text : '';
		$errors       = array();
		if ( $source_wp_id < 1 ) {
			$errors[] = __( 'Enter a positive WordPress post ID.', 'ehrman-blog-discovery' );
		}
		if ( '' === $title ) {
			$errors[] = __( 'Enter the post title.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $title ) > self::MAX_TITLE_LENGTH ) {
			$errors[] = __( 'The post title is too long.', 'ehrman-blog-discovery' );
		}
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'Enter a valid post URL.', 'ehrman-blog-discovery' );
		}
		if ( '' === $author || $this->length( $author ) > 191 ) {
			$errors[] = __( 'Enter an author no longer than 191 characters.', 'ehrman-blog-discovery' );
		}
		if ( null === $date ) {
			$errors[] = __( 'Enter a valid publication date.', 'ehrman-blog-discovery' );
		}
		if ( '' === $post_text ) {
			$errors[] = __( 'Paste the complete post text.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $post_text ) > self::MAX_POST_LENGTH ) {
			$errors[] = __( 'The post text exceeds the 120,000-character limit.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $errors ) || null === $date ) {
			return new WP_Error( 'ehrman_ingestion_invalid_post', implode( ' ', $errors ) );
		}
		return array(
			'source_wp_id' => $source_wp_id,
			'title'        => $title,
			'url'          => $url,
			'author'       => $author,
			'date_text'    => $date['display'],
			'published_at' => $date['published'],
			'post_text'    => $post_text,
		);
	}

	/**
	 * Validates and canonicalizes an AI or administrator proposal.
	 *
	 * @param array<string,mixed> $proposal           Candidate proposal.
	 * @param bool                $allow_held         Whether held proposals are valid.
	 * @param bool                $require_rationales Whether every selected topic requires AI review evidence.
	 * @param string              $post_title         Post title used to prioritize possible missing-topic warnings.
	 * @phpstan-return Proposal|WP_Error
	 */
	private function validate_proposal( array $proposal, bool $allow_held, bool $require_rationales = false, string $post_title = '' ) {
		$description = $this->one_line( Database::text( $proposal['description'] ?? null ) );
		$summary     = $this->one_line( Database::text( $proposal['searchSummary'] ?? null ) );
		$status      = sanitize_key( Database::text( $proposal['status'] ?? null ) );
		$errors      = array();
		$style_notes = array();
		if ( '' === $description ) {
			$errors[] = __( 'Description is required.', 'ehrman-blog-discovery' );
		} elseif ( $this->word_count( $description ) >= 30 ) {
			$errors[] = __( 'Description must contain fewer than 30 words.', 'ehrman-blog-discovery' );
		} elseif ( ! $this->has_house_style_opening( $description ) ) {
			$errors[] = __( 'Description must begin with an approved active, present-tense verb.', 'ehrman-blog-discovery' );
		}
		$description_words = $this->word_count( $description );
		if ( '' !== $description && ( $description_words < 18 || $description_words > 23 ) ) {
			$style_notes[] = sprintf(
				/* translators: %d: description word count. */
				__( 'Style warning: Description has %d words; house style is normally 18-23 words.', 'ehrman-blog-discovery' ),
				$description_words
			);
		}
		if ( '' === $summary ) {
			$errors[] = __( 'Search summary is required.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $summary ) > 1200 ) {
			$errors[] = __( 'Search summary must not exceed 1,200 characters.', 'ehrman-blog-discovery' );
		} elseif ( ! $this->has_house_style_opening( $summary ) ) {
			$errors[] = __( 'Search summary must begin with an approved active, present-tense verb.', 'ehrman-blog-discovery' );
		}
		if ( '' !== $summary && $this->first_sentence_is_question( $summary ) ) {
			$errors[] = __( 'Search summary must open with a declarative statement, not a question.', 'ehrman-blog-discovery' );
		}
		$summary_words = $this->word_count( $summary );
		if ( '' !== $summary && ( $summary_words < 60 || $summary_words > 80 ) ) {
			$style_notes[] = sprintf(
				/* translators: %d: search-summary word count. */
				__( 'Style warning: Search summary has %d words; house style is normally 60-80 words.', 'ehrman-blog-discovery' ),
				$summary_words
			);
		}
		$summary_sentences = $this->sentence_count( $summary );
		if ( '' !== $summary && 3 !== $summary_sentences ) {
			$style_notes[] = sprintf(
				/* translators: %d: search-summary sentence count. */
				__( 'Style warning: Search summary has %d sentences; house style normally uses three.', 'ehrman-blog-discovery' ),
				$summary_sentences
			);
		}
		if ( ! in_array( $status, array( self::STATUS_READY, self::STATUS_HELD ), true ) ) {
			$errors[] = __( 'Proposal status must be ready or held.', 'ehrman-blog-discovery' );
		}
		if ( ! $allow_held && self::STATUS_HELD === $status ) {
			$errors[] = __( 'A held proposal cannot be approved.', 'ehrman-blog-discovery' );
		}

		$vocabulary      = $this->vocabulary();
		$topic_map       = array();
		$topic_alias_map = array();
		$topic_match_map = array();
		$keyword_map     = array();
		foreach ( $vocabulary['topics'] as $topic ) {
			$topic_name                     = $topic['name'];
			$normalized_topic               = Search_Service::normalize( $topic_name );
			$topic_map[ $normalized_topic ] = $topic_name;
			$topic_match_map[ $normalized_topic ][ $topic_name ] = true;
			foreach ( $topic['aliases'] as $alias ) {
				$normalized_alias = Search_Service::normalize( $alias );
				if ( '' !== $normalized_alias ) {
					$topic_alias_map[ $topic_name ][ $normalized_alias ] = true;
					$topic_match_map[ $normalized_alias ][ $topic_name ] = true;
				}
			}
		}
		foreach ( $vocabulary['keywords'] as $keyword ) {
			$keyword_map[ $keyword['normalized'] ] = $keyword['label'];
		}
		$topics           = $this->canonical_labels( $proposal['topics'] ?? array(), $topic_map, $errors, 'topic' );
		$topic_rationales = $this->topic_rationales( $proposal['topicRationales'] ?? array(), $topic_map, $topics, $errors, $require_rationales );
		if ( self::STATUS_READY === $status && empty( $topics ) ) {
			$errors[] = __( 'A ready proposal requires at least one existing topic.', 'ehrman-blog-discovery' );
		}
		if ( in_array( 'Ignore', $topics, true ) && count( $topics ) > 1 ) {
			$errors[] = __( 'Ignore cannot be combined with another topic.', 'ehrman-blog-discovery' );
		}

		$keywords               = $this->canonical_labels( $proposal['secondaryKeywords'] ?? array(), $keyword_map, $errors, 'secondary keyword' );
		$new_keywords           = $this->new_keyword_labels( $proposal['newSecondaryKeywords'] ?? array(), $keyword_map, $errors );
		$topic_norms            = array_fill_keys( array_map( array( Search_Service::class, 'normalize' ), $topics ), true );
		$assigned_topic_names   = array_fill_keys( $topics, true );
		$assigned_topic_aliases = array();
		$central_text           = Search_Service::normalize( $post_title . ' ' . $description . ' ' . $this->first_sentence( $summary ) );
		foreach ( $topics as $topic ) {
			foreach ( array_keys( $topic_alias_map[ $topic ] ?? array() ) as $normalized_alias ) {
				$assigned_topic_aliases[ $normalized_alias ] = $topic;
			}
		}
		$keyword_seen          = array();
		$missing_topic_matches = array();
		foreach ( array_merge( $keywords, $new_keywords ) as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			if ( isset( $topic_norms[ $normalized ] ) ) {
				/* translators: %s: secondary keyword label. */
				$errors[] = sprintf( __( 'Secondary keyword "%s" duplicates an assigned topic.', 'ehrman-blog-discovery' ), $keyword );
			}
			if ( isset( $assigned_topic_aliases[ $normalized ] ) ) {
				$style_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: assigned topic label. */
					__( 'Review warning: Secondary keyword "%1$s" overlaps an alias of assigned topic "%2$s".', 'ehrman-blog-discovery' ),
					$keyword,
					$assigned_topic_aliases[ $normalized ]
				);
			}
			foreach ( array_keys( $topic_match_map[ $normalized ] ?? array() ) as $matching_topic ) {
				if ( isset( $assigned_topic_names[ $matching_topic ] ) ) {
					continue;
				}
				$topic_normalized = Search_Service::normalize( $matching_topic );
				$is_strong        = $this->contains_normalized_phrase( $central_text, $normalized )
					|| $this->contains_normalized_phrase( $central_text, $topic_normalized );
				$current          = $missing_topic_matches[ $matching_topic ] ?? null;
				if ( ! is_array( $current ) || ( $is_strong && empty( $current['strong'] ) ) ) {
					$missing_topic_matches[ $matching_topic ] = array(
						'keyword' => $keyword,
						'strong'  => $is_strong,
					);
				}
			}
			if ( isset( $keyword_seen[ $normalized ] ) ) {
				/* translators: %s: secondary keyword label. */
				$errors[] = sprintf( __( 'Secondary keyword "%s" is duplicated.', 'ehrman-blog-discovery' ), $keyword );
			}
			$keyword_seen[ $normalized ] = true;
		}
		ksort( $missing_topic_matches, SORT_NATURAL | SORT_FLAG_CASE );
		$strong_topic_notes   = array();
		$advisory_topic_notes = array();
		foreach ( $missing_topic_matches as $matching_topic => $match ) {
			if ( ! empty( $match['strong'] ) ) {
				$strong_topic_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: unassigned topic label. */
					__( 'Strong review warning: Possible missing topic "%2$s": matching keyword "%1$s" also appears in the title, description, or opening summary sentence.', 'ehrman-blog-discovery' ),
					Database::text( $match['keyword'] ),
					$matching_topic
				);
			} else {
				$advisory_topic_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: unassigned topic label. */
					__( 'Advisory review warning: Secondary keyword "%1$s" matches unassigned topic "%2$s"; confirm that it is supporting rather than central.', 'ehrman-blog-discovery' ),
					Database::text( $match['keyword'] ),
					$matching_topic
				);
			}
		}

		$proposal_notes = array();
		foreach ( is_array( $proposal['reviewNotes'] ?? null ) ? $proposal['reviewNotes'] : array() as $note ) {
			$clean = sanitize_text_field( is_scalar( $note ) ? (string) $note : '' );
			if ( '' !== $clean && ! in_array( $clean, $proposal_notes, true ) ) {
				$proposal_notes[] = $clean;
			}
		}
		$notes = array_values( array_unique( array_merge( $strong_topic_notes, $advisory_topic_notes, $style_notes, $proposal_notes ) ) );
		if ( self::STATUS_HELD === $status && empty( $proposal_notes ) ) {
			$errors[] = __( 'A held proposal requires a review note explaining why no topic fits.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $new_keywords ) && empty( $proposal_notes ) ) {
			$errors[] = __( 'A new secondary keyword requires a review note.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'ehrman_ingestion_invalid_proposal', implode( ' ', array_values( array_unique( $errors ) ) ) );
		}
		return array(
			'description'          => $description,
			'searchSummary'        => $summary,
			'topics'               => $topics,
			'topicRationales'      => $topic_rationales,
			'secondaryKeywords'    => $keywords,
			'newSecondaryKeywords' => $new_keywords,
			'status'               => $status,
			'reviewNotes'          => $notes,
		);
	}

	/**
	 * Converts form fields into a proposal record.
	 *
	 * @param array<string,mixed> $input Submitted proposal fields.
	 * @return array<string,mixed> Proposal record.
	 */
	private function proposal_from_input( array $input ): array {
		$topic_rationales = json_decode( Database::text( $input['topic_rationales_json'] ?? null ), true );
		return array(
			'description'          => Database::text( $input['description'] ?? null ),
			'searchSummary'        => Database::text( $input['search_summary'] ?? null ),
			'topics'               => is_array( $input['topics'] ?? null ) ? $input['topics'] : array(),
			'topicRationales'      => is_array( $topic_rationales ) ? $topic_rationales : array(),
			'secondaryKeywords'    => $this->lines( Database::text( $input['secondary_keywords'] ?? null ) ),
			'newSecondaryKeywords' => $this->lines( Database::text( $input['new_secondary_keywords'] ?? null ) ),
			'status'               => Database::text( $input['status'] ?? null ),
			'reviewNotes'          => $this->lines( Database::text( $input['review_notes'] ?? null ) ),
		);
	}

	/**
	 * Inserts one post and every search relationship inside the caller transaction.
	 *
	 * @param array<string,mixed> $draft    Draft record.
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @param array<string,mixed> $taxonomy Vocabulary.
	 * @return int New internal post identifier.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When any row cannot be inserted.
	 */
	private function insert_approved_post( array $draft, array $proposal, array $taxonomy ): int {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$url    = Database::text( $draft['url'] ?? null );
		$ok     = $wpdb->insert(
			$tables['external_posts'],
			array(
				'source_wp_id'   => Database::integer( $draft['source_wp_id'] ?? null ),
				'title'          => Database::text( $draft['title'] ?? null ),
				'url'            => $url,
				'url_hash'       => hash( 'sha256', $url, true ),
				'author'         => Database::text( $draft['author'] ?? null ),
				'date_text'      => Database::text( $draft['date_text'] ?? null ),
				'published_at'   => Database::text( $draft['published_at'] ?? null ),
				'description'    => $proposal['description'],
				'search_summary' => $proposal['searchSummary'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'The approved post could not be inserted: ' . $wpdb->last_error );
		}
		$post_id = (int) $wpdb->insert_id;

		$topic_ids = array();
		foreach ( $taxonomy['topics'] as $topic ) {
			$topic_ids[ $topic['name'] ] = $topic['id'];
		}
		$keyword_ids = array();
		foreach ( $taxonomy['keywords'] as $keyword ) {
			$keyword_ids[ $keyword['normalized'] ] = $keyword['id'];
		}
		foreach ( $proposal['newSecondaryKeywords'] as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			if ( false === $wpdb->insert(
				$tables['keywords'],
				array(
					'label'      => $keyword,
					'normalized' => $normalized,
				),
				array( '%s', '%s' )
			) ) {
				throw new RuntimeException( 'A new secondary keyword could not be inserted: ' . $wpdb->last_error );
			}
			$keyword_ids[ $normalized ] = (int) $wpdb->insert_id;
		}

		$search_terms = array();
		foreach ( $proposal['topics'] as $topic_name ) {
			$topic_id = $topic_ids[ $topic_name ] ?? 0;
			if ( $topic_id < 1 || false === $wpdb->insert(
				$tables['post_topics'],
				array(
					'post_id'  => $post_id,
					'topic_id' => $topic_id,
				),
				array( '%d', '%d' )
			) ) {
				throw new RuntimeException( 'A post-topic relationship could not be inserted: ' . $wpdb->last_error );
			}
			$search_terms[ Search_Service::normalize( $topic_name ) . '|topic' ] = array( $topic_name, Search_Service::normalize( $topic_name ), 'topic', 6 );
			$alias_sql = $wpdb->prepare( 'SELECT label,normalized FROM %i WHERE topic_id=%d ORDER BY label', $tables['topic_aliases'], $topic_id );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
			$aliases = Database::associative_rows( $wpdb->get_results( $alias_sql, ARRAY_A ) );
			foreach ( $aliases as $alias ) {
				$normalized = Database::text( $alias['normalized'] ?? null );
				if ( '' !== $normalized ) {
					$search_terms[ $normalized . '|alias' ] = array( $topic_name, $normalized, 'alias', 6 );
				}
			}
		}

		foreach ( array_merge( $proposal['secondaryKeywords'], $proposal['newSecondaryKeywords'] ) as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			$keyword_id = $keyword_ids[ $normalized ] ?? 0;
			if ( $keyword_id < 1 || false === $wpdb->insert(
				$tables['post_keywords'],
				array(
					'post_id'    => $post_id,
					'keyword_id' => $keyword_id,
				),
				array( '%d', '%d' )
			) ) {
				throw new RuntimeException( 'A post-keyword relationship could not be inserted: ' . $wpdb->last_error );
			}
			$search_terms[ $normalized . '|secondary' ] = array( $keyword, $normalized, 'secondary', 3 );
		}
		foreach ( $search_terms as $term ) {
			if ( false === $wpdb->insert(
				$tables['post_search_terms'],
				array(
					'post_id'    => $post_id,
					'label'      => $term[0],
					'normalized' => $term[1],
					'kind'       => $term[2],
					'weight'     => $term[3],
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			) ) {
				throw new RuntimeException( 'A post search term could not be inserted: ' . $wpdb->last_error );
			}
		}
		return $post_id;
	}

	/**
	 * Generates a content vector and records a visible pending state on failure.
	 *
	 * @param int $draft_id    Draft identifier.
	 * @param int $source_wp_id Source WordPress post identifier.
	 */
	private function generate_embedding( int $draft_id, int $source_wp_id ): void {
		$table = Database::tables()['ingestion_drafts'];
		$key   = self::api_key();
		if ( '' === $key ) {
			Database::client()->update(
				$table,
				array(
					'embedding_status' => 'pending',
					'embedding_error'  => __( 'The ingestion API key is not configured.', 'ehrman-blog-discovery' ),
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}
		$client  = new Embedding_Service( $key, false );
		$service = new Semantic_Search_Service( $client );
		$result  = $service->build_post_embedding( $source_wp_id, true );
		if ( is_wp_error( $result ) ) {
			Database::client()->update(
				$table,
				array(
					'embedding_status' => 'pending',
					'embedding_model'  => Embedding_Service::model_id(),
					'embedding_error'  => $result->get_error_message(),
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}
		$metrics = $result['metrics'];
		Database::client()->update(
			$table,
			array(
				'embedding_status'             => 'complete',
				'embedding_model'              => Database::text( $metrics['model'] ?? Embedding_Service::model_id() ),
				'embedding_response_id'        => Database::text( $metrics['response_id'] ?? null ),
				'embedding_input_tokens'       => Database::integer( $metrics['input_tokens'] ?? null ),
				'embedding_estimated_cost_usd' => number_format( (float) Database::text( $metrics['estimated_cost_usd'] ?? 0 ), 8, '.', '' ),
				'embedding_error'              => null,
				'updated_at'                   => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns a duplicate error message, or an empty string.
	 *
	 * @param int    $source_wp_id Source WordPress post identifier.
	 * @param string $url          Canonical post URL.
	 */
	private function duplicate_message( int $source_wp_id, string $url ): string {
		$wpdb  = Database::client();
		$table = Database::tables()['external_posts'];
		$sql   = $wpdb->prepare(
			'SELECT source_wp_id,url FROM %i WHERE source_wp_id=%d OR url_hash=%s LIMIT 1',
			$table,
			$source_wp_id,
			hash( 'sha256', $url, true )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		$row = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		if ( null === $row ) {
			return '';
		}
		return Database::integer( $row['source_wp_id'] ?? null ) === $source_wp_id
			? __( 'A post with this WordPress ID already exists in the live index.', 'ehrman-blog-discovery' )
			: __( 'A post with this URL already exists in the live index.', 'ehrman-blog-discovery' );
	}

	/**
	 * Maps submitted labels to exact vocabulary labels.
	 *
	 * @param mixed                $raw     Submitted labels.
	 * @param array<string,string> $map     Normalized-to-canonical labels.
	 * @param array<int,string>    $errors  Validation errors, updated by reference.
	 * @param string               $kind    Human-readable label kind.
	 * @return list<string> Canonical labels.
	 */
	private function canonical_labels( $raw, array $map, array &$errors, string $kind ): array {
		$labels = array();
		$seen   = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$label      = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			$normalized = Search_Service::normalize( $label );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( ! isset( $map[ $normalized ] ) ) {
				/* translators: 1: vocabulary kind, 2: submitted label. */
				$errors[] = sprintf( __( 'Unknown %1$s "%2$s".', 'ehrman-blog-discovery' ), $kind, $label );
				continue;
			}
			$labels[]            = $map[ $normalized ];
			$seen[ $normalized ] = true;
		}
		return $labels;
	}

	/**
	 * Validates topic-specific review evidence and orders it with selected topics.
	 *
	 * @param mixed                $raw         Submitted rationale records.
	 * @param array<string,string> $topic_map   Normalized-to-canonical topic labels.
	 * @param array                $topics      Selected canonical topics.
	 * @param array<int,string>    $errors      Validation errors, updated by reference.
	 * @param bool                 $require_all Whether each topic requires a rationale.
	 * @return list<array{topic:string,rationale:string}> Canonical rationale records.
	 * @phpstan-param list<string> $topics
	 */
	private function topic_rationales( $raw, array $topic_map, array $topics, array &$errors, bool $require_all ): array {
		$assigned = array_fill_keys( $topics, true );
		$by_topic = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$row = Database::associative_row( $value );
			if ( null === $row ) {
				$errors[] = __( 'Each topic rationale must contain a topic and rationale.', 'ehrman-blog-discovery' );
				continue;
			}
			$submitted_topic = sanitize_text_field( Database::text( $row['topic'] ?? null ) );
			$normalized      = Search_Service::normalize( $submitted_topic );
			if ( '' === $normalized || ! isset( $topic_map[ $normalized ] ) ) {
				/* translators: %s: submitted topic label. */
				$errors[] = sprintf( __( 'Unknown topic rationale label "%s".', 'ehrman-blog-discovery' ), $submitted_topic );
				continue;
			}
			$topic     = $topic_map[ $normalized ];
			$rationale = $this->one_line( Database::text( $row['rationale'] ?? null ) );
			if ( '' === $rationale ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is required.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			if ( $this->length( $rationale ) > 500 ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" must not exceed 500 characters.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			if ( ! isset( $assigned[ $topic ] ) ) {
				if ( $require_all ) {
					/* translators: %s: canonical topic label. */
					$errors[] = sprintf( __( 'Topic rationale "%s" does not correspond to an assigned topic.', 'ehrman-blog-discovery' ), $topic );
				}
				continue;
			}
			if ( isset( $by_topic[ $topic ] ) ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is duplicated.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			$by_topic[ $topic ] = $rationale;
		}
		$rationales = array();
		foreach ( $topics as $topic ) {
			if ( isset( $by_topic[ $topic ] ) ) {
				$rationales[] = array(
					'topic'     => $topic,
					'rationale' => $by_topic[ $topic ],
				);
			} elseif ( $require_all ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is required.', 'ehrman-blog-discovery' ), $topic );
			}
		}
		return $rationales;
	}

	/**
	 * Validates genuinely new keyword labels.
	 *
	 * @param mixed                $raw          Submitted labels.
	 * @param array<string,string> $existing     Existing normalized labels.
	 * @param array<int,string>    $errors       Validation errors, updated by reference.
	 * @return list<string> New labels.
	 */
	private function new_keyword_labels( $raw, array $existing, array &$errors ): array {
		$labels = array();
		$seen   = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$label      = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			$normalized = Search_Service::normalize( $label );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $existing[ $normalized ] ) ) {
				/* translators: %s: submitted secondary keyword label. */
				$errors[] = sprintf( __( '"%s" already exists and must be listed as an existing secondary keyword.', 'ehrman-blog-discovery' ), $label );
				continue;
			}
			if ( $this->length( $label ) > 191 || $this->length( $normalized ) > 191 ) {
				/* translators: %s: submitted secondary keyword label. */
				$errors[] = sprintf( __( 'New secondary keyword "%s" is too long.', 'ehrman-blog-discovery' ), $label );
				continue;
			}
			$labels[]            = $label;
			$seen[ $normalized ] = true;
		}
		return $labels;
	}

	/**
	 * Decodes a stored proposal.
	 *
	 * @param string $json Stored JSON.
	 * @return array<string,mixed> Decoded proposal.
	 */
	private function decode_proposal( string $json ): array {
		$decoded = Database::associative_row( json_decode( $json, true ) );
		return null === $decoded ? array() : $decoded;
	}

	/**
	 * Returns trimmed, nonempty textarea lines.
	 *
	 * @param string $text Textarea value.
	 * @return list<string> Nonempty lines.
	 */
	private function lines( string $text ): array {
		$lines = preg_split( '/\R+/', $text );
		return array_values(
			array_filter(
				array_map( 'trim', is_array( $lines ) ? $lines : array() ),
				static fn( string $line ): bool => '' !== $line
			)
		);
	}

	/**
	 * Collapses line breaks and repeated whitespace.
	 *
	 * @param string $text Text to normalize.
	 */
	private function one_line( string $text ): string {
		$clean = sanitize_text_field( $text );
		$clean = preg_replace( '/\s+/', ' ', $clean );
		return is_string( $clean ) ? trim( $clean ) : '';
	}

	/**
	 * Returns whether text begins with an established active-verb opener.
	 *
	 * @param string $text Text to inspect.
	 */
	private function has_house_style_opening( string $text ): bool {
		$matched = preg_match( '/^([A-Za-z]+)\b/', trim( $text ), $matches );
		if ( 1 !== $matched ) {
			return false;
		}
		return in_array( strtolower( $matches[1] ), self::HOUSE_STYLE_OPENING_VERBS, true );
	}

	/**
	 * Returns whether the opening sentence is phrased as a question.
	 *
	 * @param string $text Text to inspect.
	 */
	private function first_sentence_is_question( string $text ): bool {
		return 1 === preg_match( '/^[^.!?]*\?/', trim( $text ) );
	}

	/**
	 * Returns the opening sentence or the complete text when no terminator exists.
	 *
	 * @param string $text Text to inspect.
	 */
	private function first_sentence( string $text ): string {
		$parts = preg_split( '/(?<=[.!?])(?:["”’\']+)?\s+/u', trim( $text ), 2 );
		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : trim( $text );
	}

	/**
	 * Returns whether normalized text contains a complete normalized phrase.
	 *
	 * @param string $text   Normalized text to inspect.
	 * @param string $phrase Complete normalized phrase.
	 */
	private function contains_normalized_phrase( string $text, string $phrase ): bool {
		return '' !== $phrase && str_contains( ' ' . $text . ' ', ' ' . $phrase . ' ' );
	}

	/**
	 * Counts sentences using terminal punctuation and a following sentence start.
	 *
	 * @param string $text Text to count.
	 */
	private function sentence_count( string $text ): int {
		$clean = trim( $text );
		if ( '' === $clean ) {
			return 0;
		}
		$sentences = preg_split( '/[.!?](?:["”’\']+)?\s+(?=[A-Z0-9“"‘\'])/u', $clean );
		return count( array_filter( is_array( $sentences ) ? $sentences : array(), static fn( string $sentence ): bool => '' !== $sentence ) );
	}

	/**
	 * Counts whitespace-delimited words.
	 *
	 * @param string $text Text to count.
	 */
	private function word_count( string $text ): int {
		$words = preg_split( '/\s+/', trim( $text ) );
		return '' === trim( $text ) ? 0 : count( is_array( $words ) ? $words : array() );
	}

	/**
	 * Returns a multibyte-safe character count.
	 *
	 * @param string $text Text to count.
	 */
	private function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Parses an ISO publication date.
	 *
	 * @param string $value Date value.
	 * @return array{display:string,published:string}|null Parsed date.
	 */
	private function parse_date( string $value ): ?array {
		$value = trim( $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return array(
			'display'   => $date->format( 'F j, Y' ),
			'published' => $date->format( 'Y-m-d 00:00:00' ),
		);
	}

	/**
	 * Extracts the structured text from a Responses API body.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 */
	private function output_text( array $body ): string {
		if ( is_string( $body['output_text'] ?? null ) ) {
			return $body['output_text'];
		}
		foreach ( is_array( $body['output'] ?? null ) ? $body['output'] : array() as $output ) {
			$output_row = Database::associative_row( $output );
			if ( null === $output_row ) {
				continue;
			}
			foreach ( is_array( $output_row['content'] ?? null ) ? $output_row['content'] : array() as $content ) {
				$content_row = Database::associative_row( $content );
				if ( null !== $content_row && is_string( $content_row['text'] ?? null ) ) {
					return $content_row['text'];
				}
			}
		}
		return '';
	}

	/**
	 * Extracts token metrics and estimates the supported model cost.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 * @return array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
	 */
	private function response_metrics( array $body ): array {
		$usage          = is_array( $body['usage'] ?? null ) ? $body['usage'] : array();
		$input_details  = is_array( $usage['input_tokens_details'] ?? null ) ? $usage['input_tokens_details'] : array();
		$output_details = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : array();
		$input_tokens   = Database::integer( $usage['input_tokens'] ?? null );
		$cached_tokens  = min( $input_tokens, Database::integer( $input_details['cached_tokens'] ?? null ) );
		$output_tokens  = Database::integer( $usage['output_tokens'] ?? null );
		$reasoning      = min( $output_tokens, Database::integer( $output_details['reasoning_tokens'] ?? null ) );
		$uncached       = max( 0, $input_tokens - $cached_tokens );
		$cost           = 0.0;
		if ( str_starts_with( self::model_id(), 'gpt-5.6-sol' ) || 'gpt-5.6' === self::model_id() ) {
			$cost = ( ( $uncached * 4.0 ) + ( $cached_tokens * 0.4 ) + ( $output_tokens * 20.0 ) ) / 1000000;
		}
		return array(
			'response_id'         => sanitize_text_field( Database::text( $body['id'] ?? null ) ),
			'input_tokens'        => max( 0, $input_tokens ),
			'cached_input_tokens' => max( 0, $cached_tokens ),
			'output_tokens'       => max( 0, $output_tokens ),
			'reasoning_tokens'    => max( 0, $reasoning ),
			'estimated_cost_usd'  => $cost,
		);
	}

	/**
	 * Combines billable usage when an output-limit retry was required.
	 *
	 * @param array<string,mixed> $first  Earlier attempt metrics.
	 * @param array<string,mixed> $second Latest attempt metrics.
	 * @return array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
	 */
	private function combine_metrics( array $first, array $second ): array {
		return array(
			'response_id'         => Database::text( $second['response_id'] ?? null ),
			'input_tokens'        => Database::integer( $first['input_tokens'] ?? null ) + Database::integer( $second['input_tokens'] ?? null ),
			'cached_input_tokens' => Database::integer( $first['cached_input_tokens'] ?? null ) + Database::integer( $second['cached_input_tokens'] ?? null ),
			'output_tokens'       => Database::integer( $first['output_tokens'] ?? null ) + Database::integer( $second['output_tokens'] ?? null ),
			'reasoning_tokens'    => Database::integer( $first['reasoning_tokens'] ?? null ) + Database::integer( $second['reasoning_tokens'] ?? null ),
			'estimated_cost_usd'  => (float) Database::text( $first['estimated_cost_usd'] ?? 0 ) + (float) Database::text( $second['estimated_cost_usd'] ?? 0 ),
		);
	}

	/** Returns the ingestion-only API key. */
	private static function api_key(): string {
		$value = defined( 'EHRMAN_INGESTION_OPENAI_API_KEY' )
			? constant( 'EHRMAN_INGESTION_OPENAI_API_KEY' )
			: getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Returns a supported configured reasoning effort. */
	private static function reasoning_effort(): string {
		$value  = defined( 'EHRMAN_INGESTION_REASONING_EFFORT' )
			? constant( 'EHRMAN_INGESTION_REASONING_EFFORT' )
			: getenv( 'EHRMAN_INGESTION_REASONING_EFFORT' );
		$effort = strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
		return in_array( $effort, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $effort : 'high';
	}
}
