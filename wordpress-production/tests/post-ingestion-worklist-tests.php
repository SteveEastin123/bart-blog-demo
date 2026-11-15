<?php
/**
 * Metadata worklist query tests. Synthetic fixtures roll back; no API calls.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\Post_Ingestion_Worklist;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start worklist test transaction.' );
$worklist = new Post_Ingestion_Worklist();
$worklist_posts = array();
try {
	for ( $i = 0; $i < 103; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => sprintf( 'Worklist pagination fixture %03d', $i ), 'post_date' => '2026-11-01 12:00:00', 'post_date_gmt' => '2026-11-01 12:00:00' ), true );
		$assert( ! is_wp_error( $id ), 'Could not create pagination fixture.' );
		$worklist_posts[] = $id;
	}
	$assert_same( 'all', Post_Ingestion_Worklist::filters( array() )['status'], 'Worklist does not default to All.' );
	$filters = array( 'status' => 'attention', 'search' => 'Worklist pagination fixture' );
	$first = $worklist->page( $filters );
	$assert_same( 103, $first['total'], 'New published posts did not appear without ingestion history.' );
	$assert_same( 25, count( $first['rows'] ), 'Worklist did not limit rows to 25.' );
	$assert_same( 5, $first['pages'], 'Worklist page count is incorrect.' );
	$seen = array();
	for ( $page = 1; $page <= 5; ++$page ) {
		$result = $worklist->page( array_merge( $filters, array( 'paged' => $page ) ) );
		foreach ( $result['rows'] as $row ) {
			$assert_same( 'not_generated', $row['workflow_status'], 'Missing metadata has the wrong status.' );
			$assert( ! array_intersect_key( $row, array_flip( array( 'post_content', 'post_text', 'proposal_json' ) ) ), 'Worklist loaded full content.' );
			$assert( ! in_array( $row['id'], $seen, true ), 'Pagination repeated a row.' );
			$seen[] = $row['id'];
		}
	}
	$assert_same( 103, count( $seen ), 'Records beyond the previous 100-record cap are inaccessible.' );
	$last = $worklist->page( array_merge( $filters, array( 'paged' => '99999999999' ) ) );
	$assert_same( 5, $last['filters']['paged'], 'Out-of-range page did not clamp to the last page.' );
	$assert_same( 3, count( $last['rows'] ), 'Last-page row count is incorrect.' );
	$assert_same( 0, $worklist->page( array_merge( $filters, array( 'date_from' => '2026-11-02' ) ) )['total'], 'Publication-date filter failed.' );
	$assert_same( 103, $worklist->page( array_merge( $filters, array( 'date_from' => '2026-11-01', 'date_to' => '2026-11-01' ) ) )['total'], 'Inclusive publication-date boundaries failed.' );
	$assert_same( 0, $worklist->page( array( 'status' => 'all', 'search' => "%' OR 1=1 --" ) )['total'], 'Title search was not literal/prepared.' );
	$invalid = Post_Ingestion_Worklist::filters( array( 'status' => array( 'all' ), 'search' => array( 'x' ), 'date_from' => '2026-02-30', 'paged' => '-0' ) );
	$assert_same( 'all', $invalid['status'], 'Invalid status bypassed the default.' );
	$assert_same( '', $invalid['date_from'], 'Impossible date accepted.' );
	$assert_same( '', Post_Ingestion_Worklist::filters( array( 'date_to' => "2026-11-01\0" ) )['date_to'], 'Malformed date was not rejected.' );

	// Imported metadata counts as complete without history, but a foreign source ID cannot claim a local post.
	$insert_index = static function ( int $source_id, ?int $native_id, string $url ) use ( $wpdb, $tables, $assert ): int {
		$assert( false !== $wpdb->insert( $tables['external_posts'], array( 'source_wp_id' => $source_id, 'wordpress_post_id' => $native_id, 'title' => 'Imported worklist fixture', 'url' => $url, 'url_hash' => hash( 'sha256', $url, true ), 'published_at' => '2026-10-01 12:00:00', 'description' => 'Existing imported metadata.', 'search_summary' => 'Existing summary.' ) ), 'Could not create imported metadata fixture.' );
		return (int) $wpdb->insert_id;
	};
	$imported_id = $insert_index( $worklist_posts[0], null, home_url( '/original-imported-permalink/' ) );
	$assert_same( true, $worklist->is_indexed( $worklist_posts[0] ), 'Same-site imported metadata was not recognized.' );
	$assert_same( 102, $worklist->page( $filters )['total'], 'Imported post was incorrectly listed as missing metadata.' );
	$foreign_url = 'https://foreign-source.example/worklist-id-collision/';
	$insert_index( $worklist_posts[1], null, $foreign_url );
	$assert_same( false, $worklist->is_indexed( $worklist_posts[1] ), 'A foreign source ID was treated as a native ID.' );
	$assert_same( 102, $worklist->page( $filters )['total'], 'Foreign source ID hid a missing local post.' );
	$insert_index( 999999979, $worklist_posts[2], 'https://foreign-source.example/explicit-native-link/' );
	$assert_same( true, $worklist->is_indexed( $worklist_posts[2] ), 'Explicit native ownership was ignored.' );
	$assert_same( 101, $worklist->page( $filters )['total'], 'Explicitly indexed local post remained missing.' );
	$assert_same( 'Existing summary.', $worklist->indexed_post( $imported_id )['search_summary'], 'Imported metadata cannot be inspected.' );
	$assert_same( null, $worklist->indexed_post( 2147483647 ), 'Unknown indexed record did not return null.' );
	wp_update_post( array( 'ID' => $worklist_posts[3], 'post_status' => 'draft' ) );
	$assert_same( 100, $worklist->page( $filters )['total'], 'Unpublished post appears as needing generation.' );

	$repository = new Post_Ingestion_Repository();
	$draft_id = $repository->create_draft( array( 'source_wp_id' => $worklist_posts[4], 'title' => 'Worklist state fixture', 'url' => get_permalink( $worklist_posts[4] ), 'author' => 'Fixture author', 'date_text' => 'November 1, 2026', 'published_at' => '2026-11-01 12:00:00', 'post_text' => 'Synthetic full text must never be loaded in the worklist.' ), 'fixture-hash', get_current_user_id(), $worklist_posts[4] );
	$assert( ! is_wp_error( $draft_id ), 'Could not create worklist state fixture.' );
	$assert_same( 99, $worklist->page( $filters )['total'], 'A generation record duplicated a missing post.' );
	foreach ( array( 'queued' => 'generating', 'analyzing' => 'generating', 'ready' => 'needs_approval', 'held' => 'needs_approval', 'error' => 'failed', 'approved' => 'complete' ) as $stored => $display ) {
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => $stored, 'embedding_status' => 'complete' ), array( 'id' => $draft_id ) );
		$result = $worklist->page( array( 'status' => $display, 'search' => 'Worklist state fixture' ) );
		$assert_same( 1, $result['total'], 'Status filter failed for ' . $stored );
		$assert_same( $display, $result['rows'][0]['workflow_status'], 'Incorrect display status.' );
		$expected = in_array( $display, array( 'needs_approval', 'failed' ), true ) ? 1 : 0;
		$assert_same( $expected, $worklist->page( array( 'status' => 'attention', 'search' => 'Worklist state fixture' ) )['total'], 'Needs attention includes the wrong states.' );
		$assert_same( 1, $worklist->page( array( 'search' => 'Worklist state fixture' ) )['total'], 'Default All filter omitted a workflow state.' );
	}
	$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'pending' ), array( 'id' => $draft_id ) );
	$assert_same( 1, $worklist->page( array( 'status' => 'failed', 'search' => 'Worklist state fixture' ) )['total'], 'Vector failure is not actionable.' );
	$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'not_applicable' ), array( 'id' => $draft_id ) );
	$assert_same( 1, $worklist->page( array( 'status' => 'complete', 'search' => 'Worklist state fixture' ) )['total'], 'Ignore metadata was treated as a vector failure.' );

	$create_record = static function ( int $native_id, string $status, string $embedding, array $overrides = array() ) use ( $repository, $wpdb, $tables, $assert ): int {
		$input = array_merge( array(
			'source_wp_id' => $native_id, 'title' => 'Worklist history fixture', 'url' => get_permalink( $native_id ),
			'author' => 'Fixture author', 'date_text' => 'November 1, 2026', 'published_at' => '2026-11-01 12:00:00', 'post_text' => 'Synthetic history fixture.',
		), $overrides );
		$id = $repository->create_draft( $input, 'fixture-hash', get_current_user_id(), $native_id );
		$assert( ! is_wp_error( $id ), 'Could not create history fixture.' );
		$assert( false !== $wpdb->update( $tables['ingestion_drafts'], array( 'status' => $status, 'embedding_status' => $embedding, 'updated_at' => '2026-11-01 12:00:00' ), array( 'id' => $id ) ), 'Could not set history fixture state.' );
		return $id;
	};
	$history_filters = array( 'status' => 'attention', 'search' => 'Worklist history fixture' );
	$old_id = $create_record( $worklist_posts[5], 'approved', 'pending', array( 'title' => 'Worklist history fixture original', 'published_at' => '2026-10-01 12:00:00' ) );
	$assert_same( 1, $worklist->page( $history_filters )['total'], 'Current vector failure disappeared.' );
	$new_id = $create_record( $worklist_posts[5], 'approved', 'complete', array( 'title' => 'Worklist history fixture revised', 'url' => home_url( '/changed-history-permalink/' ) ) );
	$old_record = $repository->draft( $old_id );
	$new_record = $repository->draft( $new_id );
	foreach ( array( 'attention', 'failed', 'needs_approval', 'generating' ) as $status ) {
		$assert_same( 0, $worklist->page( array_merge( $history_filters, array( 'status' => $status ) ) )['total'], 'Superseded failure remains in ' . $status . '.' );
	}
	$complete = $worklist->page( array_merge( $history_filters, array( 'status' => 'complete' ) ) );
	$assert_same( array( (string) $new_id ), array_column( $complete['rows'], 'id' ), 'Current completion does not use the newest record.' );
	$all_history = $worklist->page( array_merge( $history_filters, array( 'status' => 'all' ) ) );
	$assert_same( 2, $all_history['total'], 'All discarded historical records.' );
	$assert_same( $all_history, $worklist->page( array( 'search' => 'Worklist history fixture' ) ), 'Default filter differs from explicit All history.' );
	$assert_same( array( 'complete', 'failed' ), array_column( $all_history['rows'], 'workflow_status' ), 'History lost original workflow statuses.' );
	$assert_same( $old_record, $repository->draft( $old_id ), 'Reading current status rewrote failed history.' );
	$assert_same( $new_record, $repository->draft( $new_id ), 'Reading current status rewrote current metadata.' );
	$assert_same( 0, $worklist->page( array( 'status' => 'attention', 'search' => 'Worklist history fixture original' ) )['total'], 'Title filter revived a superseded failure.' );
	$assert_same( 0, $worklist->page( array_merge( $history_filters, array( 'date_to' => '2026-10-31' ) ) )['total'], 'Date filter revived a superseded failure.' );
	$assert_same( 1, $worklist->page( array( 'status' => 'all', 'search' => 'Worklist history fixture original', 'date_to' => '2026-10-31' ) )['total'], 'Old history cannot be found by title and date.' );

	// Every current actionable state survives; the older vector failure must not duplicate it.
	foreach ( array(
		array( 'ready', 'not_requested', 'needs_approval', 1 ), array( 'held', 'not_requested', 'needs_approval', 1 ),
		array( 'queued', 'not_requested', 'generating', 0 ), array( 'analyzing', 'not_requested', 'generating', 0 ),
		array( 'error', 'not_requested', 'failed', 1 ), array( 'approved', 'pending', 'failed', 1 ),
		array( 'approved', 'not_applicable', 'complete', 0 ), array( 'approved', 'complete', 'complete', 0 ),
	) as [ $stored, $embedding, $display, $attention_count ] ) {
		$wpdb->update( $tables['ingestion_drafts'], array( 'status' => $stored, 'embedding_status' => $embedding ), array( 'id' => $new_id ) );
		$current = $worklist->page( array_merge( $history_filters, array( 'status' => $display ) ) );
		$assert_same( array( (string) $new_id ), array_column( $current['rows'], 'id' ), 'Latest state is missing or duplicated: ' . $stored . '/' . $embedding );
		$assert_same( $attention_count, $worklist->page( $history_filters )['total'], 'Incorrect current attention count: ' . $stored . '/' . $embedding );
	}
	$wpdb->delete( $tables['ingestion_drafts'], array( 'id' => $new_id ) );
	$assert_same( array( (string) $old_id ), array_column( $worklist->page( $history_filters )['rows'], 'id' ), 'Cancelling the newer record did not expose the remaining vector retry.' );

	// Group only explicit native ownership, never a coincident source ID or URL.
	$identity_filters = array( 'status' => 'attention', 'search' => 'Worklist identity fixture' );
	$native_failure = $create_record( $worklist_posts[6], 'error', 'not_requested', array( 'title' => 'Worklist identity fixture' ) );
	$create_record( $worklist_posts[7], 'approved', 'complete', array( 'title' => 'Worklist identity fixture', 'source_wp_id' => $worklist_posts[6], 'url' => get_permalink( $worklist_posts[6] ) ) );
	$create_record( 0, 'approved', 'complete', array( 'title' => 'Worklist identity fixture', 'source_wp_id' => $worklist_posts[6], 'url' => get_permalink( $worklist_posts[6] ) ) );
	$assert_same( array( (string) $native_failure ), array_column( $worklist->page( $identity_filters )['rows'], 'id' ), 'An unrelated source-ID or URL collision hid a native failure.' );
	$legacy_failure = $create_record( 0, 'error', 'not_requested', array( 'title' => 'Worklist identity fixture', 'source_wp_id' => 999999971, 'url' => 'https://foreign-source.example/legacy-history/' ) );
	$create_record( 0, 'approved', 'complete', array( 'title' => 'Worklist identity fixture', 'source_wp_id' => 999999972, 'url' => 'https://foreign-source.example/another-history/' ) );
	$assert_same( 2, $worklist->page( $identity_filters )['total'], 'Unlinked legacy records were grouped together.' );
	$assert( in_array( (string) $legacy_failure, array_column( $worklist->page( $identity_filters )['rows'], 'id' ), true ), 'Unlinked historical failure is inaccessible.' );

	// Many versions of one post must not inflate counts or consume current-result pages.
	$pagination_filters = array( 'status' => 'attention', 'search' => 'Worklist revision pagination fixture' );
	for ( $i = 0; $i < 30; ++$i ) {
		$create_record( $worklist_posts[8], 'error', 'not_requested', array( 'title' => 'Worklist revision pagination fixture' ) );
	}
	$latest_id = $create_record( $worklist_posts[8], 'ready', 'not_requested', array( 'title' => 'Worklist revision pagination fixture' ) );
	$one_page = $worklist->page( array_merge( $pagination_filters, array( 'paged' => 2 ) ) );
	$assert_same( 1, $one_page['total'], 'Superseded versions inflated current counts.' );
	$assert_same( 1, $one_page['pages'], 'Superseded versions inflated current pages.' );
	$assert_same( 1, $one_page['filters']['paged'], 'Current page was not clamped after superseded rows were excluded.' );
	$assert_same( array( (string) $latest_id ), array_column( $one_page['rows'], 'id' ), 'Current pagination returned historical rows.' );
	$all_page_one = $worklist->page( array_merge( $pagination_filters, array( 'status' => 'all' ) ) );
	$all_page_two = $worklist->page( array_merge( $pagination_filters, array( 'status' => 'all', 'paged' => 2 ) ) );
	$assert_same( 31, $all_page_one['total'], 'All omitted older versions from its count.' );
	$assert_same( 25, count( $all_page_one['rows'] ), 'History page size changed.' );
	$assert_same( 6, count( $all_page_two['rows'] ), 'Older history is not accessible on the second page.' );
	$assert_same( array(), array_values( array_intersect( array_column( $all_page_one['rows'], 'id' ), array_column( $all_page_two['rows'], 'id' ) ) ), 'History pagination repeated versions.' );
	$assert_same( '', $wpdb->last_error, 'Worklist query reported a database error.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}
echo "Metadata worklist: bounded pagination, latest native status, preserved history, filters, identity and lifecycle states: OK\n";
