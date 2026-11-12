<?php
/**
 * Upgrade from the two experimental browsing structures to one.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Preserves the first hierarchy and shared search data during retirement. */
final class Browse_Upgrade {
	/**
	 * Removes only the retired hierarchy, leaving shared taxonomy and posts intact.
	 *
	 * @throws \RuntimeException When an upgrade query fails.
	 */
	public static function migrate_data(): void {
		$wpdb    = Database::client();
		$tables  = Database::tables();
		$queries = array(
			'START TRANSACTION',
			"DELETE sac FROM {$tables['subject_area_categories']} sac JOIN {$tables['subject_areas']} sa ON sa.id=sac.subject_area_id JOIN {$tables['browse_paths']} bp ON bp.id=sa.browse_path_id WHERE bp.slug='browse-topics-2'",
			"DELETE sa FROM {$tables['subject_areas']} sa JOIN {$tables['browse_paths']} bp ON bp.id=sa.browse_path_id WHERE bp.slug='browse-topics-2'",
			"DELETE FROM {$tables['browse_paths']} WHERE slug='browse-topics-2'",
			"UPDATE {$tables['browse_paths']} SET name='Browse Topics',slug='browse-topics',description='Browse Topics hierarchy.' WHERE slug='browse-topics-1'",
			'COMMIT',
		);
		foreach ( $queries as $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static migration SQL uses only trusted table identifiers and literal values.
			if ( false === $wpdb->query( $query ) ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'Browse Topics upgrade failed; the hierarchy was not changed.' );
			}
		}
	}

	/** Reuses the first managed page and keeps retired pages recoverable as drafts. */
	public static function migrate_pages(): bool {
		$page = get_page_by_path( 'browse-topics', OBJECT, 'page' );
		if ( $page instanceof \WP_Post && ! has_shortcode( $page->post_content, 'ehrman_browse_topics' ) ) {
			// Do not silently overwrite a site's existing content at the new URL.
			return false;
		}
		$legacy_id = Database::integer( get_option( 'ehrman_discovery_page_browse_1', 0 ) );
		$legacy    = $legacy_id > 0 ? get_post( $legacy_id ) : null;
		if ( ! $legacy instanceof \WP_Post || 'page' !== $legacy->post_type || 'trash' === $legacy->post_status ) {
			$legacy = get_page_by_path( 'browse-topics-1', OBJECT, 'page' );
		}
		if ( ! $page instanceof \WP_Post && $legacy instanceof \WP_Post && has_shortcode( $legacy->post_content, 'ehrman_browse_topics' ) ) {
			$page = $legacy;
		}
		if ( $page instanceof \WP_Post ) {
			$content = str_replace( array( '[ehrman_browse_topics path="1"]', "[ehrman_browse_topics path='1']" ), '[ehrman_browse_topics]', $page->post_content );
			if ( 'Browse Topics' !== $page->post_title || 'browse-topics' !== $page->post_name || $content !== $page->post_content ) {
				$result = wp_update_post(
					array(
						'ID'           => $page->ID,
						'post_title'   => 'Browse Topics',
						'post_name'    => 'browse-topics',
						'post_content' => wp_slash( $content ),
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					return false;
				}
			}
			update_option( 'ehrman_discovery_page_browse_topics', $page->ID, false );
		}
		foreach ( array( 'browse-topics-1', 'browse-topics-2' ) as $slug ) {
			$retired = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $retired instanceof \WP_Post && has_shortcode( $retired->post_content, 'ehrman_browse_topics' ) && 'draft' !== $retired->post_status && 'trash' !== $retired->post_status ) {
				if ( is_wp_error(
					wp_update_post(
						array(
							'ID'          => $retired->ID,
							'post_status' => 'draft',
						),
						true
					)
				) ) {
					return false;
				}
			}
		}
		return true;
	}
}
