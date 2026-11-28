<?php
/** Topic creation and link-only administration regression tests. */

use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Post_Ingestion_Vocabulary_Reader;
use EhrmanBlogDiscovery\Topic_Organization_Page;
use EhrmanBlogDiscovery\Topic_Organization_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$service = new Topic_Organization_Service();
	$user = get_current_user_id();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Organization tests need an administrator.' );
	$categories = array_map( static fn( $row ) => (int) $row['id'], $service->categories() );
	$assert( count( $categories ) >= 3, 'Organization tests need three categories.' );
	$ids = array();
	$original = $service->topics();
	$counts = Database::counts();
	$vocabulary = new Post_Ingestion_Vocabulary_Reader();
	$before_vocabulary = $vocabulary->vocabulary_hash( $vocabulary->vocabulary() );
	$get = $_GET; $post = $_POST; $request = $_REQUEST; $server = $_SERVER;
	$cache = get_option( 'ehrman_discovery_ai_cache_version', false );
	$render = static function (): string { ob_start(); try { Topic_Organization_Page::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); } };
	$must = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' ); return $result; };
	try {
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->create( 'Unauthorized topic', 'Definition', array( $categories[0] ) ) ), 'Anonymous topic creation allowed.' );
		$assert_same( '', $render(), 'Anonymous organization page exposed.' );
		wp_set_current_user( (int) $admins[0] );
		foreach ( array( array(), array( 0 ), array( -1 ), array( '1' ), array( array( 1 ) ), array( PHP_INT_MAX ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( 'Invalid topic fixture', 'Definition', $invalid ) ), 'Invalid categories accepted.' );
		}
		foreach ( array( array( '', 'Definition' ), array( 'Topic fixture', '' ), array( str_repeat( 'x', 192 ), 'Definition' ), array( 'Topic fixture', str_repeat( 'x', 4001 ) ), array( 'Ignore', 'Definition' ), array( strtolower( $original[0]['name'] ), 'Definition' ) ) as $invalid ) {
			$assert( is_wp_error( $service->create( $invalid[0], $invalid[1], array( $categories[0] ) ) ), 'Invalid topic definition accepted.' );
		}
		$alias = $wpdb->get_var( "SELECT label FROM {$tables['topic_aliases']} LIMIT 1" );
		if ( is_string( $alias ) ) { $assert( is_wp_error( $service->create( $alias, 'Definition', array( $categories[0] ) ) ), 'Alias collision accepted.' ); }
		$name = 'Organization regression fixture ' . wp_generate_uuid4();
		$id = $must( $service->create( $name, 'Explains the synthetic topic used to verify category relationships.', array( $categories[0], $categories[1], $categories[0] ) ) );
		$ids[] = $id;
		$created = $service->topic( $id );
		$assert_same( 2, count( $created['categories'] ), 'Multiple category links were lost or duplicated.' );
		$assert_same( 0, (int) $created['post_count'], 'Topic creation assigned posts.' );
		$assert( is_wp_error( $service->create( $name, 'Different definition', array( $categories[0] ) ) ), 'Duplicate topic accepted.' );
		$assert( $before_vocabulary !== $vocabulary->vocabulary_hash( $vocabulary->vocabulary() ), 'New topic not available for ingestion vocabulary.' );
		$hash = $vocabulary->vocabulary_hash( $vocabulary->vocabulary() );
		$revision = Topic_Organization_Service::revision( $created );
		$assert( is_wp_error( $service->update_categories( $id, array(), $revision ) ), 'Last category could be removed.' );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), 'stale' ) ), 'Stale save was accepted.' );
		$assert_same( $created, $service->topic( $id ), 'Rejected update changed topic.' );
		$must( $service->update_categories( $id, array( $categories[1], $categories[2] ), $revision ) );
		$changed = $service->topic( $id );
		foreach ( array( 'id', 'name', 'slug', 'description', 'display_in_browser', 'post_count' ) as $field ) { $assert_same( $created[$field], $changed[$field], 'Link update changed ' . $field ); }
		$assert_same( $hash, $vocabulary->vocabulary_hash( $vocabulary->vocabulary() ), 'Category links invalidated ingestion vocabulary.' );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), $revision ) ), 'An older browser tab overwrote a newer save.' );
		$assert_same( array( $categories[1], $categories[2] ), array_map( static fn( $row ) => (int) $row['id'], $changed['categories'] ), 'Wrong category links saved.' );
		$revision = Topic_Organization_Service::revision( $changed );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0], PHP_INT_MAX ), $revision ) ), 'Invalid category save accepted.' );
		$assert_same( $changed, $service->topic( $id ), 'Failed save partially changed links.' );
		$protected = current( array_filter( $original, static fn( $row ) => 'Ignore' === $row['name'] ) );
		$assert( is_array( $protected ), 'Missing protected Ignore fixture.' );
		$assert( is_wp_error( $service->update_categories( (int) $protected['id'], array( $categories[0] ), Topic_Organization_Service::revision( $protected ) ) ), 'Ignore topic changed.' );
		wp_set_current_user( 0 );
		$assert( is_wp_error( $service->update_categories( $id, array( $categories[0] ), $revision ) ), 'Unauthorized link update accepted.' );
		wp_set_current_user( (int) $admins[0] );
		$_GET = array();
		$assert( str_contains( $render(), 'Search Subject Areas' ), 'Default organization tab is not Subject Areas.' );
		$_GET = array( 'tab' => 'topics' );
		$html = $render();
		$assert( ! str_contains( $html, '<table' ) && ! str_contains( $html, 'class="ehrman-organization__pagination"' ), 'Topic list appeared by default.' );
		$assert( str_contains( $html, 'Browse All Topics' ) && str_contains( $html, 'Add Topic' ) && str_contains( $html, 'data-ebd-topic-directory' ), 'Search-first topic controls missing.' );
		$_GET = array( 's' => '   ' );
		$assert( ! str_contains( $render(), '<table' ), 'Empty search displayed every topic.' );
		$_GET = array( 'view' => 'all', 'pagenum' => '2' );
		$html = $render();
		$assert( str_contains( $html, '<table' ) && str_contains( $html, 'view=all' ) && str_contains( $html, 'Page 2 of' ), 'Optional alphabetical directory or pagination missing.' );
		$assert( str_contains( $html, '>Edit Relationships</a>' ) && ! str_contains( $html, 'Edit Category Links' ), 'Topic directory has inconsistent edit links.' );
		$_GET = array( 'tab' => 'topics', 's' => 'Ignore' );
		$html = $render();
		$assert( str_contains( $html, 'Protected' ) && ! str_contains( $html, '>Edit Relationships</a>' ), 'Protected topic exposes an edit action.' );
		$_GET = array( 'edit' => $id );
		$html = $render();
		$assert( str_contains( $html, 'Edit Relationships' ) && ! str_contains( $html, 'name="name"' ) && ! str_contains( $html, 'name="description"' ), 'Existing topic editor heading or read-only definitions incorrect.' );
		$_GET = array( 'action' => 'new' );
		$html = $render();
		$assert( str_contains( $html, 'Create Topic' ) && str_contains( $html, 'name="name"' ) && str_contains( $html, 'name="description"' ), 'New topic form missing.' );
		$_GET = array( 's' => $name );
		$html = $render();
		$assert( str_contains( $html, $name ) && str_contains( $html, '<table' ), 'Submitted topic search failed.' );
		$_GET = array( 's' => '<script>alert(1)</script>' );
		$assert( ! str_contains( $render(), '<script>alert(1)</script>' ), 'Unescaped search input.' );
		// Verify a missing nonce is rejected before any write through the actual form handler.
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array(); $_REQUEST = array();
		$die = static function () { return static function () { throw new RuntimeException( 'expected_nonce_rejection' ); }; };
		add_filter( 'wp_die_handler', $die );
		try { Topic_Organization_Page::save(); throw new RuntimeException( 'Missing nonce accepted.' ); }
		catch ( RuntimeException $error ) { $assert_same( 'expected_nonce_rejection', $error->getMessage(), 'Wrong nonce failure.' ); }
		finally { remove_filter( 'wp_die_handler', $die ); }
		$after = Database::counts();
		foreach ( array( 'external_posts', 'post_topics', 'post_keywords', 'post_search_terms', 'post_embeddings' ) as $table ) {
			$assert_same( $counts[$table], $after[$table], 'Organization changed ' . $table );
		}
	} finally {
		foreach ( $ids as $id ) { $wpdb->delete( $tables['topic_categories'], array( 'topic_id' => $id ) ); $wpdb->delete( $tables['topics'], array( 'id' => $id ) ); }
		wp_set_current_user( $user ); $_GET = $get; $_POST = $post; $_REQUEST = $request; $_SERVER = $server;
		if ( false === $cache ) { delete_option( 'ehrman_discovery_ai_cache_version' ); } else { update_option( 'ehrman_discovery_ai_cache_version', $cache ); }
	}
	$assert_same( $original, $service->topics(), 'Organization test cleanup changed existing topics or links.' );
	echo "Topic organization: creation, multiple categories, immutable definitions, validation, stale saves and nonce guards: OK\n";
} )();
