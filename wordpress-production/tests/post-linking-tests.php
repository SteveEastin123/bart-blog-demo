<?php
/** Explicit post linking and imported-record maintenance, using disposable native fixtures. */

use EhrmanBlogDiscovery\AI_Metadata_Usage;
use EhrmanBlogDiscovery\Data_Installer;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Page;
use EhrmanBlogDiscovery\Post_Ingestion_Review_Workflow;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Link_Plan;
use EhrmanBlogDiscovery\Post_Linker;
use EhrmanBlogDiscovery\Semantic_Index_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same ): void {
	$wpdb = Database::client(); $tables = Database::tables();
	$source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ); $user = get_current_user_id(); $get = $_GET;
	$receipt = get_option( 'ehrman_discovery_install_receipt', null );
	$setting = get_option( 'ehrman_ingestion_default_mode', 'manual' );
	$native_ids = array(); $indexed_ids = array(); $calls = 0; $mock_proposal = null; $embedding_failure = false;
	$site = 'https://link-fixture.invalid';
	$hash_method = new ReflectionMethod( Data_Installer::class, 'database_hash' );
	$hash = static fn() => $hash_method->invoke( new Data_Installer() );
	$original_hash = $hash();
	$http = static function ( $pre, $args, $url ) use ( &$calls, &$mock_proposal, &$embedding_failure ) {
		++$calls;
		if ( null === $mock_proposal ) return new WP_Error( 'link-test-http', 'Network is blocked.' );
		if ( str_ends_with( $url, '/responses' ) ) {
			$body = array( 'id' => 'link-test-response', 'status' => 'completed', 'output_text' => wp_json_encode( $mock_proposal ), 'usage' => array( 'input_tokens' => 20, 'output_tokens' => 30 ) );
		} elseif ( str_ends_with( $url, '/embeddings' ) ) {
			if ( $embedding_failure ) return new WP_Error( 'link-test-vector', 'Simulated vector failure.' );
			$blob = array_fill( 0, Embedding_Service::dimensions(), 0.0 ); $blob[1] = 1.0;
			$body = array( 'model' => Embedding_Service::model_id(), 'data' => array( array( 'index' => 0, 'embedding' => $blob ) ), 'usage' => array( 'prompt_tokens' => 30, 'total_tokens' => 30 ) );
		} else { return new WP_Error( 'link-test-http', 'Unexpected request blocked.' ); }
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
	};
	$send = static function ( int $id, string $action, array $values = array() ) {
		$request = new WP_REST_Request( '' === $action ? 'GET' : 'POST', '/ehrman-discovery/v1/ingestion/post/' . $id . ( '' === $action ? '' : '/' . $action ) );
		$request->set_body_params( $values );
		return rest_get_server()->dispatch( $request );
	};
	$expect_error = static function ( callable $work, string $needle ) use ( $assert ): void {
		try { $work(); } catch ( RuntimeException $error ) {
			$assert( str_contains( $error->getMessage(), $needle ), 'Unexpected linking error: ' . $error->getMessage() ); return;
		}
		throw new RuntimeException( 'Linking should have rejected: ' . $needle );
	};
	$make = static function ( string $name, string $mode = '' ) use ( $wpdb, $tables, $site, &$native_ids, &$indexed_ids ): array {
		$id = wp_insert_post( array( 'post_title' => 'Imported linking fixture ' . $name, 'post_name' => 'linking-' . wp_generate_uuid4(), 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => str_repeat( 'This fixture discusses historical interpretation and how evidence supports scholarly conclusions. ', 25 ) ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$native_ids[] = $id;
		$url = $site . substr( get_permalink( $id ), strlen( rtrim( home_url(), '/' ) ) );
		if ( 'path' === $mode ) $url .= 'different/';
		$data = array( 'source_wp_id' => $id, 'title' => 'Imported linking fixture ' . $name, 'url' => $url, 'url_hash' => hash( 'sha256', $url, true ), 'author' => 'Fixture author', 'date_text' => 'November 17, 2026', 'published_at' => '2026-11-17 00:00:00', 'description' => 'Explores historical interpretation and the use of evidence in scholarly conclusions.', 'search_summary' => 'Explains historical interpretation, evaluates evidence, and considers competing scholarly conclusions. Distinguishes historical reasoning from assumptions.' );
		if ( false === $wpdb->insert( $tables['external_posts'], $data ) ) throw new RuntimeException( 'Could not create linking fixture.' );
		$index = (int) $wpdb->insert_id; $indexed_ids[] = $index;
		if ( 'title' === $mode ) wp_update_post( array( 'ID' => $id, 'post_title' => 'Different article with the same ID' ) );
		if ( 'private' === $mode ) wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
		return array( $id, $index );
	};
	try {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] ); update_option( 'ehrman_ingestion_default_mode', 'manual' );
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' ); add_filter( 'pre_http_request', $http, -100, 3 );
		[ $id, $index ] = $make( 'valid' );
		[ $second_id, $second_index ] = $make( 'second' );
		[ $wrong_id, $wrong_index ] = $make( 'wrong title', 'title' );
		[ $path_id, $path_index ] = $make( 'wrong path', 'path' );
		[ $private_id, $private_index ] = $make( 'private', 'private' );
		[ $missing_id, $missing_index ] = $make( 'missing' ); wp_delete_post( $missing_id, true );
		$topic = (int) $wpdb->get_var( "SELECT id FROM {$tables['topics']} WHERE name<>'Ignore' ORDER BY id LIMIT 1" );
		$keyword = (int) $wpdb->get_var( "SELECT id FROM {$tables['keywords']} ORDER BY id LIMIT 1" );
		foreach ( array( $index, $second_index ) as $entry ) {
			$wpdb->insert( $tables['post_topics'], array( 'post_id' => $entry, 'topic_id' => $topic ) );
			$wpdb->insert( $tables['post_keywords'], array( 'post_id' => $entry, 'keyword_id' => $keyword ) );
		}
		$live = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $index ), ARRAY_A );
		$vector = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $id ), ARRAY_A );
		$blob = array_fill( 0, Embedding_Service::dimensions(), 0.0 ); $blob[0] = 1.0;
		$wpdb->insert( $tables['post_embeddings'], array( 'source_wp_id' => $id, 'model' => Embedding_Service::model_id(), 'dimensions' => count( $blob ), 'content_hash' => Semantic_Index_Service::content_hash( $live() ), 'embedding' => pack( 'g*', ...$blob ), 'embedding_norm' => 1, 'updated_at' => current_time( 'mysql', true ) ) );
		$original_vector = $vector(); $original_live = $live(); $before = $hash(); $counts = Database::counts();
		$planner = new Post_Link_Plan(); $linker = new Post_Linker(); $service = new Post_Ingestion_Service();
		$plan = $planner->build( $site );
		$assert_same( 2, $plan['ready'], 'Only the two verified fixture posts should match.' );
		$wpdb->update( $tables['external_posts'], array( 'wordpress_post_id' => $id ), array( 'id' => $wrong_index ) );
		$assert_same( 1, $planner->build( $site )['ready'], 'A conflicting owner was ignored.' );
		$wpdb->update( $tables['external_posts'], array( 'wordpress_post_id' => null ), array( 'id' => $wrong_index ) );
		$assert_same( $before, $hash(), 'Preview modified discovery data.' );
		$assert_same( $counts, Database::counts(), 'Preview created ingestion history.' );
		$assert_same( null, $service->latest_for_post( $id ), 'Imported metadata was automatically adopted before linking.' );
		$assert_same( 0, $planner->build( 'https://wrong-source.invalid' )['ready'], 'Wrong source site was accepted.' );
		$expect_error( static fn() => $planner->build( '/relative' ), 'absolute' );
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
		$expect_error( static fn() => $linker->apply( $site, $plan['fingerprint'] ), 'database-managed' );
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
		$expect_error( static fn() => $linker->apply( $site, '' ), '--expect' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Changed after preview' ) );
		$expect_error( static fn() => $linker->apply( $site, $plan['fingerprint'] ), 'changed since preview' );
		wp_update_post( array( 'ID' => $id, 'post_title' => $original_live['title'] ) );
		$assert_same( $original_live, $live(), 'Rejected preview changed ownership.' );
		$plan = $planner->build( $site );

		// A failure on the second baseline must roll back both ownership and the first baseline.
		$inserts = 0;
		$fail = static function ( string $sql ) use ( $tables, &$inserts ): string {
			if ( str_starts_with( $sql, 'INSERT INTO `' . $tables['ingestion_drafts'] . '`' ) && ++$inserts === 2 ) return 'INSERT INTO missing_link_fixture_table (id) VALUES (1)';
			return $sql;
		};
		$errors = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
		try { $expect_error( static fn() => $linker->apply( $site, $plan['fingerprint'] ), 'baseline' ); }
		finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $errors ); }
		$assert_same( 2, $inserts, 'Rollback injection did not reach the second baseline.' );
		$assert_same( $before, $hash(), 'Failed linking left partial ownership.' );
		$assert_same( $counts, Database::counts(), 'Failed linking left baseline rows.' );

		// The verified installation receipt must advance with links, but never conceal prior drift.
		update_option( 'ehrman_discovery_install_receipt', array( 'database_hash' => $hash(), 'source_checksum' => 'fixture', 'vector_checksum' => 'fixture' ) );
		$before_receipt = get_option( 'ehrman_discovery_install_receipt' );
		$fail_receipt = static function ( string $sql ) use ( $wpdb ): string {
			return str_starts_with( $sql, 'INSERT INTO `' . $wpdb->options . '`' ) && str_contains( $sql, 'ehrman_discovery_install_receipt' ) ? 'INSERT INTO missing_link_receipt_table (id) VALUES (1)' : $sql;
		};
		$errors = $wpdb->suppress_errors( true ); add_filter( 'query', $fail_receipt );
		try { $expect_error( static fn() => $linker->apply( $site, $plan['fingerprint'] ), 'bookkeeping' ); }
		finally { remove_filter( 'query', $fail_receipt ); $wpdb->suppress_errors( $errors ); }
		$assert_same( $before, $hash(), 'Receipt failure left ownership writes.' );
		$assert_same( $before_receipt, get_option( 'ehrman_discovery_install_receipt' ), 'Receipt failure changed the saved receipt.' );
		$assert_same( $counts, Database::counts(), 'Receipt failure retained baselines.' );
		$plan = $planner->build( $site ); $result = $linker->apply( $site, $plan['fingerprint'] );
		$assert_same( 2, $result['ready'], 'Verified matches were not applied.' );
		$assert_same( $hash(), get_option( 'ehrman_discovery_install_receipt' )['database_hash'], 'Linking broke the verified installation receipt.' );
		$expected_live = $original_live; $expected_live['wordpress_post_id'] = (string) $id;
		$assert_same( $expected_live, $live(), 'Linking changed metadata beyond ownership.' );
		$assert_same( $original_vector, $vector(), 'Linking regenerated or changed the supplied vector.' );
		foreach ( array( $wrong_index, $path_index, $private_index, $missing_index ) as $unresolved ) {
			$assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT wordpress_post_id FROM %i WHERE id=%d', $tables['external_posts'], $unresolved ) ), 'An unresolved record was linked.' );
		}
		$parent = $service->latest_for_post( $id );
		$assert_same( 'imported', $parent['approval_mode'], 'Imported provenance was lost.' );
		$assert_same( null, $parent['approved_at'], 'Linking invented a publisher approval.' );
		$assert_same( null, $parent['post_text'], 'Linking copied article content into the baseline.' );
		$assert_same( 'complete', $parent['embedding_status'], 'Supplied current vector was not recognized.' );
		$usage_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ai_usage']}" );
		AI_Metadata_Usage::import_history();
		$assert_same( $usage_count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['ai_usage']}" ), 'Linking invented AI usage.' );
		$plan = $planner->build( $site ); $again_counts = Database::counts();
		$assert_same( 0, $linker->apply( $site, $plan['fingerprint'] )['ready'], 'Repeat linking was not a no-op.' );
		$assert_same( $again_counts, Database::counts(), 'Repeat linking duplicated history.' );
		$_GET = array( 'draft' => (int) $parent['id'] ); ob_start(); Post_Ingestion_Page::render(); $html = ob_get_clean();
		$assert( str_contains( $html, 'Imported metadata; no new approval' ) && str_contains( $html, 'Edit Metadata' ) && str_contains( $html, 'Regenerate Metadata' ), 'Imported Tools page is missing provenance or maintenance controls.' );
		$assert_same( (int) $parent['id'], $send( $id, '' )->get_data()['draft']['id'], 'Editor cannot see imported metadata.' );
		wp_set_current_user( 0 ); $assert_same( 403, $send( $id, 'edit-metadata' )->get_status(), 'Anonymous editor modified imported metadata.' ); wp_set_current_user( (int) $admins[0] );
		$response = $send( $id, 'edit-metadata' );
		$assert_same( 200, $response->get_status(), 'Imported metadata could not be edited: ' . wp_json_encode( $response->get_data() ) );
		$revision = $service->latest_for_post( $id );
		$assert_same( $expected_live, $live(), 'Opening imported revision changed live metadata.' );
		$cancel = $send( $id, 'cancel-revision', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ) ) );
		$assert_same( 200, $cancel->get_status(), 'Imported revision cancellation failed.' );
		$assert_same( (int) $parent['id'], (int) $service->latest_for_post( $id )['id'], 'Cancellation lost the imported baseline.' );
		$revision = $service->edit_metadata( (int) $parent['id'] );
		$proposal = json_decode( $revision['proposal_json'], true );
		$approve = $send( $id, 'approve', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $revision ), 'proposal' => array( 'description' => $proposal['description'], 'search_summary' => $proposal['searchSummary'], 'removed_topics' => array(), 'removed_keywords' => array() ) ) );
		$assert_same( 200, $approve->get_status(), 'Imported revision approval failed: ' . wp_json_encode( $approve->get_data() ) );
		$assert_same( $original_vector, $vector(), 'Unchanged title/summary did not reuse the imported vector.' );
		$assert_same( 0, $calls, 'Preview, linking or unchanged manual revision made a network call.' );

		// Regenerate directly from a second imported baseline; failed vector preparation cannot replace live metadata.
		$second_parent = $service->latest_for_post( $second_id );
		$mock_proposal = json_decode( $second_parent['proposal_json'], true );
		$mock_proposal['searchSummary'] .= ' Examines a newly generated interpretation without altering the original evidence.';
		$mock_proposal['topicRationales'] = array_map( static fn( $name ) => array( 'topic' => $name, 'rationale' => 'The fixture develops this historical subject throughout the article.' ), $mock_proposal['topics'] );
		$second_live = static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $second_index ), ARRAY_A );
		$second_before = $second_live();
		$response = $send( $second_id, 'reanalyze', array( 'revision' => Post_Ingestion_Review_Workflow::revision( $second_parent ) ) );
		$assert_same( 200, $response->get_status(), 'Regenerating imported metadata failed: ' . wp_json_encode( $response->get_data() ) );
		$assert_same( $second_before, $second_live(), 'Regeneration replaced live metadata before approval.' );
		$generated = $service->latest_for_post( $second_id );
		$values = array( 'revision' => Post_Ingestion_Review_Workflow::revision( $generated ), 'proposal' => array( 'description' => $mock_proposal['description'], 'search_summary' => $mock_proposal['searchSummary'], 'removed_topics' => array(), 'removed_keywords' => array() ) );
		$embedding_failure = true;
		$assert( $send( $second_id, 'approve', $values )->get_status() >= 400, 'Simulated embedding failure was ignored.' );
		$assert_same( $second_before, $second_live(), 'Failed vector preparation changed imported metadata.' );
		$embedding_failure = false;
		$response = $send( $second_id, 'approve', $values );
		$assert_same( 200, $response->get_status(), 'Regenerated imported metadata could not be approved: ' . wp_json_encode( $response->get_data() ) );
		$assert_same( $mock_proposal['searchSummary'], $second_live()['search_summary'], 'Approved regeneration was not applied.' );
		$assert_same( (string) $second_index, $second_live()['id'], 'Regeneration duplicated the imported search record.' );
		$assert_same( 3, $calls, 'Unexpected simulated AI call count.' );
		$drifted = get_option( 'ehrman_discovery_install_receipt' );
		$plan = $planner->build( $site ); $linker->apply( $site, $plan['fingerprint'] );
		$assert_same( $drifted, get_option( 'ehrman_discovery_install_receipt' ), 'Repeat linking repaired a receipt after editorial changes.' );
		wp_delete_post( $id, true );
		$assert_same( null, $live(), 'Permanent native deletion retained linked imported metadata.' );
		$assert_same( null, $vector(), 'Permanent native deletion retained the imported vector.' );
		$assert_same( null, $service->latest_for_post( $id ), 'Permanent deletion retained linked history.' );
		$assert( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d', $tables['external_posts'], $second_index ) ), 'Deleting a linked post removed another record.' );
		echo "Post linking: preview, identity checks, stale plans, JSON guard, rollback/receipt failures, idempotence, imported revisions/regeneration, vector reuse/failure and deletion: OK\n";
	} finally {
		remove_filter( 'pre_http_request', $http, -100 );
		foreach ( $native_ids as $id ) {
			wp_delete_post( $id, true );
			$wpdb->delete( $tables['ingestion_drafts'], array( 'wordpress_post_id' => $id ) );
			$wpdb->delete( $tables['post_embeddings'], array( 'source_wp_id' => $id ) );
			$wpdb->delete( $tables['ai_usage'], array( 'post_id' => $id ) );
		}
		foreach ( $indexed_ids as $id ) {
			foreach ( array( 'post_topics', 'post_keywords', 'post_search_terms' ) as $table ) $wpdb->delete( $tables[ $table ], array( 'post_id' => $id ) );
			$wpdb->delete( $tables['external_posts'], array( 'id' => $id ) );
		}
		null === $receipt ? delete_option( 'ehrman_discovery_install_receipt' ) : update_option( 'ehrman_discovery_install_receipt', $receipt );
		update_option( 'ehrman_ingestion_default_mode', $setting );
		putenv( false === $source ? 'EHRMAN_DISCOVERY_POST_SOURCE' : 'EHRMAN_DISCOVERY_POST_SOURCE=' . $source );
		wp_set_current_user( $user ); $_GET = $get; wp_cache_flush();
	}
	$assert_same( $original_hash, $hash(), 'Post-linking fixtures changed the original discovery data.' );
} )();
