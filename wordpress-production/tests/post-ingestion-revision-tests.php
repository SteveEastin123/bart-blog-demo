<?php
/** Approved metadata revisions: mocked AI, atomic replacement, and retained history. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Review_Workflow;
use EhrmanBlogDiscovery\Post_Ingestion_Page;
use EhrmanBlogDiscovery\Post_Ingestion_Queue;
use EhrmanBlogDiscovery\Semantic_Index_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $editor_post_id, $editor_draft_id, $approved_id, $ingestion_store, $wpdb, $tables, $block_http ): void {
	$service = new Post_Ingestion_Service();
	$parent = $ingestion_store->draft( $editor_draft_id );
	$base = '/ehrman-discovery/v1/ingestion/post/' . $editor_post_id;
	$send = static function ( string $action, array $data = array() ) use ( $base ) {
		$request = new WP_REST_Request( 'POST', $base . '/' . $action );
		$request->set_body_params( $data );
		return rest_get_server()->dispatch( $request );
	};
	$discard_form = static function ( array $values ): array {
		$saved_post = $_POST; $saved_request = $_REQUEST;
		$key = 'ehrman_ingestion_notice_' . get_current_user_id();
		$redirect = static function () { throw new RuntimeException( 'cancel-test-redirect' ); };
		add_filter( 'wp_redirect', $redirect, 0 );
		try {
			$_POST = array_merge( $values, array( '_wpnonce' => wp_create_nonce( 'ehrman_ingestion_discard' ) ) );
			$_REQUEST = $_POST;
			do_action( 'admin_post_ehrman_ingestion_discard' );
		} catch ( RuntimeException $error ) {
			if ( 'cancel-test-redirect' !== $error->getMessage() ) throw $error;
		} finally {
			remove_filter( 'wp_redirect', $redirect, 0 );
			$_POST = $saved_post; $_REQUEST = $saved_request;
		}
		$notice = get_transient( $key );
		delete_transient( $key );
		return $notice;
	};
	$live = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $approved_id ), ARRAY_A );
	$vector = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $editor_post_id ), ARRAY_A );
	$blob = array_fill( 0, Embedding_Service::dimensions(), 0.0 );
	$blob[0] = 1.0;
	$wpdb->replace( $tables['post_embeddings'], array( 'source_wp_id' => $editor_post_id, 'model' => Embedding_Service::model_id(), 'dimensions' => count( $blob ), 'content_hash' => Semantic_Index_Service::content_hash( $live() ), 'embedding' => pack( 'g*', ...$blob ), 'embedding_norm' => 1, 'updated_at' => current_time( 'mysql', true ) ) );
	$initial_live = $live();
	$initial_vector = $vector();
	$embedding_calls = 0;
	$analysis_calls = 0;
	$mode = 'success';
	$http = static function ( $pre, $args, $url ) use ( &$embedding_calls, &$analysis_calls, &$mode, $blob, $parent, $editor_post_id ): array|WP_Error {
		if ( str_ends_with( $url, '/embeddings' ) ) {
			++$embedding_calls;
			if ( 'fail' === $mode ) return new WP_Error( 'mock_embedding_failure', 'Mock embedding failure.' );
			if ( 'source-change' === $mode ) wp_update_post( array( 'ID' => $editor_post_id, 'post_title' => 'Changed while preparing a vector' ) );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'model' => Embedding_Service::model_id(), 'data' => array( array( 'index' => 0, 'embedding' => $blob ) ), 'usage' => array( 'prompt_tokens' => 50, 'total_tokens' => 50 ) ) ) );
		}
		if ( str_ends_with( $url, '/responses' ) ) {
			++$analysis_calls;
			$proposal = json_decode( $parent['proposal_json'], true );
			$proposal['secondaryKeywords'] = $proposal['newSecondaryKeywords'];
			$proposal['newSecondaryKeywords'] = array();
			$proposal['topicRationales'] = array_map( static fn( $name ) => array( 'topic' => $name, 'rationale' => 'This is a primary subject discussed throughout the fixture post.' ), $proposal['topics'] );
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'revision-fixture', 'status' => 'completed', 'output_text' => wp_json_encode( $proposal ), 'usage' => array( 'input_tokens' => 100, 'output_tokens' => 100 ) ) ) );
		}
		return new WP_Error( 'unexpected_http', 'Unexpected external request in revision test.' );
	};
	$approve = static function ( array $draft, array $changes = array() ) use ( $send ) {
		$proposal = json_decode( $draft['proposal_json'], true );
		return $send( 'approve', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $draft ), 'proposal' => array_merge( array( 'description' => $proposal['description'], 'search_summary' => $proposal['searchSummary'], 'removed_topics' => array(), 'removed_keywords' => array() ), $changes ) ) );
	};
	remove_filter( 'pre_http_request', $block_http );
	add_filter( 'pre_http_request', $http, 10, 3 );
	$saved_get = $_GET;
	try {
		$response = $send( 'edit-metadata' );
		$assert_same( 200, $response->get_status(), 'Could not begin manual revision: ' . wp_json_encode( $response->get_data() ) );
		$revision = $service->latest_for_post( $editor_post_id );
		$revision_id = (int) $revision['id'];
		$assert( $revision_id > $editor_draft_id, 'Manual edit mutated approved history.' );
		$assert_same( $editor_draft_id, (int) $revision['revision_parent_id'], 'Revision lost its parent.' );
		$assert_same( $initial_live, $live(), 'Beginning edits changed live metadata.' );
		$assert_same( $initial_vector, $vector(), 'Beginning edits changed live vector.' );
		$assert( $send( 'edit-metadata' )->get_status() >= 400, 'A duplicate pending revision was created.' );
		$assert_same( 'ehrman_ingestion_review_changed', $service->retry_embedding( $editor_draft_id )->get_error_code(), 'Old approval retried a vector during revision.' );
		$_GET = array( 'draft' => $revision_id );
		ob_start(); Post_Ingestion_Page::render(); $html = ob_get_clean();
		$assert( str_contains( $html, 'Approve Changes' ) && str_contains( $html, 'Cancel Changes' ) && str_contains( $html, 'View previous approved version' ), 'Tools revision controls missing.' );
		$assert( str_contains( $html, 'ehrman_ingestion_prepare_regeneration' ), 'Regeneration does not use the progress screen.' );
		$bad = $send( 'cancel-revision', array( 'revision' => 'stale' ) );
		$assert_same( 409, $bad->get_status(), 'Stale cancellation succeeded.' );
		$assert_same( 409, $send( 'cancel-revision' )->get_status(), 'Cancellation accepted a missing token.' );

		// The conditional DELETE must reject every field that contributes to revision().
		foreach ( array(
			'status' => 'analyzing',
			'proposal_json' => str_replace( '"description"', '"Description"', $revision['proposal_json'] ),
			'taxonomy_version' => str_repeat( 'a', 64 ),
			'analysis_attempt' => (int) $revision['analysis_attempt'] + 1,
			'updated_at' => '2000-01-01 00:00:00',
		) as $field => $value ) {
			$wpdb->update( $tables['ingestion_drafts'], array( $field => $value ), array( 'id' => $revision_id ) );
			$changed = $ingestion_store->draft( $revision_id );
			$assert_same( false, $ingestion_store->delete_draft( $revision ), 'Stale repository deletion accepted changed ' . $field . '.' );
			$assert_same( $changed, $ingestion_store->draft( $revision_id ), 'Rejected deletion changed the pending record.' );
			if ( 'status' === $field ) {
				$assert_same( false, $service->discard( $revision_id, Post_Ingestion_Review_Workflow::revision( $changed ) ), 'Running analysis was discarded.' );
			}
			$wpdb->update( $tables['ingestion_drafts'], array( $field => $revision[ $field ] ), array( 'id' => $revision_id ) );
		}

		// Simulate a save between the service's version check and its DELETE query.
		Post_Ingestion_Queue::schedule( $revision_id );
		$scheduled = wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $revision_id ) );
		$assert( false !== $scheduled, 'Cancellation fixture was not scheduled.' );
		$changed_proposal = json_decode( $revision['proposal_json'], true );
		$changed_proposal['description'] .= ' Preserves newer pending edits.';
		$race_fired = false;
		$concurrent_save = static function ( string $sql ) use ( $wpdb, $tables, $revision_id, $changed_proposal, &$race_fired ): string {
			if ( ! $race_fired && str_starts_with( $sql, 'DELETE FROM `' . $tables['ingestion_drafts'] . '`' ) ) {
				$race_fired = true;
				$wpdb->update( $tables['ingestion_drafts'], array( 'proposal_json' => wp_json_encode( $changed_proposal ) ), array( 'id' => $revision_id ) );
			}
			return $sql;
		};
		add_filter( 'query', $concurrent_save );
		try { $deleted = $service->discard( $revision_id, Post_Ingestion_Review_Workflow::revision( $revision ) ); }
		finally { remove_filter( 'query', $concurrent_save ); }
		$assert( $race_fired, 'The concurrent-save fixture did not reach the DELETE.' );
		$assert_same( false, $deleted, 'A concurrent save was deleted.' );
		$current_pending = $ingestion_store->draft( $revision_id );
		$assert_same( wp_json_encode( $changed_proposal ), $current_pending['proposal_json'], 'Concurrent pending edits were lost.' );
		$assert_same( $scheduled, wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $revision_id ) ), 'Rejected deletion cancelled queued work.' );
		$assert_same( 409, $send( 'cancel-revision', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ) ) )->get_status(), 'Editor cancellation deleted newer saved edits.' );
		foreach ( array( array(), array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ) ) ) as $token ) {
			$notice = $discard_form( array_merge( array( 'draft_id' => $revision_id ), $token ) );
			$assert_same( false, $notice['success'], 'Tools cancellation accepted a missing or stale token.' );
			$assert( str_contains( $notice['message'], 'Reload' ), 'Stale cancellation did not ask the publisher to reload.' );
			$assert_same( $current_pending, $ingestion_store->draft( $revision_id ), 'Tools cancellation removed newer saved edits.' );
		}
		$notice = $discard_form( array( 'draft_id' => $revision_id, 'revision' => Post_Ingestion_Review_Workflow::revision( $current_pending ) ) );
		$assert_same( true, $notice['success'], 'Tools could not cancel the current revision.' );
		$assert_same( null, $ingestion_store->draft( $revision_id ), 'Cancelled draft remains stored.' );
		$assert_same( false, wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $revision_id ) ), 'Successful cancellation retained queued work.' );
		$assert_same( false, $service->discard( $revision_id, Post_Ingestion_Review_Workflow::revision( $current_pending ) ), 'Repeated deletion succeeded.' );
		$assert_same( $parent, $ingestion_store->draft( $editor_draft_id ), 'Cancellation changed approved history.' );

		$revision = $service->edit_metadata( $editor_draft_id );
		$cancel = $send( 'cancel-revision', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ) ) );
		$assert_same( $editor_draft_id, $cancel->get_data()['draft']['id'], 'Cancel did not restore approved display.' );
		$assert_same( $initial_live, $live(), 'Cancel changed live metadata.' );
		$assert_same( $initial_vector, $vector(), 'Cancel changed live vector.' );

		// Failed initial-generation records can have no proposal; their current token still works.
		$empty = $service->edit_metadata( $editor_draft_id );
		$empty_id = (int) $empty['id'];
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => 'failed', 'proposal_json' => null, 'revision_parent_id' => null ), array( 'id' => $empty_id ) );
		$empty = $ingestion_store->draft( $empty_id );
		$notice = $discard_form( array( 'draft_id' => $empty_id, 'revision' => Post_Ingestion_Review_Workflow::revision( $empty ) ) );
		$assert_same( true, $notice['success'], 'A current draft without a proposal could not be discarded.' );

		$revision = $service->edit_metadata( $editor_draft_id );
		$proposal = json_decode( $revision['proposal_json'], true );
		$assert_same( array(), $proposal['newSecondaryKeywords'], 'Previously approved keywords were proposed as new again.' );
		$response = $approve( $revision, array( 'removed_keywords' => $proposal['secondaryKeywords'], 'description' => 'Explains how publishers can revise approved metadata while keeping the original search entry active until the changes are approved.' ) );
		$assert_same( 200, $response->get_status(), 'Manual revision failed: ' . wp_json_encode( $response->get_data() ) );
		$current_id = (int) $response->get_data()['draft']['id'];
		$assert_same( false, $ingestion_store->store_proposal( $revision, wp_json_encode( $proposal ), 'ready' ), 'A late save changed approved history back to pending.' );
		$source = ( new EhrmanBlogDiscovery\Post_Ingestion_Validator() )->validate_submission( EhrmanBlogDiscovery\Post_Ingestion_Post_Source::input( get_post( $editor_post_id ) ) );
		$assert_same( false, $ingestion_store->update_pending_source( $revision, $source ), 'A late regeneration copied full text back into approved history.' );
		$assert_same( null, $ingestion_store->draft( $current_id )['post_text'], 'Approved history retained full post text.' );
		$assert_same( $approved_id, (int) $live()['id'], 'Approval duplicated the search entry.' );
		$assert_same( 0, $embedding_calls, 'Term/description-only edits regenerated an unchanged vector.' );
		$assert_same( $initial_vector, $vector(), 'Reused vector changed.' );
		$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id=%d', $tables['post_keywords'], $approved_id ) ), 'Removed keywords remained linked.' );
		$assert_same( $parent['proposal_json'], $ingestion_store->draft( $editor_draft_id )['proposal_json'], 'Previous approved proposal was overwritten.' );
		$assert( is_wp_error( $service->edit_metadata( $editor_draft_id ) ), 'An obsolete approval could start a revision.' );
		$current_approved = $ingestion_store->draft( $current_id );
		$assert_same( false, $ingestion_store->delete_draft( $current_approved ), 'Deletion removed an approved record.' );
		$assert_same( false, $service->discard( $current_id, Post_Ingestion_Review_Workflow::revision( $current_approved ) ), 'Service discarded approved metadata.' );

		$revision = $service->edit_metadata( $current_id );
		$new_summary = $proposal['searchSummary'] . ' Preserves previous approved versions for comparison.';
		$before = $live(); $before_vector = $vector();
		putenv( 'OPENAI_API_KEY=revision-fixture-not-real' );
		$mode = 'fail';
		$response = $approve( $revision, array( 'search_summary' => $new_summary ) );
		$assert_same( 'ehrman_ingestion_embedding_failed', $response->get_data()['code'] ?? '', 'Failed vector did not prevent replacement.' );
		$assert_same( $before, $live(), 'Failed vector changed metadata.' );
		$assert_same( $before_vector, $vector(), 'Failed vector replaced live vector.' );
		$assert_same( 'ready', $ingestion_store->draft( (int) $revision['id'] )['status'], 'Failed vector approved the revision.' );

		$mode = 'source-change';
		$response = $approve( $revision, array( 'search_summary' => $new_summary ) );
		$assert_same( 'ehrman_ingestion_apply_failed', $response->get_data()['code'] ?? '', 'Concurrent post edit was approved.' );
		$assert_same( $before, $live(), 'Concurrent edit changed metadata.' );
		wp_update_post( array( 'ID' => $editor_post_id, 'post_title' => $parent['title'] ) );
		$mode = 'success';
		// Force a storage failure after relationships have been replaced; the transaction must restore them.
		$fail_write = static function ( string $sql ) use ( $tables ): string {
			return str_starts_with( $sql, 'REPLACE INTO `' . $tables['post_embeddings'] . '`' ) ? 'INVALID REVISION FIXTURE SQL' : $sql;
		};
		$previous_suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_write );
		try { $response = $approve( $revision, array( 'search_summary' => $new_summary ) ); }
		finally { remove_filter( 'query', $fail_write ); $wpdb->suppress_errors( $previous_suppress ); }
		$assert_same( 'ehrman_ingestion_apply_failed', $response->get_data()['code'] ?? '', 'Failed replacement write did not roll back.' );
		$assert_same( $before, $live(), 'Storage failure changed live metadata.' );
		$assert_same( $before_vector, $vector(), 'Storage failure changed live vector.' );
		$response = $approve( $revision, array( 'search_summary' => $new_summary ) );
		$assert_same( 200, $response->get_status(), 'Changed-summary approval failed: ' . wp_json_encode( $response->get_data() ) );
		$assert_same( $new_summary, $live()['search_summary'], 'New summary not applied.' );
		$assert_same( Semantic_Index_Service::content_hash( $live() ), $vector()['content_hash'], 'New vector does not match live summary.' );
		$assert_same( 'complete', $response->get_data()['draft']['embeddingStatus'], 'Approved replacement vector not complete.' );
		$current = $service->latest_for_post( $editor_post_id );
		$response = $send( 'reanalyze', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $current ) ) );
		$assert_same( 200, $response->get_status(), 'Regeneration of approved metadata failed: ' . wp_json_encode( $response->get_data() ) );
		$assert_same( 1, $analysis_calls, 'Regeneration did not make exactly one mocked analysis call.' );
		$assert_same( 'ready', $response->get_data()['draft']['status'], 'Regeneration auto-approved the new proposal.' );
		$assert_same( $new_summary, $live()['search_summary'], 'Regeneration changed live summary before approval.' );
		$assert_same( null, $ingestion_store->draft( (int) $current['id'] )['post_text'], 'History retained full post text.' );
		$assert_same( 0, (int) wp_next_scheduled( 'ehrman_ingestion_process_draft', array( (int) $response->get_data()['draft']['id'] ) ), 'Regeneration scheduled background analysis.' );
		$assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_wp_id=%d', $tables['external_posts'], $editor_post_id ) ), 'Revisions duplicated live metadata.' );
	} finally {
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d AND id<>%d', $tables['ingestion_drafts'], $editor_post_id, $editor_draft_id ) ) as $pending_id ) {
			Post_Ingestion_Queue::cancel( (int) $pending_id );
		}
		$_GET = $saved_get;
		remove_filter( 'pre_http_request', $http );
		add_filter( 'pre_http_request', $block_http );
		putenv( 'OPENAI_API_KEY=' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE wordpress_post_id=%d AND id<>%d', $tables['ingestion_drafts'], $editor_post_id, $editor_draft_id ) );
		$wpdb->delete( $tables['ai_usage'], array( 'post_id' => $editor_post_id ) );
		wp_update_post( array( 'ID' => $editor_post_id, 'post_title' => $parent['title'] ) );
	}
	echo "Approved metadata revisions: edit, stale-tab/atomic cancellation, history, vector reuse/replacement, failure rollback and regeneration: OK\n";
} )();
