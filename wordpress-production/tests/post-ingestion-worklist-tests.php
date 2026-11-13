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
	$filters = array( 'search' => 'Worklist pagination fixture' );
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
	$assert_same( 'attention', $invalid['status'], 'Invalid status bypassed the default.' );
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
		$assert_same( $expected, $worklist->page( array( 'search' => 'Worklist state fixture' ) )['total'], 'Needs attention includes the wrong states.' );
	}
	$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'pending' ), array( 'id' => $draft_id ) );
	$assert_same( 1, $worklist->page( array( 'status' => 'failed', 'search' => 'Worklist state fixture' ) )['total'], 'Vector failure is not actionable.' );
	$wpdb->update( $tables['ingestion_drafts'], array( 'embedding_status' => 'not_applicable' ), array( 'id' => $draft_id ) );
	$assert_same( 1, $worklist->page( array( 'status' => 'complete', 'search' => 'Worklist state fixture' ) )['total'], 'Ignore metadata was treated as a vector failure.' );
	$assert_same( '', $wpdb->last_error, 'Worklist query reported a database error.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}
echo "Metadata worklist: bounded pagination, filters, native/imported identity, lifecycle states and old records: OK\n";
