<?php
/**
 * Hierarchical browsing data services.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads subject areas, categories, topics, and their aggregate post counts. */
final class Browse_Service {

	/**
	 * Returns the subject areas in a browse path.
	 *
	 * @param int $path_number Browse-path number.
	 * @return array<int,array<string,mixed>> Subject-area records and counts.
	 */
	public function subject_areas( int $path_number ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$path   = $this->browse_path( $path_number );
		if ( null === $path ) {
			return array();
		}
		$sql = 'SELECT sa.id,sa.name,sa.slug,sa.description,sa.position, '
			. 'COUNT(DISTINCT sac.category_id) category_count,COUNT(DISTINCT tc.topic_id) topic_count,'
			. "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['subject_areas']} sa "
			. "LEFT JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
			. 'WHERE sa.browse_path_id=%d GROUP BY sa.id,sa.name,sa.slug,sa.description,sa.position '
			. 'ORDER BY sa.position,sa.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, Database::integer( $path['id'] ?? null ) ), ARRAY_A );
		return Database::associative_rows( $rows );
	}

	/**
	 * Finds one subject area by path and slug.
	 *
	 * @param int    $path_number Browse-path number.
	 * @param string $slug        Subject-area slug.
	 * @return array<string,mixed>|null Subject-area record when found.
	 */
	public function subject_area( int $path_number, string $slug ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$path   = $this->browse_path( $path_number );
		if ( null === $path ) {
			return null;
		}
		$sql = "SELECT * FROM {$tables['subject_areas']} WHERE browse_path_id=%d AND slug=%s LIMIT 1";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$row = $wpdb->get_row( $wpdb->prepare( $sql, Database::integer( $path['id'] ?? null ), sanitize_title( $slug ) ), ARRAY_A );
		return Database::associative_row( $row );
	}

	/**
	 * Returns the categories assigned to a subject area.
	 *
	 * @param int $subject_area_id Subject-area database identifier.
	 * @return array<int,array<string,mixed>> Category records and counts.
	 */
	public function subject_area_categories( int $subject_area_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = 'SELECT c.id,c.name,c.slug,c.description,sac.position, '
			. 'COUNT(DISTINCT tc.topic_id) topic_count,COUNT(DISTINCT pt.post_id) post_count '
			. "FROM {$tables['subject_area_categories']} sac "
			. "JOIN {$tables['categories']} c ON c.id=sac.category_id "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
			. 'WHERE sac.subject_area_id=%d '
			. 'GROUP BY c.id,c.name,c.slug,c.description,sac.position ORDER BY sac.position,c.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $subject_area_id ), ARRAY_A );
		return Database::associative_rows( $rows );
	}

	/**
	 * Returns every category alphabetically for the structure-review index.
	 *
	 * @return array<int,array<string,mixed>> Category records and counts.
	 */
	public function categories(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = 'SELECT c.id,c.name,c.slug,c.description,COUNT(DISTINCT tc.topic_id) topic_count,'
			. "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['categories']} c "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
			. 'GROUP BY c.id,c.name,c.slug,c.description ORDER BY c.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only trusted table identifiers and no variable values.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns every visible topic alphabetically for the structure-review index.
	 *
	 * @return array<int,array<string,mixed>> Topic records, category names, and post counts.
	 */
	public function topics(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = 'SELECT t.id,t.name,t.slug,t.description,COUNT(DISTINCT pt.post_id) post_count,'
			. 'COUNT(DISTINCT c.id) category_count,'
			. "GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '||') category_names "
			. "FROM {$tables['topics']} t "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
			. "LEFT JOIN {$tables['categories']} c ON c.id=tc.category_id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
			. 'WHERE t.display_in_browser=1 '
			. 'GROUP BY t.id,t.name,t.slug,t.description ORDER BY t.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only trusted table identifiers and no variable values.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns aggregate counts for a subject area.
	 *
	 * @param int $subject_area_id Subject-area database identifier.
	 * @return array<string,int|string> Category, topic, and post counts.
	 */
	public function subject_area_counts( int $subject_area_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = 'SELECT COUNT(DISTINCT sac.category_id) category_count,'
			. 'COUNT(DISTINCT tc.topic_id) topic_count,COUNT(DISTINCT pt.post_id) post_count '
			. "FROM {$tables['subject_area_categories']} sac "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
			. 'WHERE sac.subject_area_id=%d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$row = Database::associative_row( $wpdb->get_row( $wpdb->prepare( $sql, $subject_area_id ), ARRAY_A ) );
		return array(
			'category_count' => Database::integer( $row['category_count'] ?? null ),
			'topic_count'    => Database::integer( $row['topic_count'] ?? null ),
			'post_count'     => Database::integer( $row['post_count'] ?? null ),
		);
	}

	/**
	 * Finds a category by slug.
	 *
	 * @param string $slug Category slug.
	 * @return array<string,mixed>|null Category record when found.
	 */
	public function category( string $slug ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['categories']} WHERE slug=%s LIMIT 1", sanitize_title( $slug ) ),
			ARRAY_A
		);
		return Database::associative_row( $row );
	}

	/**
	 * Returns the visible topics assigned to a category.
	 *
	 * @param int $category_id Category database identifier.
	 * @return array<int,array<string,mixed>> Ordered topic records and counts.
	 */
	public function category_topics( int $category_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = 'SELECT t.id,t.name,t.slug,t.description,tc.position,COUNT(DISTINCT pt.post_id) post_count '
			. "FROM {$tables['topics']} t JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
			. 'WHERE tc.category_id=%d AND t.display_in_browser=1 '
			. 'GROUP BY t.id,t.name,t.slug,t.description,tc.position '
			. 'ORDER BY CASE WHEN tc.position>0 THEN 0 ELSE 1 END,tc.position,t.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $category_id ), ARRAY_A );
		return Database::associative_rows( $rows );
	}

	/**
	 * Counts unique posts connected to a category through its topics.
	 *
	 * @param int $category_id Category database identifier.
	 * @return int Unique post count.
	 */
	public function category_post_count( int $category_id ): int {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['post_topics']} pt "
			. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id WHERE tc.category_id=%d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		return Database::integer( $wpdb->get_var( $wpdb->prepare( $sql, $category_id ) ) );
	}

	/**
	 * Finds a topic by slug.
	 *
	 * @param string $slug Topic slug.
	 * @return array<string,mixed>|null Topic record when found.
	 */
	public function topic( string $slug ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['topics']} WHERE slug=%s LIMIT 1", sanitize_title( $slug ) ),
			ARRAY_A
		);
		return Database::associative_row( $row );
	}

	/**
	 * Selects the requested or first category assigned to a topic.
	 *
	 * @param int    $topic_id       Topic database identifier.
	 * @param string $requested_slug Preferred category slug.
	 * @return array<string,mixed>|null Category record when available.
	 */
	public function topic_category( int $topic_id, string $requested_slug = '' ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( '' !== $requested_slug ) {
			$sql = "SELECT c.* FROM {$tables['categories']} c "
				. "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
				. 'WHERE tc.topic_id=%d AND c.slug=%s LIMIT 1';
			$row = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, $topic_id, sanitize_title( $requested_slug ) ),
				ARRAY_A
			);
			$record = Database::associative_row( $row );
			if ( null !== $record ) {
				return $record;
			}
		}
		$sql = "SELECT c.* FROM {$tables['categories']} c "
			. "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
			. 'WHERE tc.topic_id=%d ORDER BY c.name LIMIT 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $topic_id ), ARRAY_A );
		return Database::associative_row( $row );
	}

	/**
	 * Selects the requested or first subject area containing a category.
	 *
	 * @param int    $path_number    Browse-path number.
	 * @param int    $category_id    Category database identifier.
	 * @param string $requested_slug Preferred subject-area slug.
	 * @return array<string,mixed>|null Subject-area record when available.
	 */
	public function primary_subject_area( int $path_number, int $category_id, string $requested_slug = '' ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$path   = $this->browse_path( $path_number );
		if ( null === $path ) {
			return null;
		}
		$requested_slug = sanitize_title( $requested_slug );
		if ( '' !== $requested_slug ) {
			$sql = "SELECT sa.* FROM {$tables['subject_areas']} sa "
				. "JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
				. 'WHERE sa.browse_path_id=%d AND sac.category_id=%d AND sa.slug=%s LIMIT 1';
			$row = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, Database::integer( $path['id'] ?? null ), $category_id, $requested_slug ),
				ARRAY_A
			);
			$record = Database::associative_row( $row );
			if ( null !== $record ) {
				return $record;
			}
		}
		$sql = "SELECT sa.* FROM {$tables['subject_areas']} sa "
			. "JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
			. 'WHERE sa.browse_path_id=%d AND sac.category_id=%d '
			. 'ORDER BY sac.position,sa.position,sa.name LIMIT 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$row = $wpdb->get_row( $wpdb->prepare( $sql, Database::integer( $path['id'] ?? null ), $category_id ), ARRAY_A );
		return Database::associative_row( $row );
	}

	/**
	 * Returns all categories for the keyword-search scope selector.
	 *
	 * @return array<int,array<string,mixed>> Alphabetized category options and counts.
	 */
	public function category_options(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT c.name,c.slug,COUNT(DISTINCT pt.post_id) post_count FROM {$tables['categories']} c "
			. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
			. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
			. 'GROUP BY c.id,c.name,c.slug ORDER BY c.name';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query contains only trusted table identifiers and no variable values.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Finds a browse-path record by its numeric path selector.
	 *
	 * @param int $path_number Browse-path number.
	 * @return array<string,mixed>|null Browse-path record when found.
	 */
	public function browse_path( int $path_number ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$slug   = 'browse-topics-' . ( 2 === $path_number ? '2' : '1' );
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['browse_paths']} WHERE slug=%s LIMIT 1", $slug ),
			ARRAY_A
		);
		return Database::associative_row( $row );
	}
}
