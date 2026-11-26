<?php
/** Description edits must not change names, relationships or existing post metadata. */

use EhrmanBlogDiscovery\Category_Organization_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Organization_Description_Service;
use EhrmanBlogDiscovery\Organization_Page_Labels;
use EhrmanBlogDiscovery\Post_Ingestion_Vocabulary_Reader;
use EhrmanBlogDiscovery\Subject_Area_Organization_Service;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$user = get_current_user_id(); $get = $_GET;
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$writer = new Organization_Description_Service();
	$topics = new Topic_Organization_Service(); $areas = new Subject_Area_Organization_Service(); $categories = new Category_Organization_Service();
	$vocabulary = new Post_Ingestion_Vocabulary_Reader();
	$hash = static fn() => $vocabulary->vocabulary_hash( $vocabulary->vocabulary() );
	$must = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' ); return $result; };
	$render = static function (): string { ob_start(); try { Topic_Organization_Page::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); } };
	$capture = static function ( callable $read ): array {
		$queries = array();
		$record_query = static function ( string $sql ) use ( &$queries ): string { $queries[] = $sql; return $sql; };
		add_filter( 'query', $record_query );
		try { return array( $read(), $queries ); } finally { remove_filter( 'query', $record_query ); }
	};
	$assert_scoped_counts = static function ( array $queries, int $id ) use ( $assert ): void {
		foreach ( $queries as $sql ) {
			if ( str_contains( $sql, ' post_count FROM ' ) ) {
				$assert( $id > 0 && 1 === preg_match( '/\b(?:t|sa|c)\.id=' . $id . '(?:\D|$)/', $sql ), 'An item form or lookup counted the entire directory.' );
			}
		}
	};
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$ids = array(); $other_path = 0; $other_area = 0;
	$before = Database::counts();
	try {
		wp_set_current_user( 0 );
		$assert( is_wp_error( $writer->update( 'topics', 1, 'Unauthorized', '' ) ), 'Anonymous description write accepted.' );
		wp_set_current_user( (int) $admins[0] );
		$readers = array( 'topics' => array( $topics, 'topic' ), 'subject-areas' => array( $areas, 'area' ), 'categories' => array( $categories, 'category' ) );
		$directories = array( 'topics' => $topics->topics(), 'subject-areas' => $areas->areas(), 'categories' => $categories->categories() );
		foreach ( $readers as $tab => $read ) {
			foreach ( $directories[$tab] as $expected ) {
				$id = (int) $expected['id'];
				list( $actual, $queries ) = $capture( static fn() => $read( $id ) );
				$assert_same( $expected, $actual, 'Direct lookup changed fields, link order or counts: ' . $tab );
				$assert_scoped_counts( $queries, $id );
			}
			foreach ( array( -1, 0, PHP_INT_MAX ) as $missing ) {
				list( $actual, $queries ) = $capture( static fn() => $read( $missing ) );
				$assert_same( null, $actual, 'Missing organization ID returned a record.' );
				if ( $missing < 1 ) { $assert_same( array(), $queries, 'Invalid ID read the database.' ); }
				$assert_scoped_counts( $queries, $missing );
			}
		}
		foreach ( array( 'topics' => $topics, 'subject-areas' => $areas ) as $tab => $service ) {
			$expected = 'topics' === $tab ? array_values( array_filter( $directories[$tab], array( Topic_Organization_Service::class, 'editable' ) ) ) : $directories[$tab];
			list( $options, $queries ) = $capture( static fn() => $service->options() );
			foreach ( array( 'id', 'name' ) as $field ) { $assert_same( array_column( $expected, $field ), array_column( $options, $field ), 'Lightweight picker changed selection or ordering.' ); }
			foreach ( $queries as $sql ) { $assert( ! str_contains( $sql, $tables['post_topics'] ) && ! str_contains( $sql, $tables['topic_categories'] ) && ! str_contains( $sql, $tables['subject_area_categories'] ), 'Picker loaded unrelated links or post counts.' ); }
		}
		$category_id = (int) $categories->categories()[0]['id'];
		$tag = wp_generate_uuid4();
		$ids['topics'] = $must( $topics->create( 'Description topic ' . $tag, 'Original topic definition.', array( $category_id ) ) );
		$ids['subject_areas'] = $must( $areas->create( 'Description area ' . $tag, 'Original area definition.', array( $category_id ) ) );
		$ids['categories'] = $must( $categories->create( 'Description category ' . $tag, 'Original category definition.', array( $ids['subject_areas'] ), array( $ids['topics'] ) ) );
		foreach ( $readers as $tab => $read ) {
			$id = $ids[str_replace( '-', '_', $tab )]; $record = $read( $id );
			$singular = array( 'topics' => 'Topic', 'subject-areas' => 'Subject Area', 'categories' => 'Category' )[$tab];
			$scope = array( 'topics' => 'topic', 'subject-areas' => 'subject_area', 'categories' => 'category' )[$tab];
			$labels = Organization_Page_Labels::for_tab( $tab );
			$assert_same( array_keys( Organization_Page_Labels::for_tab( 'topics' ) ), array_keys( $labels ), 'Tab labels have different contracts.' );
			$assert_same( $singular, $labels['singular'], 'Shared view uses the wrong item type.' );
			$_GET = array( 'tab' => $tab, 'action' => 'new' );
			list( $html, $queries ) = $capture( $render );
			$assert_scoped_counts( $queries, 0 );
			$assert( str_contains( $html, 'Create ' . $singular ) && str_contains( $html, 'name="' . $scope . '_id" value="0"' ), 'Creation form lost its tab-specific label or ID field.' );
			$assert( str_contains( $html, 'data-ebd-topic-form' ) && ! str_contains( $html, 'data-ebd-description-form' ) && ! str_contains( $html, 'data-ebd-topic-search' ), 'Creation renders another view.' );
			$revision = Organization_Description_Service::revision( $record );
			foreach ( array( '', '   ', '<b></b>', str_repeat( 'x', 4001 ) ) as $invalid ) {
				$assert( is_wp_error( $writer->update( $tab, $id, $invalid, $revision ) ), 'Invalid description accepted.' );
			}
			$assert( is_wp_error( $writer->update( $tab, $id, 'New definition', 'stale' ) ), 'Stale description accepted.' );
			$assert( is_wp_error( $writer->update( $tab, PHP_INT_MAX, 'Missing record', '' ) ), 'Missing record accepted.' );
			$assert_same( $record, $read( $id ), 'Rejected save changed the record.' );
			$old_hash = $hash(); $cache_version = get_option( 'ehrman_discovery_ai_cache_version' );
			$must( $writer->update( $tab, $id, "Clarifies <b>the same subject</b>.\nKeeps its original scope.", $revision ) );
			$changed = $read( $id ); $expected = $record;
			$expected['description'] = "Clarifies the same subject.\nKeeps its original scope.";
			$assert_same( $expected, $changed, 'Description edit changed links, names or counts.' );
			$assert_same( 'topics' === $tab, $old_hash !== $hash(), 'Wrong effect on ingestion vocabulary hash.' );
			$assert_same( $cache_version, get_option( 'ehrman_discovery_ai_cache_version' ), 'Description cleared unrelated Ask AI caches.' );
			$assert( is_wp_error( $writer->update( $tab, $id, 'Stale overwrite', $revision ) ), 'Older description overwrote a newer edit.' );
			$must( $writer->update( $tab, $id, $changed['description'], Organization_Description_Service::revision( $changed ) ) );
			$assert_same( $changed, $read( $id ), 'Unchanged description caused other writes.' );
			$_GET = array( 'tab' => $tab, 'edit' => $id );
			list( $html, $queries ) = $capture( $render );
			$assert_scoped_counts( $queries, $id );
			$assert( str_contains( $html, 'name="' . $scope . '_id" value="' . $id . '"' ) && str_contains( $html, esc_html( $record['name'] ) ), 'Relationship view lost the selected item.' );
			$assert( str_contains( $html, 'Edit Description' ) && ! str_contains( $html, 'name="description"' ), 'Description editor is not opt-in.' );
			$_GET['description'] = 'edit';
			list( $html, $queries ) = $capture( $render );
			$assert_scoped_counts( $queries, $id );
			$assert( str_contains( $html, 'name="' . $scope . '_id" value="' . $id . '"' ) && str_contains( $html, esc_html( $record['name'] ) ), 'Description view lost the selected item.' );
			$assert( str_contains( $html, 'Save Description' ) && str_contains( $html, '>Cancel</a>' ) && ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'data-ebd-link-group' ), 'Description editor changes names or relationships.' );
			$assert_same( 'topics' === $tab, str_contains( $html, 'Keep the topic&#039;s meaning unchanged' ), 'Topic safeguard displayed on wrong tab.' );
			$_GET = array( 'tab' => $tab, 'edit' => $id, 'action' => 'delete' );
			list( $delete_html, $queries ) = $capture( $render );
			$assert_scoped_counts( $queries, $id );
			$assert( str_contains( $delete_html, 'Confirm deletion' ), 'Deletion confirmation missing after direct lookup.' );
			if ( 'topics' === $tab ) {
				$assert( str_contains( $html, 'does not reassign existing posts' ) && str_contains( $html, 'regenerated before approval' ), 'Topic consequences are not explained.' );
				$assert( is_wp_error( $topics->update_categories( $id, array( $category_id ), Topic_Organization_Service::revision( $record ) ) ), 'Old relationship form accepted changed definition.' );
				// Relationship changes must not block or be overwritten by an open description editor.
				$revision = Organization_Description_Service::revision( $changed );
				$must( $topics->update_categories( $id, array( $ids['categories'] ), Topic_Organization_Service::revision( $changed ) ) );
				$must( $writer->update( $tab, $id, 'Clarifies the same topic after links changed.', $revision ) );
				$assert_same( array( $ids['categories'] ), array_map( static fn( $row ) => (int) $row['id'], $topics->topic( $id )['categories'] ), 'Description overwrote concurrent links.' );
			}
		}
		$assert( is_wp_error( $writer->update( 'external_posts', $ids['topics'], 'Invalid table', '' ) ), 'Untrusted scope accepted.' );
		$assert( is_wp_error( $writer->update( 'topics', 0, 'Invalid ID', '' ) ), 'Invalid ID accepted.' );
		$protected = current( array_filter( $topics->topics(), static fn( $row ) => ! Topic_Organization_Service::editable( $row ) ) );
		$assert( is_array( $protected ), 'Missing protected topic fixture.' );
		$assert( is_wp_error( $writer->update( 'topics', (int) $protected['id'], 'Protected overwrite', Organization_Description_Service::revision( $protected ) ) ), 'Protected topic description changed.' );
		$wpdb->insert( $tables['browse_paths'], array( 'slug' => 'description-test-' . $tag, 'name' => 'Other path', 'is_active' => 0 ) ); $other_path = (int) $wpdb->insert_id;
		$wpdb->insert( $tables['subject_areas'], array( 'browse_path_id' => $other_path, 'name' => 'Other area', 'slug' => 'other-area', 'description' => 'Other description', 'position' => 0 ) ); $other_area = (int) $wpdb->insert_id;
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['subject_areas']} WHERE id=%d", $other_area ), ARRAY_A );
		$assert( is_wp_error( $writer->update( 'subject-areas', $other_area, 'Cross-path overwrite', Organization_Description_Service::revision( $record ) ) ), 'Retired path description changed.' );
		// Editing a description is valid even when other relationship work has left no links.
		$wpdb->delete( $tables['topic_categories'], array( 'topic_id' => $ids['topics'] ) );
		$record = $topics->topic( $ids['topics'] );
		$must( $writer->update( 'topics', $ids['topics'], 'Clarifies an unlinked topic.', Organization_Description_Service::revision( $record ) ) );
		$assert_same( array(), $topics->topic( $ids['topics'] )['categories'], 'Description added category links.' );
		$_GET = array(); $html = $render();
		$assert( str_contains( $html, 'name="tab" value="subject-areas"' ), 'Default tab is not Subject Areas.' );
		preg_match_all( '/class="nav-tab[^>]+>([^<]+)<\/a>/', $html, $matches );
		$assert_same( array( 'Subject Areas', 'Categories', 'Topics', 'Export' ), $matches[1], 'Wrong tab order.' );
		$_GET = array( 'edit' => $ids['topics'] );
		$assert( str_contains( $render(), 'name="topic_id"' ), 'Legacy topic link changed scope.' );
		$after = Database::counts();
		foreach ( array( 'external_posts', 'post_topics', 'post_keywords', 'post_search_terms', 'post_embeddings' ) as $table ) { $assert_same( $before[$table], $after[$table], 'Description changed post data: ' . $table ); }
	} finally {
		if ( isset( $ids['categories'] ) ) {
			foreach ( array( 'topic_categories', 'subject_area_categories' ) as $key ) { $wpdb->delete( $tables[$key], array( 'category_id' => $ids['categories'] ) ); }
			$wpdb->delete( $tables['categories'], array( 'id' => $ids['categories'] ) );
		}
		if ( isset( $ids['topics'] ) ) { $wpdb->delete( $tables['topic_categories'], array( 'topic_id' => $ids['topics'] ) ); $wpdb->delete( $tables['topics'], array( 'id' => $ids['topics'] ) ); }
		if ( isset( $ids['subject_areas'] ) ) { $wpdb->delete( $tables['subject_area_categories'], array( 'subject_area_id' => $ids['subject_areas'] ) ); $wpdb->delete( $tables['subject_areas'], array( 'id' => $ids['subject_areas'] ) ); }
		if ( $other_area ) { $wpdb->delete( $tables['subject_areas'], array( 'id' => $other_area ) ); }
		if ( $other_path ) { $wpdb->delete( $tables['browse_paths'], array( 'id' => $other_path ) ); }
		wp_set_current_user( $user ); $_GET = $get;
		if ( false === $cache ) { delete_option( 'ehrman_discovery_ai_cache_version' ); } else { update_option( 'ehrman_discovery_ai_cache_version', $cache ); }
	}
	$assert_same( $before, Database::counts(), 'Description fixtures changed stored counts.' );
	echo "Organization lookups and descriptions: direct/directory parity, scoped queries, lightweight pickers, separate saves, scope guards, stale edits, vocabulary, links and post data: OK\n";
} )();
