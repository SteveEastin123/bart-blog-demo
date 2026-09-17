<?php
/**
 * Background queue for post-ingestion analysis.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dispatches ingestion analysis through one-time WordPress cron events. */
final class Post_Ingestion_Queue {
	private const HOOK = 'ehrman_ingestion_process_draft';

	/** Registers the background worker callback. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'process' ), 10, 1 );
	}

	/**
	 * Schedules a draft once, preserving an existing event for the same draft.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return true|WP_Error Scheduling result.
	 */
	public static function schedule( int $draft_id ) {
		$args = array( $draft_id );
		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( time(), self::HOOK, $args, true );
		if ( is_wp_error( $scheduled ) ) {
			if ( 'duplicate_event' === $scheduled->get_error_code() ) {
				return true;
			}
			return $scheduled;
		}
		return true;
	}

	/**
	 * Removes any pending worker event for a discarded or retried draft.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public static function cancel( int $draft_id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $draft_id ) );
	}

	/**
	 * Runs a claimed draft outside the editor request.
	 *
	 * @param int $draft_id Draft identifier.
	 */
	public static function process( int $draft_id ): void {
		( new Post_Ingestion_Service() )->process_queued( $draft_id );
	}
}
