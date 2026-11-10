<?php
/** Native-post lifecycle regression. Runs only in the disposable regression database. */

use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Index_Transfer;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Lifecycle;
use EhrmanBlogDiscovery\Post_Ingestion_Post_Source;
use EhrmanBlogDiscovery\Post_Ingestion_Queue;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Validator;
use EhrmanBlogDiscovery\Post_Visibility;
use EhrmanBlogDiscovery\Search_Post_Repository;
use EhrmanBlogDiscovery\Search_Service;
use EhrmanBlogDiscovery\Search_Suggestion_Service;
use EhrmanBlogDiscovery\Semantic_Index_Service;
use EhrmanBlogDiscovery\Semantic_Post_Repository;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$old_source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
	$old_key = getenv( 'OPENAI_API_KEY' );
	$before = Database::counts();
	$posts = array();
	$store = new Post_Ingestion_Repository();
	$service = new Post_Ingestion_Service();
	$search = new Search_Post_Repository();
	$browse = new Browse_Service();
	$taxonomy = $service->vocabulary();
	$topic_record = array_values( array_filter( $taxonomy['topics'], static fn( $row ) => 'Ignore' !== $row['name'] ) )[0];
	$proposal = array(
		'description' => 'Explains how reviewed metadata supports searching while respecting the publication status of a WordPress post and preserving its assigned subjects.',
		'searchSummary' => 'Explains the lifecycle of approved search metadata for a WordPress post. Describes how publishing makes indexed topics and summaries available to readers, while trashing or unpublishing hides the post without discarding its stored vector. Distinguishes permanent deletion from restoration and verifies that shared taxonomy definitions remain available for other posts.',
		'topics' => array( $topic_record['name'] ), 'topicRationales' => array(),
		'secondaryKeywords' => array( $taxonomy['keywords'][0]['label'] ), 'newSecondaryKeywords' => array(),
		'status' => 'ready', 'reviewNotes' => array(),
	);
	$hash = $store->vocabulary_hash( $taxonomy );
	$metrics = array( 'response_id' => 'mock', 'input_tokens' => 1, 'cached_input_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0, 'estimated_cost_usd' => 0 );
	$topic = $search->record_by_slug( 'topics', sanitize_title( $proposal['topics'][0] ) );
	$assert( null !== $topic, 'Lifecycle fixture topic missing.' );
	$category = $browse->topic_category( (int) $topic['id'] );
	$base_count = $category ? $browse->category_post_count( (int) $category['id'] ) : 0;
	$base_topic_count = count( $search->topic_post_ids( (int) $topic['id'] ) );
	$autocomplete_cases = array(
		'unscoped' => array( $topic['name'] ),
		'topic-scope' => array( '', array(), '', $topic['slug'] ),
		'selected-topic' => array( '', array( $topic['name'] ), '', '', array( Search_Service::TERM_MODE_TOPIC ) ),
		'selected-keyword' => array( '', array( $proposal['secondaryKeywords'][0] ), '', '', array( Search_Service::TERM_MODE_KEYWORD ) ),
		'selected-combined' => array( '', array( $topic['name'] ), '', '', array( Search_Service::TERM_MODE_COMBINED ) ),
	);
	if ( $category ) {
		$autocomplete_cases['category-list'] = array( '', array(), $category['slug'] );
		$autocomplete_cases['category-prefix'] = array( $topic['name'], array(), $category['slug'] );
	}
	$capture_autocomplete = static function () use ( $autocomplete_cases, $wpdb, $assert ): array {
		$queries = array();
		$record_query = static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
		add_filter( 'query', $record_query );
		try {
			$results = array();
			$autocomplete = new Search_Service();
			foreach ( $autocomplete_cases as $name => $args ) {
				$results[$name] = $autocomplete->suggestions( ...$args );
			}
		} finally {
			remove_filter( 'query', $record_query );
		}
		$assert( ! empty( $queries ), 'Autocomplete did not execute its index queries.' );
		foreach ( $queries as $sql ) {
			$assert( ! str_contains( $sql, $wpdb->posts ), 'Autocomplete queried native publication status.' );
		}
		return $results;
	};
	$package = get_temp_dir() . 'lifecycle-handoff-' . wp_generate_uuid4() . '.jsonl.gz';
	$http_calls = 0;
	$delete_during_embedding = 0;
	$mock_http = static function ( $preempt, $args, $url ) use ( &$http_calls, &$delete_during_embedding, $assert_same ) {
		++$http_calls;
		$assert_same( 'https://api.openai.com/v1/embeddings', $url, 'Lifecycle test attempted a non-embedding API request.' );
		if ( $delete_during_embedding ) { wp_delete_post( $delete_during_embedding, true ); }
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array(
			'data' => array( array( 'index' => 0, 'embedding' => array_fill( 0, Embedding_Service::dimensions(), 0.1 ) ) ),
			'usage' => array( 'prompt_tokens' => 12, 'total_tokens' => 12 ),
		) ) );
	};
	$make_post = static function () use ( &$posts, $wpdb, $tables, $assert ): int {
		$id = 1000 + max( (int) $wpdb->get_var( "SELECT MAX(source_wp_id) FROM {$tables['external_posts']}" ), (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) );
		$result = wp_insert_post( array( 'import_id' => $id, 'post_author' => (int) $wpdb->get_var( "SELECT MIN(ID) FROM {$wpdb->users}" ), 'post_title' => 'Disposable metadata lifecycle fixture', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => str_repeat( 'This regression article tests the metadata workflow and lifecycle of a published WordPress post. ', 10 ) ), true );
		$assert( ! is_wp_error( $result ), 'Could not create lifecycle fixture.' );
		$posts[] = (int) $result;
		return (int) $result;
	};
	$make_draft = static function ( int $id ) use ( $store, $hash, $assert ): int {
		$input = ( new Post_Ingestion_Validator() )->validate_submission( Post_Ingestion_Post_Source::input( get_post( $id ) ) );
		$assert( ! is_wp_error( $input ), is_wp_error( $input ) ? $input->get_error_message() : 'Invalid lifecycle fixture input.' );
		$draft = $store->create_draft( $input, $hash, 0, $id );
		$assert( ! is_wp_error( $draft ), 'Could not create lifecycle draft.' );
		return (int) $draft;
	};
	add_filter( 'pre_http_request', $mock_http, 99, 3 );
	try {
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
		putenv( 'OPENAI_API_KEY=lifecycle-mock-key' );
		$autocomplete_before = $capture_autocomplete();
		$id = $make_post();
		$draft = $make_draft( $id );
		$store->store_analysis( $draft, $proposal, $metrics );
		$approved = $service->approve( $draft, false );
		$assert( ! is_wp_error( $approved ), is_wp_error( $approved ) ? $approved->get_error_message() : 'Lifecycle approval failed.' );
		$external_id = (int) $approved['approved_post_id'];
		$assert_same( $id, (int) $approved['wordpress_post_id'], 'Draft ownership was lost.' );
		$assert_same( $id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wordpress_post_id FROM %i WHERE id=%d', $tables['external_posts'], $external_id ) ), 'Approved post ownership was lost.' );
		$assert_same( 'complete', $approved['embedding_status'], 'Mock vector did not complete.' );
		$vector = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $id ), ARRAY_A );
		$assert( ! empty( $vector ), 'Approved post vector missing.' );
		$assert_same( $base_topic_count + 1, count( $search->topic_post_ids( (int) $topic['id'] ) ), 'Published post was not counted.' );
		$autocomplete_published = $capture_autocomplete();
		$assert( ! empty( $autocomplete_published['unscoped'] ), 'Autocomplete fixture produced no suggestions.' );
		$assert( $autocomplete_before['unscoped'] !== $autocomplete_published['unscoped'], 'Autocomplete did not count the fixture metadata.' );
		$candidates = ( new Semantic_Post_Repository() )->candidate_rows();
		$assert( in_array( $external_id, array_map( 'intval', array_column( $candidates, 'id' ) ), true ), 'Published post missing from semantic candidates.' );

		// An otherwise valid export containing a database-only test post must not pass the handoff check.
		$eligible = array();
		foreach ( ( new Semantic_Index_Service() )->eligible_posts() as $post ) {
			if ( (int) $post['source_wp_id'] !== $id ) { $eligible[(int) $post['source_wp_id']] = $post; }
		}
		$transfer = new Embedding_Index_Transfer();
		$transfer->export( $package );
		$rejected = false;
		try { $transfer->read_package( $package, $eligible ); }
		catch ( RuntimeException $error ) { $rejected = str_contains( $error->getMessage(), 'unknown or ineligible' ); }
		$assert( $rejected, 'Database-only test vector passed handoff validation.' );

		$version = AI_Usage::cache_version();
		wp_trash_post( $id );
		$assert( $version !== AI_Usage::cache_version(), 'Trashing did not invalidate cached search results.' );
		foreach ( array( 'trash', 'draft', 'private' ) as $status ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
			$assert_same( $autocomplete_published, $capture_autocomplete(), 'Autocomplete stopped using retained index metadata after unpublishing.' );
			$results = ( new Search_Service() )->search( array( $topic['name'] ), 'ranked', '', '', 1, 0, array( Search_Service::TERM_MODE_TOPIC ) );
			$assert( ! in_array( $external_id, array_map( 'intval', array_column( $results['posts'], 'id' ) ), true ), 'Autocomplete opt-out exposed an unpublished search result.' );
			$assert_same( array(), $search->posts_by_ids( array( $external_id ) ), 'An unpublished post remained searchable.' );
			$assert_same( array(), Post_Visibility::filter_posts( array( array( 'id' => $external_id ) ) ), 'An in-flight candidate remained visible after unpublishing.' );
			$assert_same( $base_topic_count, count( $search->topic_post_ids( (int) $topic['id'] ) ), 'Hidden post changed topic counts.' );
			$assert( ! isset( $search->post_scores_for_term( $topic['name'] )[ $external_id ] ), 'Hidden post remained in keyword scores.' );
			$assert( ! isset( $search->post_scores_for_term( $topic['name'], Search_Service::TERM_MODE_TOPIC )[ $external_id ] ), 'Hidden post remained in topic scores.' );
			if ( $category ) {
				$assert_same( $base_count, $browse->category_post_count( (int) $category['id'] ), 'Hidden post changed browse counts.' );
				$assert( ! isset( $search->category_post_ids( (int) $category['id'] )[ $external_id ] ), 'Hidden post remained in category search.' );
			}
			$rows = ( new Semantic_Post_Repository() )->candidate_rows();
			$assert( ! in_array( $external_id, array_map( 'intval', array_column( $rows, 'id' ) ), true ), 'Hidden post remained in semantic candidates.' );
			$assert( is_wp_error( ( new Semantic_Index_Service() )->build_post_embedding( $id, true ) ), 'Unpublished post vector regeneration was allowed.' );
		}
		$assert_same( 1, $http_calls, 'Unpublishing initiated a paid request.' );
		$assert_same( $vector, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $id ), ARRAY_A ), 'Unpublishing discarded the vector.' );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$assert_same( 1, count( $search->posts_by_ids( array( $external_id ) ) ), 'Republishing did not restore search visibility.' );
		$assert_same( 1, $http_calls, 'Republishing regenerated the vector.' );

		// Deletion during a mocked embedding call must not recreate orphan vector rows.
		$delete_during_embedding = $id;
		$result = ( new Semantic_Index_Service( new Embedding_Service( null, false ) ) )->build_post_embedding( $id, true );
		$delete_during_embedding = 0;
		$assert( is_wp_error( $result ), 'A deleted post accepted a late vector response.' );
		$assert_same( null, $store->draft( $draft ), 'Permanent deletion retained owned ingestion history.' );
		$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], $id ) ), 'Permanent deletion left an orphan vector.' );
		foreach ( array( 'post_topics', 'post_keywords', 'post_search_terms' ) as $key ) {
			$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id=%d', $tables[$key], $external_id ) ), 'Permanent deletion left search assignments.' );
		}
		$assert_same( $autocomplete_before, $capture_autocomplete(), 'Permanent deletion did not restore autocomplete counts and suggestions.' );

		$id = $make_post();
		$draft = $make_draft( $id );
		Post_Ingestion_Queue::schedule( $draft );
		$attempt = $store->claim_analysis( $draft );
		wp_trash_post( $id );
		$assert_same( false, wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $draft ) ), 'Trash did not cancel the queued job.' );
		$assert_same( 'error', $store->draft( $draft )['status'], 'Trash did not stop the worker lease.' );
		$assert_same( false, $store->store_analysis( $draft, $proposal, $metrics, null, $attempt ), 'A late analysis response overwrote canceled work.' );
		wp_delete_post( $id, true );
		$assert_same( null, $store->draft( $draft ), 'Deleting a pending post retained its draft.' );

		// A source ID in the import is not proof that a native post owns that record.
		$imported = $wpdb->get_row( "SELECT p.* FROM {$tables['external_posts']} p LEFT JOIN {$wpdb->posts} w ON w.ID=p.source_wp_id WHERE w.ID IS NULL AND p.wordpress_post_id IS NULL LIMIT 1", ARRAY_A );
		$assert( is_array( $imported ), 'Missing ID-collision fixture.' );
		$collision = wp_insert_post( array( 'import_id' => (int) $imported['source_wp_id'], 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Unrelated native fixture' ), true );
		$assert( ! is_wp_error( $collision ), 'Could not create collision fixture.' );
		$posts[] = (int) $collision;
		$assert_same( (int) $imported['source_wp_id'], (int) $collision, 'Collision test did not use the same source ID.' );
		wp_trash_post( $collision );
		$assert_same( 1, count( $search->posts_by_ids( array( (int) $imported['id'] ) ) ), 'Trashing an unrelated local post hid imported content.' );
		wp_delete_post( $collision, true );
		$assert_same( $imported, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $imported['id'] ), ARRAY_A ), 'ID collision deleted an unrelated imported article.' );
	} finally {
		remove_filter( 'pre_http_request', $mock_http, 99 );
		foreach ( $posts as $id ) { wp_delete_post( $id, true ); Post_Ingestion_Lifecycle::cleanup_deleted( $id ); }
		if ( is_file( $package ) ) { unlink( $package ); }
		false === $old_source ? putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ) : putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=' . $old_source );
		false === $old_key ? putenv( 'OPENAI_API_KEY' ) : putenv( 'OPENAI_API_KEY=' . $old_key );
	}
	$assert_same( $before, Database::counts(), 'Lifecycle tests did not restore the database counts.' );
	echo "Post lifecycle: native ownership, visibility, restore, deletion, worker fencing and handoff exclusion: OK\n";
} )();
