<?php
/**
 * Subject-area creation and category relationships.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Changes only subject areas and their links in the current Browse Topics path. */
final class Subject_Area_Organization_Service {
	/**
	 * Returns subject areas alphabetically, including their existing category links.
	 *
	 * @return list<array<string,mixed>> Subject areas.
	 */
	public function areas(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$path   = ( new Browse_Service() )->browse_path();
		if ( null === $path ) {
			return array();
		}
		$areas = Database::associative_rows(
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT sa.*, (SELECT COUNT(DISTINCT pt.post_id) FROM %i sac JOIN %i tc ON tc.category_id=sac.category_id JOIN %i pt ON pt.topic_id=tc.topic_id WHERE sac.subject_area_id=sa.id) post_count FROM %i sa WHERE sa.browse_path_id=%d ORDER BY sa.name,sa.id',
					$tables['subject_area_categories'],
					$tables['topic_categories'],
					$tables['post_topics'],
					$tables['subject_areas'],
					Database::integer( $path['id'] )
				),
				ARRAY_A
			)
		);
		$links = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT sac.subject_area_id,c.id,c.name FROM %i sac JOIN %i c ON c.id=sac.category_id JOIN %i sa ON sa.id=sac.subject_area_id WHERE sa.browse_path_id=%d ORDER BY c.name,c.id', $tables['subject_area_categories'], $tables['categories'], $tables['subject_areas'], Database::integer( $path['id'] ) ), ARRAY_A ) );
		$by_id = array();
		foreach ( $links as $link ) {
			$by_id[ Database::integer( $link['subject_area_id'] ) ][] = $link;
		}
		foreach ( $areas as &$area ) {
			$area['categories'] = $by_id[ Database::integer( $area['id'] ) ] ?? array();
		}
		unset( $area );
		return $areas;
	}

	/**
	 * Reads a subject area only from the current browse path.
	 *
	 * @param int $id Subject-area ID.
	 * @return array<string,mixed>|null Subject area.
	 */
	public function area( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		$path   = ( new Browse_Service() )->browse_path();
		if ( null === $path ) {
			return null;
		}
		$area = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT sa.*, (SELECT COUNT(DISTINCT pt.post_id) FROM %i sac JOIN %i tc ON tc.category_id=sac.category_id JOIN %i pt ON pt.topic_id=tc.topic_id WHERE sac.subject_area_id=sa.id) post_count FROM %i sa WHERE sa.browse_path_id=%d AND sa.id=%d', $tables['subject_area_categories'], $tables['topic_categories'], $tables['post_topics'], $tables['subject_areas'], Database::integer( $path['id'] ), $id ), ARRAY_A ) );
		if ( null !== $area ) {
			$area['categories'] = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT sac.subject_area_id,c.id,c.name FROM %i sac JOIN %i c ON c.id=sac.category_id WHERE sac.subject_area_id=%d ORDER BY c.name,c.id', $tables['subject_area_categories'], $tables['categories'], $id ), ARRAY_A ) );
		}
		return $area;
	}

	/**
	 * Returns current-path relationship choices without counts or category links.
	 *
	 * @return list<array<string,mixed>> Subject-area choices in display order.
	 */
	public function options(): array {
		$path = ( new Browse_Service() )->browse_path();
		if ( null === $path ) {
			return array();
		}
		$wpdb = Database::client();
		return Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT id,name FROM %i WHERE browse_path_id=%d ORDER BY name,id', Database::tables()['subject_areas'], Database::integer( $path['id'] ) ), ARRAY_A ) );
	}

	/**
	 * Identifies the subject-area definition and links opened by an administrator.
	 *
	 * @param array<string,mixed> $area Subject area.
	 */
	public static function revision( array $area ): string {
		$ids = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $area['categories'] ?? null ) );
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', (string) wp_json_encode( array( $area['id'], $area['browse_path_id'], $area['name'], $area['slug'], $area['description'], $ids ) ) );
	}

	/**
	 * Creates a subject area with optional existing categories.
	 *
	 * @param string           $name Name.
	 * @param string           $description Description.
	 * @param array<int,mixed> $category_ids Selected categories.
	 * @return int|\WP_Error Saved ID or validation error.
	 */
	public function create( string $name, string $description, array $category_ids ) {
		return $this->save( 0, $name, $description, $category_ids, '' );
	}

	/**
	 * Changes only category links, leaving the existing definition unchanged.
	 *
	 * @param int              $id Subject-area ID.
	 * @param array<int,mixed> $category_ids Selected categories.
	 * @param string           $revision Opened snapshot.
	 * @return int|\WP_Error Saved ID or validation error.
	 */
	public function update_categories( int $id, array $category_ids, string $revision ) {
		if ( $id < 1 ) {
			return new \WP_Error( 'ehrman_subject_area_missing', __( 'Subject area not found.', 'ehrman-blog-discovery' ) );
		}
		return $this->save( $id, '', '', $category_ids, $revision );
	}

	/**
	 * Commits the definition and complete category selection atomically.
	 *
	 * @param int              $id Zero for creation.
	 * @param string           $name New name.
	 * @param string           $description New description.
	 * @param array<int,mixed> $category_ids Selected categories.
	 * @param string           $revision Opened snapshot.
	 * @return int|\WP_Error Saved ID or error.
	 * @throws RuntimeException Internally caught to roll back incomplete changes.
	 */
	private function save( int $id, string $name, string $description, array $category_ids, string $revision ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_subject_area_forbidden', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		$valid_ids = array();
		foreach ( $category_ids as $value ) {
			if ( ! is_int( $value ) || $value < 1 ) {
				return new \WP_Error( 'ehrman_subject_area_categories', __( 'Select valid categories.', 'ehrman-blog-discovery' ) );
			}
			$valid_ids[] = $value;
		}
		$category_ids = array_values( array_unique( $valid_ids ) );
		sort( $category_ids, SORT_NUMERIC );
		$name        = trim( sanitize_text_field( $name ) );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( 0 === $id && ( '' === $name || mb_strlen( $name ) > 191 || '' === $description || mb_strlen( $description ) > 4000 || '' === sanitize_title( $name ) || '' === Search_Service::normalize( $name ) ) ) {
			return new \WP_Error( 'ehrman_subject_area_fields', __( 'Enter a subject-area name (up to 191 characters) and a description (up to 4,000 characters).', 'ehrman-blog-discovery' ) );
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ehrman_subject_area_save', __( 'The subject area could not be saved. Please try again.', 'ehrman-blog-discovery' ) );
		}
		try {
			// Serialize this small administration workflow, including duplicate-name checks.
			$path_id = Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s AND is_active=1 FOR UPDATE', $tables['browse_paths'], 'browse-topics' ) ) );
			if ( $path_id < 1 ) {
				throw new RuntimeException( __( 'Browse Topics must be installed and active before subject areas can be changed.', 'ehrman-blog-discovery' ) );
			}
			if ( $id > 0 ) {
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d AND browse_path_id=%d FOR UPDATE', $tables['subject_areas'], $id, $path_id ) );
				$area   = null !== $locked ? $this->area( $id ) : null;
				if ( null === $area ) {
					throw new RuntimeException( __( 'This subject area is unavailable for category changes.', 'ehrman-blog-discovery' ) );
				}
				if ( ! hash_equals( self::revision( $area ), $revision ) ) {
					throw new RuntimeException( __( 'This subject area changed after you opened it. Reload the page before saving.', 'ehrman-blog-discovery' ) );
				}
			} else {
				$names = Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM %i WHERE browse_path_id=%d', $tables['subject_areas'], $path_id ) ) );
				if ( in_array( Search_Service::normalize( $name ), array_map( array( Search_Service::class, 'normalize' ), $names ), true ) ) {
					throw new RuntimeException( __( 'That name is already used by a subject area.', 'ehrman-blog-discovery' ) );
				}
			}
			$available = array_map( array( Database::class, 'integer' ), Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id LOCK IN SHARE MODE', $tables['categories'] ) ) ) );
			if ( array_diff( $category_ids, $available ) ) {
				throw new RuntimeException( __( 'A selected category no longer exists. Reload the page and select its replacement.', 'ehrman-blog-discovery' ) );
			}
			if ( 0 === $id ) {
				$base   = (string) preg_replace( '/%[a-f0-9]?$/i', '', substr( sanitize_title( $name ), 0, 180 ) );
				$slug   = $base;
				$suffix = 2;
				while ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE browse_path_id=%d AND slug=%s', $tables['subject_areas'], $path_id, $slug ) ) ) {
					$slug = $base . '-' . $suffix;
					++$suffix;
				}
				$position = 1 + Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(position) FROM %i WHERE browse_path_id=%d', $tables['subject_areas'], $path_id ) ) );
				$this->checked(
					$wpdb->insert(
						$tables['subject_areas'],
						array(
							'browse_path_id' => $path_id,
							'name'           => $name,
							'slug'           => $slug,
							'description'    => $description,
							'position'       => $position,
						),
						array( '%d', '%s', '%s', '%s', '%d' )
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			$current = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT category_id,position FROM %i WHERE subject_area_id=%d', $tables['subject_area_categories'], $id ), ARRAY_A ) );
			$old_ids = array_map( static fn( array $row ): int => Database::integer( $row['category_id'] ), $current );
			foreach ( array_diff( $old_ids, $category_ids ) as $category_id ) {
				$this->checked(
					$wpdb->delete(
						$tables['subject_area_categories'],
						array(
							'subject_area_id' => $id,
							'category_id'     => $category_id,
						),
						array( '%d', '%d' )
					)
				);
			}
			$position = empty( $current ) ? 0 : max( array_map( static fn( array $row ): int => Database::integer( $row['position'] ), $current ) ) + 1;
			foreach ( array_diff( $category_ids, $old_ids ) as $category_id ) {
				$this->checked(
					$wpdb->insert(
						$tables['subject_area_categories'],
						array(
							'subject_area_id' => $id,
							'category_id'     => $category_id,
							'position'        => $position++,
						),
						array( '%d', '%d', '%d' )
					)
				);
			}
			$this->checked( $wpdb->query( 'COMMIT' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ehrman_subject_area_save', $error->getMessage() );
		}
		AI_Usage::invalidate_search_caches();
		return $id;
	}

	/**
	 * Converts write errors to a rollback without exposing SQL.
	 *
	 * @param int|bool $result Database operation result.
	 * @throws RuntimeException On failure.
	 */
	private function checked( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( __( 'The subject area could not be saved. Please reload and try again.', 'ehrman-blog-discovery' ) );
		}
	}
}
