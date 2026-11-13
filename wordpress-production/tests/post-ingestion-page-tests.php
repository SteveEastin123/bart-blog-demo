<?php
/**
 * Ingestion page rendering regressions. Fixtures are rolled back; no AI calls.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Post_Ingestion_Page;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Post_Source;
use EhrmanBlogDiscovery\Post_Ingestion_Review_Workflow;
use EhrmanBlogDiscovery\Post_Ingestion_Validator;
use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$page_saved_get = $_GET;
$page_saved_post = $_POST;
$page_saved_request = $_REQUEST;
$page_saved_user = get_current_user_id();
$page_saved_source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
$page_saved_key = getenv( 'OPENAI_API_KEY' );
$page_http_calls = 0;
$page_block_http = static function () use ( &$page_http_calls ) {
	++$page_http_calls;
	return new WP_Error( 'ingestion_page_test', 'Page rendering must not call an API.' );
};
$page_render = static function ( int $id = 0, array $filters = array() ): string {
	$_GET = $id > 0 ? array( 'draft' => (string) $id ) : $filters;
	ob_start();
	try {
		Post_Ingestion_Page::render();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
$page_dom = static function ( string $html ): DOMXPath {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	return new DOMXPath( $document );
};
$page_check_forms = static function ( DOMXPath $dom, array $actions ) use ( $assert_same, $assert ): void {
	$forms = $dom->query( '//form' );
	$found = array();
	foreach ( $forms as $form ) {
		$action = $dom->evaluate( 'string(input[@name="action"]/@value)', $form );
		$found[] = $action;
		$assert_same( 'post', $form->getAttribute( 'method' ), 'Ingestion action stopped using POST.' );
		$assert_same( admin_url( 'admin-post.php' ), $form->getAttribute( 'action' ), 'Ingestion form target changed.' );
		$nonce = $dom->evaluate( 'string(input[@name="_wpnonce"]/@value)', $form );
		$assert( false !== wp_verify_nonce( $nonce, $action ), 'Missing or invalid nonce for ' . $action );
	}
	$assert_same( $actions, $found, 'Unexpected ingestion actions for the current state.' );
};
$page_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$assert( ! empty( $page_admins ), 'An administrator is required for ingestion page tests.' );
$page_admin = (int) $page_admins[0];
$page_notice_key = 'ehrman_ingestion_notice_' . $page_admin;
$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start ingestion page test transaction.' );
add_filter( 'pre_http_request', $page_block_http );
try {
	wp_set_current_user( 0 );
	$assert_same( '', $page_render(), 'Unauthenticated visitors can see ingestion controls.' );
	wp_set_current_user( $page_admin );
	$assert_same( false, has_action( 'admin_post_ehrman_ingestion_analyze' ), 'Retired manual-entry handler is still registered.' );
	$assert( ! is_file( EHRMAN_DISCOVERY_PLUGIN_DIR . 'templates/admin/post-ingestion-new.php' ), 'Retired manual-entry template is still packaged.' );
	foreach ( array( 'create_metadata', 'proposal', 'reanalyze', 'retry_embedding', 'discard' ) as $action ) {
		$assert( false !== has_action( 'admin_post_ehrman_ingestion_' . $action ), 'Metadata history lost its ' . $action . ' handler.' );
	}
	delete_transient( $page_notice_key );
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
	putenv( 'OPENAI_API_KEY=' );
	$html = $page_render();
	$dom = $page_dom( $html );
	$assert_same( 1, $dom->query( '//form[@method="get"]' )->length, 'Worklist filters are missing.' );
	$assert_same( 'attention', $dom->evaluate( 'string(//select[@name="status"]/option[@selected]/@value)' ), 'Worklist does not default to Needs attention.' );
	$assert_same( 'Search Metadata', $dom->evaluate( 'string(//h1)' ), 'Search Metadata heading is incorrect.' );
	$assert_same( admin_url( 'edit.php' ), $dom->evaluate( 'string(//a[text()="Posts"]/@href)' ), 'History no longer links to the normal Posts screen.' );
	$assert_same( 0, $dom->query( '//input[@name="source_wp_id"]|//textarea[@name="post_text"]' )->length, 'Manual post-entry fields are still displayed.' );
	$assert( str_contains( $html, 'Analysis is not configured.' ) && str_contains( $html, 'JSON is the current source of truth.' ), 'Configuration warnings are missing.' );
	$empty = $page_render( 0, array( 'search' => 'no-such-worklist-record-781924' ) );
	$assert( str_contains( $empty, 'No posts match these filters.' ) && ! str_contains( $empty, '<table' ), 'Empty worklist state changed.' );

	$repository = new Post_Ingestion_Repository();
	$vocabulary = $repository->vocabulary();
	$topic_name = $vocabulary['topics'][0]['name'];
	$unsafe = '<img src=x onerror=alert(1)> & "quoted"';
	$proposal = array(
		'description' => 'Explains ' . $unsafe,
		'searchSummary' => 'Examines ' . $unsafe,
		'topics' => array( $topic_name ),
		'topicRationales' => array( array( 'topic' => $topic_name, 'rationale' => $unsafe ) ),
		'secondaryKeywords' => array( 'Existing & keyword', $unsafe ),
		'newSecondaryKeywords' => array( 'New ' . $unsafe ),
		'status' => 'ready',
		'reviewNotes' => array( 'Review ' . $unsafe ),
	);
	$id = $repository->create_draft(
		array(
			'source_wp_id' => 999999991,
			'title' => 'Ingestion page fixture ' . $unsafe,
			'url' => 'https://example.test/ingestion-page/?a=1&b=2',
			'author' => 'Author ' . $unsafe,
			'date_text' => 'October 20, 2026',
			'published_at' => '2026-10-20',
			'post_text' => 'Synthetic page-rendering fixture, never sent for analysis.',
		),
		$repository->vocabulary_hash( $vocabulary ),
		$page_admin
	);
	$assert( ! is_wp_error( $id ), 'Could not insert ingestion page fixture.' );
	$table = $tables['ingestion_drafts'];
	$update_page_draft = static function ( array $values ) use ( $wpdb, $table, $id, $assert ): void {
		$assert( false !== $wpdb->update( $table, $values, array( 'id' => $id ) ), 'Could not update ingestion page fixture.' );
	};
	$update_page_draft( array( 'proposal_json' => wp_json_encode( $proposal ), 'model' => 'test-model', 'input_tokens' => 1234, 'output_tokens' => 56, 'estimated_cost_usd' => 0.0123 ) );
	$html = $page_render( $id );
	$page_check_forms( $page_dom( $html ), array( 'ehrman_ingestion_reanalyze' ) );
	$assert_same( Post_Ingestion_Page::page_url(), $page_dom( $html )->evaluate( 'string(//a[contains(., "Search Metadata")]/@href)' ), 'Draft lost its worklist return link.' );
	$assert( str_contains( $html, 'Retry analysis' ) && ! str_contains( $html, 'Review metadata' ), 'Legacy queued analysis lost its retry control.' );
	$assert( str_contains( $html, '1,234 input tokens, 56 output tokens, approximately $0.0123' ), 'Analysis usage formatting changed.' );
	$update_page_draft( array( 'status' => 'analyzing', 'analysis_started_at' => current_time( 'mysql', true ) ) );
	$assert( str_contains( $page_render( $id ), 'Refresh status' ), 'Running analysis lost its refresh link.' );
	$update_page_draft( array( 'analysis_started_at' => '2000-01-01 00:00:00' ) );
	$html = $page_render( $id );
	$page_check_forms( $page_dom( $html ), array( 'ehrman_ingestion_reanalyze' ) );
	$assert( str_contains( $html, 'Retry analysis' ) && ! str_contains( $html, 'Refresh status' ), 'Stalled analysis recovery controls changed.' );

	foreach ( array( 'ready', 'held' ) as $status ) {
		$proposal['status'] = $status;
		$update_page_draft( array( 'status' => $status, 'proposal_json' => wp_json_encode( $proposal ) ) );
		$html = $page_render( $id );
		$dom = $page_dom( $html );
		$page_check_forms( $dom, array( 'ehrman_ingestion_proposal', 'ehrman_ingestion_reanalyze', 'ehrman_ingestion_discard' ) );
		$assert_same( 0, $dom->query( '//img|//script|//*[@onerror]' )->length, 'Proposal text became executable markup.' );
		$assert_same( $proposal['description'], $dom->evaluate( 'string(//textarea[@name="description"])' ), 'Description did not round-trip safely.' );
		$assert_same( $proposal['searchSummary'], $dom->evaluate( 'string(//textarea[@name="search_summary"])' ), 'Summary did not round-trip safely.' );
		$assert_same( implode( ', ', array_merge( $proposal['secondaryKeywords'], $proposal['newSecondaryKeywords'] ) ), $dom->evaluate( 'string(//tr[th="Keywords"]/td)' ), 'Combined keywords changed.' );
		$assert_same( $topic_name, $dom->evaluate( 'string(//tr[th="Topics"]/td)' ), 'AI-selected topic changed.' );
		$assert_same( 'Summary', $dom->evaluate( 'string(//label[@for="search_summary"])' ), 'Summary label changed.' );
		$assert_same( 0, $dom->query( '//select|//textarea[@name="review_notes"]|//textarea[@name="new_secondary_keywords"]' )->length, 'Removed manual controls remain.' );
		$assert_same( 1, $dom->query( '//button[@value="approve"][@disabled]' )->length, 'JSON-authoritative approval should be disabled.' );
		$assert_same( 0, $dom->query( '//input[@name="approve_new_keywords"]' )->length, 'Separate keyword approval remains.' );
		$assert_same( (string) $id, $dom->evaluate( 'string(//input[@name="draft_id"]/@value)' ), 'Form draft identifier changed.' );
		$assert_same( Post_Ingestion_Review_Workflow::revision( $repository->draft( $id ) ), $dom->evaluate( 'string(//input[@name="revision"]/@value)' ), 'History review lost its proposal revision.' );
		$assert( str_contains( $dom->evaluate( 'string(//form[input[@value="ehrman_ingestion_discard"]]/@onsubmit)' ), 'window.confirm(' ), 'Discard confirmation is missing.' );
		$label = 'ready' === $status ? 'Search metadata is ready for review.' : 'Metadata cannot be approved yet.';
		$assert( str_contains( $html, $label ), 'Missing review-state notice.' );
	}
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
	putenv( 'OPENAI_API_KEY=render-test-only' );
	$proposal['newSecondaryKeywords'] = array();
	$proposal['topicRationales'] = array();
	$update_page_draft( array( 'status' => 'ready', 'proposal_json' => wp_json_encode( $proposal ) ) );
	$dom = $page_dom( $page_render( $id ) );
	$assert_same( 0, $dom->query( '//button[@value="approve"][@disabled]|//input[@name="approve_new_keywords"]|//ul[@class="ehrman-ingestion__rationales"]' )->length, 'Approval or optional fields changed in database-authoritative mode.' );
	$dom = $page_dom( $page_render() );
	$assert_same( 0, $dom->query( '//input[@name="source_wp_id"]|//textarea[@name="post_text"]' )->length, 'Configured worklist still offers manual entry.' );
	$assert( str_contains( $page_render( $id + 1000000 ), 'The requested ingestion record was not found.' ), 'Missing draft notice is absent.' );

	foreach ( array( 'failed', 'ready' ) as $status ) {
		$update_page_draft( array( 'status' => $status, 'proposal_json' => '{invalid', 'error_message' => $unsafe ) );
		$html = $page_render( $id );
		$page_check_forms( $page_dom( $html ), array( 'ehrman_ingestion_reanalyze', 'ehrman_ingestion_discard' ) );
		$assert( str_contains( $html, esc_html( $unsafe ) ) && ! str_contains( $html, 'Review proposal' ), 'Empty/invalid proposal recovery changed.' );
	}
	foreach ( array( 'pending', 'complete', 'not_applicable' ) as $vector_status ) {
		$update_page_draft( array( 'status' => 'approved', 'proposal_json' => wp_json_encode( $proposal ), 'embedding_status' => $vector_status, 'embedding_error' => $unsafe ) );
		$html = $page_render( $id );
		$dom = $page_dom( $html );
		$page_check_forms( $dom, 'pending' === $vector_status ? array( 'ehrman_ingestion_retry_embedding' ) : array() );
		$assert( str_contains( $html, 'Approved enrichment' ) && ! str_contains( $html, 'Review proposal' ), 'Approved records became editable.' );
		$assert_same( 0, $dom->query( '//textarea|//select|//img|//script' )->length, 'Approved display is unsafe or editable.' );
	}
	$history = $page_dom( $page_render( 0, array( 'status' => 'all', 'search' => 'Ingestion page fixture' ) ) );
	$fixture_row = '//table[@class="widefat striped"]/tbody/tr[td[5]/a[contains(@href,"draft=' . $id . '")]]';
	$assert_same( 'View', trim( $history->evaluate( 'string(' . $fixture_row . '/td[5]/a)' ) ), 'Approved history action changed.' );
	$assert_same( 'not applicable', trim( $history->evaluate( 'string(' . $fixture_row . '/td[4])' ) ), 'History vector label changed.' );
	foreach ( array( 'ready' => 'Review and approve', 'held' => 'Review and approve', 'failed' => 'View' ) as $status => $label ) {
		$update_page_draft( array( 'status' => $status ) );
		$history = $page_dom( $page_render() );
		$assert_same( $label, trim( $history->evaluate( 'string(' . $fixture_row . '/td[5]/a)' ) ), 'History action changed for ' . $status );
	}
	foreach ( array( true, false ) as $success ) {
		set_transient( $page_notice_key, array( 'success' => $success, 'message' => $unsafe ), 60 );
		$dom = $page_dom( $page_render( $id ) );
		$notice_class = $success ? 'notice-success' : 'notice-error';
		$assert_same( $unsafe, $dom->evaluate( 'string(//div[@class="notice ' . $notice_class . ' is-dismissible"]/p)' ), 'Action notice changed or was not escaped.' );
		$assert_same( false, get_transient( $page_notice_key ), 'Action notice was not consumed.' );
	}
	// Exercise the actual fallback POST handlers, intercepting only their final redirect.
	$native = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Metadata history source fixture', 'post_author' => $page_admin, 'post_content' => 'Original saved content for the metadata history regression.' ), true );
	$assert( ! is_wp_error( $native ), 'Could not create the saved-post fixture.' );
	$input = ( new Post_Ingestion_Validator() )->validate_submission( Post_Ingestion_Post_Source::input( get_post( $native ) ) );
	$assert( ! is_wp_error( $input ), 'Saved-post fixture was invalid.' );
	$native_draft = $repository->create_draft( $input, $repository->vocabulary_hash( $vocabulary ), $page_admin, $native );
	$assert( ! is_wp_error( $native_draft ), 'Could not create the linked draft.' );
	$fields = array(
		'description' => 'Explains how reviewed metadata enters the search index only after an administrator approves the proposed topics and supporting keywords.',
		'search_summary' => 'Explains how inline approval saves revised search metadata without changing the original WordPress article. Distinguishes approved topics from supporting secondary keywords and preserves validation of both selections. Describes how a failed embedding remains pending for retry after metadata has been safely committed to the discovery index, while the temporary full text is removed from the ingestion record after the administrator approves it.',
		'topics' => array( $topic_name ), 'secondary_keywords' => '', 'new_secondary_keywords' => '', 'status' => 'ready', 'review_notes' => '',
	);
	$valid_proposal = ( new Post_Ingestion_Validator() )->proposal_from_input( $fields );
	$wpdb->update( $table, array( 'status' => 'ready', 'proposal_json' => wp_json_encode( $valid_proposal ) ), array( 'id' => $native_draft ) );
	$pending = $repository->draft( $native_draft );
	$revision = Post_Ingestion_Review_Workflow::revision( $pending );
	$send_action = static function ( string $action, array $values ) use ( $page_notice_key ): array {
		$_POST = array_merge( $values, array( 'action' => $action, '_wpnonce' => wp_create_nonce( $action ) ) );
		$_REQUEST = $_POST;
		$redirect = static function () { throw new RuntimeException( 'history-test-redirect' ); };
		add_filter( 'wp_redirect', $redirect, 0 );
		try { do_action( 'admin_post_' . $action ); }
		catch ( RuntimeException $error ) { if ( 'history-test-redirect' !== $error->getMessage() ) { throw $error; } }
		finally { remove_filter( 'wp_redirect', $redirect, 0 ); }
		return get_transient( $page_notice_key );
	};
	$body = array_merge( $fields, array( 'draft_id' => $native_draft, 'revision' => $revision ) );
	wp_update_post( array( 'ID' => $native, 'post_content' => 'The publisher changed the article after its original analysis.' ) );
	foreach ( array( 'save', 'approve' ) as $intent ) {
		$notice = $send_action( 'ehrman_ingestion_proposal', array_merge( $body, array( 'ingestion_intent' => $intent ) ) );
		$assert_same( false, $notice['success'], 'History accepted a changed source.' );
		$assert( str_contains( $notice['message'], 'changed after analysis' ), 'History did not explain the changed source.' );
		$assert_same( $pending, $repository->draft( $native_draft ), 'Rejected history action mutated the draft.' );
	}
	foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
		wp_update_post( array( 'ID' => $native, 'post_status' => $status ) );
		$notice = $send_action( 'ehrman_ingestion_proposal', array_merge( $body, array( 'ingestion_intent' => 'approve' ) ) );
		$assert_same( false, $notice['success'], 'History approved an unpublished post.' );
		$assert( str_contains( $notice['message'], 'Publish the post' ), 'History lost the publish-first prerequisite.' );
	}
	wp_update_post( array( 'ID' => $native, 'post_status' => 'publish', 'post_content' => $input['post_text'] ) );
	foreach ( array( '', str_repeat( '0', 64 ) ) as $bad_revision ) {
		foreach ( array( 'save', 'approve' ) as $intent ) {
			$notice = $send_action( 'ehrman_ingestion_proposal', array_merge( $body, array( 'revision' => $bad_revision, 'ingestion_intent' => $intent ) ) );
			$assert_same( false, $notice['success'], 'History accepted a missing or stale revision.' );
			$assert_same( $pending, $repository->draft( $native_draft ), 'Revision rejection changed stored metadata.' );
		}
	}
	$notice = $send_action( 'ehrman_ingestion_proposal', array_merge( $body, array(
		'ingestion_intent' => 'save',
		'description' => 'Explains how updated metadata enters the search index only after an administrator approves the proposed topics and supporting keywords.',
		'topics' => array( 'Injected topic' ), 'new_secondary_keywords' => 'Injected keyword', 'review_notes' => 'Injected note',
	) ) );
	$saved_proposal = json_decode( $repository->draft( $native_draft )['proposal_json'], true );
	$assert_same( $valid_proposal['topics'], $saved_proposal['topics'], 'History allowed overriding AI topics.' );
	$assert_same( array(), $saved_proposal['newSecondaryKeywords'], 'History allowed injecting new keywords.' );
	$assert_same( true, $notice['success'], 'Valid history revisions could not be saved.' );
	$notice = $send_action( 'ehrman_ingestion_proposal', array_merge( $body, array( 'ingestion_intent' => 'approve' ) ) );
	$assert_same( false, $notice['success'], 'An old history form replaced newly saved revisions.' );
	wp_update_post( array( 'ID' => $native, 'post_title' => 'Current saved title', 'post_content' => 'Current saved content used by the next metadata run.' ) );
	$notice = $send_action( 'ehrman_ingestion_reanalyze', array( 'draft_id' => $native_draft ) );
	$assert_same( false, $notice['success'], 'Failed foreground generation was reported as successful.' );
	$refreshed = $repository->draft( $native_draft );
	$assert_same( 'error', $refreshed['status'], 'Failed foreground generation was not retained for retry.' );
	$assert_same( 'Current saved title', $refreshed['title'], 'History retried the old title.' );
	$assert_same( false, Post_Ingestion_Post_Source::source_changed( get_post( $native ), $refreshed ), 'History retried an old source snapshot.' );
	$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_wp_id=%d', $tables['external_posts'], $native ) ), 'Rejected history approval changed the live index.' );
	$assert_same( 1, $page_http_calls, 'Regeneration did not run once in the foreground.' );
	// Start from a native published post without accepting submitted text or metadata.
	$new_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Worklist create metadata fixture', 'post_content' => 'Saved article content used by the protected worklist generation action.', 'post_author' => $page_admin ), true );
	$assert( ! is_wp_error( $new_post ), 'Could not create worklist generation fixture.' );
	$notice = $send_action( 'ehrman_ingestion_create_metadata', array( 'post_id' => $new_post, 'post_text' => 'UNTRUSTED OVERRIDE' ) );
	$assert_same( false, $notice['success'], 'Worklist did not return the generation failure.' );
	$created = $repository->latest_for_source_wp_id( $new_post );
	$assert_same( 'error', $created['status'], 'Worklist did not wait for generation.' );
	$assert_same( $new_post, (int) $created['wordpress_post_id'], 'Worklist lost native-post ownership.' );
	$assert_same( false, str_contains( $created['post_text'], 'UNTRUSTED' ), 'Worklist accepted submitted post content.' );
	$assert_same( false, $send_action( 'ehrman_ingestion_create_metadata', array( 'post_id' => $new_post ) )['success'], 'Repeated Create Metadata queued duplicate work.' );
	wp_update_post( array( 'ID' => $new_post, 'post_status' => 'draft' ) );
	$assert_same( false, $send_action( 'ehrman_ingestion_create_metadata', array( 'post_id' => $new_post ) )['success'], 'Worklist accepted an unpublished post.' );
	$assert_same( 2, $page_http_calls, 'Worklist must run one foreground request, without duplicating it.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	remove_filter( 'pre_http_request', $page_block_http );
	wp_cache_delete( $page_notice_key, 'transient' );
	wp_cache_delete( '_transient_' . $page_notice_key, 'options' );
	wp_cache_delete( '_transient_timeout_' . $page_notice_key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_set_current_user( $page_saved_user );
	$_GET = $page_saved_get;
	$_POST = $page_saved_post;
	$_REQUEST = $page_saved_request;
	putenv( false === $page_saved_source ? 'EHRMAN_DISCOVERY_POST_SOURCE' : 'EHRMAN_DISCOVERY_POST_SOURCE=' . $page_saved_source );
	putenv( false === $page_saved_key ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $page_saved_key );
}
echo "Post ingestion page rendering: OK\n";
