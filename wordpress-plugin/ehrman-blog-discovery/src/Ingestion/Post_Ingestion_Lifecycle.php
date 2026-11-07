<?php
/**
 * Native WordPress post lifecycle cleanup for editor-created metadata.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cancels background work and removes only explicitly linked local-post data. */
final class Post_Ingestion_Lifecycle {
	private const RETRY_HOOK      = 'ehrman_ingestion_cleanup_deleted_post';
	private const FAILURES_OPTION = 'ehrman_ingestion_cleanup_failures';

	/** Registers lifecycle hooks; these never initiate AI requests. */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'deleted' ), 10, 2 );
		add_action( self::RETRY_HOOK, array( self::class, 'cleanup_deleted' ) );
		add_action( 'admin_notices', array( self::class, 'cleanup_notice' ) );
	}

	/**
	 * Invalidates cached results and fences workers when a native post is unpublished.
	 *
	 * @param string  $new_status New publication status.
	 * @param string  $old_status Previous publication status.
	 * @param WP_Post $post       Saved WordPress post.
	 */
	public static function status_changed( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'post' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		$wpdb      = Database::client();
		$tables    = Database::tables();
		$draft_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d', $tables['ingestion_drafts'], $post->ID ) );
		$owned     = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d LIMIT 1', $tables['external_posts'], $post->ID ) );
		if ( empty( $draft_ids ) && null === $owned ) {
			return;
		}
		if ( 'publish' !== $new_status ) {
			foreach ( $draft_ids as $draft_id ) {
				Post_Ingestion_Queue::cancel( Database::integer( $draft_id ) );
			}
			$sql = $wpdb->prepare(
				'UPDATE %i SET status=%s,analysis_attempt=analysis_attempt+1,error_message=%s,updated_at=%s WHERE wordpress_post_id=%d AND status IN (%s,%s)',
				$tables['ingestion_drafts'],
				'error',
				__( 'The post is no longer published. Publish it and regenerate metadata to continue.', 'ehrman-blog-discovery' ),
				current_time( 'mysql', true ),
				$post->ID,
				'queued',
				'analyzing'
			);
			if ( is_string( $sql ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				$wpdb->query( $sql );
			}
		}
		AI_Usage::invalidate_search_caches();
	}

	/**
	 * Cleans metadata after WordPress has permanently removed the native post.
	 *
	 * @param int     $post_id Deleted WordPress ID.
	 * @param WP_Post $post    Deleted post snapshot.
	 */
	public static function deleted( int $post_id, WP_Post $post ): void {
		if ( 'post' === $post->post_type ) {
			self::cleanup_deleted( $post_id );
		}
	}

	/**
	 * Removes owned metadata transactionally, retrying a database failure without exposing the post.
	 *
	 * @param int $post_id Deleted local WordPress ID.
	 */
	public static function cleanup_deleted( int $post_id ): void {
		$wpdb = Database::client();
		// Read the database directly: deleted_post fires before WordPress clears its post cache.
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID=%d', $wpdb->posts, $post_id ) ) ) {
			return;
		}
		$tables    = Database::tables();
		$draft_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d', $tables['ingestion_drafts'], $post_id ) );
		$owned     = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d LIMIT 1', $tables['external_posts'], $post_id ) );
		if ( empty( $draft_ids ) && null === $owned ) {
			return;
		}
		foreach ( $draft_ids as $draft_id ) {
			Post_Ingestion_Queue::cancel( Database::integer( $draft_id ) );
		}
		$failures    = Database::associative_row( get_option( self::FAILURES_OPTION, array() ) ) ?? array();
		$failure_key = 'post-' . $post_id;
		try {
			self::execute( 'START TRANSACTION' );
			foreach ( array( 'post_topics', 'post_keywords', 'post_search_terms' ) as $key ) {
				self::execute( $wpdb->prepare( 'DELETE related FROM %i related JOIN %i owned ON owned.id=related.post_id WHERE owned.wordpress_post_id=%d', $tables[ $key ], $tables['external_posts'], $post_id ) );
			}
			self::execute( $wpdb->prepare( 'DELETE stored_vector FROM %i stored_vector JOIN %i owned ON owned.source_wp_id=stored_vector.source_wp_id WHERE owned.wordpress_post_id=%d', $tables['post_embeddings'], $tables['external_posts'], $post_id ) );
			self::execute( $wpdb->prepare( 'DELETE FROM %i WHERE wordpress_post_id=%d', $tables['external_posts'], $post_id ) );
			self::execute( $wpdb->prepare( 'DELETE FROM %i WHERE wordpress_post_id=%d', $tables['ingestion_drafts'], $post_id ) );
			self::execute( 'COMMIT' );
			unset( $failures[ $failure_key ] );
			wp_clear_scheduled_hook( self::RETRY_HOOK, array( $post_id ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$failures[ $failure_key ] = true;
			if ( ! wp_next_scheduled( self::RETRY_HOOK, array( $post_id ) ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RETRY_HOOK, array( $post_id ) );
			}
		}
		update_option( self::FAILURES_OPTION, $failures, false );
		AI_Usage::invalidate_search_caches();
	}

	/** Warns administrators when cleanup needs a database retry. */
	public static function cleanup_notice(): void {
		if ( current_user_can( 'manage_options' ) && get_option( self::FAILURES_OPTION, array() ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Search metadata cleanup could not finish for a deleted post. The post is hidden from search and cleanup will retry automatically. If this persists, ask the site administrator to check the database and scheduled jobs.', 'ehrman-blog-discovery' ) . '</p></div>';
		}
	}

	/**
	 * Executes a trusted transaction statement or already prepared query.
	 *
	 * @param string|null $sql Internal SQL, or null when wpdb could not prepare it.
	 * @throws RuntimeException On database failure.
	 */
	private static function execute( ?string $sql ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Queries are prepared by this class; transaction statements are fixed.
		if ( null === $sql || false === Database::client()->query( $sql ) ) {
			throw new RuntimeException( 'Search metadata cleanup failed.' );
		}
	}
}
