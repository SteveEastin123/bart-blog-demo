<?php
/** Administrator publishing preferences use the core settings endpoint without triggering AI. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Post_Ingestion_Publishing_Settings as Publishing;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same ): void {
	$old_user = get_current_user_id();
	$old_default = get_option( Publishing::OPTION, false );
	$wpdb = Database::client(); $tables = Database::tables();
	$before_drafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ingestion_drafts']}" );
	$before_cron = _get_cron_array();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Publishing settings tests require an administrator.' );
	$editor = wp_insert_user( array( 'user_login' => 'publishing-settings-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 30 ), 'role' => 'editor' ) );
	$assert( ! is_wp_error( $editor ), 'Could not create publishing settings editor fixture.' );
	$request = static function ( string $method, $mode = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/settings' );
		if ( 'POST' === $method ) $request->set_body_params( array( Publishing::OPTION => $mode ) );
		return rest_get_server()->dispatch( $request );
	};
	try {
		wp_set_current_user( (int) $admins[0] );
		delete_option( Publishing::OPTION );
		$assert_same( 'manual', $request( 'GET' )->get_data()[Publishing::OPTION] ?? null, 'The REST installation default is not Manual.' );
		foreach ( array( 'automatic', 'automatic', 'manual' ) as $mode ) {
			$response = $request( 'POST', $mode );
			$assert_same( 200, $response->get_status(), 'Authorized publishing mode save failed.' );
			$assert_same( $mode, $response->get_data()[Publishing::OPTION] ?? null, 'Settings response did not confirm the saved mode.' );
			$assert_same( $mode, Publishing::default_mode(), 'Publishing mode did not persist.' );
		}
		foreach ( array( 'inherit', 'invalid', array( 'automatic' ) ) as $invalid ) {
			$assert_same( 400, $request( 'POST', $invalid )->get_status(), 'Invalid publishing mode was accepted.' );
			$assert_same( 'manual', Publishing::default_mode(), 'Invalid input changed the saved mode.' );
		}
		foreach ( array( 0 => 401, $editor => 403 ) as $user => $status ) {
			wp_set_current_user( $user );
			$assert_same( $status, $request( 'GET' )->get_status(), 'Unauthorized user can read publishing settings.' );
			$assert_same( $status, $request( 'POST', 'automatic' )->get_status(), 'Unauthorized user changed publishing settings.' );
			$assert_same( 'manual', Publishing::default_mode(), 'Rejected save changed the mode.' );
		}
		$assert_same( $before_drafts, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ingestion_drafts']}" ), 'Changing the site default generated metadata for existing posts.' );
		$assert_same( $before_cron, _get_cron_array(), 'Changing the site default scheduled background work.' );
	} finally {
		if ( false === $old_default ) delete_option( Publishing::OPTION ); else update_option( Publishing::OPTION, $old_default );
		wp_set_current_user( $old_user );
		if ( ! function_exists( 'wp_delete_user' ) ) require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor );
	}
} )();

echo "Publishing settings autosave: defaults, persistence, enum validation, administrator-only permissions, no queued work: OK\n";
