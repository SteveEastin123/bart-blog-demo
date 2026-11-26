<?php
/** Subject-area organization regression tests; synthetic fixtures are removed. */

use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Subject_Area_Organization_Service;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$service = new Subject_Area_Organization_Service();
	$topics = new Topic_Organization_Service();
	$browse = new Browse_Service();
	$user = get_current_user_id();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Subject-area tests need an administrator.' );
	$categories = array_map( static fn( $row ) => (int) $row['id'], $topics->categories() );
	$assert( count( $categories ) >= 3, 'Subject-area tests need three categories.' );
	$original = $service->areas();
	$original_topics = $topics->topics();
	$original_categories = $topics->categories();
	$counts = Database::counts();
	$get = $_GET; $post = $_POST; $request = $_REQUEST; $server = $_SERVER;
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$ids = array(); $other_path = 0; $other_area = 0;
	$render = static function (): string { ob_start(); try { Topic_Organization_Page::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); } };
	$must = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' ); return $result; };
	try {
		$_GET = array( 'tab' => 'subject-areas' );
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->create( 'Unauthorized subject area', 'Definition', array( $categories[0] ) ) ), 'Anonymous creation accepted.' );
		$assert_same( '', $render(), 'Anonymous subject-area page exposed.' );
		wp_set_current_user( (int) $admins[0] );
		foreach ( array( array( 0 ), array( -1 ), array( '1' ), array( array( 1 ) ), array( PHP_INT_MAX ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( 'Invalid subject-area fixture', 'Definition', $invalid ) ), 'Invalid categories accepted.' );
		}
		foreach ( array( array( '', 'Definition' ), array( 'Fixture', '' ), array( str_repeat( 'x', 192 ), 'Definition' ), array( 'Fixture', str_repeat( 'x', 4001 ) ), array( strtolower( $original[0]['name'] ), 'Definition' ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( $invalid[0], $invalid[1], array( $categories[0] ) ) ), 'Invalid subject-area definition accepted.' );
		}
		$name = 'Subject-area regression fixture ' . wp_generate_uuid4();
		$empty_id = $must( $service->create( $name . ' initially empty', 'A subject area prepared before its categories.', array() ) );
		$ids[] = $empty_id;
		$empty_area = $service->area( $empty_id );
		$assert_same( array(), $empty_area['categories'], 'Unlinked creation added categories.' );
		$assert_same( 0, (int) $empty_area['post_count'], 'Unlinked area has posts.' );
		$assert( null !== $browse->subject_area( $empty_area['slug'] ), 'Unlinked area missing from browse.' );
		$assert_same( array(), $browse->subject_area_categories( $empty_id ), 'Unlinked browse returned categories.' );
		$assert_same( array( 'category_count' => 0, 'topic_count' => 0, 'post_count' => 0 ), $browse->subject_area_counts( $empty_id ), 'Unlinked browse counts are not zero.' );
		$must( $service->update_categories( $empty_id, array( $categories[0] ), Subject_Area_Organization_Service::revision( $empty_area ) ) );
		$assert_same( 1, count( $service->area( $empty_id )['categories'] ), 'Could not add a category later.' );
		$id = $must( $service->create( $name, 'Groups categories for a synthetic administration test.', array( $categories[0], $categories[1], $categories[0] ) ) );
		$ids[] = $id;
		$created = $service->area( $id );
		$assert_same( (int) $browse->browse_path()['id'], (int) $created['browse_path_id'], 'Created in the wrong browse path.' );
		$assert_same( 2, count( $created['categories'] ), 'Multiple categories lost or duplicated.' );
		$assert( null !== $browse->subject_area( $created['slug'] ), 'New subject area missing from public browse.' );
		$assert_same( 2, count( $browse->subject_area_categories( $id ) ), 'New links missing from public browse.' );
		$assert_same( $browse->subject_area_counts( $id )['post_count'], (int) $created['post_count'], 'Incorrect distinct post count.' );
		$assert( is_wp_error( $service->create( strtolower( $name ), 'Duplicate definition', array( $categories[0] ) ) ), 'Duplicate name accepted.' );
		$revision = Subject_Area_Organization_Service::revision( $created );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), 'stale' ) ), 'Stale save accepted.' );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0], PHP_INT_MAX ), $revision ) ), 'Nonexistent category accepted.' );
		$assert_same( $created, $service->area( $id ), 'Rejected update changed links.' );
		$must( $service->update_categories( $id, array( $categories[1], $categories[2] ), $revision ) );
		$changed = $service->area( $id );
		foreach ( array( 'id', 'browse_path_id', 'name', 'slug', 'description', 'position' ) as $field ) { $assert_same( $created[$field], $changed[$field], 'Category edit changed ' . $field ); }
		$assert_same( array( $categories[1], $categories[2] ), array_map( static fn( $row ) => (int) $row['id'], $changed['categories'] ), 'Wrong links saved.' );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), $revision ) ), 'Old browser tab overwrote saved links.' );
		$shared = $must( $service->create( $name . ' shared', 'Uses a category already linked elsewhere.', array( $categories[1] ) ) );
		$ids[] = $shared;
		$assert_same( $changed, $service->area( $id ), 'Linking another subject area moved an existing link.' );
		$shared_revision = Subject_Area_Organization_Service::revision( $service->area( $shared ) );
		$must( $service->update_categories( $shared, array(), $shared_revision ) );
		$cleared = $service->area( $shared );
		$assert_same( array(), $cleared['categories'], 'Last category could not be unlinked.' );
		$assert_same( 0, (int) $cleared['post_count'], 'Cleared area retained a post count.' );
		$assert_same( $changed, $service->area( $id ), 'Clearing an area changed another area.' );
		$assert( is_wp_error( $service->update_categories( $shared, array( $categories[0] ), $shared_revision ) ), 'Stale edit overwrote cleared links.' );
		$must( $service->update_categories( $shared, array( $categories[0] ), Subject_Area_Organization_Service::revision( $cleared ) ) );
		$assert_same( 1, count( $service->area( $shared )['categories'] ), 'Cleared area could not be relinked.' );
		$parse_ids = new ReflectionMethod( Topic_Organization_Page::class, 'selected_ids' );
		$assert_same( array(), $parse_ids->invoke( null, array(), 'category_ids' ), 'Omitted optional categories rejected.' );
		$assert_same( array(), $parse_ids->invoke( null, array( 'category_ids' => array() ), 'category_ids' ), 'Empty categories rejected.' );
		foreach ( array( '', '1', null ) as $malformed ) {
			$assert_same( array( 0 ), $parse_ids->invoke( null, array( 'category_ids' => $malformed ), 'category_ids' ), 'Malformed categories treated as an empty selection.' );
		}
		$wpdb->insert( $tables['browse_paths'], array( 'name' => 'Other fixture path', 'slug' => 'subject-area-fixture-' . wp_generate_uuid4(), 'description' => '', 'position' => 999, 'is_active' => 1 ) );
		$other_path = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['subject_areas'], array( 'browse_path_id' => $other_path, 'name' => 'Other fixture area', 'slug' => 'other-fixture-area', 'description' => '', 'position' => 0 ) );
		$other_area = (int) $wpdb->insert_id;
		$assert_same( null, $service->area( $other_area ), 'Another browse path leaked into organization.' );
		$assert( ! in_array( $other_area, array_map( 'intval', array_column( $service->options(), 'id' ) ), true ), 'Another browse path leaked into relationship choices.' );
		$assert( is_wp_error( $service->update_categories( $other_area, array( $categories[0] ), '' ) ), 'Another browse path was editable.' );
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), Subject_Area_Organization_Service::revision( $changed ) ) ), 'Anonymous link edit accepted.' );
		wp_set_current_user( (int) $admins[0] );
		$html = $render();
		$assert( ! str_contains( $html, '<table' ), 'Subject areas listed by default.' );
		$assert( str_contains( $html, 'Search Subject Areas' ) && str_contains( $html, 'Browse All Subject Areas' ) && str_contains( $html, 'Add Subject Area' ), 'Subject-area controls missing.' );
		$assert( strpos( $html, 'Browse All Subject Areas' ) < strpos( $html, 'Add Subject Area' ), 'Add Subject Area is not third.' );
		$assert( str_contains( $html, 'name="tab" value="subject-areas"' ), 'Search did not retain the tab.' );
		$_GET = array( 'tab' => 'subject-areas', 's' => '  ' );
		$assert( ! str_contains( $render(), '<table' ), 'Empty search listed all subject areas.' );
		$_GET = array( 'tab' => 'subject-areas', 'view' => 'all' );
		$assert( str_contains( $render(), '<table' ), 'Optional directory missing.' );
		$_GET = array( 'tab' => 'subject-areas', 's' => $name );
		$assert( str_contains( $render(), $name ), 'Subject-area search failed.' );
		$_GET = array( 'tab' => 'subject-areas', 'edit' => $id );
		$html = $render();
		$assert( str_contains( $html, 'name="subject_area_id"' ) && ! str_contains( $html, 'name="topic_id"' ) && ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'name="description"' ), 'Subject-area form permits definition edits or uses topic IDs.' );
		$_GET = array( 'tab' => 'subject-areas', 'action' => 'new' );
		$html = $render();
		$assert( str_contains( $html, 'Create Subject Area' ) && str_contains( $html, 'name="name"' ) && str_contains( $html, 'name="description"' ), 'New subject-area fields missing.' );
		$document = new DOMDocument();
		@$document->loadHTML( $html );
		$xpath = new DOMXPath( $document );
		$assert_same( 1, $xpath->query( '//select[@name="category_ids[]" and not(@required)]' )->length, 'No-JS subject-area form requires categories.' );
		$assert_same( '', trim( $xpath->query( '//*[@id="ebd-category-warning"]' )->item( 0 )->textContent ), 'Optional categories announce a required-selection warning.' );
		$assert_same( 2, $xpath->query( '//*[@required and (@name="name" or @name="description")]' )->length, 'Name or description became optional.' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'subject_area_id' => '0', 'organization_action' => 'create' );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'ehrman_topic_organization' ) );
		$die = static function () { return static function () { throw new RuntimeException( 'expected_nonce_rejection' ); }; };
		add_filter( 'wp_die_handler', $die );
		try { Topic_Organization_Page::save(); throw new RuntimeException( 'Topic nonce accepted in Subject Areas.' ); }
		catch ( RuntimeException $error ) { $assert_same( 'expected_nonce_rejection', $error->getMessage(), 'Wrong nonce rejection.' ); }
		finally { remove_filter( 'wp_die_handler', $die ); }
		$after = Database::counts();
		foreach ( array( 'categories', 'topics', 'topic_categories', 'external_posts', 'post_topics', 'post_keywords', 'post_search_terms', 'post_embeddings' ) as $table ) { $assert_same( $counts[$table], $after[$table], 'Subject-area edit changed ' . $table ); }
		$assert_same( $original_topics, $topics->topics(), 'Topic definitions or links changed.' );
		$assert_same( $original_categories, $topics->categories(), 'Categories were deleted or changed.' );
	} finally {
		foreach ( $ids as $id ) { $wpdb->delete( $tables['subject_area_categories'], array( 'subject_area_id' => $id ) ); $wpdb->delete( $tables['subject_areas'], array( 'id' => $id ) ); }
		if ( $other_area ) { $wpdb->delete( $tables['subject_areas'], array( 'id' => $other_area ) ); }
		if ( $other_path ) { $wpdb->delete( $tables['browse_paths'], array( 'id' => $other_path ) ); }
		wp_set_current_user( $user ); $_GET = $get; $_POST = $post; $_REQUEST = $request; $_SERVER = $server;
		if ( false === $cache ) { delete_option( 'ehrman_discovery_ai_cache_version' ); } else { update_option( 'ehrman_discovery_ai_cache_version', $cache ); }
	}
	$assert_same( $original, $service->areas(), 'Subject-area fixtures changed existing records or links.' );
	echo "Subject-area organization: optional categories, deferred linking, last-link removal, zero counts, malformed inputs, shared categories, immutable definitions, scope, stale saves and tab-specific nonces: OK\n";
} )();
