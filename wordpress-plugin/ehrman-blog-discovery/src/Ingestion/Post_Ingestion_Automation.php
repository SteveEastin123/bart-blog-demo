<?php
/**
 * Opt-in first-publication metadata generation and approval.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use Throwable;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runs one bounded automatic attempt, retaining the manual workflow for recovery. */
final class Post_Ingestion_Automation {
	public const PUBLISHED_META = '_ehrman_metadata_first_publication';
	public const ERROR_META     = '_ehrman_metadata_automatic_error';
	private const ACTOR_META    = '_ehrman_metadata_publisher';

	/** Registers after-save handling, after WordPress has persisted REST post metadata. */
	public static function register(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'after_save' ), 20, 4 );
	}

	/**
	 * Queues first publication only; updates, restores and autosaves never regenerate.
	 *
	 * @param int          $post_id Native post ID.
	 * @param WP_Post      $post Saved post.
	 * @param bool         $update Whether this was an update.
	 * @param WP_Post|null $before Previous saved post.
	 */
	public static function after_save( int $post_id, WP_Post $post, bool $update, ?WP_Post $before ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$actor = get_current_user_id();
		if ( user_can( $actor, 'manage_options' ) && user_can( $actor, 'edit_post', $post_id ) ) {
			update_post_meta( $post_id, self::ACTOR_META, $actor );
		} elseif ( 0 === $actor && null !== $before && 'future' === $before->post_status ) {
			$actor = Database::integer( get_post_meta( $post_id, self::ACTOR_META, true ) );
		}
		if ( null !== $before && in_array( $before->post_status, array( 'publish', 'private', 'trash' ), true ) ) {
			add_post_meta( $post_id, self::PUBLISHED_META, '1', true );
			return;
		}
		if ( 'publish' !== $post->post_status || ! add_post_meta( $post_id, self::PUBLISHED_META, '1', true )
			|| 'automatic' !== Post_Ingestion_Publishing_Settings::mode( $post_id ) ) {
			return;
		}
		if ( ! user_can( $actor, 'manage_options' ) || ! user_can( $actor, 'edit_post', $post_id ) ) {
			update_post_meta( $post_id, self::ERROR_META, __( 'Automatic metadata requires an administrator authorized to edit this post. Create metadata manually.', 'ehrman-blog-discovery' ) );
			return;
		}
		try {
			$service = new Post_Ingestion_Service();
			$result  = $service->queue_automatic( $post, $actor );
			if ( is_wp_error( $result ) ) {
				update_post_meta( $post_id, self::ERROR_META, sanitize_text_field( $result->get_error_message() ) );
				return;
			}
			$scheduled = Post_Ingestion_Queue::schedule( $result );
			if ( is_wp_error( $scheduled ) ) {
				self::fail( $result, __( 'Automatic metadata could not be scheduled. Regenerate metadata manually.', 'ehrman-blog-discovery' ) );
			}
		} catch ( Throwable $error ) {
			update_post_meta( $post_id, self::ERROR_META, __( 'Automatic metadata could not be started. Open Search Metadata to recover.', 'ehrman-blog-discovery' ) );
		}
	}

	/**
	 * Runs the persisted job without impersonating a logged-in administrator.
	 *
	 * @param int $draft_id Queued ingestion record.
	 */
	public static function process( int $draft_id ): void {
		$wpdb  = Database::client();
		$table = Database::tables()['ingestion_drafts'];
		$sql   = $wpdb->prepare( 'UPDATE %i SET automatic_status=%s,updated_at=%s WHERE id=%d AND status=%s AND automatic_status=%s', $table, 'running', current_time( 'mysql', true ), $draft_id, 'queued', 'queued' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with internal identifiers.
		if ( ! is_string( $sql ) || 1 !== $wpdb->query( $sql ) ) {
			return;
		}
		$service = new Post_Ingestion_Service();
		$draft   = $service->draft( $draft_id );
		if ( null === $draft ) {
			return;
		}
		$attempt = Database::integer( $draft['analysis_attempt'] ?? null );
		try {
			if ( ! self::authorized( $draft ) ) {
				self::fail( $draft_id, __( 'Automatic metadata is no longer authorized. Review the publishing mode, permissions and database configuration, then create metadata manually.', 'ehrman-blog-discovery' ) );
				return;
			}
			$post = Post_Ingestion_Post_Source::published_post( $draft );
			if ( is_wp_error( $post ) || null === $post || Post_Ingestion_Post_Source::source_changed( $post, $draft ) ) {
				self::fail( $draft_id, __( 'The post changed before automatic generation. Regenerate metadata from the saved post.', 'ehrman-blog-discovery' ) );
				return;
			}
			$result  = $service->process_queued( $draft_id );
			$current = $service->draft( $draft_id );
			$attempt = Database::integer( $current['analysis_attempt'] ?? null );
			if ( null === $current || is_wp_error( $result ) || null === $result || 'ready' !== $result['status'] ) {
				return;
			}
			$result = $service->approve( $draft_id, true, null, Post_Ingestion_Review_Workflow::revision( $result ), true );
			if ( is_wp_error( $result ) ) {
				$wpdb->update(
					$table,
					array( 'error_message' => sanitize_text_field( $result->get_error_message() ) ),
					array(
						'id'               => $draft_id,
						'automatic_status' => 'running',
						'analysis_attempt' => $attempt,
					)
				);
			}
		} catch ( Throwable $error ) {
			self::fail( $draft_id, __( 'Automatic metadata stopped unexpectedly. Open Search Metadata to recover.', 'ehrman-blog-discovery' ) );
		} finally {
			// Never clear a newer manual recovery attempt or overwrite its result.
			$sql = $wpdb->prepare( "UPDATE %i SET automatic_status=CASE WHEN status='approved' AND embedding_status IN ('complete','not_applicable') THEN 'complete' ELSE 'attention' END,updated_at=%s WHERE id=%d AND automatic_status=%s AND analysis_attempt=%d", $table, current_time( 'mysql', true ), $draft_id, 'running', $attempt );
			if ( is_string( $sql ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with internal identifiers.
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Rechecks permission and configuration immediately before automatic approval.
	 *
	 * @param array<string,mixed> $draft Persisted automatic attempt.
	 */
	public static function authorized( array $draft ): bool {
		$id    = Database::integer( $draft['wordpress_post_id'] ?? null );
		$actor = Database::integer( $draft['created_by'] ?? null );
		clean_user_cache( $actor );
		return $id > 0 && 'running' === ( $draft['automatic_status'] ?? '' )
			&& Post_Ingestion_Settings::database_is_authoritative()
			&& 'automatic' === Post_Ingestion_Publishing_Settings::mode( $id, true )
			&& user_can( $actor, 'manage_options' ) && user_can( $actor, 'edit_post', $id );
	}

	/**
	 * Returns whether automatic work still owns the proposal or vector operation.
	 *
	 * @param array<string,mixed> $draft Ingestion record.
	 */
	public static function active( array $draft ): bool {
		return in_array( $draft['automatic_status'] ?? '', array( 'queued', 'running' ), true );
	}

	/**
	 * Allows explicit manual recovery after a worker has stopped responding.
	 *
	 * @param array<string,mixed> $draft Ingestion record.
	 */
	public static function busy( array $draft ): bool {
		return self::active( $draft ) && ! self::stalled( $draft );
	}

	/**
	 * Uses the persisted activity timestamp, including time waiting for cron.
	 *
	 * @param array<string,mixed> $draft Ingestion record.
	 */
	public static function stalled( array $draft ): bool {
		$time = strtotime( Database::text( $draft['updated_at'] ?? null ) . ' UTC' );
		return self::active( $draft ) && false !== $time && time() - $time >= 10 * MINUTE_IN_SECONDS;
	}

	/**
	 * Keeps a failed automatic attempt visible without affecting approved data.
	 *
	 * @param int    $draft_id Attempt identifier.
	 * @param string $message Safe administrator-facing error.
	 */
	private static function fail( int $draft_id, string $message ): void {
		$wpdb = Database::client();
		$sql  = $wpdb->prepare( 'UPDATE %i SET status=%s,automatic_status=%s,error_message=%s,updated_at=%s WHERE id=%d AND status IN (%s,%s) AND automatic_status IN (%s,%s)', Database::tables()['ingestion_drafts'], 'error', 'attention', $message, current_time( 'mysql', true ), $draft_id, 'queued', 'analyzing', 'queued', 'running' );
		if ( is_string( $sql ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with internal identifiers.
			$wpdb->query( $sql );
		}
	}
}
