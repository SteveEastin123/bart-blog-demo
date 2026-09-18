<?php
/**
 * Database access used by keyword search.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads search scope, score, and post records from the custom tables. */
final class Search_Post_Repository {
	/**
	 * Finds a record by slug in an allowed custom table.
	 *
	 * @param string $table_key Logical custom-table key.
	 * @param string $slug      Record slug.
	 * @return array<string,mixed>|null Record when found.
	 */
	public function record_by_slug( string $table_key, string $slug ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		/**
		 * Internal query with a trusted custom-table identifier.
		 *
		 * @var non-falsy-string&literal-string $sql
		 */
		$sql = "SELECT * FROM {$tables[$table_key]} WHERE slug=%s LIMIT 1";
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; the identifier comes from Database::tables().
			$wpdb->prepare( $sql, $slug ),
			ARRAY_A
		);
		return Database::associative_row( $row );
	}

	/**
	 * Returns the unique posts connected to a category.
	 *
	 * @param int $category_id Category identifier.
	 * @return array<int,bool> Post-ID set.
	 */
	public function category_post_ids( int $category_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT DISTINCT pt.post_id FROM {$tables['post_topics']} pt "
			. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id WHERE tc.category_id=%d";
		/**
		 * Internal query with trusted custom-table identifiers.
		 *
		 * @var non-falsy-string&literal-string $sql
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $category_id ) );
		return array_fill_keys( array_map( static fn( $id ): int => Database::integer( $id ), $ids ), true );
	}

	/**
	 * Returns the posts assigned directly to a topic.
	 *
	 * @param int $topic_id Topic identifier.
	 * @return array<int,bool> Post-ID set.
	 */
	public function topic_post_ids( int $topic_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT post_id FROM {$tables['post_topics']} WHERE topic_id=%d";
		/**
		 * Internal query with a trusted custom-table identifier.
		 *
		 * @var non-falsy-string&literal-string $sql
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $topic_id ) );
		return array_fill_keys( array_map( static fn( $id ): int => Database::integer( $id ), $ids ), true );
	}

	/**
	 * Returns matching post IDs and index weights for one term.
	 *
	 * @param string $term Search term.
	 * @param string $mode Search-term mode.
	 * @return array<int,int> Scores keyed by post ID.
	 */
	public function post_scores_for_term( string $term, string $mode = Search_Service::TERM_MODE_COMBINED ): array {
		$wpdb       = Database::client();
		$tables     = Database::tables();
		$normalized = Search_Service::normalize( $term );
		if ( '' === $normalized ) {
			return array();
		}
		if ( Search_Service::TERM_MODE_TOPIC === $mode ) {
			$sql = 'SELECT post_id,MAX(weight+2) score '
				. "FROM {$tables['post_search_terms']} WHERE normalized=%s AND kind IN ('topic','alias') GROUP BY post_id";
			/**
			 * Internal query with a trusted custom-table identifier.
			 *
			 * @var non-falsy-string&literal-string $sql
			 */
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, $normalized ),
				ARRAY_A
			);
		} else {
			$sql = 'SELECT post_id,MAX(weight+CASE WHEN normalized=%s THEN 2 ELSE 0 END) score '
				. "FROM {$tables['post_search_terms']} WHERE normalized=%s "
				. "OR CONCAT(' ',normalized,' ') LIKE %s GROUP BY post_id";
			/**
			 * Internal query with a trusted custom-table identifier.
			 *
			 * @var non-falsy-string&literal-string $sql
			 */
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, $normalized, $normalized, "% {$normalized} %" ),
				ARRAY_A
			);
		}
		$matches = array();
		foreach ( Database::associative_rows( $rows ) as $row ) {
			$matches[ Database::integer( $row['post_id'] ?? null ) ] = Database::integer( $row['score'] ?? null );
		}
		return $matches;
	}

	/**
	 * Loads post records for a sanitized set of IDs.
	 *
	 * @param array<int,int|string> $post_ids Post identifiers.
	 * @return array<int,array<string,mixed>> Post records.
	 */
	public function posts_by_ids( array $post_ids ): array {
		$wpdb = Database::client();
		if ( empty( $post_ids ) ) {
			return array();
		}
		$tables = Database::tables();
		$sql    = "SELECT * FROM {$tables['external_posts']} WHERE id IN (" . $this->integer_list( $post_ids ) . ')';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The ID list is reduced to integers by integer_list().
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Converts IDs into an integer-only SQL list.
	 *
	 * @param array<int,int|string> $values Candidate identifiers.
	 */
	private function integer_list( array $values ): string {
		$values = array_map( static fn( $value ): int => Database::integer( $value ), $values );
		return empty( $values ) ? '0' : implode( ',', $values );
	}
}
