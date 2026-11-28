<?php
/** Category organization regressions; all writes use removable fixtures. */

use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Category_Organization_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Subject_Area_Organization_Service;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$service = new Category_Organization_Service();
	$areas = new Subject_Area_Organization_Service();
	$topics = new Topic_Organization_Service();
	$before = $service->categories();
	$original_areas = $areas->areas(); $original_topics = $topics->topics();
	$counts = Database::counts();
	$user = get_current_user_id();
	$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	$get = $_GET; $post = $_POST; $request = $_REQUEST; $server = $_SERVER;
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$ids = array(); $fixture_area = 0; $fixture_topic = 0; $other_path = 0; $other_area = 0;
	$area_ids = array_map( static fn( $row ) => (int) $row['id'], $original_areas );
	$topic_ids = array_values( array_map( static fn( $row ) => (int) $row['id'], array_filter( $original_topics, array( Topic_Organization_Service::class, 'editable' ) ) ) );
	$must = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' ); return $result; };
	$render = static function (): string { ob_start(); try { Topic_Organization_Page::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); } };
	try {
		$_GET = array( 'tab' => 'categories' );
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->create( 'Unauthorized category', 'Definition', array( $area_ids[0] ), array( $topic_ids[0] ) ) ), 'Anonymous category creation accepted.' );
		$assert_same( '', $render(), 'Anonymous category page exposed.' );
		wp_set_current_user( $admin );
		foreach ( array( array( 0 ), array( -1 ), array( '1' ), array( array( 1 ) ), array( PHP_INT_MAX ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( 'Invalid category fixture', 'Definition', $invalid, array( $topic_ids[0] ) ) ), 'Invalid subject areas accepted.' );
			$assert( is_wp_error( $service->create( 'Invalid category fixture', 'Definition', array( $area_ids[0] ), $invalid ) ), 'Invalid topics accepted.' );
		}
		foreach ( array( array(), array( $area_ids[0] ) ) as $selected_areas ) {
			$assert( is_wp_error( $service->create( 'Missing topics fixture', 'Definition', $selected_areas, array() ) ), 'Category created without topics.' );
		}
		foreach ( array( array( '', 'Definition' ), array( 'Fixture', '' ), array( str_repeat( 'x', 192 ), 'Definition' ), array( 'Fixture', str_repeat( 'x', 4001 ) ), array( strtolower( $before[0]['name'] ), 'Definition' ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( $invalid[0], $invalid[1], array( $area_ids[0] ), array( $topic_ids[0] ) ) ), 'Invalid category definition accepted.' );
		}
		$name = 'Category regression ' . wp_generate_uuid4();
		$unlinked_id = $must( $service->create( $name . ' unlinked', 'No subject area yet.', array(), array( $topic_ids[0] ) ) ); $ids[] = $unlinked_id;
		$unlinked = $service->category( $unlinked_id );
		$assert_same( array(), $unlinked['subject_areas'], 'An unlinked category acquired a subject area.' );
		$assert_same( 1, count( $unlinked['topics'] ), 'Unlinked category lost its topic.' );
		$assert_same( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$tables['post_topics']} WHERE topic_id=%d", $topic_ids[0] ) ), (int) $unlinked['post_count'], 'Unlinked category lost its post count.' );
		$assert( in_array( $unlinked, $service->categories(), true ), 'Unlinked category missing from the directory.' );
		$area = $areas->area( $area_ids[0] );
		$linked = array_map( static fn( $row ) => (int) $row['id'], $area['categories'] ); $linked[] = $unlinked_id;
		$must( $areas->update_categories( $area_ids[0], $linked, Subject_Area_Organization_Service::revision( $area ) ) );
		$assert_same( 1, count( $service->category( $unlinked_id )['subject_areas'] ), 'A subject area could not link an unlinked category.' );
		$assert( is_wp_error( $service->update_links( $unlinked_id, array(), array( $topic_ids[0] ), Category_Organization_Service::revision( $unlinked ) ) ), 'Stale form cleared a newly added subject area.' );
		$must( $service->update_links( $unlinked_id, array(), array( $topic_ids[0] ), Category_Organization_Service::revision( $service->category( $unlinked_id ) ) ) );
		$assert_same( $unlinked, $service->category( $unlinked_id ), 'Removing the last subject area changed the category or topic assignments.' );
		$must( $service->update_links( $unlinked_id, array( $area_ids[1] ), array( $topic_ids[0] ), Category_Organization_Service::revision( $unlinked ) ) );
		$assert_same( 1, count( $service->category( $unlinked_id )['subject_areas'] ), 'Category editor could not add a subject area later.' );
		$must( $service->update_links( $unlinked_id, array(), array( $topic_ids[0] ), Category_Organization_Service::revision( $service->category( $unlinked_id ) ) ) );
		$assert_same( $unlinked, $service->category( $unlinked_id ), 'Relinking changed unrelated category data.' );
		$id = $must( $service->create( $name, 'Groups topics for an organization test.', array( $area_ids[0], $area_ids[1], $area_ids[0] ), array( $topic_ids[0], $topic_ids[1], $topic_ids[0] ) ) );
		$ids[] = $id;
		$created = $service->category( $id );
		$assert_same( 2, count( $created['subject_areas'] ), 'Subject areas duplicated or lost.' );
		$assert_same( 2, count( $created['topics'] ), 'Topics duplicated or lost.' );
		$expected_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$tables['post_topics']} WHERE topic_id IN (%d,%d)", $topic_ids[0], $topic_ids[1] ) );
		$assert_same( $expected_count, (int) $created['post_count'], 'Category post count is not distinct.' );
		$assert( is_wp_error( $service->create( strtolower( $name ), 'Duplicate', array( $area_ids[0] ), array( $topic_ids[0] ) ) ), 'Duplicate category accepted.' );
		$shared = $must( $service->create( $name . ' shared', 'Shares both sides.', array( $area_ids[0] ), array( $topic_ids[0] ) ) ); $ids[] = $shared;
		$assert_same( $created, $service->category( $id ), 'Shared links moved out of original category.' );
		$revision = Category_Organization_Service::revision( $created );
		$assert( is_wp_error( $service->update_links( $id, array(), array(), $revision ) ), 'All subject areas and topics removed from category.' );
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array(), $revision ) ), 'Last topic removed from category.' );
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array( PHP_INT_MAX ), $revision ) ), 'Missing topic accepted.' );
		$assert_same( $created, $service->category( $id ), 'Failed validation partially changed links.' );
		$must( $service->update_links( $id, array( $area_ids[1], $area_ids[2] ), array( $topic_ids[1], $topic_ids[2] ), $revision ) );
		$changed = $service->category( $id );
		foreach ( array( 'id', 'name', 'slug', 'description' ) as $key ) { $assert_same( $created[$key], $changed[$key], 'Relationship update changed ' . $key ); }
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), $revision ) ), 'Stale save accepted.' );
		// Fail the second side after the subject-area changes have already been written.
		$fail = static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO `' . $tables['topic_categories'] . '`' ) ? 'INSERT INTO missing_category_regression_table VALUES (1)' : $sql;
		$show = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $result = $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), Category_Organization_Service::revision( $changed ) ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $show ); }
		$assert( is_wp_error( $result ), 'Forced storage failure not reported.' );
		$assert_same( $changed, $service->category( $id ), 'Storage failure did not roll back both sides.' );
		// Changes through either reciprocal tab must invalidate an already-open category form.
		$area = $areas->area( $area_ids[0] );
		$linked = array_map( static fn( $row ) => (int) $row['id'], $area['categories'] ); $linked[] = $id;
		$must( $areas->update_categories( $area_ids[0], $linked, Subject_Area_Organization_Service::revision( $area ) ) );
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), Category_Organization_Service::revision( $changed ) ) ), 'Subject-area tab edit was overwritten.' );
		$changed = $service->category( $id );
		$topic = $topics->topic( $topic_ids[0] );
		$linked = array_map( static fn( $row ) => (int) $row['id'], $topic['categories'] ); $linked[] = $id;
		$must( $topics->update_categories( $topic_ids[0], $linked, Topic_Organization_Service::revision( $topic ) ) );
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), Category_Organization_Service::revision( $changed ) ) ), 'Topic tab edit was overwritten.' );
		$path_id = (int) ( new Browse_Service() )->browse_path()['id'];
		$wpdb->insert( $tables['subject_areas'], array( 'browse_path_id' => $path_id, 'name' => $name, 'slug' => sanitize_title( $name ), 'description' => 'Orphan test', 'position' => 999 ) ); $fixture_area = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['topics'], array( 'name' => $name, 'slug' => sanitize_title( $name ), 'description' => 'Orphan test', 'display_in_browser' => 1 ) ); $fixture_topic = (int) $wpdb->insert_id;
		$must( $service->update_links( $id, array( $fixture_area ), array( $fixture_topic ), Category_Organization_Service::revision( $service->category( $id ) ) ) );
		$must( $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), Category_Organization_Service::revision( $service->category( $id ) ) ) );
		$assert_same( array(), $areas->area( $fixture_area )['categories'], 'Unlinking an area with no other categories was blocked.' );
		$assert_same( array(), $topics->topic( $fixture_topic )['categories'], 'Unlinking a topic with no other categories was blocked.' );
		$protected = current( array_filter( $original_topics, static fn( $row ) => ! Topic_Organization_Service::editable( $row ) ) );
		$assert( is_array( $protected ), 'Missing protected topic fixture.' );
		$assert( is_wp_error( $service->create( $name . ' protected', 'Definition', array( $area_ids[0] ), array( (int) $protected['id'] ) ) ), 'Protected topic selectable.' );
		$wpdb->insert( $tables['topic_categories'], array( 'topic_id' => (int) $protected['id'], 'category_id' => $id, 'position' => 999 ) );
		$must( $service->update_links( $id, array( $area_ids[1] ), array( $topic_ids[1] ), Category_Organization_Service::revision( $service->category( $id ) ) ) );
		$assert_same( 1, count( $service->category( $id )['protected_topics'] ), 'Existing protected link removed.' );
		$wpdb->insert( $tables['browse_paths'], array( 'name' => $name, 'slug' => sanitize_title( $name ), 'description' => '', 'is_active' => 1 ) ); $other_path = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['subject_areas'], array( 'browse_path_id' => $other_path, 'name' => 'Other area', 'slug' => 'other', 'description' => '' ) ); $other_area = (int) $wpdb->insert_id;
		$assert( is_wp_error( $service->create( $name . ' other path', 'Definition', array( $other_area ), array( $topic_ids[0] ) ) ), 'Another browse path accepted.' );
		$wpdb->insert( $tables['subject_area_categories'], array( 'subject_area_id' => $other_area, 'category_id' => $id, 'position' => 999 ) );
		$must( $service->update_links( $id, array(), array( $topic_ids[2] ), Category_Organization_Service::revision( $service->category( $id ) ) ) );
		$assert_same( array(), $service->category( $id )['subject_areas'], 'Last current-path subject area could not be removed.' );
		$assert_same( 1, count( $service->category( $id )['protected_topics'] ), 'Clearing subject areas removed a protected topic.' );
		$assert_same( '999', (string) $wpdb->get_var( $wpdb->prepare( "SELECT position FROM {$tables['subject_area_categories']} WHERE subject_area_id=%d AND category_id=%d", $other_area, $id ) ), 'Other-path link modified.' );
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->update_links( $id, array( $area_ids[0] ), array( $topic_ids[0] ), '' ) ), 'Anonymous update accepted.' );
		wp_set_current_user( $admin );
		$html = $render();
		$assert( ! str_contains( $html, '<table' ) && str_contains( $html, 'Search Categories' ) && str_contains( $html, 'Browse All Categories' ) && str_contains( $html, 'Add Category' ), 'Search-first category controls missing.' );
		$assert( str_contains( $html, 'name="tab" value="categories"' ), 'Category navigation loses tab.' );
		$_GET = array( 'tab' => 'categories', 'view' => 'all' );
		$html = $render();
		$assert( str_contains( $html, 'ehrman-organization__categories' ) && ! str_contains( $html, '>Subject Areas and Topics<' ), 'Separate directory columns unavailable.' );
		$directory = new DOMDocument();
		@$directory->loadHTML( $html );
		$directory_xpath = new DOMXPath( $directory );
		$assert_same( 4, $directory_xpath->query( '//table/thead/tr/th' )->length, 'Category directory must include an Action column.' );
		$directory_rows = $directory_xpath->query( '//table/tbody/tr' );
		$assert( $directory_rows->length > 0, 'Category directory has no rows.' );
		foreach ( $directory_rows as $directory_row ) {
			$assert_same( 4, $directory_xpath->query( './td', $directory_row )->length, 'Category action cell is missing.' );
			$action = $directory_xpath->query( './td[4]/a', $directory_row )->item( 0 );
			$assert( $action instanceof DOMElement, 'Category edit link is missing.' );
			$assert_same( 'Edit Relationships', trim( $action->textContent ), 'Category edit label is inconsistent.' );
			$assert_same( $directory_xpath->evaluate( 'string(./td[1]/a/@href)', $directory_row ), $action->getAttribute( 'href' ), 'Category name and action edit different records.' );
		}
		$_GET = array( 'tab' => 'categories', 's' => $name . ' not present' );
		$assert( str_contains( $render(), 'colspan="4"' ), 'Empty category directory has the wrong column span.' );
		$row = array( 'name' => 'Test <category>', 'post_count' => 1234, 'subject_areas' => array( array( 'name' => 'Zulu, First Area' ), array( 'name' => 'Alpha Area' ) ), 'topics' => array( array( 'name' => 'Zulu Topic' ), array( 'name' => 'Beta Topic' ), array( 'name' => 'Alpha Topic' ) ), 'protected_topics' => array( array( 'name' => 'Delta Topic' ) ) );
		$row_html = static fn( $record ) => EhrmanBlogDiscovery\Template::render( 'admin/organization-category-row.php', array( 'category' => $record, 'url' => Topic_Organization_Page::url( array( 'tab' => 'categories', 'edit' => 1 ) ) ) );
		$html = $row_html( $row );
		$assert( str_contains( $html, 'Alpha Area; Zulu, First Area' ) && str_contains( $html, 'Alpha Topic; Beta Topic; Delta Topic</span>' ), 'Compact relationships not alphabetized or semicolon-separated.' );
		$assert( str_contains( $html, 'Show all 4 topics' ) && str_contains( $html, 'Show fewer' ) && str_contains( $html, 'Alpha Topic; Beta Topic; Delta Topic; Zulu Topic</p>' ), 'Disclosure omits linked/protected topics or the full count.' );
		$assert( ! str_contains( $html, '<details open' ) && str_contains( $html, 'Test &lt;category&gt;' ) && ! str_contains( $html, 'Test <category>' ), 'Disclosure defaults or escaped category names incorrect.' );
		$row['protected_topics'] = array();
		$assert( ! str_contains( $row_html( $row ), '<details' ), 'Three topics unnecessarily require expansion.' );
		$row['topics'] = array(); $row['subject_areas'] = array();
		$assert_same( 2, substr_count( $row_html( $row ), 'None linked' ), 'Empty relationships misleading.' );
		$_GET = array( 'tab' => 'categories', 'edit' => $id );
		$html = $render();
		$assert( ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'name="description"' ) && str_contains( $html, 'name="category_id"' ) && str_contains( $html, 'name="subject_area_ids[]"' ) && str_contains( $html, 'name="topic_ids[]"' ), 'Existing category fields or selectors incorrect.' );
		$_GET = array( 'tab' => 'categories', 'action' => 'new' );
		$html = $render();
		$assert( str_contains( $html, 'Create Category' ), 'Creation unavailable.' );
		$dom = new DOMDocument(); $old_errors = libxml_use_internal_errors( true );
		try { $dom->loadHTML( $html ); } finally { libxml_clear_errors(); libxml_use_internal_errors( $old_errors ); }
		$xpath = new DOMXPath( $dom );
		$assert_same( 0, $xpath->query( '//select[@name="subject_area_ids[]"][@required]' )->length, 'Optional subject areas have native required validation.' );
		$assert_same( 1, $xpath->query( '//select[@name="topic_ids[]"][@required]' )->length, 'Topics lost native required validation.' );
		$assert_same( '', trim( $xpath->evaluate( 'string(//*[@id="ebd-category-areas-warning"])' ) ), 'Optional subject areas announce a required warning.' );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'category_id' => '0', 'organization_action' => 'create' );
		$die = static fn() => static function () { throw new RuntimeException( 'expected_nonce_rejection' ); };
		add_filter( 'wp_die_handler', $die );
		try {
			foreach ( array( 'ehrman_topic_organization', 'ehrman_subject_area_organization' ) as $nonce ) {
				$_REQUEST = array( '_wpnonce' => wp_create_nonce( $nonce ) );
				try { Topic_Organization_Page::save(); throw new RuntimeException( 'Cross-tab nonce accepted.' ); }
				catch ( RuntimeException $error ) { $assert_same( 'expected_nonce_rejection', $error->getMessage(), 'Wrong nonce failure.' ); }
			}
		} finally { remove_filter( 'wp_die_handler', $die ); }
		$after = Database::counts();
		foreach ( array( 'external_posts', 'post_topics', 'keywords', 'post_keywords', 'post_search_terms', 'post_embeddings' ) as $table ) { $assert_same( $counts[$table], $after[$table], 'Category edit changed ' . $table ); }
	} finally {
		foreach ( $ids as $id ) { $wpdb->delete( $tables['topic_categories'], array( 'category_id' => $id ) ); $wpdb->delete( $tables['subject_area_categories'], array( 'category_id' => $id ) ); $wpdb->delete( $tables['categories'], array( 'id' => $id ) ); }
		if ( $fixture_topic ) { $wpdb->delete( $tables['topics'], array( 'id' => $fixture_topic ) ); }
		foreach ( array_filter( array( $fixture_area, $other_area ) ) as $id ) { $wpdb->delete( $tables['subject_areas'], array( 'id' => $id ) ); }
		if ( $other_path ) { $wpdb->delete( $tables['browse_paths'], array( 'id' => $other_path ) ); }
		wp_set_current_user( $user ); $_GET = $get; $_POST = $post; $_REQUEST = $request; $_SERVER = $server;
		if ( false === $cache ) { delete_option( 'ehrman_discovery_ai_cache_version' ); } else { update_option( 'ehrman_discovery_ai_cache_version', $cache ); }
	}
	$assert_same( $before, $service->categories(), 'Existing category data changed after cleanup.' );
	$assert_same( $original_topics, $topics->topics(), 'Existing topics changed after cleanup.' );
	$assert_same( $original_areas, $areas->areas(), 'Existing subject areas changed after cleanup.' );
	echo "Category organization: two-sided links, atomic rollback, shared relationships, orphans permitted, protected topics, stale cross-tab saves, permissions and scope: OK\n";
} )();
