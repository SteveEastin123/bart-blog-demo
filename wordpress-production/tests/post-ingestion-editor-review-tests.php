<?php
/** Inline editor approval, with no real AI calls and complete fixture cleanup. */

use EhrmanBlogDiscovery\Post_Ingestion_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function () use ( $assert, $assert_same, $editor_post_id, $editor_draft_id, $ingestion_store, $proposal, $wpdb, $tables ): void {
	$source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
	$key = getenv( 'OPENAI_API_KEY' );
	$base = '/ehrman-discovery/v1/ingestion/post/' . $editor_post_id;
	$stored = $ingestion_store->draft( $editor_draft_id );
	$approved_id = 0;
	$keyword_label = 'Metadata Approval Fixture ' . $editor_draft_id;
	$http_calls = 0;
	$block_http = static function () use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'fixture_api_blocked', 'No API calls in regression tests.' ); };
	add_filter( 'pre_http_request', $block_http );
	$status = static fn() => rest_get_server()->dispatch( new WP_REST_Request( 'GET', $base ) )->get_data();
	$send = static function ( array $body ) use ( $base ) {
		$request = new WP_REST_Request( 'POST', $base . '/approve' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	};
	$fields = array(
		'description' => 'Explains how reviewed metadata enters the search index only after an administrator approves the proposed topics and supporting keywords.',
		'search_summary' => $proposal['searchSummary'],
		'topics' => $proposal['topics'],
		'secondary_keywords' => '',
		'new_secondary_keywords' => '',
		'status' => 'ready',
		'review_notes' => '',
	);
	try {
		putenv( 'OPENAI_API_KEY=' );
		$revision = $status()['draft']['revision'];
		$assert_same( 64, strlen( $revision ), 'The editor does not have a proposal revision.' );
		$body = array( 'proposal' => $fields, 'revision' => $revision, 'approve_new_keywords' => false );
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
		$assert_same( 'ehrman_ingestion_json_authoritative', $send( $body )->get_data()['code'], 'Inline edits bypassed the JSON lock.' );
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
		$assert_same( true, $status()['databaseAuthoritative'], 'Approval was not enabled by default.' );
		$cases = array(
			array( array_merge( $body, array( 'revision' => '' ) ), 'ehrman_ingestion_review_changed' ),
			array( array_merge( $body, array( 'revision' => str_repeat( '0', 64 ) ) ), 'ehrman_ingestion_review_changed' ),
			array( array_merge( $body, array( 'proposal' => 'not-an-object' ) ), 'ehrman_ingestion_invalid_proposal' ),
		);
		foreach ( array( array( 'description' => '' ), array( 'search_summary' => '' ) ) as $invalid ) {
			$cases[] = array( array_merge( $body, array( 'proposal' => array_merge( $fields, $invalid ) ) ), 'ehrman_ingestion_invalid_proposal' );
		}
		// Topics, keywords and review state come from the stored AI result, not the request.
		foreach ( $cases as list( $submitted, $expected ) ) {
			$response = $send( $submitted );
			$assert_same( $expected, $response->get_data()['code'] ?? '', 'Inline review accepted invalid or unapproved metadata.' );
			$assert_same( $stored['proposal_json'], $ingestion_store->draft( $editor_draft_id )['proposal_json'], 'A failed approval overwrote the saved proposal.' );
		}
		$wpdb->update( $tables['ingestion_drafts'], array( 'analysis_attempt' => 500 ), array( 'id' => $editor_draft_id ) );
		$assert_same( 'ehrman_ingestion_review_changed', $send( $body )->get_data()['code'], 'A stale editor overwrote newer analysis.' );
		$wpdb->update( $tables['ingestion_drafts'], array( 'analysis_attempt' => $stored['analysis_attempt'], 'status' => 'analyzing' ), array( 'id' => $editor_draft_id ) );
		$body['revision'] = $status()['draft']['revision'];
		$assert_same( 'ehrman_ingestion_review_changed', $send( $body )->get_data()['code'], 'Running analysis was approved from the editor.' );
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => 'held' ), array( 'id' => $editor_draft_id ) );
		$body['revision'] = $status()['draft']['revision'];
		$assert_same( 'ehrman_ingestion_invalid_proposal', $send( $body )->get_data()['code'], 'A forged ready status approved a held result.' );
		$with_keyword = $proposal;
		$with_keyword['newSecondaryKeywords'] = array( $keyword_label );
		$with_keyword['reviewNotes'] = array( 'This specific subject has no existing equivalent keyword.' );
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => 'ready', 'proposal_json' => wp_json_encode( $with_keyword ) ), array( 'id' => $editor_draft_id ) );
		$body['revision'] = $status()['draft']['revision'];
		$body['proposal']['topics'] = array( 'Unknown fixture topic' );
		$body['proposal']['new_secondary_keywords'] = 'Client-injected keyword';
		$body['proposal']['search_summary'] = 'Explains how inline approval saves revised search metadata without changing the original WordPress article. Distinguishes approved topics from supporting secondary keywords and preserves validation of both selections. Describes how a failed embedding remains pending for retry after metadata has been safely committed to the discovery index, while the temporary full text is removed from the ingestion record after the administrator approves it.';
		$response = $send( $body );
		$assert_same( 200, $response->get_status(), 'Revised metadata could not be approved in the editor.' );
		$approved = $ingestion_store->draft( $editor_draft_id );
		$approved_id = (int) $approved['approved_post_id'];
		$assert( $approved_id > 0, 'Inline approval did not create a discovery record.' );
		$assert_same( 'approved', $response->get_data()['draft']['status'], 'Inline approval did not return success.' );
		$assert_same( $proposal['topics'], $response->get_data()['draft']['topics'], 'Client overrode the AI-selected topics.' );
		$assert_same( array( $keyword_label ), $response->get_data()['draft']['newSecondaryKeywords'], 'Overall approval did not preserve the AI-proposed keyword.' );
		$assert_same( 'pending', $response->get_data()['draft']['embeddingStatus'], 'A missing key did not leave the vector available for retry.' );
		$assert_same( $fields['description'], $response->get_data()['draft']['description'], 'The publisher description edit was lost.' );
		$assert_same( $body['proposal']['search_summary'], $response->get_data()['draft']['searchSummary'], 'The publisher summary edit was lost.' );
		$assert_same( null, $approved['post_text'], 'Approved inline metadata retained full article text.' );
		$assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_wp_id=%d', $tables['external_posts'], $editor_post_id ) ), 'Inline approval duplicated the post.' );
		$assert( $send( $body )->get_status() >= 400, 'A second approval was accepted.' );
		$assert_same( 0, $http_calls, 'Regression attempted a paid API call.' );
	} finally {
		remove_filter( 'pre_http_request', $block_http );
		if ( $approved_id > 0 ) {
			foreach ( array( 'post_search_terms', 'post_topics', 'post_keywords' ) as $table ) $wpdb->delete( $tables[$table], array( 'post_id' => $approved_id ) );
			$wpdb->delete( $tables['post_embeddings'], array( 'source_wp_id' => $editor_post_id ) );
			$wpdb->delete( $tables['external_posts'], array( 'id' => $approved_id ) );
		}
		$wpdb->update( $tables['ingestion_drafts'], $stored, array( 'id' => $editor_draft_id ) );
		$wpdb->delete( $tables['keywords'], array( 'label' => $keyword_label ) );
		false === $source ? putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ) : putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=' . $source );
		false === $key ? putenv( 'OPENAI_API_KEY' ) : putenv( 'OPENAI_API_KEY=' . $key );
	}
	echo "Inline editor review: validation, revision protection, JSON lock, stored AI selections, approval and pending vectors: OK\n";
} )();
