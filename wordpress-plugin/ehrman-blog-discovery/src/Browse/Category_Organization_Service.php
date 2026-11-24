<?php
/**
 * Category creation and its two-sided organization links.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Changes organization only, never post assignments or vectors. */
final class Category_Organization_Service {
	/**
	 * Returns alphabetical categories with both sets of links and distinct post counts.
	 *
	 * @return list<array<string,mixed>> Categories.
	 */
	public function categories(): array {
		$wpdb   = Database::client();
		$t      = Database::tables();
		$path   = ( new Browse_Service() )->browse_path();
		$rows   = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, (SELECT COUNT(DISTINCT pt.post_id) FROM %i tc JOIN %i pt ON pt.topic_id=tc.topic_id WHERE tc.category_id=c.id) post_count FROM %i c ORDER BY c.name,c.id', $t['topic_categories'], $t['post_topics'], $t['categories'] ), ARRAY_A ) );
		$areas  = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT sac.category_id,sa.id,sa.name FROM %i sac JOIN %i sa ON sa.id=sac.subject_area_id WHERE sa.browse_path_id=%d ORDER BY sa.name,sa.id', $t['subject_area_categories'], $t['subject_areas'], Database::integer( $path['id'] ?? null ) ), ARRAY_A ) );
		$topics = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT tc.category_id,t.id,t.name,t.display_in_browser FROM %i tc JOIN %i t ON t.id=tc.topic_id ORDER BY t.name,t.id', $t['topic_categories'], $t['topics'] ), ARRAY_A ) );
		$links  = array();
		foreach ( $areas as $area ) {
			$links[ Database::integer( $area['category_id'] ) ]['subject_areas'][] = $area;
		}
		foreach ( $topics as $topic ) {
			$key = Topic_Organization_Service::editable( $topic ) ? 'topics' : 'protected_topics';
			$links[ Database::integer( $topic['category_id'] ) ][ $key ][] = $topic;
		}
		foreach ( $rows as &$row ) {
			foreach ( array( 'subject_areas', 'topics', 'protected_topics' ) as $key ) {
				$row[ $key ] = $links[ Database::integer( $row['id'] ) ][ $key ] ?? array();
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Reads one category and its links.
	 *
	 * @param int $id Category ID.
	 * @return array<string,mixed>|null Category.
	 */
	public function category( int $id ): ?array {
		foreach ( $this->categories() as $row ) {
			if ( Database::integer( $row['id'] ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Includes links on both sides, including changes made through the other tabs.
	 *
	 * @param array<string,mixed> $category Category snapshot.
	 */
	public static function revision( array $category ): string {
		$parts = array( $category['id'], $category['name'], $category['slug'], $category['description'] );
		foreach ( array( 'subject_areas', 'topics', 'protected_topics' ) as $key ) {
			$ids = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $category[ $key ] ?? null ) );
			sort( $ids, SORT_NUMERIC );
			$parts[] = $ids;
		}
		return hash( 'sha256', (string) wp_json_encode( $parts ) );
	}

	/**
	 * Creates a category with existing topics and optional subject areas.
	 *
	 * @param string           $name Category name.
	 * @param string           $description Definition.
	 * @param array<int,mixed> $area_ids Subject areas.
	 * @param array<int,mixed> $topic_ids Topics.
	 * @return int|\WP_Error Saved ID or error.
	 */
	public function create( string $name, string $description, array $area_ids, array $topic_ids ) {
		return $this->save( 0, $name, $description, $area_ids, $topic_ids, '' );
	}

	/**
	 * Changes only relationships, leaving the existing category definition intact.
	 *
	 * @param int              $id Category ID.
	 * @param array<int,mixed> $area_ids Subject areas.
	 * @param array<int,mixed> $topic_ids Topics.
	 * @param string           $revision Opened snapshot.
	 * @return int|\WP_Error Saved ID or error.
	 */
	public function update_links( int $id, array $area_ids, array $topic_ids, string $revision ) {
		if ( $id < 1 ) {
			return new \WP_Error( 'ehrman_category_missing', __( 'Category not found.', 'ehrman-blog-discovery' ) );
		}
		return $this->save( $id, '', '', $area_ids, $topic_ids, $revision );
	}

	/**
	 * Saves both relationship sets in one transaction.
	 *
	 * @param int              $id Zero for creation.
	 * @param string           $name New name.
	 * @param string           $description New description.
	 * @param array<int,mixed> $area_ids Subject areas.
	 * @param array<int,mixed> $topic_ids Topics.
	 * @param string           $revision Opened snapshot.
	 * @return int|\WP_Error Saved ID or error.
	 * @throws RuntimeException Caught internally to roll back incomplete changes.
	 */
	private function save( int $id, string $name, string $description, array $area_ids, array $topic_ids, string $revision ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_category_forbidden', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		if ( empty( $topic_ids ) ) {
			return new \WP_Error( 'ehrman_category_links', __( 'Select at least one valid topic.', 'ehrman-blog-discovery' ) );
		}
		$selections = array();
		foreach ( array( $area_ids, $topic_ids ) as $ids ) {
			$valid = array();
			foreach ( $ids as $value ) {
				if ( ! is_int( $value ) || $value < 1 ) {
					return new \WP_Error( 'ehrman_category_links', __( 'Select valid subject areas and topics.', 'ehrman-blog-discovery' ) );
				}
				$valid[] = $value;
			}
			$valid = array_values( array_unique( $valid ) );
			sort( $valid, SORT_NUMERIC );
			$selections[] = $valid;
		}
		$area_ids    = $selections[0];
		$topic_ids   = $selections[1];
		$name        = trim( sanitize_text_field( $name ) );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( 0 === $id && ( '' === $name || mb_strlen( $name ) > 191 || '' === $description || mb_strlen( $description ) > 4000 || '' === sanitize_title( $name ) || '' === Search_Service::normalize( $name ) ) ) {
			return new \WP_Error( 'ehrman_category_fields', __( 'Enter a category name (up to 191 characters) and a description (up to 4,000 characters).', 'ehrman-blog-discovery' ) );
		}
		$wpdb = Database::client();
		$t    = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ehrman_category_save', __( 'The category could not be saved. Please try again.', 'ehrman-blog-discovery' ) );
		}
		try {
			// Match the other editors' lock order: browse path, topics, then categories.
			$path_id = Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s AND is_active=1 FOR UPDATE', $t['browse_paths'], 'browse-topics' ) ) );
			if ( $path_id < 1 ) {
				throw new RuntimeException( __( 'Browse Topics must be installed and active before categories can be changed.', 'ehrman-blog-discovery' ) );
			}
			$topics           = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,display_in_browser FROM %i ORDER BY id FOR UPDATE', $t['topics'] ), ARRAY_A ) );
			$available_topics = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), array_filter( $topics, array( Topic_Organization_Service::class, 'editable' ) ) );
			$areas            = array_map( array( Database::class, 'integer' ), Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE browse_path_id=%d ORDER BY id LOCK IN SHARE MODE', $t['subject_areas'], $path_id ) ) ) );
			if ( array_diff( $area_ids, $areas ) || array_diff( $topic_ids, $available_topics ) ) {
				throw new RuntimeException( __( 'A selected subject area or topic is unavailable. Reload the page and select its replacement.', 'ehrman-blog-discovery' ) );
			}
			$category = null;
			if ( $id > 0 ) {
				$locked   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d FOR UPDATE', $t['categories'], $id ) );
				$category = null !== $locked ? $this->category( $id ) : null;
				if ( null === $category ) {
					throw new RuntimeException( __( 'This category is unavailable for relationship changes.', 'ehrman-blog-discovery' ) );
				}
				if ( ! hash_equals( self::revision( $category ), $revision ) ) {
					throw new RuntimeException( __( 'This category changed after you opened it. Reload the page before saving.', 'ehrman-blog-discovery' ) );
				}
			} else {
				$names = Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM %i', $t['categories'] ) ) );
				if ( in_array( Search_Service::normalize( $name ), array_map( array( Search_Service::class, 'normalize' ), $names ), true ) ) {
					throw new RuntimeException( __( 'That name is already used by a category.', 'ehrman-blog-discovery' ) );
				}
				$base   = (string) preg_replace( '/%[a-f0-9]?$/i', '', substr( sanitize_title( $name ), 0, 180 ) );
				$slug   = $base;
				$suffix = 2;
				while ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s', $t['categories'], $slug ) ) ) {
					$slug = $base . '-' . $suffix;
					++$suffix;
				}
				$this->checked(
					$wpdb->insert(
						$t['categories'],
						array(
							'name'        => $name,
							'slug'        => $slug,
							'description' => $description,
						),
						array( '%s', '%s', '%s' )
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			// Only the current browse path and editable topics are managed here.
			// Unchanged links keep their positions; unlinking never deletes an endpoint.
			foreach ( array(
				'subject_areas' => $area_ids,
				'topics'        => $topic_ids,
			) as $kind => $selected ) {
				$is_area = 'subject_areas' === $kind;
				$table   = $t[ $is_area ? 'subject_area_categories' : 'topic_categories' ];
				$field   = $is_area ? 'subject_area_id' : 'topic_id';
				$old_ids = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $category[ $kind ] ?? null ) );
				foreach ( array_diff( $old_ids, $selected ) as $linked_id ) {
					$this->checked(
						$wpdb->delete(
							$table,
							array(
								'category_id' => $id,
								$field        => $linked_id,
							),
							array( '%d', '%d' )
						)
					);
				}
				foreach ( array_diff( $selected, $old_ids ) as $linked_id ) {
					$position = 1 + Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(position) FROM %i WHERE %i=%d', $table, $is_area ? $field : 'category_id', $is_area ? $linked_id : $id ) ) );
					$this->checked(
						$wpdb->insert(
							$table,
							array(
								'category_id' => $id,
								$field        => $linked_id,
								'position'    => $position,
							),
							array( '%d', '%d', '%d' )
						)
					);
				}
			}
			$this->checked( $wpdb->query( 'COMMIT' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ehrman_category_save', $error->getMessage() );
		}
		AI_Usage::invalidate_search_caches();
		return $id;
	}

	/**
	 * Converts write failures to rollback without exposing SQL.
	 *
	 * @param int|bool $result Write result.
	 * @throws RuntimeException On failure.
	 */
	private function checked( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( __( 'The category could not be saved. Please reload and try again.', 'ehrman-blog-discovery' ) );
		}
	}
}
