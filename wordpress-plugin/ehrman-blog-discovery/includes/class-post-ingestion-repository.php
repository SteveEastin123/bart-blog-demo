<?php
/**
 * Post-ingestion persistence.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores ingestion drafts and applies approved metadata to the discovery index.
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
	 * Creates an analyzing draft and returns its identifier.
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
				'status'           => 'analyzing',
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
	 * Records an analysis failure without discarding the retained source text.
	 *
	 * @param int    $draft_id Draft identifier.
	 * @param string $message  User-visible error message.
	 */
	public function mark_analysis_error( int $draft_id, string $message ): void {
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			array(
				'status'        => 'error',
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $draft_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Stores validated analysis output and usage metrics.
	 *
	 * @param int                 $draft_id      Draft identifier.
	 * @param array<string,mixed> $proposal      Validated proposal.
	 * @param array<string,mixed> $metrics       API usage metrics.
	 * @param string|null         $taxonomy_hash Refreshed taxonomy fingerprint for reanalysis.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param AnalysisMetrics $metrics
	 */
	public function store_analysis( int $draft_id, array $proposal, array $metrics, ?string $taxonomy_hash = null ): void {
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
		Database::client()->update(
			Database::tables()['ingestion_drafts'],
			$data,
			array( 'id' => $draft_id ),
			$formats,
			array( '%d' )
		);
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
	 * Applies one approved proposal inside a transaction.
	 *
	 * @param int                 $draft_id     Draft identifier.
	 * @param array<string,mixed> $draft        Draft record.
	 * @param array<string,mixed> $proposal     Approved proposal.
	 * @param array<string,mixed> $taxonomy     Current vocabulary.
	 * @param string              $approved_json Encoded approved proposal.
	 * @return int|WP_Error New internal post identifier or error.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When an approval row cannot be persisted.
	 */
	public function approve( int $draft_id, array $draft, array $proposal, array $taxonomy, string $approved_json ) {
		$wpdb = Database::client();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ehrman_ingestion_transaction', __( 'The approval transaction could not be started.', 'ehrman-blog-discovery' ) );
		}
		try {
			$post_id = $this->insert_approved_post( $draft, $proposal, $taxonomy );
			$updated = $wpdb->update(
				Database::tables()['ingestion_drafts'],
				array(
					'status'           => 'approved',
					'proposal_json'    => $approved_json,
					'post_text'        => null,
					'error_message'    => null,
					'approved_at'      => current_time( 'mysql', true ),
					'approved_post_id' => $post_id,
					'embedding_status' => in_array( 'Ignore', $proposal['topics'], true ) ? 'not_applicable' : 'pending',
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
			return $post_id;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ehrman_ingestion_apply_failed', sanitize_text_field( $error->getMessage() ) );
		}
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

	/**
	 * Inserts one post and every search relationship inside the caller transaction.
	 *
	 * @param array<string,mixed> $draft    Draft record.
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @param array<string,mixed> $taxonomy Vocabulary.
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
}
