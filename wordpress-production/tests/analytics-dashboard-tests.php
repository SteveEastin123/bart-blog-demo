<?php
/** Unified analytics regression tests. All fixture writes and reset checks roll back. */

use EhrmanBlogDiscovery\AI_Analytics_Exporter;
use EhrmanBlogDiscovery\AI_Analytics_Page;
use EhrmanBlogDiscovery\AI_Analytics_Renderer;
use EhrmanBlogDiscovery\AI_Analytics_Report;
use EhrmanBlogDiscovery\AI_Analytics_Request;
use EhrmanBlogDiscovery\AI_Analytics_Reset_Handler;
use EhrmanBlogDiscovery\AI_Refinements;
use EhrmanBlogDiscovery\AI_Requests;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$marker = 'analytics-regression-' . wp_generate_uuid4();
$filters = array( 'search' => $marker, 'feedback' => 'all', 'date_from' => '', 'date_to' => '', 'zero_results' => '' );
$builder = new AI_Analytics_Report();
$exporter = new AI_Analytics_Exporter();
$saved_get = $_GET;
$saved_user = get_current_user_id();
$baseline_periods = $builder->periods();
$dashboard_dom = static function ( string $markup ): DOMXPath {
	$document = new DOMDocument();
	$previous_errors = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<?xml encoding="UTF-8">' . $markup );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
	}
	return new DOMXPath( $document );
};
$render_dashboard = static function ( array $values, int $page = 1, bool $was_reset = false ): string {
	ob_start();
	try {
		( new AI_Analytics_Renderer() )->render( $values, $page, $was_reset );
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start analytics test transaction.' );
try {
	$insert = static function ( $table, $values ) use ( $wpdb, $assert ) {
		$assert( false !== $wpdb->insert( $table, $values ), 'Analytics fixture insertion failed: ' . $wpdb->last_error );
	};
	$requests = array();
	foreach ( array( 'paid', 'cached', 'failed', 'taxonomy', 'experiment' ) as $kind ) {
		$id = wp_generate_uuid4();
		$requests[ $kind ] = $id;
		$insert( $tables['ai_requests'], array(
			'request_id' => $id, 'created_at' => '2026-10-18 04:00:00',
			'request_type' => 'taxonomy' === $kind ? 'taxonomy' : 'semantic',
			'question' => $marker . ' ' . $kind, 'selected_terms' => '[]',
			'model' => 'test-review', 'prompt_version' => 'experiment' === $kind ? 'hybrid-metadata-1' : 'hybrid-1',
			'result_count' => 'paid' === $kind ? 2 : 0, 'result_recorded' => 'failed' === $kind ? 0 : 1,
			'cache_hit' => 'cached' === $kind ? 1 : 0, 'request_succeeded' => 'failed' === $kind ? 0 : 1,
			'feedback' => 'paid' === $kind ? 1 : ( 'cached' === $kind ? 0 : null ),
		) );
	}
	$usage = static function ( $id, $cost, $input, $output = 0, $cached = false ) use ( $insert, $tables ) {
		$insert( $tables['ai_usage'], array(
			'created_at' => '2026-10-18 04:00:00', 'request_id' => $id, 'model' => 'test-model',
			'estimated_cost_usd' => $cost, 'input_tokens' => $input, 'output_tokens' => $output,
			'total_tokens' => $input + $output, 'cache_hit' => $cached ? 1 : 0, 'request_succeeded' => 1,
		) );
	};
	$review_ids = array();
	foreach ( array( 'paid', 'cached', 'taxonomy', 'experiment' ) as $kind ) {
		$id = wp_generate_uuid4();
		$review_ids[ $kind ] = $id;
		$insert( $tables['ai_refinements'], array(
			'refinement_id' => $id, 'request_id' => $requests[ $kind ], 'created_at' => '2026-10-18 04:00:01',
			'question' => $marker . ' ' . $kind, 'selected_posts' => '[]', 'model' => 'test-review', 'prompt_version' => 'test',
			'cache_hit' => 'cached' === $kind ? 1 : 0, 'request_succeeded' => 1,
		) );
		$usage( $requests[ $kind ], 'paid' === $kind ? 0.001 : ( 'cached' === $kind ? 0 : 9 ), 'cached' === $kind ? 0 : 100, 0, 'cached' === $kind );
		$usage( $id, 'paid' === $kind ? 0.009 : ( 'cached' === $kind ? 0 : 9 ), 'cached' === $kind ? 0 : 200, 'cached' === $kind ? 0 : 30, 'cached' === $kind );
	}
	// A retry must contribute to the same question's total, not create another question.
	$retry = wp_generate_uuid4();
	$insert( $tables['ai_refinements'], array(
		'refinement_id' => $retry, 'request_id' => $requests['paid'], 'created_at' => '2026-10-18 04:00:02',
		'question' => $marker . ' paid', 'selected_posts' => '[]', 'model' => 'test-review', 'prompt_version' => 'test',
		'request_succeeded' => 0, 'error_code' => 'test-failure',
	) );
	$usage( $retry, 0.002, 40, 10 );
	$usage( '', 20, 1000 ); // Index preparation must never enter question costs or resets.
	$orphan = wp_generate_uuid4();
	$usage( $orphan, 10, 500 );
	$insert( $tables['ai_feedback'], array(
		'created_at' => '2026-10-18 04:00:00', 'question' => $marker, 'selected_terms' => '[]',
		'helpful' => 1, 'model' => 'legacy', 'prompt_version' => 'legacy',
	) );

	$report = AI_Requests::analytics( $filters, 1, 0 );
	$ids = array_fill_keys( array_column( $report['rows'], 'request_id' ), true );
	$reviews = $builder->filtered_refinements( AI_Refinements::recent( 0 ), $ids );
	$summary = $builder->summary( $report['rows'], $reviews );
	$assert_same( 3, $report['total'], 'Retired or experimental requests leaked into analytics.' );
	$assert_same( 3, count( $reviews ), 'Retired reviews leaked in or a retry was omitted.' );
	$assert( abs( $summary['total_cost'] - 0.012 ) < 0.0000001, 'Total question cost is incorrect.' );
	$assert( abs( $summary['average_question'] - 0.004 ) < 0.0000001, 'Cached/failed questions were omitted from average cost.' );
	$assert_same( 380, $summary['total_tokens'], 'Question tokens did not include embedding, review and retry.' );
	$assert_same( 2, $summary['cache_hits'], 'Embedding and review cache hits were not retained.' );
	$assert_same( 4, $summary['uncached_stages'], 'Uncached stage totals changed.' );
	foreach ( array( 'average_interpretation', 'average_refinement', 'average_api_call', 'average_calls', 'refinement_rate', 'api_calls' ) as $obsolete_key ) {
		$assert( ! array_key_exists( $obsolete_key, $summary ), 'Unused analytics metric remains: ' . $obsolete_key );
	}
	$assert_same( 1, $summary['zero_results'], 'Failed retrieval was counted as a completed zero-result search.' );
	$assert( abs( $report['helpful_rate'] - 50 ) < 0.00001, 'Helpful rate denominator must exclude unanswered feedback.' );
	$assert_same( 1, AI_Requests::analytics( array_merge( $filters, array( 'feedback' => 'yes' ) ) )['total'], 'Yes filter failed.' );
	$assert_same( 1, AI_Requests::analytics( array_merge( $filters, array( 'feedback' => 'no' ) ) )['total'], 'No filter failed.' );
	$assert_same( 1, AI_Requests::analytics( array_merge( $filters, array( 'feedback' => 'unanswered' ) ) )['total'], 'Unanswered filter failed.' );
	$assert_same( 1, AI_Requests::analytics( array_merge( $filters, array( 'zero_results' => '1' ) ) )['total'], 'Zero-result filter includes incomplete searches.' );
	$assert_same( 3, AI_Requests::analytics( array_merge( $filters, array( 'date_from' => '2026-10-18', 'date_to' => '2026-10-18' ) ) )['total'], 'Eastern midnight boundary failed.' );
	$assert_same( 0, AI_Requests::analytics( array_merge( $filters, array( 'date_to' => '2026-10-17' ) ) )['total'], 'Eastern end-of-day boundary failed.' );
	$page_one = AI_Requests::analytics( $filters, 1, 2 );
	$page_two = AI_Requests::analytics( $filters, 2, 2 );
	$assert_same( 3, $page_two['total'], 'Pagination changed the total count.' );
	$assert_same( 1, count( $page_two['rows'] ), 'Pagination returned the wrong row count.' );
	$assert_same( array(), array_intersect( array_column( $page_one['rows'], 'request_id' ), array_column( $page_two['rows'], 'request_id' ) ), 'Question pages overlap.' );
	$periods = $builder->periods();
	$assert( abs( $periods['All retained']['total_cost'] - $baseline_periods['All retained']['total_cost'] - 0.012 ) < 0.0000001, 'Period totals include archived or index costs.' );

	foreach ( array( 'ask-ai', 'ask-ai-2', 'combined', 'comparison' ) as $view ) {
		$_GET = array( 'view' => $view, 'interface' => 'taxonomy', 'date_from' => '2026-02-30', 'search' => $marker );
		$parsed = ( new AI_Analytics_Request() )->filters();
		$assert( ! isset( $parsed['view'] ) && ! isset( $parsed['interface'] ), 'Legacy URL still selects a method.' );
		$assert_same( '', $parsed['date_from'], 'Invalid calendar date was accepted.' );
		$assert_same( 3, AI_Requests::analytics( $parsed )['total'], 'Legacy analytics URL does not resolve to the unified dashboard.' );
	}

	ob_start();
	( new AI_Analytics_Renderer() )->render( $filters, 1, false );
	$html = ob_get_clean();
	$assert( str_contains( $html, 'Ask AI Analytics' ) && ! str_contains( $html, 'nav-tab-wrapper' ), 'Dashboard still has method tabs.' );
	$assert( ! str_contains( $html, 'Topics and keywords' ) && ! str_contains( $html, 'Side-by-side' ), 'Comparison content remains.' );
	$assert( ! str_contains( $html, $marker . ' taxonomy' ) && ! str_contains( $html, $marker . ' experiment' ), 'Hidden methods appeared in dashboard details.' );
	$assert( str_contains( $html, '1.200' ) && str_contains( $html, '0.400' ), 'Combined and average costs are missing from dashboard.' );
	$dashboard = $dashboard_dom( $html );
	$question_table = '//table[@data-ebd-analytics-questions]';
	$review_table = '//details[summary/strong[text()="AI review details"]]//table';
	$assert_same( 7, $dashboard->query( $question_table . '/thead/tr/th' )->length, 'Question columns changed.' );
	$assert_same( 8, $dashboard->query( $review_table . '/thead/tr/th' )->length, 'Review columns changed.' );
	$assert_same( 3, $dashboard->query( $question_table . '/tbody/tr' )->length, 'Question template dropped rows.' );
	$assert_same( 3, $dashboard->query( $review_table . '/tbody/tr' )->length, 'Review template dropped retries.' );
	$paid_row = $question_table . '/tbody/tr[td[2][contains(., "' . $marker . ' paid")]]';
	$failed_row = $question_table . '/tbody/tr[td[2][contains(., "' . $marker . ' failed")]]';
	$assert_same( 'Oct 18, 2026 12:00 AM EDT', trim( $dashboard->evaluate( 'string(' . $paid_row . '/td[1])' ) ), 'Question timezone display changed.' );
	$assert_same( '1.200¢', trim( $dashboard->evaluate( 'string(' . $paid_row . '/td[3]/strong)' ) ), 'Question total is not displayed first.' );
	$assert_same( 'Embedding: 0.100¢; AI review: 1.100¢', trim( $dashboard->evaluate( 'string(' . $paid_row . '/td[3]/small)' ) ), 'Question cost breakdown changed.' );
	$assert_same( '380', trim( $dashboard->evaluate( 'string(' . $paid_row . '/td[7]/strong)' ) ), 'Question token total changed.' );
	$assert_same( 'Not recorded', trim( $dashboard->evaluate( 'string(' . $failed_row . '/td[4])' ) ), 'Incomplete search result label changed.' );
	foreach ( array( 'WordPress cache', 'Failed; broader results returned.', 'No AI review recorded.', 'Not provided' ) as $label ) {
		$assert( str_contains( $html, $label ), 'Dashboard state is missing: ' . $label );
	}
	$assert_same( 0, $dashboard->query( '//div[@class="tablenav"]' )->length, 'Single-page dashboard unexpectedly has pagination.' );
	$reset_form = '//form[input[@name="action" and @value="ehrman_ai_analytics_reset"]]';
	$nonce = $dashboard->evaluate( 'string(' . $reset_form . '/input[@name="_wpnonce"]/@value)' );
	$assert( false !== wp_verify_nonce( $nonce, 'ehrman_ai_analytics_reset' ), 'Reset form nonce is missing or invalid.' );
	$assert( str_contains( $dashboard->evaluate( 'string(' . $reset_form . '/@onsubmit)' ), 'window.confirm(' ), 'Reset confirmation was removed.' );
	$empty_filters = array_merge( $filters, array( 'search' => $marker . ' "><img src=x onerror=alert(1)>', 'feedback' => 'unanswered', 'zero_results' => '1', 'date_from' => '2026-10-01', 'date_to' => '2026-10-20' ) );
	$empty_html = $render_dashboard( $empty_filters, 1, true );
	$empty_dom = $dashboard_dom( $empty_html );
	$assert( str_contains( $empty_html, 'No requests match these filters.' ) && str_contains( $empty_html, 'No AI reviews match these filters.' ), 'Empty template states are missing.' );
	$assert( str_contains( $empty_html, 'notice-success' ), 'Reset success notice was removed.' );
	$assert_same( $empty_filters['search'], $empty_dom->evaluate( 'string(//input[@name="search"]/@value)' ), 'Search filter was not preserved safely.' );
	$assert_same( 'unanswered', $empty_dom->evaluate( 'string(//select[@name="feedback"]/option[@selected]/@value)' ), 'Feedback selection changed.' );
	$assert_same( 1, $empty_dom->query( '//input[@name="zero_results"][@checked]' )->length, 'Zero-results selection changed.' );
	$assert_same( '2026-10-01', $empty_dom->evaluate( 'string(//input[@name="date_from"]/@value)' ), 'From date changed.' );
	$assert_same( '2026-10-20', $empty_dom->evaluate( 'string(//input[@name="date_to"]/@value)' ), 'To date changed.' );
	$assert_same( 0, $empty_dom->query( '//img|//script' )->length, 'Search filter markup was not escaped.' );

	// Exercise escaping at the real renderer/template boundary without retaining fixture changes.
	$wpdb->query( 'SAVEPOINT analytics_template_escape' );
	try {
		$unsafe_question = $marker . ' <img src=x onerror=alert(1)>';
		$unsafe_source = '<b>source</b>';
		$unsafe_id = 'response-" onmouseover="alert(1)-long-identifier';
		$wpdb->update( $tables['ai_requests'], array( 'question' => $unsafe_question, 'error_code' => '<b>failure</b>' ), array( 'request_id' => $requests['failed'] ) );
		$wpdb->update( $tables['ai_usage'], array( 'model' => $unsafe_source, 'service_tier' => 'priority', 'pricing_version' => '<b>price</b>', 'response_id' => $unsafe_id ), array( 'request_id' => $requests['paid'] ) );
		$wpdb->update( $tables['ai_refinements'], array( 'selected_posts' => wp_json_encode( array( array( 'title' => 'Alpha & Omega' ) ) ) ), array( 'refinement_id' => $review_ids['paid'] ) );
		$escaped_html = $render_dashboard( $filters );
		$escaped_dom = $dashboard_dom( $escaped_html );
		$assert( str_contains( $escaped_html, esc_html( $unsafe_question ) ) && str_contains( $escaped_html, esc_html( '<b>failure</b>' ) ), 'Question or error text was not escaped.' );
		$assert( str_contains( $escaped_html, esc_html( $unsafe_source . ' (priority)' ) ) && str_contains( $escaped_html, esc_html( '<b>price</b>' ) ), 'Model, service tier or pricing display changed.' );
		$assert_same( $unsafe_id, $escaped_dom->evaluate( 'string(' . $paid_row . '/td[6]//code/@title)' ), 'Full response ID was not preserved safely.' );
		$assert_same( substr( $unsafe_id, 0, 14 ) . '…' . substr( $unsafe_id, -7 ), $escaped_dom->evaluate( 'string(' . $paid_row . '/td[6]//code)' ), 'Response ID shortening changed.' );
		$assert( str_contains( $escaped_html, 'Alpha &amp; Omega' ), 'Retained post titles were not escaped.' );
		$assert_same( 0, $escaped_dom->query( '//img|//script|//*[@onmouseover]' )->length, 'Stored analytics text became executable markup.' );
	} finally {
		$wpdb->query( 'ROLLBACK TO SAVEPOINT analytics_template_escape' );
	}

	$stream = fopen( 'php://temp', 'w+' );
	try {
		$exporter->write_questions( $stream, $filters );
		rewind( $stream );
		$headers = fgetcsv( $stream, null, ',', '"', '' );
		$csv = array();
		while ( false !== ( $line = fgetcsv( $stream, null, ',', '"', '' ) ) ) {
			$csv[] = array_combine( $headers, $line );
		}
		$assert_same( 3, count( $csv ), 'Question CSV leaked retired data.' );
		$assert( ! in_array( 'Method', $headers, true ) && ! in_array( 'Topics and keywords', $headers, true ), 'Question CSV retains comparison columns.' );
		$paid = array_values( array_filter( $csv, static fn( $row ) => $row['Request ID'] === $requests['paid'] ) )[0];
		$assert( abs( (float) $paid['Total cost USD'] - 0.012 ) < 0.0000001, 'CSV total omits a stage or retry.' );
		$assert_same( '380', $paid['Total tokens'], 'CSV token total is incorrect.' );
		ftruncate( $stream, 0 );
		rewind( $stream );
		$exporter->write_refinements( $stream, $filters );
		rewind( $stream );
		$review_csv = stream_get_contents( $stream );
		$assert( ! str_contains( $review_csv, $marker . ' taxonomy' ) && ! str_contains( $review_csv, $marker . ' experiment' ), 'Review CSV leaked retired data.' );
		$assert( str_contains( $review_csv, $retry ), 'Review CSV omitted a failed retry.' );
		$wpdb->update( $tables['ai_requests'], array( 'question' => '=1+1 ' . $marker ), array( 'request_id' => $requests['paid'] ) );
		ftruncate( $stream, 0 );
		rewind( $stream );
		$exporter->write_questions( $stream, $filters );
		rewind( $stream );
		$assert( str_contains( stream_get_contents( $stream ), "'=1+1 " . $marker ), 'CSV did not neutralize spreadsheet formulas.' );
	} finally {
		fclose( $stream );
	}

	// Scope filtering must happen before any limit, and totals must not silently stop at 5,000.
	for ( $i = 0; $i < 5001; ++$i ) {
		$insert( $tables['ai_requests'], array(
			'request_id' => wp_generate_uuid4(), 'created_at' => '2026-10-18 04:00:00',
			'request_type' => 'semantic', 'question' => $marker . ' volume', 'selected_terms' => '[]',
			'model' => 'test-review', 'prompt_version' => 'hybrid-1', 'cache_hit' => 1,
		) );
		$insert( $tables['ai_refinements'], array(
			'refinement_id' => wp_generate_uuid4(), 'request_id' => $requests['paid'], 'created_at' => '2026-10-18 04:00:01',
			'question' => $marker . ' volume review', 'selected_posts' => '[]', 'model' => 'test-review', 'prompt_version' => 'test',
			'cache_hit' => 1, 'request_succeeded' => 1,
		) );
	}
	$assert_same( 5004, AI_Requests::analytics( $filters, 1, 0 )['total'], 'Active analytics are still capped at 5,000 requests.' );
	$all_reviews = $builder->filtered_refinements( AI_Refinements::recent( 0 ), $ids );
	$assert_same( 5004, count( $all_reviews ), 'Reviews are still capped at 5,000.' );
	$assert( abs( $builder->summary( $report['rows'], $all_reviews )['total_cost'] - 0.012 ) < 0.0000001, 'Older review costs were omitted after more than 5,000 reviews.' );
	$volume_dom = $dashboard_dom( $render_dashboard( $filters, 2 ) );
	$assert_same( 50, $volume_dom->query( $question_table . '/tbody/tr' )->length, 'Dashboard question page size changed.' );
	$assert_same( 100, $volume_dom->query( $review_table . '/tbody/tr' )->length, 'Dashboard review display limit changed.' );
	$assert_same( '2', trim( $volume_dom->evaluate( 'string(//div[@class="tablenav-pages"]//span[@aria-current="page"])' ) ), 'Dashboard pagination current page changed.' );
	foreach ( $volume_dom->query( '//div[@class="tablenav-pages"]//a' ) as $link ) {
		parse_str( (string) wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), $page_query );
		$assert_same( $marker, $page_query['search'] ?? '', 'Pagination lost the search filter.' );
		$assert_same( 'ehrman-ai-analytics', $page_query['page'] ?? '', 'Pagination changed the administration page.' );
	}

	// Invoke the deletion operation inside this transaction, then roll everything back.
	$delete = new ReflectionMethod( AI_Analytics_Reset_Handler::class, 'delete_test_analytics' );
	$delete->invoke( new AI_Analytics_Reset_Handler() );
	$assert_same( 0, AI_Requests::analytics( array(), 1, 0 )['total'], 'Reset left active-method questions behind.' );
	foreach ( array( 'taxonomy', 'experiment' ) as $kind ) {
		$assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_requests']} WHERE request_id=%s", $requests[ $kind ] ) ), 'Reset removed retired-method requests.' );
		$assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_refinements']} WHERE refinement_id=%s", $review_ids[ $kind ] ) ), 'Reset removed retired-method reviews.' );
		$assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_usage']} WHERE request_id=%s", $review_ids[ $kind ] ) ), 'Reset removed retired-method usage.' );
	}
	$assert_same( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_usage']} WHERE request_id IN (%s,%s,%s)", $requests['paid'], $review_ids['paid'], $retry ) ), 'Reset left active usage behind.' );
	$assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_feedback']} WHERE question=%s", $marker ) ), 'Reset removed legacy feedback.' );
	$assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ai_usage']} WHERE request_id=''" ) > 0, 'Reset removed index preparation.' );
	$assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['ai_usage']} WHERE request_id=%s", $orphan ) ), 'Reset removed unrelated usage.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = $saved_get;
	wp_set_current_user( $saved_user );
}

// A guest cannot render, export or reset analytics. Do not invoke a privileged reset.
$die = static fn() => static function ( $message ) { throw new RuntimeException( (string) $message ); };
add_filter( 'wp_die_handler', $die );
try {
	wp_set_current_user( 0 );
	ob_start();
	AI_Analytics_Page::render();
	$assert_same( '', ob_get_clean(), 'Guest could render analytics.' );
	foreach ( array( 'export_csv', 'reset_test_analytics' ) as $action ) {
		$blocked = false;
		try {
			AI_Analytics_Page::$action();
		} catch ( RuntimeException $error ) {
			$blocked = str_contains( $error->getMessage(), 'not allowed' );
		}
		$assert( $blocked, 'Guest could invoke analytics action: ' . $action );
	}
} finally {
	remove_filter( 'wp_die_handler', $die );
	wp_set_current_user( $saved_user );
}
