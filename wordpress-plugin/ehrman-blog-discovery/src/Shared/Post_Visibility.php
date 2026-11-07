<?php
/**
 * Publication visibility for imported and editor-managed discovery records.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Imported source IDs are not assumed to identify local WordPress posts. */
final class Post_Visibility {
	/**
	 * Rechecks candidates after a slow AI response without changing their ranking.
	 *
	 * @param list<array<string,mixed>> $posts Candidate post records.
	 * @return list<array<string,mixed>> Still-visible candidates.
	 */
	public static function filter_posts( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}
		$ids     = array_map( static fn( array $post ): int => Database::integer( $post['id'] ?? null ), $posts );
		$records = ( new Search_Post_Repository() )->posts_by_ids( $ids );
		$visible = array_fill_keys( array_map( static fn( array $post ): int => Database::integer( $post['id'] ?? null ), $records ), true );
		return array_values( array_filter( $posts, static fn( array $post ): bool => isset( $visible[ Database::integer( $post['id'] ?? null ) ] ) ) );
	}

	/** Returns an internal SQL subquery containing only publicly searchable post IDs. */
	public static function ids_sql(): string {
		$wpdb  = Database::client();
		$table = Database::tables()['external_posts'];
		return "SELECT visible_post.id FROM {$table} visible_post "
			. "LEFT JOIN {$wpdb->posts} native_post ON native_post.ID=visible_post.wordpress_post_id "
			. "WHERE visible_post.wordpress_post_id IS NULL OR (native_post.post_type='post' AND native_post.post_status='publish')";
	}
}
