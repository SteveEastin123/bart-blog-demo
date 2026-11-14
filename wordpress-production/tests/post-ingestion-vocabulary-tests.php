<?php
/** Vocabulary approval guards: stable definitions, legacy drafts, and real usage changes. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Post_Source;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Validator;
use EhrmanBlogDiscovery\Semantic_Index_Service;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

( static function () use ( $assert, $assert_same, $wpdb, $tables ): void {
	$store = new Post_Ingestion_Repository();
	$service = new Post_Ingestion_Service();
	$taxonomy = $store->vocabulary();
	$original_taxonomy = $taxonomy;
	$hash = $store->vocabulary_hash( $taxonomy );
	$legacy_hash = hash( 'sha256', wp_json_encode( $taxonomy ) );
	$assert_same( $original_taxonomy, $taxonomy, 'Hashing modified vocabulary used by generation.' );
	$assert( $store->vocabulary_matches( $hash, $taxonomy ), 'Current fingerprint was rejected.' );
	$assert( $store->vocabulary_matches( $legacy_hash, $taxonomy ), 'Unchanged legacy fingerprint was rejected.' );
	foreach ( array( '', str_repeat( '0', 64 ) ) as $invalid ) {
		$assert( ! $store->vocabulary_matches( $invalid, $taxonomy ), 'Invalid fingerprint bypassed vocabulary checks.' );
	}
	$count_changed = $taxonomy;
	++$count_changed['keywords'][0]['count'];
	$assert_same( $hash, $store->vocabulary_hash( $count_changed ), 'Usage count changed the definition fingerprint.' );
	$assert( $store->vocabulary_matches( $hash, $count_changed ), 'Usage-only change rejected a current draft.' );
	$assert( ! $store->vocabulary_matches( $legacy_hash, $count_changed ), 'An unverifiable legacy snapshot was accepted.' );
	$mutations = array(
		'topic identity' => static function ( &$v ) { ++$v['topics'][0]['id']; },
		'topic name' => static function ( &$v ) { $v['topics'][0]['name'] .= ' changed'; },
		'topic description' => static function ( &$v ) { $v['topics'][0]['description'] .= ' changed'; },
		'topic alias' => static function ( &$v ) { $v['topics'][0]['aliases'][] = 'Additional alias'; },
		'topic added' => static function ( &$v ) { $v['topics'][] = array( 'id' => -1, 'name' => 'New topic', 'description' => '', 'aliases' => array() ); },
		'topic removed' => static function ( &$v ) { array_pop( $v['topics'] ); },
		'keyword identity' => static function ( &$v ) { ++$v['keywords'][0]['id']; },
		'keyword label' => static function ( &$v ) { $v['keywords'][0]['label'] .= ' changed'; },
		'keyword normalization' => static function ( &$v ) { $v['keywords'][0]['normalized'] .= ' changed'; },
		'keyword added' => static function ( &$v ) { $v['keywords'][] = array( 'id' => -1, 'label' => 'New keyword', 'normalized' => 'new keyword', 'count' => 0 ); },
		'keyword removed' => static function ( &$v ) { array_pop( $v['keywords'] ); },
	);
	foreach ( $mutations as $name => $mutate ) {
		$changed = $taxonomy;
		$mutate( $changed );
		foreach ( array( $hash, $legacy_hash ) as $stored_hash ) {
			$assert( ! $store->vocabulary_matches( $stored_hash, $changed ), 'Vocabulary check missed ' . $name . '.' );
		}
	}

	$source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
	$key = getenv( 'OPENAI_API_KEY' );
	$user = get_current_user_id();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'Vocabulary approval tests require an administrator.' );
	$admin = (int) $admins[0];
	$topic = current( array_filter( $taxonomy['topics'], static fn( $item ) => 'Ignore' !== $item['name'] ) );
	$keyword = current( array_filter( $taxonomy['keywords'], static fn( $item ) => ! in_array( $item['label'], array_column( $taxonomy['topics'], 'name' ), true ) ) );
	$assert( is_array( $topic ) && is_array( $keyword ), 'Vocabulary approval fixtures require an existing topic and keyword.' );
	$proposal = array(
		'description' => 'Explains how changes in keyword usage leave pending metadata approvals valid while changes to the actual vocabulary still require fresh analysis.',
		'searchSummary' => 'Explains how publishers can approve separate posts using the same existing keyword without invalidating pending metadata. Distinguishes changing usage counts from changes to topic names, descriptions, aliases, or keyword definitions. Describes checks for older pending drafts and approved metadata revisions while preserving the live search index until approval.',
		'topics' => array( $topic['name'] ), 'topicRationales' => array(),
		'secondaryKeywords' => array( $keyword['label'] ), 'newSecondaryKeywords' => array(),
		'status' => 'ready', 'reviewNotes' => array(),
	);
	$posts = array();
	$http_calls = 0;
	$block_http = static function () use ( &$http_calls ) { ++$http_calls; return new WP_Error( 'fixture_http_blocked', 'No external calls in vocabulary tests.' ); };
	$must = static function ( $value ) use ( $assert ) {
		$assert( ! is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : '' );
		return $value;
	};
	$create = static function ( string $label, bool $legacy = false ) use ( &$posts, $admin, $store, $must, $proposal, $wpdb, $tables ): int {
		$id = $must( wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $admin,
			'post_title' => 'Vocabulary regression fixture ' . $label,
			'post_content' => 'A synthetic article for testing pending approvals when another post uses an existing keyword.' ), true ) );
		$posts[] = $id;
		$input = $must( ( new Post_Ingestion_Validator() )->validate_submission( Post_Ingestion_Post_Source::input( get_post( $id ) ) ) );
		$vocabulary = $store->vocabulary();
		$fingerprint = $legacy ? hash( 'sha256', wp_json_encode( $vocabulary ) ) : $store->vocabulary_hash( $vocabulary );
		$draft_id = $must( $store->create_draft( $input, $fingerprint, $admin, $id ) );
		$must_update = $wpdb->update( $tables['ingestion_drafts'], array( 'status' => 'ready', 'proposal_json' => wp_json_encode( $proposal ) ), array( 'id' => $draft_id ) );
		if ( false === $must_update ) { throw new RuntimeException( 'Could not prepare vocabulary fixture.' ); }
		return $draft_id;
	};
	add_filter( 'pre_http_request', $block_http );
	try {
		putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
		putenv( 'OPENAI_API_KEY=' );
		wp_set_current_user( $admin );
		$first_id = $create( 'current A' );
		$second_id = $create( 'current B' );
		$legacy_id = $create( 'legacy unchanged', true );
		$stale_id = $create( 'legacy stale', true );
		$legacy = $must( $service->approve( $legacy_id, false ) );
		$assert_same( 'approved', $legacy['status'], 'An unchanged pre-upgrade draft could not be approved.' );
		$stale = $service->approve( $stale_id, false );
		$assert_same( 'ehrman_ingestion_taxonomy_changed', is_wp_error( $stale ) ? $stale->get_error_code() : '', 'Unverifiable older draft did not require regeneration.' );
		$assert_same( 'ready', $store->draft( $stale_id )['status'], 'Rejected legacy draft was modified.' );

		$second = $must( $service->approve( $second_id, false ) );
		$after = $store->vocabulary();
		$assert_same( $hash, $store->vocabulary_hash( $after ), 'Other approvals changed vocabulary definitions.' );
		$assert( $legacy_hash !== hash( 'sha256', wp_json_encode( $after ) ), 'Fixture approvals did not change usage counts.' );
		$assert_same( $keyword['count'] + 2, current( array_filter( $after['keywords'], static fn( $item ) => $item['id'] === $keyword['id'] ) )['count'], 'Existing keyword usage was not retained for generation.' );

		// A real vocabulary change must still stop approval without changing the pending draft.
		$wpdb->update( $tables['topics'], array( 'description' => $topic['description'] . ' Changed definition.' ), array( 'id' => $topic['id'] ) );
		try {
			$rejected = $service->approve( $first_id, false );
			$assert_same( 'ehrman_ingestion_taxonomy_changed', is_wp_error( $rejected ) ? $rejected->get_error_code() : '', 'Changed topic definition bypassed approval checks.' );
			$assert_same( 'ready', $store->draft( $first_id )['status'], 'Rejected draft was modified.' );
		} finally {
			$wpdb->update( $tables['topics'], array( 'description' => $topic['description'] ), array( 'id' => $topic['id'] ) );
		}
		$first = $must( $service->approve( $first_id, false ) );
		$assert_same( 'approved', $first['status'], 'Other posts using an existing keyword blocked initial approval.' );

		// Reuse a synthetic current vector to exercise manual revision approval without an API call.
		$native_id = (int) $first['wordpress_post_id'];
		$live = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $first['approved_post_id'] ), ARRAY_A );
		$vector = array_fill( 0, Embedding_Service::dimensions(), 0.0 );
		$vector[0] = 1.0;
		$wpdb->replace( $tables['post_embeddings'], array( 'source_wp_id' => $native_id, 'content_hash' => Semantic_Index_Service::content_hash( $live ),
			'model' => Embedding_Service::model_id(), 'dimensions' => count( $vector ), 'embedding' => pack( 'g*', ...$vector ), 'embedding_norm' => 1,
			'updated_at' => current_time( 'mysql', true ) ) );
		$revision = $must( $service->edit_metadata( $first_id ) );
		wp_delete_post( (int) $second['wordpress_post_id'], true );
		$revised = $must( $service->approve( (int) $revision['id'], false ) );
		$assert_same( 'approved', $revised['status'], 'Removing another keyword assignment blocked revision approval.' );
		$assert_same( 'complete', $revised['embedding_status'], 'Revision did not reuse the unchanged vector.' );
		$assert_same( $first['proposal_json'], $store->draft( $first_id )['proposal_json'], 'Vocabulary compatibility changed approved history.' );
		$assert_same( 0, $http_calls, 'Vocabulary regression attempted an external API call.' );
	} finally {
		foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
		remove_filter( 'pre_http_request', $block_http );
		wp_set_current_user( $user );
		putenv( false === $source ? 'EHRMAN_DISCOVERY_POST_SOURCE' : 'EHRMAN_DISCOVERY_POST_SOURCE=' . $source );
		putenv( false === $key ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $key );
	}
	$assert_same( $original_taxonomy, $store->vocabulary(), 'Vocabulary test cleanup changed definitions or usage counts.' );
	echo "Vocabulary approval: usage changes allowed; definition changes blocked; legacy snapshots checked; initial and revision approvals: OK\n";
} )();
