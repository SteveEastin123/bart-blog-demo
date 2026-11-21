<?php
/** Description edits must not change names, relationships or existing post metadata. */

use EhrmanBlogDiscovery\Category_Organization_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Organization_Description_Service;
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
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$ids = array(); $other_path = 0; $other_area = 0;
	$before = Database::counts();
	try {
		wp_set_current_user( 0 );
		$assert( is_wp_error( $writer->update( 'topics', 1, 'Unauthorized', '' ) ), 'Anonymous description write accepted.' );
		wp_set_current_user( (int) $admins[0] );
		$category_id = (int) $categories->categories()[0]['id'];
		$tag = wp_generate_uuid4();
		$ids['topics'] = $must( $topics->create( 'Description topic ' . $tag, 'Original topic definition.', array( $category_id ) ) );
		$ids['subject_areas'] = $must( $areas->create( 'Description area ' . $tag, 'Original area definition.', array( $category_id ) ) );
		$ids['categories'] = $must( $categories->create( 'Description category ' . $tag, 'Original category definition.', array( $ids['subject_areas'] ), array( $ids['topics'] ) ) );
		$readers = array( 'topics' => array( $topics, 'topic' ), 'subject-areas' => array( $areas, 'area' ), 'categories' => array( $categories, 'category' ) );
		foreach ( $readers as $tab => $read ) {
			$id = $ids[str_replace( '-', '_', $tab )]; $record = $read( $id );
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
			$html = $render();
			$assert( str_contains( $html, 'Edit Description' ) && ! str_contains( $html, 'name="description"' ), 'Description editor is not opt-in.' );
			$_GET['description'] = 'edit'; $html = $render();
			$assert( str_contains( $html, 'Save Description' ) && str_contains( $html, '>Cancel</a>' ) && ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'data-ebd-link-group' ), 'Description editor changes names or relationships.' );
			$assert_same( 'topics' === $tab, str_contains( $html, 'Keep the topic&#039;s meaning unchanged' ), 'Topic safeguard displayed on wrong tab.' );
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
	echo "Organization descriptions: separate saves, scope guards, stale edits, vocabulary, links and post data: OK\n";
} )();
