<?php
/**
 * Post-ingestion persistence.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores ingestion drafts, workflow state, vocabulary, and embedding status.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 * @phpstan-type AnalysisMetrics array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
 */
final class Post_Ingestion_Repository {
	/**
	 * Creates a queued draft and returns its identifier.
	 *
	 * @param array<string,mixed> $post          Validated post.
	 * @param string              $taxonomy_hash Taxonomy fingerprint.
	 * @param int                 $user_id       Administrator user identifier.
	 * @return int|WP_Error Draft identifier or storage error.
	 * @phpstan-param ValidatedPost $post
	 */
	public function create_draft( array $post, string $taxonomy_hash, int $user_id ) {
		$now      = current_time( 'mysql', true );
		$wpdb     = Database::client();
		$inserted = $wpdb->insert(
			Database::tables()['ingestion_drafts'],
			array(
				'status'           => 'queued',
				'source_wp_id'     => $post['source_wp_id'],
				'title'            => $post['title'],
				'url'              => $post['url'],
				'url_hash'         => hash( 'sha256', $post['url'], true ),
				'author'           => $post['author'],
				'date_text'        => $post['date_text'],
				'published_at'     => $post['published_at'],
				'post_text'        => $post['post_text'],
				'model'            => Post_Ingestion_Settings::model_id(),
				'prompt_version'   => Post_Ingestion_Settings::prompt_version(),
				'taxonomy_version' => $taxonomy_hash,
				'created_by'       => max( 0, $user_id ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return false === $inserted
			? new WP_Error( 'ehrman_ingestion_storage_error', __( 'The ingestion draft could not be stored.', 'ehrman-blog-discovery' ) )
			: (int) $wpdb->insert_id;
	}

	/**
	 * Atomically claims one queued draft for background analysis.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public function claim_analysis( int $draft_id ): int {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare(
			'UPDATE %i SET status=%s,error_message=NULL,analysis_attempt=analysis_attempt+1,analysis_started_at=%s,updated_at=%s WHERE id=%d AND status=%s',
			$table,
			'analyzing',
			current_time( 'mysql', true ),
			current_time( 'mysql', true ),
			$draft_id,
			'queued'
		);
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		if ( 1 !== $wpdb->query( $sql ) ) {
			return 0;
		}
		$draft = $this->draft( $draft_id );
		return null === $draft ? 0 : Database::integer( $draft['analysis_attempt'] ?? null );
	}

	/**
	 * Resets a pending draft for a fresh background analysis attempt.
	 *
	 * @param int    $draft_id     Draft identifier.
	 * @param string $taxonomy_hash Current taxonomy fingerprint.
	 */
	public function queue_analysis( int $draft_id, string $taxonomy_hash ): bool {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare(
			'UPDATE %i SET status=%s,proposal_json=NULL,model=%s,prompt_version=%s,taxonomy_version=%s,response_id=%s,input_tokens=0,cached_input_tokens=0,output_tokens=0,reasoning_tokens=0,estimated_cost_usd=0,error_message=NULL,analysis_started_at=NULL,updated_at=%s WHERE id=%d AND status<>%s',
			$table,
			'queued',
			Post_Ingestion_Settings::model_id(),
			Post_Ingestion_Settings::prompt_version(),
			$taxonomy_hash,
			'',
			current_time( 'mysql', true ),
			$draft_id,
			'approved'
		);
		if ( ! is_string( $sql ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		return 1 === $wpdb->query( $sql );
	}

	/**
	 * Records an analysis failure without discarding the retained source text.
	 *
	 * @param int      $draft_id Draft identifier.
	 * @param string   $message  User-visible error message.
	 * @param int|null $attempt Claimed analysis attempt, when fencing a worker result.
	 */
	public function mark_analysis_error( int $draft_id, string $message, ?int $attempt = null ): bool {
		$where         = array( 'id' => $draft_id );
		$where_formats = array( '%d' );
		if ( null !== $attempt ) {
			$where['analysis_attempt'] = $attempt;
			$where['status']           = 'analyzing';
			$where_formats             = array( '%d', '%d', '%s' );
		}
		$updated = Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'status'        => 'error',
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			),
			$where,
			array( '%s', '%s', '%s' ),
			$where_formats
		);
		return null === $attempt ? false !== $updated : 1 === $updated;
	}

	/**
	 * Stores validated analysis output and usage metrics.
	 *
	 * @param int                 $draft_id      Draft identifier.
	 * @param array<string,mixed> $proposal      Validated proposal.
	 * @param array<string,mixed> $metrics       API usage metrics.
	 * @param string|null         $taxonomy_hash Refreshed taxonomy fingerprint for reanalysis.
	 * @param int|null            $attempt       Claimed analysis attempt, when fencing a worker result.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param AnalysisMetrics $metrics
	 */
	public function store_analysis( int $draft_id, array $proposal, array $metrics, ?string $taxonomy_hash = null, ?int $attempt = null ): bool {
		$proposal_json = wp_json_encode( $proposal );
		$data          = array(
			'status'              => $proposal['status'],
			'proposal_json'       => is_string( $proposal_json ) ? $proposal_json : '',
			'response_id'         => $metrics['response_id'],
			'input_tokens'        => $metrics['input_tokens'],
			'cached_input_tokens' => $metrics['cached_input_tokens'],
			'output_tokens'       => $metrics['output_tokens'],
			'reasoning_tokens'    => $metrics['reasoning_tokens'],
			'estimated_cost_usd'  => number_format( $metrics['estimated_cost_usd'], 8, '.', '' ),
			'error_message'       => null,
			'updated_at'          => current_time( 'mysql', true ),
		);
		$formats       = array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );
		if ( null !== $taxonomy_hash ) {
			$data    = array_merge(
				array(
					'status'           => $proposal['status'],
					'proposal_json'    => is_string( $proposal_json ) ? $proposal_json : '',
					'model'            => Post_Ingestion_Settings::model_id(),
					'prompt_version'   => Post_Ingestion_Settings::prompt_version(),
					'taxonomy_version' => $taxonomy_hash,
				),
				array_slice( $data, 2 )
			);
			$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );
		}
		$where         = array( 'id' => $draft_id );
		$where_formats = array( '%d' );
		if ( null !== $attempt ) {
			$where['analysis_attempt'] = $attempt;
			$where['status']           = 'analyzing';
			$where_formats             = array( '%d', '%d', '%s' );
		}
		$updated = Database::client()->update(
			Database::tables()['ingestion_drafts'],
			$data,
			$where,
			$formats,
			$where_formats
		);
		return null === $attempt ? false !== $updated : 1 === $updated;
	}

	/**
	 * Stores administrator revisions to a pending proposal.
	 *
	 * @param int    $draft_id     Draft identifier.
	 * @param string $proposal_json Encoded proposal.
	 * @param string $status       Validated workflow status.
	 */
	public function store_proposal( int $draft_id, string $proposal_json, string $status ): void {
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'status'        => $status,
				'proposal_json' => $proposal_json,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Refreshes a pending draft from the current saved WordPress post.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $post     Validated current post values.
	 * @phpstan-param ValidatedPost $post
	 */
	public function update_pending_source( int $draft_id, array $post ): bool {
		$updated = Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'title'        => $post['title'],
				'url'          => $post['url'],
				'url_hash'     => hash( 'sha256', $post['url'], true ),
				'author'       => $post['author'],
				'date_text'    => $post['date_text'],
				'published_at' => $post['published_at'],
				'post_text'    => $post['post_text'],
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	/**
	 * Deletes a draft after the workflow confirms that it is still pending.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public function delete_draft( int $draft_id ): bool {
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
	 * Returns the most recent ingestion record for a WordPress post.
	 *
	 * @param int $source_wp_id Source WordPress post identifier.
	 * @return array<string,mixed>|null Draft or approval record.
	 */
	public function latest_for_source_wp_id( int $source_wp_id ): ?array {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare(
			'SELECT * FROM %i WHERE source_wp_id=%d ORDER BY updated_at DESC,id DESC LIMIT 1',
			$table,
			$source_wp_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		return Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
	}

	/**
	 * Returns the most recent ingestion record for each supplied WordPress post.
	 *
	 * @param array<int> $source_wp_ids Source WordPress post identifiers.
	 * @return array<int,array<string,mixed>> Records keyed by WordPress post ID.
	 * @phpstan-param list<int> $source_wp_ids
	 */
	public function latest_for_source_wp_ids( array $source_wp_ids ): array {
		$source_wp_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_wp_ids ) ) ) );
		if ( empty( $source_wp_ids ) ) {
			return array();
		}

		$wpdb         = Database::client();
		$table        = Database::tables()['ingestion_drafts'];
		$placeholders = implode( ',', array_fill( 0, count( $source_wp_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT * FROM %i WHERE source_wp_id IN ({$placeholders}) ORDER BY updated_at DESC,id DESC",
			array_merge( array( $table ), $source_wp_ids )
		);
		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier and post IDs are trusted arguments.
		$rows   = Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
		$latest = array();
		foreach ( $rows as $row ) {
			$source_wp_id = Database::integer( $row['source_wp_id'] ?? null );
			if ( $source_wp_id > 0 && ! isset( $latest[ $source_wp_id ] ) ) {
				$latest[ $source_wp_id ] = $row;
			}
		}
		return $latest;
	}

	/** Returns the number of proposals requiring administrator review. */
	public function review_count(): int {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s,%s)', $table, 'ready', 'held' );
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier and statuses are trusted arguments.
		return Database::integer( $wpdb->get_var( $sql ) );
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
	 * Returns a stable hash of an approved vocabulary snapshot.
	 *
	 * @param array<string,mixed> $taxonomy Approved vocabulary.
	 * @phpstan-param Vocabulary $taxonomy
	 */
	public function vocabulary_hash( array $taxonomy ): string {
		$taxonomy_json = wp_json_encode( $taxonomy );
		return hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' );
	}

	/**
	 * Returns a duplicate error message, or an empty string.
	 *
	 * @param int    $source_wp_id Source WordPress post identifier.
	 * @param string $url          Canonical post URL.
	 */
	public function duplicate_message( int $source_wp_id, string $url ): string {
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
	 * Returns a duplicate-pending-workflow message, or an empty string.
	 *
	 * @param int    $source_wp_id Source WordPress post identifier.
	 * @param string $url          Canonical post URL.
	 */
	public function pending_duplicate_message( int $source_wp_id, string $url ): string {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare(
			'SELECT source_wp_id,url FROM %i WHERE status<>%s AND (source_wp_id=%d OR url_hash=%s) LIMIT 1',
			$table,
			'approved',
			$source_wp_id,
			hash( 'sha256', $url, true )
		);
		if ( ! is_string( $sql ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
		$row = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		if ( null === $row ) {
			return '';
		}
		return Database::integer( $row['source_wp_id'] ?? null ) === $source_wp_id
			? __( 'An ingestion workflow already exists for this WordPress post.', 'ehrman-blog-discovery' )
			: __( 'An ingestion workflow already exists for this post URL.', 'ehrman-blog-discovery' );
	}

	/**
	 * Records an embedding failure or missing-key state.
	 *
	 * @param int    $draft_id Draft identifier.
	 * @param string $message  User-visible error message.
	 * @param string $model    Embedding model, when an API request was attempted.
	 */
	public function mark_embedding_pending( int $draft_id, string $message, string $model = '' ): void {
		$data    = array(
			'embedding_status' => 'pending',
			'embedding_error'  => $message,
			'updated_at'       => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s' );
		if ( '' !== $model ) {
			$data    = array(
				'embedding_status' => 'pending',
				'embedding_model'  => $model,
				'embedding_error'  => $message,
				'updated_at'       => current_time( 'mysql', true ),
			);
			$formats = array( '%s', '%s', '%s', '%s' );
		}
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			$data,
			array( 'id' => $draft_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Records a successfully generated post embedding.
	 *
	 * @param int                 $draft_id Draft identifier.
	 * @param array<string,mixed> $metrics  Embedding API metrics.
	 */
	public function mark_embedding_complete( int $draft_id, array $metrics ): void {
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
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
}
