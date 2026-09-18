<?php
/**
 * Ask AI analytics reset handling.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Clears test analytics while preserving semantic-index preparation data. */
final class AI_Analytics_Reset_Handler {
	/**
	 * Handles the protected analytics reset request.
	 *
	 * @throws \RuntimeException When a database transaction operation fails.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to reset AI search analytics.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( 'ehrman_ai_analytics_reset' );

		$view = ( new AI_Analytics_Request() )->posted_view();
		$wpdb = Database::client();
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'The analytics reset transaction could not be started.' );
			}
			$this->delete_test_analytics();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'The analytics reset transaction could not be committed.' );
			}
		} catch ( \Throwable ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The test analytics could not be reset. No analytics records were intentionally removed.', 'ehrman-blog-discovery' ) );
		}

		AI_Usage::invalidate_search_caches();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'ehrman-ai-analytics',
					'view'            => $view,
					'analytics_reset' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Deletes question analytics while preserving semantic-index preparation usage.
	 *
	 * @throws \RuntimeException When an analytics table cannot be cleared.
	 */
	private function delete_test_analytics(): void {
		$tables = Database::tables();
		$this->delete_all_rows( $tables['ai_feedback'] );
		$this->delete_all_rows( $tables['ai_refinements'] );
		$this->delete_all_rows( $tables['ai_requests'] );

		$sql = "DELETE FROM {$tables['ai_usage']} WHERE request_id<>''";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally and no external values are used.
		if ( false === Database::client()->query( $sql ) ) {
			throw new \RuntimeException( 'Question usage records could not be deleted.' );
		}
	}

	/**
	 * Deletes every row from one internally named analytics table.
	 *
	 * @param string $table Fully qualified table name.
	 * @throws \RuntimeException When the analytics table cannot be cleared.
	 */
	private function delete_all_rows( string $table ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		if ( false === Database::client()->query( "DELETE FROM {$table}" ) ) {
			throw new \RuntimeException( 'An analytics table could not be cleared.' );
		}
	}
}
