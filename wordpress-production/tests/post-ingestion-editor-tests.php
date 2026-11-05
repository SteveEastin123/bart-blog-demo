<?php
/** Editor REST and saved-source contracts, using the parent suite's temporary post. */

use EhrmanBlogDiscovery\Post_Ingestion_Editor;
use EhrmanBlogDiscovery\Post_Ingestion_Post_Source;
use EhrmanBlogDiscovery\Post_Ingestion_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function () use ( $assert, $assert_same, $editor_post_id, $editor_user_id ): void {
	$server = rest_get_server();
	$routes = $server->get_routes();
	$pattern = '/ehrman-discovery/v1/ingestion/post/(?P<post_id>\d+)';
	$base = '/ehrman-discovery/v1/ingestion/post/' . $editor_post_id;
	$actions = array( '' => 'status', '/analyze' => 'analyze', '/reanalyze' => 'reanalyze', '/approve' => 'approve', '/retry-embedding' => 'retry_embedding' );
	foreach ( $actions as $suffix => $callback ) {
		$endpoint = $routes[ $pattern . $suffix ][0] ?? array();
		$assert_same( array( Post_Ingestion_REST_Controller::class, $callback ), $endpoint['callback'] ?? null, 'The ingestion route has the wrong owner.' );
		$assert_same( array( Post_Ingestion_REST_Controller::class, 'permission' ), $endpoint['permission_callback'] ?? null, 'An ingestion route lost its permission callback.' );
		$assert( ! empty( $endpoint['methods'][ '' === $suffix ? 'GET' : 'POST' ] ), 'An ingestion route changed its HTTP method.' );
	}
	$assert( false !== has_action( 'enqueue_block_editor_assets', array( Post_Ingestion_Editor::class, 'enqueue_assets' ) ), 'Editor assets are no longer registered.' );
	$assert_same( false, has_action( 'rest_api_init', array( Post_Ingestion_Editor::class, 'register_routes' ) ), 'REST registration still depends on the editor UI.' );
	$status = $server->dispatch( new WP_REST_Request( 'GET', $base ) );
	$assert_same( 200, $status->get_status(), 'The administrator could not load the editor status route.' );
	$assert_same( null, $status->get_data()['draft'], 'A new post unexpectedly has ingestion data.' );
	$assert_same( 'no-store', $status->get_headers()['Cache-Control'] ?? '', 'Editor status may be cached.' );
	foreach ( array( '/reanalyze', '/approve', '/retry-embedding' ) as $suffix ) {
		$response = $server->dispatch( new WP_REST_Request( 'POST', $base . $suffix ) );
		$assert_same( 404, $response->get_status(), 'A missing ingestion record lost its REST error status.' );
		$assert_same( 'ehrman_ingestion_missing_draft', $response->get_data()['code'], 'Missing-record error code changed.' );
	}
	$invalid = $server->dispatch( new WP_REST_Request( 'GET', '/ehrman-discovery/v1/ingestion/post/0' ) );
	$assert_same( 400, $invalid->get_status(), 'Invalid post IDs bypassed route validation.' );
	try {
		wp_update_post( array( 'ID' => $editor_post_id, 'post_status' => 'draft' ) );
		foreach ( array( '/analyze', '/reanalyze', '/approve' ) as $suffix ) {
			$response = $server->dispatch( new WP_REST_Request( 'POST', $base . $suffix ) );
			$assert_same( 409, $response->get_status(), 'An unpublished post bypassed the workflow guard.' );
			$assert_same( 'ehrman_ingestion_unpublished', $response->get_data()['code'], 'Unpublished-post error code changed.' );
		}
	} finally {
		wp_update_post( array( 'ID' => $editor_post_id, 'post_status' => 'publish' ) );
	}

	$user = new WP_User( $editor_user_id );
	try {
		foreach ( array( 'editor', 'anonymous' ) as $role ) {
			$user->set_role( 'editor' );
			wp_set_current_user( 0 );
			if ( 'editor' === $role ) {
				wp_set_current_user( $editor_user_id );
				$assert( current_user_can( 'edit_post', $editor_post_id ), 'Non-admin fixture cannot edit its own post.' );
			}
			foreach ( $actions as $suffix => $callback ) {
				$response = $server->dispatch( new WP_REST_Request( '' === $suffix ? 'GET' : 'POST', $base . $suffix ) );
				$assert_same( 403, $response->get_status(), 'An ingestion route allowed a non-administrator.' );
				$assert_same( 'ehrman_ingestion_forbidden', $response->get_data()['code'], 'Permission error contract changed.' );
			}
		}
	} finally {
		$user->set_role( 'administrator' );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor_user_id );
	}

	$post = get_post( $editor_post_id );
	$assert( $post instanceof WP_Post, 'Saved-post fixture is missing.' );
	$input = Post_Ingestion_Post_Source::input( $post );
	$assert_same( $editor_post_id, $input['source_wp_id'], 'Saved WordPress ID changed.' );
	$assert_same( get_permalink( $post ), $input['url'], 'Saved canonical URL changed.' );
	$assert_same( get_the_author_meta( 'display_name', $editor_user_id ), $input['author'], 'Saved author changed.' );
	$assert( ! str_contains( $input['post_text'], '<p>' ) && str_contains( $input['post_text'], 'complete text' ), 'Block-to-text conversion changed.' );
	$draft = $input;
	$draft['published_at'] = $input['date'] . ' 00:00:00';
	$assert_same( false, Post_Ingestion_Post_Source::source_changed( $post, $draft ), 'Unchanged source requires reanalysis.' );
	foreach ( array( 'source_wp_id', 'title', 'url', 'author', 'published_at', 'post_text' ) as $field ) {
		$changed = $draft;
		$changed[ $field ] = 'source_wp_id' === $field ? $editor_post_id + 1 : 'changed fixture';
		$assert_same( true, Post_Ingestion_Post_Source::source_changed( $post, $changed ), 'A changed source field was ignored: ' . $field );
	}
	echo "Post-editor contracts: five protected routes, safe status, admin-only access and saved-source comparison: OK\n";
} )();
