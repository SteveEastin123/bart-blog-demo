<?php
/**
 * Semantic post-index maintenance.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds and reports the title-and-summary vector index. */
final class Semantic_Index_Service {
	private const RETRIEVAL_METHOD = 'hybrid';

	/**
	 * Embedding API client.
	 *
	 * @var Embedding_Service
	 */
	private Embedding_Service $embeddings;

	/**
	 * Creates the semantic index service.
	 *
	 * @param Embedding_Service|null $embeddings Optional workflow-specific embedding client.
	 */
	public function __construct( ?Embedding_Service $embeddings = null ) {
		$this->embeddings = $embeddings ?? new Embedding_Service();
	}

	/**
	 * Generates or refreshes the title-and-summary vector for one approved post.
	 *
	 * @param int  $source_wp_id Source WordPress post identifier.
	 * @param bool $force        Regenerate even when the stored fingerprint matches.
	 * @return array{generated:bool,metrics:array<string,int|float|string>}|WP_Error Result or error.
	 */
	public function build_post_embedding( int $source_wp_id, bool $force = false ) {
		if ( $source_wp_id < 1 ) {
			return new WP_Error( 'ehrman_embedding_invalid_post', __( 'The post identifier is invalid.', 'ehrman-blog-discovery' ) );
		}
		if ( ! $this->embeddings->configured() ) {
			return new WP_Error( 'ehrman_embedding_not_configured', __( 'An OpenAI API key is required to build the post vector.', 'ehrman-blog-discovery' ) );
		}

		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = $wpdb->prepare(
			"SELECT p.* FROM {$tables['external_posts']} p WHERE p.source_wp_id=%d AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')",
			$source_wp_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifiers are internal.
		$post = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		if ( null === $post ) {
			return new WP_Error( 'ehrman_embedding_ineligible_post', __( 'The approved post is not eligible for semantic indexing.', 'ehrman-blog-discovery' ) );
		}

		$existing = $this->existing_rows()[ $source_wp_id ] ?? null;
		if ( ! $force && is_array( $existing )
			&& hash_equals( self::content_hash( $post ), Database::text( $existing['content_hash'] ?? null ) )
			&& Embedding_Service::model_id() === Database::text( $existing['model'] ?? null )
			&& Embedding_Service::dimensions() === Database::integer( $existing['dimensions'] ?? null ) ) {
			return array(
				'generated' => false,
				'metrics'   => array(),
			);
		}

		$vectors = $this->embeddings->embed( array( self::content_text( $post ) ) );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}
		$this->store_vector( $post, $vectors[0] );
		return array(
			'generated' => true,
			'metrics'   => $this->embeddings->last_metrics(),
		);
	}

	/**
	 * Returns the eligible and current embedding counts.
	 *
	 * @return array{
	 *     eligible:int,
	 *     indexed:int,
	 *     stored:int,
	 *     current:int,
	 *     missing:int,
	 *     stale:int,
	 *     obsolete:int,
	 *     ready:bool,
	 *     model:string,
	 *     dimensions:int,
	 *     strategy:string
	 * }
	 */
	public function status(): array {
		$wpdb         = Database::client();
		$tables       = Database::tables();
		$eligibility  = "p.source_wp_id IS NOT NULL AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')";
		$eligible_sql = "SELECT COUNT(*) FROM {$tables['external_posts']} p WHERE {$eligibility}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		$eligible = Database::integer( $wpdb->get_var( $eligible_sql ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$stored      = Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['post_embeddings']}" ) );
		$indexed_sql = "SELECT COUNT(*) FROM {$tables['post_embeddings']} e "
			. "JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id WHERE {$eligibility}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		$indexed     = Database::integer( $wpdb->get_var( $indexed_sql ) );
		$current_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['post_embeddings']} e "
			. "JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id WHERE {$eligibility} "
			. 'AND e.model=%s AND e.dimensions=%d AND e.embedding_norm>0 '
			. 'AND OCTET_LENGTH(e.embedding)=e.dimensions*4 '
			. "AND e.content_hash=SHA2(CONCAT('Title: ',COALESCE(p.title,''),CHAR(10),'Summary: ',COALESCE(p.search_summary,'')),256)",
			Embedding_Service::model_id(),
			Embedding_Service::dimensions()
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally and values are prepared.
		$current       = Database::integer( $wpdb->get_var( $current_sql ) );
		$missing       = max( 0, $eligible - $indexed );
		$stale         = max( 0, $indexed - $current );
		$obsolete      = max( 0, $stored - $indexed );
		$content_ready = $eligible > 0 && $current === $eligible;
		return array(
			'eligible'   => $eligible,
			'indexed'    => $indexed,
			'stored'     => $stored,
			'current'    => $current,
			'missing'    => $missing,
			'stale'      => $stale,
			'obsolete'   => $obsolete,
			'ready'      => $content_ready,
			'model'      => Embedding_Service::model_id(),
			'dimensions' => Embedding_Service::dimensions(),
			'strategy'   => self::RETRIEVAL_METHOD,
		);
	}

	/**
	 * Generates missing or stale post embeddings.
	 *
	 * @param bool          $force      Regenerate every eligible post.
	 * @param int           $batch_size Number of posts embedded per API call.
	 * @param callable|null $progress   Optional progress callback receiving processed and total counts.
	 * @return array{eligible:int,generated:int,unchanged:int,removed:int}|WP_Error Build summary or error.
	 */
	public function build_index( bool $force = false, int $batch_size = 50, ?callable $progress = null ) {
		if ( ! Embedding_Service::is_configured() ) {
			return new WP_Error( 'ehrman_embedding_not_configured', __( 'OPENAI_API_KEY is required to build the semantic index.', 'ehrman-blog-discovery' ) );
		}
		$batch_size = max( 1, min( 100, $batch_size ) );
		$posts      = $this->eligible_posts();
		$existing   = $this->existing_rows();
		/**
		 * Posts requiring new embeddings.
		 *
		 * @var list<array<string,mixed>> $pending
		 */
		$pending   = array();
		$unchanged = 0;
		foreach ( $posts as $post ) {
			$wp_id = Database::integer( $post['source_wp_id'] ?? null );
			$hash  = self::content_hash( $post );
			$row   = $existing[ $wp_id ] ?? null;
			if ( ! $force && is_array( $row )
				&& hash_equals( $hash, Database::text( $row['content_hash'] ?? null ) )
				&& Embedding_Service::model_id() === Database::text( $row['model'] ?? null )
				&& Embedding_Service::dimensions() === Database::integer( $row['dimensions'] ?? null ) ) {
				++$unchanged;
				continue;
			}
			$pending[] = $post;
		}

		$generated = 0;
		$total     = count( $pending );
		foreach ( array_chunk( $pending, $batch_size ) as $batch ) {
			$texts   = array_map( array( self::class, 'content_text' ), $batch );
			$vectors = $this->embeddings->embed( $texts );
			if ( is_wp_error( $vectors ) ) {
				return $vectors;
			}
			foreach ( $batch as $index => $post ) {
				$this->store_vector( $post, $vectors[ $index ] );
				++$generated;
			}
			if ( null !== $progress ) {
				$progress( $generated, $total );
			}
		}

		$eligible_ids = array();
		foreach ( $posts as $post ) {
			$eligible_ids[ Database::integer( $post['source_wp_id'] ?? null ) ] = true;
		}
		$removed = 0;
		foreach ( array_keys( $existing ) as $wp_id ) {
			if ( isset( $eligible_ids[ $wp_id ] ) ) {
				continue;
			}
			Database::client()->delete( Database::tables()['post_embeddings'], array( 'source_wp_id' => $wp_id ), array( '%d' ) );
			++$removed;
		}
		return array(
			'eligible'  => count( $posts ),
			'generated' => $generated,
			'unchanged' => $unchanged,
			'removed'   => $removed,
		);
	}

	/**
	 * Returns posts eligible for semantic indexing.
	 *
	 * @return list<array<string,mixed>> Eligible post rows.
	 */
	public function eligible_posts(): array {
		$tables = Database::tables();
		$sql    = "SELECT p.* FROM {$tables['external_posts']} p WHERE p.source_wp_id IS NOT NULL AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore') ORDER BY p.source_wp_id";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		return Database::associative_rows( Database::client()->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns stored embedding metadata keyed by source WordPress ID.
	 *
	 * @return array<int,array<string,mixed>> Stored embedding metadata.
	 */
	private function existing_rows(): array {
		$table = Database::tables()['post_embeddings'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$rows = Database::associative_rows( Database::client()->get_results( "SELECT source_wp_id,content_hash,model,dimensions FROM {$table}", ARRAY_A ) );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ Database::integer( $row['source_wp_id'] ?? null ) ] = $row;
		}
		return $map;
	}

	/**
	 * Stores one float32 vector and its source-content fingerprint.
	 *
	 * @param array<string,mixed> $post   Source post record.
	 * @param array<int,float>    $vector Embedding vector.
	 */
	private function store_vector( array $post, array $vector ): void {
		$wpdb = Database::client();
		$wpdb->replace(
			Database::tables()['post_embeddings'],
			array(
				'source_wp_id'   => Database::integer( $post['source_wp_id'] ?? null ),
				'content_hash'   => self::content_hash( $post ),
				'model'          => Embedding_Service::model_id(),
				'dimensions'     => Embedding_Service::dimensions(),
				'embedding'      => pack( 'g*', ...$vector ),
				'embedding_norm' => number_format( self::vector_norm( $vector ), 12, '.', '' ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Builds the canonical title-and-summary text for one post.
	 *
	 * @param array<string,mixed> $post Source post record.
	 */
	public static function content_text( array $post ): string {
		return 'Title: ' . Database::text( $post['title'] ?? null ) . "\nSummary: " . Database::text( $post['search_summary'] ?? null );
	}

	/**
	 * Returns the canonical content fingerprint for one post.
	 *
	 * @param array<string,mixed> $post Source post record.
	 */
	public static function content_hash( array $post ): string {
		return hash( 'sha256', self::content_text( $post ) );
	}

	/**
	 * Calculates a vector's Euclidean norm.
	 *
	 * @param array<int,float> $vector Embedding vector.
	 */
	private static function vector_norm( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $value ) {
			$value = (float) $value;
			$sum  += $value * $value;
		}
		return sqrt( $sum );
	}
}
