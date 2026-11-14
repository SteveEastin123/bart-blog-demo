<?php
/** Approved metadata revisions: mocked AI, atomic replacement, and retained history. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Review_Workflow;
use EhrmanBlogDiscovery\Post_Ingestion_Page;
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
		$cancel = $send( 'cancel-revision', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ) ) );
		$assert_same( $editor_draft_id, $cancel->get_data()['draft']['id'], 'Cancel did not restore approved display.' );
		$assert_same( $initial_live, $live(), 'Cancel changed live metadata.' );
		$assert_same( $initial_vector, $vector(), 'Cancel changed live vector.' );

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
		$assert_same( false, $ingestion_store->delete_draft( $current_id ), 'Deletion removed an approved record.' );

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
		$_GET = $saved_get;
		remove_filter( 'pre_http_request', $http );
		add_filter( 'pre_http_request', $block_http );
		putenv( 'OPENAI_API_KEY=' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE wordpress_post_id=%d AND id<>%d', $tables['ingestion_drafts'], $editor_post_id, $editor_draft_id ) );
		$wpdb->delete( $tables['ai_usage'], array( 'post_id' => $editor_post_id ) );
		wp_update_post( array( 'ID' => $editor_post_id, 'post_title' => $parent['title'] ) );
	}
	echo "Approved metadata revisions: edit, cancel, history, vector reuse/replacement, failure rollback and regeneration: OK\n";
} )();
