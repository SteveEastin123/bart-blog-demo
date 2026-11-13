<?php
/**
 * Bounded administration queries for missing, pending, and completed metadata.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Combines published posts, ingestion records, and imported metadata without loading post bodies. */
final class Post_Ingestion_Worklist {
	public const PAGE_SIZE = 25;

	/**
	 * Returns the available status filters.
	 *
	 * @return array<string,string> Available worklist filters.
	 */
	public static function statuses(): array {
		return array(
			'attention'      => __( 'Needs attention', 'ehrman-blog-discovery' ),
			'all'            => __( 'All', 'ehrman-blog-discovery' ),
			'not_generated'  => __( 'Not generated', 'ehrman-blog-discovery' ),
			'generating'     => __( 'Generating', 'ehrman-blog-discovery' ),
			'needs_approval' => __( 'Needs approval', 'ehrman-blog-discovery' ),
			'complete'       => __( 'Complete', 'ehrman-blog-discovery' ),
			'failed'         => __( 'Failed', 'ehrman-blog-discovery' ),
		);
	}

	/**
	 * Normalizes read-only filters before using them in SQL or links.
	 *
	 * @param array<string,mixed> $input Request values.
	 * @return array{status:string,search:string,date_from:string,date_to:string,paged:int}
	 */
	public static function filters( array $input ): array {
		$status = sanitize_key( Database::text( $input['status'] ?? null ) );
		return array(
			'status'    => isset( self::statuses()[ $status ] ) ? $status : 'attention',
			'search'    => sanitize_text_field( Database::text( $input['search'] ?? null ) ),
			'date_from' => self::date( Database::text( $input['date_from'] ?? null ) ),
			'date_to'   => self::date( Database::text( $input['date_to'] ?? null ) ),
			'paged'     => max( 1, min( 1000000, absint( Database::text( $input['paged'] ?? null ) ) ) ),
		);
	}

	/**
	 * Counts matching records and retrieves only the requested page's display fields.
	 *
	 * @param array<string,mixed> $input Filter input.
	 * @return array<string,mixed> Filtered rows and pagination values.
	 */
	public function page( array $input ): array {
		$filters = self::filters( $input );
		$wpdb    = Database::client();
		$source  = $this->source_sql();
		$where   = array( '1=1' );
		if ( 'attention' === $filters['status'] ) {
			$where[] = "workflow_status IN ('not_generated','needs_approval','failed')";
		} elseif ( 'all' !== $filters['status'] ) {
			$where[] = $wpdb->prepare( 'workflow_status=%s', $filters['status'] );
		}
		if ( '' !== $filters['search'] ) {
			$where[] = $wpdb->prepare( 'title LIKE %s', '%' . $wpdb->esc_like( $filters['search'] ) . '%' );
		}
		if ( '' !== $filters['date_from'] ) {
			$where[] = $wpdb->prepare( 'published_at >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( '' !== $filters['date_to'] ) {
			$where[] = $wpdb->prepare( 'published_at <= %s', $filters['date_to'] . ' 23:59:59' );
		}
		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Source identifiers and filter values are prepared above; structural SQL is fixed.
		$total = Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM ({$source}) worklist WHERE {$where_sql}" ) );
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page  = min( $filters['paged'], $pages );
		$limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prepared source, predicates, and numeric bounds; only fixed ordering is appended.
		$rows             = Database::associative_rows( $wpdb->get_results( "SELECT * FROM ({$source}) worklist WHERE {$where_sql} ORDER BY published_at DESC,updated_at DESC,kind ASC,id DESC {$limit}", ARRAY_A ) );
		$filters['paged'] = $page;
		return array(
			'rows'    => $rows,
			'total'   => $total,
			'pages'   => $pages,
			'filters' => $filters,
		);
	}

	/**
	 * Checks real indexed data before offering generation for a saved post.
	 *
	 * @param int $post_id Local WordPress post identifier.
	 */
	public function is_indexed( int $post_id ): bool {
		$wpdb  = Database::client();
		$table = Database::tables()['external_posts'];
		$match = $this->native_index_match();
		$sql   = $wpdb->prepare( 'SELECT e.id FROM %i e JOIN %i p ON ', $table, $wpdb->posts ) . $match . $wpdb->prepare( ' WHERE p.ID=%d LIMIT 1', $post_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return null !== $wpdb->get_var( $sql );
	}

	/**
	 * Reads one imported/approved metadata record without assuming its ID belongs to WordPress.
	 *
	 * @param int $id Discovery-index identifier.
	 * @return array<string,mixed>|null Indexed metadata and labels.
	 */
	public function indexed_post( int $id ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$post   = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT title,url,description,search_summary FROM %i WHERE id=%d', $tables['external_posts'], $id ), ARRAY_A ) );
		if ( null === $post ) {
			return null;
		}
		$post['topics']   = implode( ', ', Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT t.name FROM %i pt JOIN %i t ON t.id=pt.topic_id WHERE pt.post_id=%d ORDER BY t.name', $tables['post_topics'], $tables['topics'], $id ) ) ) );
		$post['keywords'] = implode( ', ', Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT k.label FROM %i pk JOIN %i k ON k.id=pk.keyword_id WHERE pk.post_id=%d ORDER BY k.label', $tables['post_keywords'], $tables['keywords'], $id ) ) ) );
		return $post;
	}

	/** Returns prepared UNION branches with only lightweight list columns. */
	private function source_sql(): string {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$match  = $this->native_index_match();
		// Imported source IDs are not automatically local IDs: only explicit ownership or the same source site qualifies.
		$posts   = $wpdb->prepare(
			"SELECT p.ID id,'post' kind,p.ID wordpress_post_id,p.post_title title,p.post_date published_at,p.post_modified_gmt updated_at,'not_generated' workflow_status,'not_requested' embedding_status FROM %i p
			WHERE p.post_type='post' AND p.post_status='publish'
			AND NOT EXISTS (SELECT 1 FROM %i d WHERE d.wordpress_post_id=p.ID)
			AND NOT EXISTS (SELECT 1 FROM %i e WHERE ",
			$wpdb->posts,
			$tables['ingestion_drafts'],
			$tables['external_posts']
		) . $match . ')';
		$drafts  = $wpdb->prepare(
			"SELECT d.id,'draft' kind,d.wordpress_post_id,d.title,d.published_at,d.updated_at,
			CASE WHEN d.status IN ('ready','held') THEN 'needs_approval'
			WHEN d.status='error' OR (d.status='approved' AND d.embedding_status='pending') THEN 'failed'
			WHEN d.status IN ('queued','analyzing') THEN 'generating'
			WHEN d.status='approved' THEN 'complete' ELSE 'failed' END workflow_status,d.embedding_status
			FROM %i d",
			$tables['ingestion_drafts']
		);
		$indexed = $wpdb->prepare(
			"SELECT e.id,'indexed' kind,e.wordpress_post_id,e.title,e.published_at,e.published_at updated_at,'complete' workflow_status,
			CASE WHEN v.source_wp_id IS NOT NULL THEN 'complete' ELSE 'not_requested' END embedding_status
			FROM %i e LEFT JOIN %i v ON v.source_wp_id=e.source_wp_id
			WHERE NOT EXISTS (SELECT 1 FROM %i d WHERE d.url_hash=e.url_hash OR (e.wordpress_post_id IS NOT NULL AND d.wordpress_post_id=e.wordpress_post_id))",
			$tables['external_posts'],
			$tables['post_embeddings'],
			$tables['ingestion_drafts']
		);
		return $posts . ' UNION ALL ' . $drafts . ' UNION ALL ' . $indexed;
	}

	/** Matches imported IDs only within their own site; demo ID collisions must not hide missing metadata. */
	private function native_index_match(): string {
		$wpdb  = Database::client();
		$home  = trailingslashit( home_url() );
		$http  = set_url_scheme( $home, 'http' );
		$https = set_url_scheme( $home, 'https' );
		return (string) $wpdb->prepare(
			'(e.wordpress_post_id=p.ID OR (e.wordpress_post_id IS NULL AND e.source_wp_id=p.ID AND (e.url LIKE %s OR e.url LIKE %s)))',
			$wpdb->esc_like( $http ) . '%',
			$wpdb->esc_like( $https ) . '%'
		);
	}

	/**
	 * Rejects impossible dates rather than silently changing the filter.
	 *
	 * @param string $value Requested date.
	 */
	private static function date( string $value ): string {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}
}
