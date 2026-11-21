<?php
/** Read-only structure export coverage; synthetic records are removed. */

use EhrmanBlogDiscovery\Organization_Export;
use EhrmanBlogDiscovery\Subject_Area_Organization_Service;
use EhrmanBlogDiscovery\Topic_Organization_Page;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$export = new Organization_Export();
	$areas = ( new Subject_Area_Organization_Service() )->areas();
	$all = $export->rows( 'all', null );
	$assert( ! is_wp_error( $all ) && count( $all ) > 300, 'Full structure not exported.' );
	$names = array_values( array_unique( array_column( $all, 'subject_area' ) ) );
	$assert_same( array_column( $areas, 'name' ), $names, 'Subject areas not complete and alphabetical.' );
	$selected = array( (string) $areas[0]['id'], (string) $areas[1]['id'] );
	$partial = $export->rows( 'selected', array_reverse( $selected ) );
	$assert_same( array_values( array_filter( $all, static fn( $row ) => in_array( $row['subject_area'], array_slice( $names, 0, 2 ), true ) ) ), $partial, 'Selection lost relationships or alphabetical order.' );
	$assert_same( $partial, $export->rows( 'selected', array_merge( $selected, $selected ) ), 'Repeated selections duplicate rows.' );
	foreach ( array( null, array(), '1', array( '0' ), array( '01' ), array( '-1' ), array( '1.1' ), array( '9999999999' ), array( array( 1 ) ), array( $selected[0], 'invalid' ) ) as $invalid ) {
		$assert( is_wp_error( $export->rows( 'selected', $invalid ) ), 'Malformed/unavailable selection accepted.' );
	}
	$assert( is_wp_error( $export->rows( 'unknown', null ) ), 'Unknown scope accepted.' );
	$rows = array(
		array( 'subject_area' => '=SUM(1,2)', 'category' => " +formula", 'topic' => '@formula' ),
		array( 'subject_area' => 'Quoted "name", with comma', 'category' => "Line\nbreak", 'topic' => "Caf\u{00e9}, Paul\u{2019}s Letters" ),
	);
	$stream = fopen( 'php://temp', 'w+' ); $export->write_csv( $stream, $rows ); rewind( $stream );
	$assert_same( "\xEF\xBB\xBF", fread( $stream, 3 ), 'UTF-8 marker missing.' );
	$assert_same( array( 'Subject Area', 'Category', 'Topic' ), fgetcsv( $stream, 0, ',', '"', '' ), 'Wrong CSV headings.' );
	$assert_same( array( "'=SUM(1,2)", "' +formula", "'@formula" ), fgetcsv( $stream, 0, ',', '"', '' ), 'Spreadsheet formulas not neutralized.' );
	$assert_same( array_values( $rows[1] ), fgetcsv( $stream, 0, ',', '"', '' ), 'CSV quoting or Unicode corrupted.' );
	fclose( $stream );
	$area_id = 0; $category_id = 0; $topic_id = 0; $other_path = 0; $other_area = 0;
	$user = get_current_user_id(); $get = $_GET;
	try {
		$path = ( new EhrmanBlogDiscovery\Browse_Service() )->browse_path();
		$tag = wp_generate_uuid4();
		$wpdb->insert( $tables['subject_areas'], array( 'name' => 'Export area ' . $tag, 'slug' => $tag, 'description' => 'not exported', 'browse_path_id' => $path['id'] ) ); $area_id = (int) $wpdb->insert_id;
		$empty = $export->rows( 'selected', array( $area_id ) );
		$assert_same( '', $empty[0]['category'], 'Empty subject area was omitted.' );
		$wpdb->insert( $tables['categories'], array( 'name' => 'Export category ' . $tag, 'slug' => $tag, 'description' => 'not exported' ) ); $category_id = (int) $wpdb->insert_id;
		foreach ( array( $area_id, (int) $areas[0]['id'] ) as $id ) { $wpdb->insert( $tables['subject_area_categories'], array( 'subject_area_id' => $id, 'category_id' => $category_id ) ); }
		$assert_same( '', $export->rows( 'selected', array( $area_id ) )[0]['topic'], 'Empty category was omitted.' );
		$wpdb->insert( $tables['topics'], array( 'name' => 'Export topic ' . $tag, 'slug' => $tag, 'description' => 'not exported', 'display_in_browser' => 1 ) ); $topic_id = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['topic_categories'], array( 'topic_id' => $topic_id, 'category_id' => $category_id ) );
		$shared = array_values( array_filter( $export->rows( 'all', null ), static fn( $row ) => $row['topic'] === 'Export topic ' . $tag ) );
		$assert_same( 2, count( $shared ), 'Shared category not repeated under both parents.' );
		$assert_same( array( 'subject_area', 'category', 'topic' ), array_keys( $shared[0] ), 'Export leaked descriptions or post fields.' );
		$wpdb->update( $tables['topics'], array( 'display_in_browser' => 0 ), array( 'id' => $topic_id ) );
		$assert_same( '', $export->rows( 'selected', array( $area_id ) )[0]['topic'], 'Hidden internal topic exported.' );
		$wpdb->insert( $tables['browse_paths'], array( 'name' => 'Export other path', 'slug' => 'export-' . $tag, 'is_active' => 0 ) ); $other_path = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['subject_areas'], array( 'name' => 'Export other area', 'slug' => 'other', 'browse_path_id' => $other_path ) ); $other_area = (int) $wpdb->insert_id;
		$assert( is_wp_error( $export->rows( 'selected', array( $other_area ) ) ), 'Retired path accepted.' );
		$_GET = array( 'tab' => 'export' ); wp_set_current_user( 0 );
		ob_start(); Topic_Organization_Page::render(); $html = ob_get_clean();
		$assert_same( '', $html, 'Anonymous export page exposed.' );
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
		ob_start(); Topic_Organization_Page::render(); $html = ob_get_clean();
		$assert( str_contains( $html, 'Download PDF' ) && str_contains( $html, 'Download CSV' ) && str_contains( $html, 'value="all" checked' ), 'Export controls/default missing.' );
		$assert( ! str_contains( $html, 'organization_action' ), 'Export page can mutate organization.' );
	} finally {
		if ( $category_id ) { $wpdb->delete( $tables['topic_categories'], array( 'category_id' => $category_id ) ); $wpdb->delete( $tables['subject_area_categories'], array( 'category_id' => $category_id ) ); $wpdb->delete( $tables['categories'], array( 'id' => $category_id ) ); }
		if ( $topic_id ) $wpdb->delete( $tables['topics'], array( 'id' => $topic_id ) );
		foreach ( array_filter( array( $area_id, $other_area ) ) as $id ) $wpdb->delete( $tables['subject_areas'], array( 'id' => $id ) );
		if ( $other_path ) $wpdb->delete( $tables['browse_paths'], array( 'id' => $other_path ) );
		wp_set_current_user( $user ); $_GET = $get;
	}
	$assert_same( $all, $export->rows( 'all', null ), 'Export fixtures changed existing hierarchy.' );
	echo "Organization export: scopes, database-only hierarchy, shared links, empty nodes, hidden topics, UTF-8, CSV formula safety: OK\n";
} )();
