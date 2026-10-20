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

/** Clears active-method analytics without deleting archived method history. */
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
					'analytics_reset' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Deletes only active-method questions and linked review/usage records.
	 *
	 * @throws \RuntimeException When an analytics table cannot be cleared.
	 */
	private function delete_test_analytics(): void {
		$tables = Database::tables();
		$scope  = AI_Analytics_Scope::where();
		// Delete dependents before parents, within handle()'s transaction.
		$queries = array(
			"DELETE u FROM {$tables['ai_usage']} u JOIN {$tables['ai_refinements']} f ON f.refinement_id=u.request_id JOIN {$tables['ai_requests']} r ON r.request_id=f.request_id WHERE {$scope}",
			"DELETE u FROM {$tables['ai_usage']} u JOIN {$tables['ai_requests']} r ON r.request_id=u.request_id WHERE {$scope}",
			"DELETE f FROM {$tables['ai_refinements']} f JOIN {$tables['ai_requests']} r ON r.request_id=f.request_id WHERE {$scope}",
			"DELETE r FROM {$tables['ai_requests']} r WHERE {$scope}",
		);
		foreach ( $queries as $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internally generated table names and fixed active-method predicate; no request values.
			if ( false === Database::client()->query( $sql ) ) {
				throw new \RuntimeException( 'Active Ask AI analytics could not be deleted.' );
			}
		}
	}
}
