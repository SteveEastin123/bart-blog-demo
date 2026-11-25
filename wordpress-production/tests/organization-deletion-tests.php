<?php
/** Deletion is opt-in, rejects references/stale state, and never deletes child data. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Organization_Deletion_Service;
use EhrmanBlogDiscovery\Organization_Deletion_Settings;
use EhrmanBlogDiscovery\Post_Ingestion_Approval_Writer;
use EhrmanBlogDiscovery\Post_Ingestion_Vocabulary_Reader;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$user = get_current_user_id();
	$old = get_option( Organization_Deletion_Settings::OPTION, false );
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$before = Database::counts(); $ids = array();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$service = new Organization_Deletion_Service();
	$must = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' ); return $result; };
	$blocked = static function ( $result ) use ( $assert ) { $assert( is_wp_error( $result ), 'Unsafe deletion accepted.' ); };
	$add = static function ( $table, $values ) use ( $wpdb, $tables, &$ids, $assert ): int {
		$assert( false !== $wpdb->insert( $tables[$table], $values ), 'Fixture insert failed.' );
		$id = (int) $wpdb->insert_id; $ids[$table][] = $id; return $id;
	};
	$path = (int) $wpdb->get_var( "SELECT id FROM {$tables['browse_paths']} WHERE slug='browse-topics' AND is_active=1" );
	$tag = wp_generate_uuid4();
	try {
		wp_set_current_user( (int) $admins[0] );
		delete_option( Organization_Deletion_Settings::OPTION );
		$assert_same( false, Organization_Deletion_Settings::enabled(), 'Deletion did not default off.' );
		Organization_Deletion_Settings::register_setting();
		foreach ( array( true, 1, 'yes', 'ENABLED', array( 'enabled' ) ) as $bad ) {
			$assert_same( 'disabled', sanitize_option( Organization_Deletion_Settings::OPTION, $bad ), 'Malformed setting enabled deletion.' );
		}
		$topic = $add( 'topics', array( 'name' => 'Delete topic ' . $tag, 'slug' => 'delete-topic-' . $tag, 'description' => 'Deletion fixture.', 'display_in_browser' => 1 ) );
		$category = $add( 'categories', array( 'name' => 'Delete category ' . $tag, 'slug' => 'delete-category-' . $tag, 'description' => 'Deletion fixture.' ) );
		$other_category = $add( 'categories', array( 'name' => 'Other category ' . $tag, 'slug' => 'other-category-' . $tag, 'description' => 'Deletion fixture.' ) );
		$area = $add( 'subject_areas', array( 'name' => 'Delete area ' . $tag, 'slug' => 'delete-area-' . $tag, 'description' => 'Deletion fixture.', 'browse_path_id' => $path ) );
		$other_area = $add( 'subject_areas', array( 'name' => 'Other area ' . $tag, 'slug' => 'other-area-' . $tag, 'description' => 'Deletion fixture.', 'browse_path_id' => $path ) );
		$preview = $must( $service->preview( 'topics', $topic ) );
		$blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		update_option( Organization_Deletion_Settings::OPTION, 'enabled' );
		wp_set_current_user( 0 );
		$blocked( $service->preview( 'topics', $topic ) ); $blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		wp_set_current_user( (int) $admins[0] );
		foreach ( array( 'keywords', 'external_posts', 'export', '' ) as $bad_tab ) {
			$blocked( $service->preview( $bad_tab, $topic ) ); $blocked( $service->delete( $bad_tab, $topic, '' ) );
		}
		foreach ( array( 0, -1, PHP_INT_MAX ) as $bad_id ) { $blocked( $service->delete( 'topics', $bad_id, '' ) ); }
		$protected = current( array_filter( (new Topic_Organization_Service())->topics(), static fn( $row ) => ! Topic_Organization_Service::editable( $row ) ) );
		$blocked( $service->preview( 'topics', (int) $protected['id'] ) ); $blocked( $service->delete( 'topics', (int) $protected['id'], '' ) );
		$wpdb->insert( $tables['subject_area_categories'], array( 'subject_area_id' => $area, 'category_id' => $category ) );
		$wpdb->insert( $tables['topic_categories'], array( 'topic_id' => $topic, 'category_id' => $category ) );
		$post = (int) $wpdb->get_var( "SELECT id FROM {$tables['external_posts']} ORDER BY id LIMIT 1" );
		$wpdb->insert( $tables['post_topics'], array( 'topic_id' => $topic, 'post_id' => $post ) );
		$preview = $must( $service->preview( 'subject-areas', $area ) );
		$assert_same( array(), $preview['blockers'], 'A category without another subject area blocks deletion.' );
		$wpdb->insert( $tables['subject_area_categories'], array( 'subject_area_id' => $area, 'category_id' => $other_category ) );
		$wpdb->insert( $tables['subject_area_categories'], array( 'subject_area_id' => $other_area, 'category_id' => $other_category ) );
		$blocked( $service->delete( 'subject-areas', $area, $preview['revision'] ) );
		$preview = $must( $service->preview( 'subject-areas', $area ) );
		$assert_same( array(), $preview['blockers'], 'Shared or unlinked categories block subject-area deletion.' );
		$preserve = static function () use ( $wpdb, $tables, $category, $other_category, $topic, $post ): array {
			return array(
				$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['categories']} WHERE id IN (%d,%d) ORDER BY id", $category, $other_category ), ARRAY_A ),
				$wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['topics']} WHERE id=%d", $topic ), ARRAY_A ),
				$wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['external_posts']} WHERE id=%d", $post ), ARRAY_A ),
				$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['topic_categories']} WHERE topic_id=%d ORDER BY category_id", $topic ), ARRAY_A ),
				$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['post_topics']} WHERE topic_id=%d ORDER BY post_id", $topic ), ARRAY_A ),
			);
		};
		$preserved = $preserve();
		$fail = static fn( $sql ) => str_starts_with( $sql, "DELETE FROM `{$tables['subject_areas']}`" ) ? 'INVALID DELETION TEST SQL' : $sql;
		$suppress = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $blocked( $service->delete( 'subject-areas', $area, $preview['revision'] ) ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $suppress ); }
		$assert_same( $preview, $must( $service->preview( 'subject-areas', $area ) ), 'Failed subject-area deletion lost its links.' );
		$must( $service->delete( 'subject-areas', $area, $preview['revision'] ) );
		$assert_same( $preserved, $preserve(), 'Deleting an area changed categories, topics, posts or their assignments.' );
		$assert_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['subject_areas']} WHERE id=%d", $area ) ), 'Subject area not deleted.' );
		$assert_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['subject_area_categories']} WHERE subject_area_id=%d OR category_id=%d", $area, $category ) ), 'Subject-area links remain after deletion.' );
		$assert_same( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['subject_area_categories']} WHERE subject_area_id=%d AND category_id=%d", $other_area, $other_category ) ), 'Deleting an area changed another area link.' );
		$preview = $must( $service->preview( 'categories', $category ) );
		$assert( str_contains( implode( ' ', $preview['blockers'] ), 'Link these Topics' ), 'Last category not protected.' );
		$blocked( $service->delete( 'categories', $category, $preview['revision'] ) );
		$wpdb->insert( $tables['topic_categories'], array( 'topic_id' => $topic, 'category_id' => $other_category ) );
		$preview = $must( $service->preview( 'categories', $category ) );
		$must( $service->delete( 'categories', $category, $preview['revision'] ) );
		$assert_same( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['topics']} WHERE id=%d", $topic ) ), 'Deleting category deleted topic.' );
		$preview = $must( $service->preview( 'topics', $topic ) );
		$assert( str_contains( implode( ' ', $preview['blockers'] ), 'assigned to 1 post.' ), 'Assigned post not explained.' );
		$blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		$wpdb->delete( $tables['post_topics'], array( 'topic_id' => $topic ) );
		$wpdb->insert( $tables['topic_aliases'], array( 'topic_id' => $topic, 'label' => 'Deletion alias', 'normalized' => 'deletion alias ' . $tag ) );
		$preview = $must( $service->preview( 'topics', $topic ) );
		$wpdb->update( $tables['topics'], array( 'description' => 'Changed after confirmation.' ), array( 'id' => $topic ) );
		$blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		$preview = $must( $service->preview( 'topics', $topic ) );
		update_option( Organization_Deletion_Settings::OPTION, 'disabled' );
		$blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		update_option( Organization_Deletion_Settings::OPTION, 'enabled' );
		// A later failure must restore the earlier link and alias deletions.
		$fail = static fn( $sql ) => str_starts_with( $sql, "DELETE FROM `{$tables['topics']}`" ) ? 'INVALID DELETION TEST SQL' : $sql;
		$suppress = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $blocked( $service->delete( 'topics', $topic, $preview['revision'] ) ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $suppress ); }
		$assert_same( $preview, $must( $service->preview( 'topics', $topic ) ), 'Failed deletion was not rolled back.' );
		$vocabulary = (new Post_Ingestion_Vocabulary_Reader())->vocabulary();
		$name = $preview['record']['name'];
		// A second connection cannot delete a Topic while approval holds its shared lock.
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true ); $other->query( 'SET SESSION innodb_lock_wait_timeout=1' );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$lock = new ReflectionMethod( Post_Ingestion_Approval_Writer::class, 'lock_topics' );
			$lock->invoke( new Post_Ingestion_Approval_Writer(), array( 'topics' => array( $name ) ), $vocabulary );
			$assert_same( false, $other->delete( $tables['topics'], array( 'id' => $topic ) ), 'In-flight approval did not block concurrent deletion.' );
			$assert( str_contains( $other->last_error, 'Lock wait timeout' ), 'Concurrency test failed for a reason other than the held lock.' );
		} finally { $wpdb->query( 'ROLLBACK' ); $other->close(); }
		$must( $service->delete( 'topics', $topic, $preview['revision'] ) );
		foreach ( array( 'topic_categories', 'topic_aliases', 'post_topics' ) as $table ) {
			$assert_same( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables[$table]} WHERE topic_id=%d", $topic ) ), 'Dangling topic references.' );
		}
		$blocked( $service->delete( 'topics', $topic, $preview['revision'] ) );
		$proposal = array( 'topics' => array( $name ) );
		$result = (new Post_Ingestion_Approval_Writer())->approve( 0, array(), $proposal, $vocabulary, '{}' );
		$blocked( $result ); $assert( str_contains( $result->get_error_message(), 'no longer exists' ), 'Stale in-flight approval did not reject deleted topic.' );
		foreach ( array( 'subject-areas' => $other_area, 'categories' => $other_category ) as $tab => $id ) {
			$preview = $must( $service->preview( $tab, $id ) ); $must( $service->delete( $tab, $id, $preview['revision'] ) );
		}
		$retired = $add( 'browse_paths', array( 'name' => 'Retired deletion test', 'slug' => 'retired-delete-' . $tag, 'is_active' => 0 ) );
		$retired_area = $add( 'subject_areas', array( 'browse_path_id' => $retired, 'name' => 'Retired area', 'slug' => 'retired', 'description' => 'Protected.' ) );
		$blocked( $service->preview( 'subject-areas', $retired_area ) );
		$assert( get_option( 'ehrman_discovery_ai_cache_version' ) !== $cache, 'Successful deletion did not invalidate caches.' );
	} finally {
		foreach ( $ids['topics'] ?? array() as $id ) {
			foreach ( array( 'topic_categories', 'topic_aliases', 'post_topics' ) as $key ) { $wpdb->delete( $tables[$key], array( 'topic_id' => $id ) ); }
		}
		foreach ( $ids['categories'] ?? array() as $id ) {
			foreach ( array( 'topic_categories', 'subject_area_categories' ) as $key ) { $wpdb->delete( $tables[$key], array( 'category_id' => $id ) ); }
		}
		foreach ( $ids['subject_areas'] ?? array() as $id ) { $wpdb->delete( $tables['subject_area_categories'], array( 'subject_area_id' => $id ) ); }
		foreach ( $ids as $table => $items ) { foreach ( $items as $id ) { $wpdb->delete( $tables[$table], array( 'id' => $id ) ); } }
		foreach ( array( Organization_Deletion_Settings::OPTION => $old, 'ehrman_discovery_ai_cache_version' => $cache ) as $key => $value ) {
			if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); }
		}
		wp_set_current_user( $user );
	}
	$assert_same( $before, Database::counts(), 'Deletion tests changed stored counts.' );
	echo "Organization deletion: opt-in, permissions, references, stale state, rollback, aliases and in-flight approval: OK\n";
} )();
