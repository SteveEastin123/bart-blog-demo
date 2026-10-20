<?php
/**
 * Ingestion page rendering regressions. Fixtures are rolled back; no AI calls.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Post_Ingestion_Page;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$page_saved_get = $_GET;
$page_saved_user = get_current_user_id();
$page_saved_source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
$page_saved_key = getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
$page_http_calls = 0;
$page_block_http = static function () use ( &$page_http_calls ) {
	++$page_http_calls;
	return new WP_Error( 'ingestion_page_test', 'Page rendering must not call an API.' );
};
$page_render = static function ( int $id = 0 ): string {
	$_GET = $id > 0 ? array( 'draft' => (string) $id ) : array();
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
	delete_transient( $page_notice_key );
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' );
	$html = $page_render();
	$dom = $page_dom( $html );
	$page_check_forms( $dom, array( 'ehrman_ingestion_analyze' ) );
	$assert( str_contains( $html, 'Analysis is not configured.' ) && str_contains( $html, 'JSON is the current source of truth.' ), 'Configuration warnings are missing.' );
	$assert_same( 1, $dom->query( '//input[@type="submit"][@disabled]' )->length, 'Unconfigured analysis should be disabled.' );
	$empty = Template::render( 'admin/post-ingestion/history.php', array( 'rows' => array() ) );
	$assert( str_contains( $empty, 'No ingestion records yet.' ) && ! str_contains( $empty, '<table' ), 'Empty history state changed.' );

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
	$page_check_forms( $page_dom( $html ), array() );
	$assert( str_contains( $html, 'Refresh status' ) && ! str_contains( $html, 'Review proposal' ), 'Queued analysis exposed proposal controls.' );
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
		$assert_same( implode( "\n", $proposal['secondaryKeywords'] ), $dom->evaluate( 'string(//textarea[@name="secondary_keywords"])' ), 'Existing keyword lines changed.' );
		$assert_same( implode( "\n", $proposal['newSecondaryKeywords'] ), $dom->evaluate( 'string(//textarea[@name="new_secondary_keywords"])' ), 'New keyword lines changed.' );
		$assert_same( $proposal['topicRationales'], json_decode( $dom->evaluate( 'string(//input[@name="topic_rationales_json"]/@value)' ), true ), 'Rationale evidence was not preserved.' );
		$assert_same( $topic_name, $dom->evaluate( 'string(//select[@name="topics[]"]/option[@selected]/@value)' ), 'Selected topic changed.' );
		$assert_same( $status, $dom->evaluate( 'string(//select[@name="status"]/option[@selected]/@value)' ), 'Selected decision changed.' );
		$assert_same( 1, $dom->query( '//button[@value="approve"][@disabled]' )->length, 'JSON-authoritative approval should be disabled.' );
		$assert_same( 1, $dom->query( '//input[@name="approve_new_keywords"]' )->length, 'New keywords lost explicit approval.' );
		$assert_same( (string) $id, $dom->evaluate( 'string(//input[@name="draft_id"]/@value)' ), 'Form draft identifier changed.' );
		$assert( str_contains( $dom->evaluate( 'string(//form[input[@value="ehrman_ingestion_discard"]]/@onsubmit)' ), 'window.confirm(' ), 'Discard confirmation is missing.' );
		$label = 'ready' === $status ? 'Search metadata is ready for review.' : 'Search metadata requires review.';
		$assert( str_contains( $html, $label ), 'Missing review-state notice.' );
	}
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=render-test-only' );
	$proposal['newSecondaryKeywords'] = array();
	$proposal['topicRationales'] = array();
	$update_page_draft( array( 'proposal_json' => wp_json_encode( $proposal ) ) );
	$dom = $page_dom( $page_render( $id ) );
	$assert_same( 0, $dom->query( '//button[@value="approve"][@disabled]|//input[@name="approve_new_keywords"]|//ul[@class="ehrman-ingestion__rationales"]' )->length, 'Approval or optional fields changed in database-authoritative mode.' );
	$dom = $page_dom( $page_render() );
	$assert_same( 0, $dom->query( '//input[@type="submit"][@disabled]' )->length, 'Configured analysis should be enabled.' );
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
	$history = $page_dom( $page_render() );
	$fixture_row = '//table[@class="widefat striped"]/tbody/tr[td[2]/span[text()="#999999991"]]';
	$assert_same( 'View', trim( $history->evaluate( 'string(' . $fixture_row . '/td[5]/a)' ) ), 'Approved history action changed.' );
	$assert_same( 'not applicable', trim( $history->evaluate( 'string(' . $fixture_row . '/td[4])' ) ), 'History vector label changed.' );
	foreach ( array( 'ready' => 'Review and approve', 'held' => 'Review required', 'failed' => 'Review' ) as $status => $label ) {
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
	$assert_same( 0, $page_http_calls, 'Ingestion page rendering attempted an API call.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	remove_filter( 'pre_http_request', $page_block_http );
	wp_cache_delete( $page_notice_key, 'transient' );
	wp_cache_delete( '_transient_' . $page_notice_key, 'options' );
	wp_cache_delete( '_transient_timeout_' . $page_notice_key, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_set_current_user( $page_saved_user );
	$_GET = $page_saved_get;
	putenv( false === $page_saved_source ? 'EHRMAN_DISCOVERY_POST_SOURCE' : 'EHRMAN_DISCOVERY_POST_SOURCE=' . $page_saved_source );
	putenv( false === $page_saved_key ? 'EHRMAN_INGESTION_OPENAI_API_KEY' : 'EHRMAN_INGESTION_OPENAI_API_KEY=' . $page_saved_key );
}
echo "Post ingestion page rendering: OK\n";
