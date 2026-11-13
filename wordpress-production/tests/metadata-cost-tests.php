<?php
/** Cost separation and durable ingestion-attempt history. Own fixtures are removed; all calls are mocked. */

use EhrmanBlogDiscovery\AI_Cost_Report;
use EhrmanBlogDiscovery\AI_Cost_Renderer;
use EhrmanBlogDiscovery\AI_Metadata_Usage;
use EhrmanBlogDiscovery\AI_Analytics_Request;
use EhrmanBlogDiscovery\AI_Analytics_Exporter;
use EhrmanBlogDiscovery\AI_Analytics_Reset_Handler;
use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Analysis_Worker;
use EhrmanBlogDiscovery\Post_Ingestion_Analyzer;

( static function () use ( $wpdb, $tables, $assert, $assert_same ) {
	$marker = 'metadata-cost-' . wp_generate_uuid4();
	$old_key = getenv( 'OPENAI_API_KEY' );
	$old_get = $_GET;
	$report = new AI_Cost_Report();
	$render = static function ( array $values, int $page = 1, string $tab = 'post-metadata' ): string {
		ob_start();
		try {
			( new AI_Cost_Renderer() )->render( $tab, $values, $page );
			return (string) ob_get_contents();
		} finally { ob_end_clean(); }
	};
	$cards = static function ( string $markup ): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try { $document->loadHTML( '<?xml encoding="UTF-8">' . $markup ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$xpath = new DOMXPath( $document );
		$values = array();
		foreach ( $xpath->query( '//div[@data-ebd-analytics-metrics]/div' ) as $card ) {
			$values[ $xpath->evaluate( 'string(span)', $card ) ] = $xpath->evaluate( 'string(strong)', $card );
		}
		return $values;
	};
	$assert_same( array( 'input_tokens' => 150, 'cached_input_tokens' => 30, 'cache_write_tokens' => 0, 'output_tokens' => 20, 'reasoning_tokens' => 5, 'total_tokens' => 170 ), $report->tokens( array(
		array( 'input_tokens' => 100, 'output_tokens' => 20, 'cached_input_tokens' => 30, 'reasoning_tokens' => 5, 'total_tokens' => 120 ),
		array( 'input_tokens' => 50, 'total_tokens' => 0 ),
		array(),
	) ), 'Token totals double-counted cached/reasoning subsets or lost legacy totals.' );
	$filters = array( 'search' => $marker, 'date_from' => '', 'date_to' => '', 'zero_results' => '' );
	$baseline = $report->overview( $filters );
	$store = new Post_Ingestion_Repository();
	$calls = 0;
	$mock = static function ( $preempt, $args, $url ) use ( &$calls, $assert_same ) {
		++$calls;
		$assert_same( 'https://api.openai.com/v1/responses', $url, 'Metadata test used an unexpected API.' );
		if ( 3 === $calls ) { return new WP_Error( 'http_request_failed', 'Mock timeout' ); }
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array(
			'id' => 'mock-cost-' . $calls, 'model' => 'gpt-5.6-sol',
			'usage' => array( 'input_tokens' => 100 * $calls, 'output_tokens' => 20 * $calls ),
			'incomplete_details' => array( 'reason' => 1 === $calls ? 'max_output_tokens' : '' ),
			'output' => array(),
		) ) );
	};
	$native = 0;
	$legacy = 0;
	$boundary = array();
	$bulk_ids = array();
	try {
		$baseline_html = $render( $filters, 1, 'overview' );
		$index_count = count( $report->index( $filters ) );
		foreach ( array( array( 'index_embedding', 1.25, 1 ), array( '', 2.5, 0 ), array( 'index_embedding', 0, 0 ) ) as $bulk ) {
			$assert( false !== $wpdb->insert( $tables['ai_usage'], array(
				'created_at' => '2026-11-01 12:00:00', 'model' => 'text-embedding-3-small',
				'purpose' => $bulk[0], 'estimated_cost_usd' => $bulk[1], 'cost_known' => $bulk[2],
			) ), 'Could not insert historical bulk-cost fixture.' );
			$bulk_ids[] = (int) $wpdb->insert_id;
		}
		$bulk_events = $report->index( $filters );
		$assert_same( $index_count + 3, count( $bulk_events ), 'Bulk history was not retained.' );
		$assert_same( $baseline, $report->overview( $filters ), 'Known, legacy or unknown bulk charges leaked into ongoing costs.' );
		$assert_same( $baseline_html, $render( $filters, 1, 'overview' ), 'Bulk history changed the Overview cards or incomplete-cost warnings.' );
		$assert( ! str_contains( $baseline_html, 'Bulk / index vectors' ) && str_contains( $baseline_html, 'Historical bulk/index preparation charges are excluded.' ), 'Overview does not clearly exclude the retired bulk-cost row.' );
		putenv( 'OPENAI_API_KEY=mock-metadata-cost-key' );
		$native = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $marker, 'post_content' => 'Temporary metadata cost fixture.' ) );
		$assert( $native > 0, 'Could not create native cost fixture.' );
		$post = array( 'source_wp_id' => $native, 'title' => '= ' . $marker . ' <script>alert(1)</script>', 'url' => get_permalink( $native ), 'author' => 'Test', 'date_text' => 'November 11, 2026', 'published_at' => '2026-11-11 12:00:00', 'post_text' => 'A temporary full post used only by mocked analysis.' );
		$draft = $store->create_draft( $post, $store->vocabulary_hash( $store->vocabulary() ), 0, $native );
		$assert( is_int( $draft ), 'Could not create cost draft.' );
		$worker = new Post_Ingestion_Analysis_Worker( $store, new Post_Ingestion_Analyzer() );
		add_filter( 'pre_http_request', $mock, 999, 3 );
		$result = $worker->process_queued( $draft );
		$assert( is_wp_error( $result ), 'Invalid fixture unexpectedly passed validation.' );
		$assert_same( 2, $calls, 'Output-limit retry count changed.' );
		$first_context = AI_Metadata_Usage::context( $store->draft( $draft ) );
		$events = $report->metadata( $filters );
		$summary = $report->summary( $events );
		$assert_same( 2, $summary['calls'], 'Failed output/retry usage was lost.' );
		$assert_same( 1, $summary['runs'], 'A provider retry created an extra generation run.' );
		$assert( abs( $summary['total'] - 0.0024 ) < 0.00000001, 'Retry cost was omitted or counted twice.' );
		$store->queue_analysis( $draft, $store->vocabulary_hash( $store->vocabulary() ) );
		$worker->process_queued( $draft );
		$assert_same( 3, $calls, 'Regeneration did not execute exactly once.' );
		$assert( $first_context['run_id'] !== AI_Metadata_Usage::context( $store->draft( $draft ) )['run_id'], 'Regeneration reused a run ID.' );
		$summary = $report->summary( $report->metadata( $filters ) );
		$assert_same( 2, $summary['runs'], 'Regeneration erased the previous run.' );
		$assert_same( 1, $summary['unknown'], 'Transport failure was presented as free.' );
		remove_filter( 'pre_http_request', $mock, 999 );

		$vector_calls = 0;
		$vector_mock = static function () use ( &$vector_calls ) {
			++$vector_calls;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array(
				'model' => 'text-embedding-3-small', 'usage' => array( 'prompt_tokens' => 50, 'total_tokens' => 50 ),
				'data' => array( array( 'index' => 0, 'embedding' => array_fill( 0, 1 === $vector_calls ? 2 : Embedding_Service::dimensions(), 0.1 ) ) ),
			) ) );
		};
		$index_before = AI_Usage::semantic_index_report();
		add_filter( 'pre_http_request', $vector_mock, 999, 3 );
		try {
			$client = new Embedding_Service( 'mock-key', false, $first_context );
			$assert( is_wp_error( $client->embed( array( 'Fixture summary' ) ) ), 'Invalid vector accepted.' );
			$assert( is_array( $client->embed( array( 'Fixture summary' ) ) ), 'Valid vector rejected.' );
		} finally { remove_filter( 'pre_http_request', $vector_mock, 999 ); }
		$assert_same( $index_before, AI_Usage::semantic_index_report(), 'Post vectors leaked into bulk-index costs.' );
		$events = $report->metadata( $filters );
		$summary = $report->summary( $events );
		$assert_same( 5, $summary['calls'], 'Post-vector retries were lost or duplicated.' );
		$assert_same( 2, $summary['runs'], 'Post vectors were counted as extra metadata runs.' );
		$assert( abs( $summary['vectors'] - 0.000002 ) < 0.000000001, 'Embedding cost separation is wrong.' );
		$overview = $report->overview( $filters );
		$assert_same( $baseline['search'], $overview['search'], 'Ingestion changed Ask AI totals.' );
		$assert_same( $bulk_events, $report->index( $filters ), 'Ingestion or Overview changed bulk-vector history.' );
		$assert( abs( $overview['total'] - $baseline['total'] - $summary['total'] ) < 0.00000001, 'Combined cost double-counted an event.' );
		$assert_same( $overview['search']['total_cost'] + $overview['metadata']['total'], $overview['total'], 'Overview includes costs outside its two activities.' );
		$overview_html = $render( $filters, 1, 'overview' );
		$assert_same( array(
			'Total estimated cost' => AI_Cost_Renderer::money( $overview['total'] ),
			'Ask AI cost' => AI_Cost_Renderer::money( $overview['search']['total_cost'] ),
			'Post Metadata cost' => AI_Cost_Renderer::money( $overview['metadata']['total'] ),
			'Questions' => number_format_i18n( $overview['search']['questions'] ),
			'Metadata generation runs' => number_format_i18n( $overview['metadata']['runs'] ),
		), $cards( $overview_html ), 'Overview card labels, order or accounting changed.' );
		$assert( str_contains( $overview_html, 'Totals and averages are incomplete' ), 'Overview lost the metadata unknown-cost warning.' );
		$assert( abs( $summary['average'] - $summary['total'] / 2 ) < 0.00000001, 'Average did not include failed generation runs.' );
		$tokens = $report->tokens( $events );
		$assert_same( 460, $tokens['total_tokens'], 'Token summary omitted retries or post embeddings.' );
		$assert_same( 400, $tokens['input_tokens'], 'Input tokens were not scoped to metadata and post embeddings.' );
		$expected_cards = array(
			'Total estimated cost' => '$0.0024',
			'Average cost per generation run' => '0.120' . html_entity_decode( '&cent;', ENT_QUOTES, 'UTF-8' ),
			'Generation runs' => '2',
		);
		$html = $render( $filters );
		$assert_same( $expected_cards, $cards( $html ), 'Metadata cards have incorrect labels, order, totals or currency precision.' );
		$assert_same( $expected_cards, $cards( $render( $filters, 2 ) ), 'Card totals were limited to the table page.' );
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try { $document->loadHTML( '<?xml encoding="UTF-8">' . $html ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$xpath = new DOMXPath( $document );
		$details = $xpath->query( '//table[@data-ebd-metadata-runs]//details//li' );
		$assert_same( 5, $details->length, 'Expandable details lost a metadata or vector attempt.' );
		$assert_same( 3, $xpath->query( '//table[@data-ebd-metadata-runs]//details//li/strong[text()="Metadata generation"]' )->length, 'Metadata generation details were removed with the cards.' );
		$assert_same( 2, $xpath->query( '//table[@data-ebd-metadata-runs]//details//li[strong="Post vector" and contains(., "$0.00000100")]' )->length, 'Vector detail costs lost their precision or were removed with the cards.' );
		$assert( strpos( $html, 'Totals and averages are incomplete' ) < strpos( $html, 'data-ebd-metadata-runs' ), 'Unknown-cost warning is hidden below the detail table.' );
		$assert( str_contains( $html, 'data-ebd-metadata-tokens' ) && str_contains( $html, '400 in; 0 cached; 0 written; 60 out; 0 reasoning' ), 'Expandable token summary is missing or incorrect.' );
		$empty_html = $render( array_merge( $filters, array( 'search' => $marker . '-no-match' ) ) );
		$assert_same( array( '$0.0000', '0.000' . html_entity_decode( '&cent;', ENT_QUOTES, 'UTF-8' ), '0' ), array_values( $cards( $empty_html ) ), 'Empty metadata filters did not return three zero cards.' );
		$assert( str_contains( $empty_html, 'No metadata runs match these filters.' ), 'Empty-state detail table was lost.' );

		wp_delete_post( $native, true );
		$assert_same( null, $store->draft( $draft ), 'Deletion did not remove the native draft.' );
		$assert_same( $events, $report->metadata( $filters ), 'Deleting a post erased its cost history.' );

		// Legacy import is idempotent and snapshots only the surviving totals.
		$legacy = $store->create_draft( $post, 'fixture', 0 );
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => 'ready', 'analysis_attempt' => 2, 'input_tokens' => 100, 'output_tokens' => 20, 'estimated_cost_usd' => 0.03, 'model' => 'gpt-5.6-sol', 'embedding_status' => 'complete', 'embedding_model' => 'text-embedding-3-small', 'embedding_input_tokens' => 50, 'embedding_estimated_cost_usd' => 0.000001 ), array( 'id' => $legacy ) );
		AI_Metadata_Usage::import_history();
		$imported = $report->metadata( $filters );
		AI_Metadata_Usage::import_history();
		$assert_same( $imported, $report->metadata( $filters ), 'Repeated upgrade duplicated historical costs.' );
		$assert_same( 2, $report->summary( $imported )['historical'], 'Historical snapshots were not identified.' );
		// Ignore posts never required a vector; do not manufacture a failed API call.
		$wpdb->delete( $tables['ai_usage'], array( 'run_id' => AI_Metadata_Usage::context( $store->draft( $legacy ) )['run_id'], 'purpose' => 'post_embedding' ) );
		$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'not_applicable', 'embedding_model' => '', 'embedding_input_tokens' => 0, 'embedding_estimated_cost_usd' => 0 ), array( 'id' => $legacy ) );
		$before_ignore = $report->metadata( $filters );
		AI_Metadata_Usage::import_history();
		$assert_same( $before_ignore, $report->metadata( $filters ), 'Ignore post created a fictitious vector charge or unknown-cost warning.' );
		// A real attempted call with missing usage must still remain unknown.
		$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'pending' ), array( 'id' => $legacy ) );
		AI_Metadata_Usage::import_history();
		$assert_same( $report->summary( $before_ignore )['unknown'] + 1, $report->summary( $report->metadata( $filters ) )['unknown'], 'A genuine legacy pending vector was treated as free.' );

		$unknown_before = $report->summary( $report->metadata( $filters ) )['unknown'];
		$missing_usage = static function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'model' => 'gpt-5.6-sol', 'usage' => array(), 'output' => array() ) ) );
		};
		add_filter( 'pre_http_request', $missing_usage, 999, 3 );
		try {
			( new Post_Ingestion_Analyzer() )->analyze( $post, $store->vocabulary(), 'fixture', 0, $first_context );
			( new Embedding_Service( 'mock-key', false, $first_context ) )->embed( array( 'Fixture summary' ) );
		} finally { remove_filter( 'pre_http_request', $missing_usage, 999 ); }
		$assert_same( $unknown_before + 2, $report->summary( $report->metadata( $filters ) )['unknown'], 'Empty usage was presented as a known zero-cost call.' );

		// Fall-back DST day is 25 hours; use an exclusive next-local-midnight bound.
		$boundary = array( 'run_id' => wp_generate_uuid4(), 'post_title' => $marker . '-boundary', 'post_id' => 0 );
		foreach ( array( '2026-11-01 03:59:59', '2026-11-01 04:00:00', '2026-11-02 04:59:59', '2026-11-02 05:00:00' ) as $date ) {
			AI_Metadata_Usage::record( array( 'model' => 'gpt-5.6-sol', 'estimated_cost_usd' => 0.01 ), array_merge( $boundary, array( 'created_at' => $date ) ), 'metadata_generation', true, '', true );
		}
		$assert_same( 2, count( $report->metadata( array( 'date_from' => '2026-11-01', 'date_to' => '2026-11-01', 'search' => $marker . '-boundary' ) ) ), 'Eastern date filtering mishandled DST midnight.' );
		$filtered_cards = $cards( $render( array_merge( $filters, array( 'date_from' => '2026-11-01', 'date_to' => '2026-11-01', 'search' => $marker . '-boundary' ) ) ) );
		$assert_same( '$0.0200', $filtered_cards['Total estimated cost'], 'Card totals ignored the date or post-title filter.' );
		$assert_same( '1', $filtered_cards['Generation runs'], 'Filtered attempts were counted as separate runs.' );
		$assert_same( array(), $report->metadata( array( 'search' => "%' OR 1=1 --" ) ), 'Title filtering was not escaped.' );

		$stream = fopen( 'php://temp', 'w+' );
		( new AI_Analytics_Exporter() )->write_metadata( $stream, $filters );
		rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream );
		$assert( str_contains( $csv, '"Cost known"' ) && str_contains( $csv, "'= " . $marker ), 'Metadata export lacks unknown-cost markers or formula protection.' );
		ob_start(); ( new AI_Cost_Renderer() )->render( 'post-metadata', $filters, 1 ); $html = ob_get_clean();
		$assert( str_contains( $html, 'Incomplete usage' ) && str_contains( $html, 'Historical snapshot' ), 'Metadata display hides incomplete history.' );
		$assert( ! str_contains( $html, '<script>alert(1)</script>' ), 'Post title was not escaped.' );
		$_GET = array(); $assert_same( 'overview', ( new AI_Analytics_Request() )->tab(), 'Overview is not the default.' );
		$_GET = array( 'view' => 'ask-ai-2' ); $assert_same( 'ask-ai', ( new AI_Analytics_Request() )->tab(), 'Old search bookmarks no longer reach Ask AI.' );
		$_GET = array( 'tab' => 'post-metadata' ); $assert_same( 'post-metadata', ( new AI_Analytics_Request() )->tab(), 'Metadata tab was not selected.' );
	} finally {
		remove_filter( 'pre_http_request', $mock, 999 );
		// Lifecycle cleanup owns its transaction; do not rely on an outer rollback.
		if ( $native > 0 ) {
			wp_delete_post( $native, true );
			$wpdb->delete( $tables['ai_usage'], array( 'post_id' => $native ) );
		}
		if ( $legacy > 0 ) { $wpdb->delete( $tables['ingestion_drafts'], array( 'id' => $legacy ) ); }
		if ( isset( $boundary['run_id'] ) ) { $wpdb->delete( $tables['ai_usage'], array( 'run_id' => $boundary['run_id'] ) ); }
		foreach ( $bulk_ids as $bulk_id ) { $wpdb->delete( $tables['ai_usage'], array( 'id' => $bulk_id ) ); }
		$_GET = $old_get;
		false === $old_key ? putenv( 'OPENAI_API_KEY' ) : putenv( 'OPENAI_API_KEY=' . $old_key );
	}
	echo "AI costs: separated totals, retries, unknown usage, deleted posts, historical import, DST and CSV: OK\n";
} )();
