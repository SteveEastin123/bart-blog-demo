<?php
/**
 * Saved WordPress post input and ingestion-source comparison.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shares trusted post input and change detection across ingestion interfaces. */
final class Post_Ingestion_Post_Source {
	/**
	 * Builds trusted ingestion input from the saved WordPress post.
	 *
	 * @param WP_Post $post Published post.
	 * @return array<string,mixed> Ingestion input.
	 */
	public static function input( WP_Post $post ): array {
		$rendered = do_blocks( $post->post_content );
		$rendered = strip_shortcodes( $rendered );
		$rendered = preg_replace( '/<(?:br\s*\/?|\/(?:p|div|li|h[1-6]|blockquote))>/i', "\n", $rendered );
		$text     = wp_strip_all_tags( is_string( $rendered ) ? $rendered : $post->post_content );
		$charset  = get_bloginfo( 'charset' );
		$charset  = '' !== $charset ? $charset : 'UTF-8';
		$text     = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, $charset );
		$text     = preg_replace( "/[ \t]+\n|\n[ \t]+/", "\n", $text );
		$text     = preg_replace( "/\n{3,}/", "\n\n", is_string( $text ) ? $text : '' );
		$author   = get_the_author_meta( 'display_name', (int) $post->post_author );
		$date     = get_post_time( 'Y-m-d', false, $post, false );
		return array(
			'source_wp_id' => $post->ID,
			'title'        => get_the_title( $post ),
			'url'          => get_permalink( $post ),
			'author'       => $author,
			'date'         => $date,
			'post_text'    => trim( is_string( $text ) ? $text : '' ),
		);
	}

	/**
	 * Returns whether the current saved post matches the draft's analyzed source.
	 *
	 * @param WP_Post             $post  Current WordPress post.
	 * @param array<string,mixed> $draft Ingestion draft.
	 */
	private static function source_matches_draft( WP_Post $post, array $draft ): bool {
		$current = self::input( $post );
		return Database::integer( $draft['source_wp_id'] ?? null ) === $post->ID
			&& hash_equals( Database::text( $draft['title'] ?? null ), Database::text( $current['title'] ?? null ) )
			&& hash_equals( Database::text( $draft['url'] ?? null ), Database::text( $current['url'] ?? null ) )
			&& hash_equals( Database::text( $draft['author'] ?? null ), Database::text( $current['author'] ?? null ) )
			&& substr( Database::text( $draft['published_at'] ?? null ), 0, 10 ) === Database::text( $current['date'] ?? null )
			&& hash_equals( Database::text( $draft['post_text'] ?? null ), Database::text( $current['post_text'] ?? null ) );
	}

	/**
	 * Returns whether a saved WordPress post differs from its pending analysis source.
	 *
	 * @param WP_Post             $post  Current WordPress post.
	 * @param array<string,mixed> $draft Ingestion draft.
	 */
	public static function source_changed( WP_Post $post, array $draft ): bool {
		return ! self::source_matches_draft( $post, $draft );
	}
}
