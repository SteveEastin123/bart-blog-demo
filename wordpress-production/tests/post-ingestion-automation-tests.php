<?php
/** First-publication automation, using synthetic posts and intercepted API calls only. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Automation as Automation;
use EhrmanBlogDiscovery\Post_Ingestion_Publishing_Settings as Publishing;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Queue;
use EhrmanBlogDiscovery\Post_Ingestion_Worklist;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same ): void {
	$wpdb = Database::client(); $tables = Database::tables();
	$service = new Post_Ingestion_Service();
	$old_user = get_current_user_id(); $old_default = get_option( Publishing::OPTION, false );
	$old_key = getenv( 'OPENAI_API_KEY' ); $old_source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
	$posts = array(); $calls = array( 'analysis' => 0, 'vector' => 0 ); $scenario = 'success'; $current_id = 0;
	$user_id = wp_insert_user( array( 'user_login' => 'automatic-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 30 ), 'role' => 'administrator' ) );
	$assert( ! is_wp_error( $user_id ), 'Could not create automatic publishing test user.' );
	$topic = array_values( array_filter( $service->vocabulary()['topics'], static fn( $row ) => 'Ignore' !== $row['name'] ) )[0]['name'];
	$proposal = array(
		'description' => 'Explains how automatic publishing validates the generated metadata and preserves the existing manual review workflow for publishers.',
		'searchSummary' => 'Explains how automatic publishing validates metadata before approving a new post for search. Describes the selected topic and the retained description, summary, keywords, and audit information. Distinguishes metadata generation from vector creation and preserves manual recovery when either operation fails.',
		'topics' => array( $topic ), 'topicRationales' => array( array( 'topic' => $topic, 'rationale' => 'The post develops this subject throughout its substantive discussion.' ) ),
		'secondaryKeywords' => array(), 'newSecondaryKeywords' => array(), 'status' => 'ready', 'reviewNotes' => array(),
	);
	$http = static function ( $pre, $args, $url ) use ( &$calls, &$scenario, &$current_id, $proposal, $service, $assert ): array|WP_Error {
		if ( str_ends_with( $url, '/embeddings' ) ) {
			++$calls['vector'];
			$active = $service->latest_for_post( $current_id );
			if ( 'manual-recovery' !== $scenario ) {
				$assert( Automation::busy( $active ), 'Automatic ownership ended before vector generation.' );
				$assert( is_wp_error( $service->retry_embedding( (int) $active['id'] ) ), 'Manual retry duplicated the in-flight automatic vector.' );
			}
			if ( 'vector-failure' === $scenario ) return new WP_Error( 'fixture_vector_failure', 'Synthetic vector failure.' );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'model' => Embedding_Service::model_id(), 'data' => array( array( 'index' => 0, 'embedding' => array_fill( 0, Embedding_Service::dimensions(), 0.1 ) ) ), 'usage' => array( 'prompt_tokens' => 50, 'total_tokens' => 50 ) ) ) );
		}
		if ( ! str_ends_with( $url, '/responses' ) ) return new WP_Error( 'unexpected_http', 'Unexpected request in automatic publishing test.' );
		++$calls['analysis'];
		$draft = $service->latest_for_post( $current_id );
		Post_Ingestion_Queue::process( (int) $draft['id'] );
		$assert( is_wp_error( $service->reanalyze( (int) $draft['id'] ) ), 'Manual regeneration raced the automatic worker.' );
		if ( 'failure' === $scenario ) return new WP_Error( 'fixture_timeout', 'Synthetic upstream timeout.' );
		if ( 'source-change' === $scenario ) wp_update_post( array( 'ID' => $current_id, 'post_title' => 'Changed during automatic analysis' ) );
		if ( 'revoke-during-analysis' === $scenario ) update_post_meta( $current_id, Publishing::META, 'manual' );
		if ( 'delete' === $scenario ) wp_delete_post( $current_id, true );
		if ( 'held' === $scenario ) { $proposal['topics'] = array(); $proposal['topicRationales'] = array(); $proposal['status'] = 'held'; $proposal['reviewNotes'] = array( 'No suitable existing topic covers this post.' ); }
		if ( 'ignore' === $scenario ) { $proposal['topics'] = array( 'Ignore' ); $proposal['topicRationales'] = array( array( 'topic' => 'Ignore', 'rationale' => 'This post is solely an administrative announcement.' ) ); }
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'automatic-fixture', 'status' => 'completed', 'output_text' => 'malformed' === $scenario ? '{invalid' : wp_json_encode( $proposal ), 'usage' => array( 'input_tokens' => 100, 'output_tokens' => 100 ) ) ) );
	};
	$create = static function ( string $mode = 'inherit', string $status = 'draft' ) use ( &$posts, $user_id, $assert ): int {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => $status, 'post_author' => $user_id, 'post_title' => 'Automatic metadata regression fixture', 'post_content' => str_repeat( 'This synthetic article explains how new post publishing creates searchable metadata with approval, validation, and a semantic vector. ', 12 ), 'meta_input' => array( Publishing::META => $mode ) ), true );
		$assert( ! is_wp_error( $id ), 'Could not create automatic post fixture.' );
		$posts[] = $id;
		return $id;
	};
	$publish = static function ( int $id ) use ( $assert ): void { $result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true ); $assert( ! is_wp_error( $result ), 'Publication failed.' ); };
	$process = static function ( int $id ) use ( &$current_id, $service, $assert ): array {
		$current_id = $id; $draft = $service->latest_for_post( $id );
		$assert( is_array( $draft ), 'Automatic attempt was not persisted.' );
		Post_Ingestion_Queue::process( (int) $draft['id'] );
		return $service->latest_for_post( $id ) ?? array();
	};
	add_filter( 'pre_http_request', $http, 99, 3 );
	try {
		wp_set_current_user( $user_id ); putenv( 'OPENAI_API_KEY=automatic-placeholder-key' ); putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
		delete_option( Publishing::OPTION ); Publishing::register_setting();
		$assert_same( 'manual', Publishing::default_mode(), 'Fresh installation enabled automatic calls.' );
		update_option( Publishing::OPTION, 'invalid' ); $assert_same( 'manual', Publishing::default_mode(), 'Invalid default enabled automatic calls.' );
		$id = $create(); $publish( $id ); $assert_same( null, $service->latest_for_post( $id ), 'Manual publication queued AI.' );
		update_option( Publishing::OPTION, 'automatic' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Existing post updated' ) );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ); $publish( $id );
		$assert_same( null, $service->latest_for_post( $id ), 'Enabling the default or republishing generated existing metadata.' );
		$id = $create( 'manual' ); $publish( $id ); $assert_same( null, $service->latest_for_post( $id ), 'Manual override was ignored.' );

		// Gutenberg saves mode metadata in the same REST request that publishes the post.
		$id = $create();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
		$request->set_body_params( array( 'status' => 'publish', 'meta' => array( Publishing::META => 'manual' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$assert_same( 200, $response->get_status(), 'REST mode override was rejected.' );
		$assert_same( null, $service->latest_for_post( $id ), 'Publication ran before the REST mode override was saved.' );
		update_option( Publishing::OPTION, 'manual' );
		$id = $create( 'automatic' ); $publish( $id );
		$assert_same( array( 'analysis' => 0, 'vector' => 0 ), $calls, 'Publishing made a synchronous AI call.' );
		$draft = $service->latest_for_post( $id ); $assert_same( 'queued', $draft['automatic_status'], 'Automatic override did not queue.' );
		$assert( false !== wp_next_scheduled( 'ehrman_ingestion_process_draft', array( (int) $draft['id'] ) ), 'No automatic cron event was scheduled.' );
		$assert( is_wp_error( $service->analyze( EhrmanBlogDiscovery\Post_Ingestion_Post_Source::input( get_post( $id ) ), $user_id, $id ) ), 'Manual initial creation duplicated the queued post.' );
		$done = $process( $id );
		$assert_same( 'approved', $done['status'], 'Automatic approval failed: ' . ( $done['error_message'] ?? '' ) );
		$assert_same( 'automatic', $done['approval_mode'], 'Automatic approval was not identified in the audit record.' );
		$assert_same( 'complete', $done['embedding_status'], 'Automatic vector was not created.' );
		$assert_same( 'complete', $done['automatic_status'], 'Automatic workflow remained active.' );
		$assert_same( null, $done['post_text'], 'Approved automatic history retained full post text.' );
		$assert_same( array( 'analysis' => 1, 'vector' => 1 ), $calls, 'Automatic workflow made duplicate requests.' );
		$assert( (float) $done['estimated_cost_usd'] > 0 && (float) $done['embedding_estimated_cost_usd'] > 0, 'Automatic usage was not recorded.' );
		Post_Ingestion_Queue::process( (int) $done['id'] ); wp_update_post( array( 'ID' => $id, 'post_content' => 'Updated after first publication.' ) );
		wp_trash_post( $id ); wp_untrash_post( $id ); $publish( $id );
		$assert_same( array( 'analysis' => 1, 'vector' => 1 ), $calls, 'Duplicate event, update, or restoration repeated paid work.' );

		// Browser-submitted line endings must survive the source guard at generation and approval.
		foreach ( array( "\r\n", "\r", "\n" ) as $newline ) {
			$id = $create( 'automatic' );
			$content = '<p>First unchanged paragraph about publishing metadata.</p>' . $newline . '<p>Second unchanged paragraph about semantic search.</p>';
			wp_update_post( array( 'ID' => $id, 'post_content' => $content ) );
			$assert_same( $content, get_post( $id )->post_content, 'Line-ending fixture was not stored as submitted.' );
			$publish( $id ); $before = $calls;
			$draft = $service->latest_for_post( $id );
			$assert( ! str_contains( $draft['post_text'], "\r" ), 'Queued input did not normalize line endings.' );
			$result = $process( $id );
			$assert_same( 'approved', $result['status'], 'Unedited multiline post failed automatic generation: ' . ( $result['error_message'] ?? '' ) );
			$assert_same( 'complete', $result['embedding_status'], 'Unedited multiline post did not receive a vector.' );
			$assert_same( array( 'analysis' => $before['analysis'] + 1, 'vector' => $before['vector'] + 1 ), $calls, 'Multiline publication duplicated API requests.' );
		}

		$pending_vector = 0;
		foreach ( array( 'failure', 'malformed', 'held', 'source-change', 'revoke-during-analysis', 'vector-failure', 'ignore', 'delete' ) as $scenario ) {
			$id = $create( 'automatic' ); $publish( $id ); $before = $calls; $result = $process( $id );
			$assert_same( $before['analysis'] + 1, $calls['analysis'], 'Unexpected analysis retries for ' . $scenario );
			if ( 'delete' === $scenario ) { $assert_same( array(), $result, 'Deletion recreated an orphan attempt.' ); continue; }
			if ( 'vector-failure' === $scenario ) {
				$assert_same( 'approved', $result['status'], 'Initial vector failure discarded approved metadata.' );
				$assert_same( 'pending', $result['embedding_status'], 'Vector failure is not retryable.' );
				$pending_vector = $id;
			} elseif ( 'ignore' === $scenario ) {
				$assert_same( 'approved', $result['status'], 'Ignore post was not approved.' );
				$assert_same( 'not_applicable', $result['embedding_status'], 'Ignore post requested a vector.' );
			} else {
				$assert( 'approved' !== $result['status'], 'Unsafe automatic approval: ' . $scenario );
				$assert_same( 'attention', $result['automatic_status'], 'Failed attempt is not recoverable: ' . $scenario );
			}
			if ( 'vector-failure' !== $scenario ) $assert_same( $before['vector'], $calls['vector'], 'Unexpected vector call: ' . $scenario );
		}
		$scenario = 'success';
		foreach ( array( 'json', 'revoked-mode', 'revoked-permission', 'missing-key', 'changed-before-start', 'content-changed-before-start', 'unpublished' ) as $guard ) {
			$id = $create( 'automatic' ); $publish( $id ); $before = $calls;
			if ( 'json' === $guard ) putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
			if ( 'revoked-mode' === $guard ) update_post_meta( $id, Publishing::META, 'manual' );
			if ( 'revoked-permission' === $guard ) ( new WP_User( $user_id ) )->set_role( 'editor' );
			if ( 'missing-key' === $guard ) putenv( 'OPENAI_API_KEY=' );
			if ( 'changed-before-start' === $guard ) wp_update_post( array( 'ID' => $id, 'post_title' => 'Edited before the job started' ) );
			if ( 'content-changed-before-start' === $guard ) wp_update_post( array( 'ID' => $id, 'post_content' => '<p>Substantively changed before generation.</p>' ) );
			if ( 'unpublished' === $guard ) wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			$result = $process( $id );
			$assert( 'approved' !== $result['status'], 'Automatic approval bypassed ' . $guard );
			$assert_same( $before, $calls, 'Guard allowed a paid request: ' . $guard );
			( new WP_User( $user_id ) )->set_role( 'administrator' ); wp_set_current_user( 0 ); wp_set_current_user( $user_id );
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' ); putenv( 'OPENAI_API_KEY=automatic-placeholder-key' );
		}

		// A scheduled post retains its administrator's authorization without a logged-in cron user.
		$id = $create( 'automatic' ); wp_update_post( array( 'ID' => $id, 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		wp_set_current_user( 0 ); wp_publish_post( $id );
		$assert( null !== $service->latest_for_post( $id ), 'Scheduled publication lost its persisted authorization.' );
		$result = $process( $id ); $assert_same( 'approved', $result['status'], 'Scheduled automatic approval failed.' );
		wp_set_current_user( $user_id );

		// Stalled queue jobs remain visible and can be recovered through manual generation.
		$id = $create( 'automatic' ); $publish( $id ); $draft = $service->latest_for_post( $id );
		$wpdb->update( $tables['ingestion_drafts'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS ) ), array( 'id' => $draft['id'] ) );
		$assert( Automation::stalled( $service->latest_for_post( $id ) ), 'Stalled automatic queue was not detected.' );
		$failed = ( new Post_Ingestion_Worklist() )->page( array( 'status' => 'failed', 'search' => 'Automatic metadata regression fixture' ) );
		$assert( in_array( (string) $draft['id'], array_column( $failed['rows'], 'id' ), true ), 'Stalled queue is missing from Failed.' );
		$scenario = 'manual-recovery'; $current_id = $id;
		$recovered = $service->reanalyze( (int) $draft['id'] );
		$assert( ! is_wp_error( $recovered ), 'Stalled automatic analysis could not be regenerated manually.' );
		$assert_same( 'ready', $recovered['status'], 'Manual recovery autoapproved without review.' );
		$assert_same( '', $recovered['automatic_status'], 'Manual recovery retained automatic ownership.' );
		$approved = $service->approve( (int) $draft['id'], true );
		$assert( ! is_wp_error( $approved ), 'Manual recovery could not be approved.' );
		$assert_same( 'manual', $approved['approval_mode'], 'Recovered approval was mislabeled.' );
		$current_id = $pending_vector; $draft = $service->latest_for_post( $pending_vector );
		$wpdb->update( $tables['ingestion_drafts'], array( 'automatic_status' => 'running', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS ) ), array( 'id' => $draft['id'] ) );
		$recovered = $service->retry_embedding( (int) $draft['id'] );
		$assert( ! is_wp_error( $recovered ), 'Stalled automatic vector could not be retried manually.' );
		$assert_same( 'complete', $recovered['embedding_status'], 'Retried automatic vector did not complete.' );
		$assert( ! Automation::active( $recovered ), 'Retried vector reactivated a stalled automatic job.' );

		$id = $create();
		( new WP_User( $user_id ) )->set_role( 'editor' ); wp_set_current_user( 0 ); wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
		$request->set_body_params( array( 'meta' => array( Publishing::META => 'automatic' ) ) );
		$assert_same( 403, rest_get_server()->dispatch( $request )->get_status(), 'Nonadministrator changed the automatic publishing preference.' );
		$assert_same( 'inherit', get_post_meta( $id, Publishing::META, true ), 'Rejected mode change was persisted.' );
	} finally {
		remove_filter( 'pre_http_request', $http, 99 );
		foreach ( $posts as $id ) { wp_clear_scheduled_hook( 'publish_future_post', array( $id ) ); wp_delete_post( $id, true ); $wpdb->delete( $tables['ai_usage'], array( 'post_id' => $id ) ); }
		wp_delete_user( $user_id ); wp_set_current_user( $old_user );
		false === $old_default ? delete_option( Publishing::OPTION ) : update_option( Publishing::OPTION, $old_default );
		false === $old_key ? putenv( 'OPENAI_API_KEY' ) : putenv( 'OPENAI_API_KEY=' . $old_key );
		false === $old_source ? putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ) : putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=' . $old_source );
		wp_cache_flush();
	}
	echo "Automatic metadata: defaults, overrides, REST publication, scheduling, permission, duplicate guards, approval, costs, vectors, failures and lifecycle: OK\n";
} )();
