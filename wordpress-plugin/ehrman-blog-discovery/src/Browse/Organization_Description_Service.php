<?php
/**
 * Description-only writes for Topics & Categories.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps definition edits separate from names, links and post assignments. */
final class Organization_Description_Service {
	/**
	 * Detects stale definition edits without conflicting with relationship-only saves.
	 *
	 * @param array<string,mixed> $record Current organization record.
	 */
	public static function revision( array $record ): string {
		$definition = array();
		foreach ( array( 'id', 'name', 'slug', 'description', 'browse_path_id', 'display_in_browser' ) as $field ) {
			$definition[ $field ] = Database::text( $record[ $field ] ?? null );
		}
		return hash( 'sha256', (string) wp_json_encode( $definition ) );
	}

	/**
	 * Saves an existing record's description without modifying its relationships.
	 *
	 * @param string $tab Validated organization scope.
	 * @param int    $id Record ID.
	 * @param string $description Plain-text definition.
	 * @param string $revision Definition revision when the form was opened.
	 * @return int|\WP_Error Saved ID or validation failure.
	 * @throws RuntimeException Caught internally to roll back and return a safe error.
	 */
	public function update( string $tab, int $id, string $description, string $revision ): int|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_description_permission', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		$table_key = match ( $tab ) {
			'subject-areas' => 'subject_areas',
			'categories' => 'categories',
			'topics' => 'topics',
			default => '',
		};
		if ( '' === $table_key || $id < 1 ) {
			return new \WP_Error( 'ehrman_description_record', __( 'This record is unavailable for description changes.', 'ehrman-blog-discovery' ) );
		}
		$description = sanitize_textarea_field( $description );
		if ( '' === trim( $description ) || mb_strlen( $description ) > 4000 ) {
			return new \WP_Error( 'ehrman_description_length', __( 'Enter a description of 1-4000 characters.', 'ehrman-blog-discovery' ) );
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ehrman_description_save', __( 'The description could not be saved. Please try again.', 'ehrman-blog-discovery' ) );
		}
		try {
			$path_id = 0;
			if ( 'subject-areas' === $tab ) {
				// Follow the subject-area writer's lock order and exclude retired paths.
				$path_id = Database::integer( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s AND is_active=1 FOR UPDATE', $tables['browse_paths'], 'browse-topics' ) ) );
				if ( $path_id < 1 ) {
					throw new RuntimeException( __( 'Browse Topics must be installed and active before subject areas can be changed.', 'ehrman-blog-discovery' ) );
				}
			}
			$record = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $tables[ $table_key ], $id ), ARRAY_A ) );
			if ( null === $record || ( 'topics' === $tab && ! Topic_Organization_Service::editable( $record ) ) || ( 'subject-areas' === $tab && Database::integer( $record['browse_path_id'] ) !== $path_id ) ) {
				throw new RuntimeException( __( 'This record is unavailable for description changes.', 'ehrman-blog-discovery' ) );
			}
			if ( ! hash_equals( self::revision( $record ), $revision ) ) {
				throw new RuntimeException( __( 'This description changed after you opened it. Cancel and reopen Edit Description before saving.', 'ehrman-blog-discovery' ) );
			}
			if ( false === $wpdb->update( $tables[ $table_key ], array( 'description' => $description ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( __( 'The description could not be saved. Please try again.', 'ehrman-blog-discovery' ) );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ehrman_description_save', $error->getMessage() );
		}
		return $id;
	}
}
