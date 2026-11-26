<?php
/**
 * Topic creation and category relationships.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Changes organization only; never rewrites post metadata or vectors. */
final class Topic_Organization_Service {
	/**
	 * Returns categories in display order.
	 *
	 * @return list<array<string,mixed>> Categories.
	 */
	public function categories(): array {
		$wpdb = Database::client();
		return Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT id,name FROM %i ORDER BY name,id', Database::tables()['categories'] ), ARRAY_A ) );
	}

	/**
	 * Returns topics with their category links and distinct assigned-post counts.
	 *
	 * @return list<array<string,mixed>> Topics.
	 */
	public function topics(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$topics = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, (SELECT COUNT(*) FROM %i pt WHERE pt.topic_id=t.id) post_count FROM %i t ORDER BY t.name,t.id', $tables['post_topics'], $tables['topics'] ), ARRAY_A ) );
		$links  = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT tc.topic_id,c.id,c.name FROM %i tc JOIN %i c ON c.id=tc.category_id ORDER BY c.name,c.id', $tables['topic_categories'], $tables['categories'] ), ARRAY_A ) );
		$by_id  = array();
		foreach ( $links as $link ) {
			$by_id[ Database::integer( $link['topic_id'] ) ][] = $link;
		}
		foreach ( $topics as &$topic ) {
			$topic['categories'] = $by_id[ Database::integer( $topic['id'] ) ] ?? array();
		}
		unset( $topic );
		return $topics;
	}

	/**
	 * Reads a topic with its current category links.
	 *
	 * @param int $id Topic ID.
	 * @return array<string,mixed>|null Topic.
	 */
	public function topic( int $id ): ?array {
		if ( $id < 1 ) {
			return null;
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		$topic  = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT t.*, (SELECT COUNT(*) FROM %i pt WHERE pt.topic_id=t.id) post_count FROM %i t WHERE t.id=%d', $tables['post_topics'], $tables['topics'], $id ), ARRAY_A ) );
		if ( null !== $topic ) {
			$topic['categories'] = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT tc.topic_id,c.id,c.name FROM %i tc JOIN %i c ON c.id=tc.category_id WHERE tc.topic_id=%d ORDER BY c.name,c.id', $tables['topic_categories'], $tables['categories'], $id ), ARRAY_A ) );
		}
		return $topic;
	}

	/**
	 * Returns editable relationship choices without post counts or category links.
	 *
	 * @return list<array<string,mixed>> Topic choices in display order.
	 */
	public function options(): array {
		$wpdb = Database::client();
		$rows = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,display_in_browser FROM %i ORDER BY name,id', Database::tables()['topics'] ), ARRAY_A ) );
		return array_values( array_filter( $rows, array( self::class, 'editable' ) ) );
	}

	/**
	 * Identifies the exact topic/link snapshot opened by an administrator.
	 *
	 * @param array<string,mixed> $topic Topic record.
	 */
	public static function revision( array $topic ): string {
		$ids = array_map( static fn( array $category ): int => Database::integer( $category['id'] ), Database::associative_rows( $topic['categories'] ?? null ) );
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', (string) wp_json_encode( array( $topic['id'], $topic['name'], $topic['slug'], $topic['description'], $topic['display_in_browser'], $ids ) ) );
	}

	/**
	 * Internal topics, including Ignore, are not editable through this page.
	 *
	 * @param array<string,mixed> $topic Topic record.
	 */
	public static function editable( array $topic ): bool {
		return 1 === Database::integer( $topic['display_in_browser'] ?? null ) && 'ignore' !== Search_Service::normalize( Database::text( $topic['name'] ?? null ) );
	}

	/**
	 * Creates a topic using existing categories only.
	 *
	 * @param string           $name Topic name.
	 * @param string           $description Topic definition.
	 * @param array<int,mixed> $category_ids Untrusted selected categories.
	 * @return int|\WP_Error Created topic ID or validation error.
	 */
	public function create( string $name, string $description, array $category_ids ) {
		return $this->save( 0, $name, $description, $category_ids, '' );
	}

	/**
	 * Changes only the category links of an existing topic.
	 *
	 * @param int              $id Topic ID.
	 * @param array<int,mixed> $category_ids Untrusted selected categories.
	 * @param string           $revision Snapshot being edited.
	 * @return int|\WP_Error Saved topic ID or validation error.
	 */
	public function update_categories( int $id, array $category_ids, string $revision ) {
		if ( $id < 1 ) {
			return new \WP_Error( 'ehrman_topic_missing', __( 'Topic not found.', 'ehrman-blog-discovery' ) );
		}
		return $this->save( $id, '', '', $category_ids, $revision );
	}

	/**
	 * Validates and commits one complete organization change.
	 *
	 * @param int              $id Zero for creation, otherwise a topic ID.
	 * @param string           $name New topic name.
	 * @param string           $description New topic definition.
	 * @param array<int,mixed> $category_ids Untrusted selected category IDs.
	 * @param string           $revision Existing snapshot.
	 * @return int|\WP_Error Saved ID or error.
	 * @throws RuntimeException Internally caught to roll back incomplete writes.
	 */
	private function save( int $id, string $name, string $description, array $category_ids, string $revision ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_topic_forbidden', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		$valid_ids = array();
		foreach ( $category_ids as $value ) {
			if ( ! is_int( $value ) || $value < 1 ) {
				return new \WP_Error( 'ehrman_topic_categories', __( 'Select at least one valid category.', 'ehrman-blog-discovery' ) );
			}
			$valid_ids[] = $value;
		}
		if ( empty( $valid_ids ) ) {
			return new \WP_Error( 'ehrman_topic_categories', __( 'Select at least one valid category.', 'ehrman-blog-discovery' ) );
		}
		$category_ids = array_values( array_unique( $valid_ids ) );
		sort( $category_ids, SORT_NUMERIC );
		$name        = trim( sanitize_text_field( $name ) );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( 0 === $id && ( '' === $name || mb_strlen( $name ) > 191 || '' === $description || mb_strlen( $description ) > 4000 || '' === sanitize_title( $name ) || '' === Search_Service::normalize( $name ) ) ) {
			return new \WP_Error( 'ehrman_topic_fields', __( 'Enter a topic name (up to 191 characters) and a description (up to 4,000 characters).', 'ehrman-blog-discovery' ) );
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ehrman_topic_save', __( 'The topic could not be saved. Please try again.', 'ehrman-blog-discovery' ) );
		}
		try {
			// Lock the topic before reading its links so simultaneous saves cannot overwrite each other.
			if ( $id > 0 ) {
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d FOR UPDATE', $tables['topics'], $id ) );
				$topic  = null !== $locked ? $this->topic( $id ) : null;
				if ( null === $topic || ! self::editable( $topic ) ) {
					throw new RuntimeException( __( 'This topic is unavailable for category changes.', 'ehrman-blog-discovery' ) );
				}
				if ( ! hash_equals( self::revision( $topic ), $revision ) ) {
					throw new RuntimeException( __( 'This topic changed after you opened it. Reload the page before saving.', 'ehrman-blog-discovery' ) );
				}
			} else {
				$this->validate_new_name( $name );
			}
			$categories = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id LOCK IN SHARE MODE', $tables['categories'] ), ARRAY_A ) );
			$available  = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), $categories );
			if ( array_diff( $category_ids, $available ) ) {
				throw new RuntimeException( __( 'A selected category no longer exists. Reload the page and select its replacement.', 'ehrman-blog-discovery' ) );
			}
			if ( 0 === $id ) {
				$base   = (string) preg_replace( '/%[a-f0-9]?$/i', '', substr( sanitize_title( $name ), 0, 180 ) );
				$slug   = $base;
				$suffix = 2;
				while ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s', $tables['topics'], $slug ) ) ) {
					$slug = substr( $base, 0, 180 ) . '-' . $suffix;
					++$suffix;
				}
				$this->checked(
					$wpdb->insert(
						$tables['topics'],
						array(
							'name'               => $name,
							'slug'               => $slug,
							'description'        => $description,
							'display_in_browser' => 1,
						),
						array( '%s', '%s', '%s', '%d' )
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			$current = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( 'SELECT category_id FROM %i WHERE topic_id=%d', $tables['topic_categories'], $id ), ARRAY_A ) );
			$old_ids = array_map( static fn( array $row ): int => Database::integer( $row['category_id'] ), $current );
			foreach ( array_diff( $old_ids, $category_ids ) as $category_id ) {
				$this->checked(
					$wpdb->delete(
						$tables['topic_categories'],
						array(
							'topic_id'    => $id,
							'category_id' => $category_id,
						),
						array( '%d', '%d' )
					)
				);
			}
			foreach ( array_diff( $category_ids, $old_ids ) as $category_id ) {
				$position = 1 + Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(position) FROM %i WHERE category_id=%d', $tables['topic_categories'], $category_id ) ) );
				$this->checked(
					$wpdb->insert(
						$tables['topic_categories'],
						array(
							'topic_id'    => $id,
							'category_id' => $category_id,
							'position'    => $position,
						),
						array( '%d', '%d', '%d' )
					)
				);
			}
			$this->checked( $wpdb->query( 'COMMIT' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ehrman_topic_save', $error->getMessage() );
		}
		AI_Usage::invalidate_search_caches();
		return $id;
	}

	/**
	 * Rejects names that would collide with canonical names or hidden topic aliases.
	 *
	 * @param string $name New topic name.
	 * @throws RuntimeException For duplicate or reserved names.
	 */
	private function validate_new_name( string $name ): void {
		$wpdb       = Database::client();
		$tables     = Database::tables();
		$normalized = Search_Service::normalize( $name );
		$names      = Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM %i', $tables['topics'] ) ) );
		$aliases    = Database::strings( $wpdb->get_col( $wpdb->prepare( 'SELECT normalized FROM %i', $tables['topic_aliases'] ) ) );
		if ( 'ignore' === $normalized || in_array( $normalized, array_map( array( Search_Service::class, 'normalize' ), $names ), true ) || in_array( $normalized, $aliases, true ) ) {
			throw new RuntimeException( __( 'That name is already used by a topic or one of its alternative search names.', 'ehrman-blog-discovery' ) );
		}
	}

	/**
	 * Converts database failures to a rollback without exposing SQL.
	 *
	 * @param int|bool $result Database operation result.
	 * @throws RuntimeException For failed writes.
	 */
	private function checked( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( __( 'The topic could not be saved. Please reload and try again.', 'ehrman-blog-discovery' ) );
		}
	}
}
