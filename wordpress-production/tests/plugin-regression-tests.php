<?php
/**
 * Focused regression checks for production WordPress plugin workflows.
 *
 * Run through WP-CLI after the authoritative import is complete.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\AI_Analytics_Page;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Index_Transfer;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Semantic_Search_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert_same = static function ( $expected, $actual, string $message ) use ( $assert ): void {
	$assert( $expected === $actual, $message . ' Expected ' . var_export( $expected, true ) . ', found ' . var_export( $actual, true ) . '.' );
};

$wpdb   = Database::client();
$tables = Database::tables();

/* Keep the selected Ask AI 2 pipeline free of the retired metadata-vector experiment. */
$assert( ! isset( $tables['post_metadata_embeddings'] ), 'The retired metadata-vector table returned to the active schema.' );
$assert_same( 'hybrid-1', Semantic_Search_Service::pipeline_version(), 'The selected semantic pipeline version changed.' );

/* Verify complete, missing, stale, and orphaned semantic-vector coverage. */
$semantic = new Semantic_Search_Service();
$baseline = $semantic->status();
$assert_same( 'hybrid', $baseline['strategy'], 'The selected semantic retrieval method changed.' );
$assert( ! isset( $baseline['metadata'] ), 'Retired metadata-vector status returned to the active runtime.' );
$max_id   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(source_wp_id),0) FROM {$tables['external_posts']}" );
$wp_id    = max( 900000000, $max_id + 1000000 );
$title    = 'Semantic coverage regression fixture';
$summary  = 'Explains how semantic coverage distinguishes current, missing, stale, and obsolete title-and-summary vectors.';
$url      = 'https://example.test/ehrman-semantic-coverage-' . $wp_id . '/';

if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
	throw new RuntimeException( 'Could not start the semantic coverage test transaction.' );
}

try {
	$inserted = $wpdb->insert(
		$tables['external_posts'],
		array(
			'source_wp_id'   => $wp_id,
			'title'          => $title,
			'url'            => $url,
			'url_hash'       => hash( 'sha256', $url, true ),
			'author'         => 'Regression Test',
			'date_text'      => 'January 1, 2026',
			'published_at'   => '2026-01-01 12:00:00',
			'description'    => 'Exercises semantic index coverage without changing authoritative discovery data.',
			'search_summary' => $summary,
		)
	);
	$assert( false !== $inserted, 'Could not insert the semantic coverage fixture post.' );

	$missing = $semantic->status();
	$assert_same( $baseline['eligible'] + 1, $missing['eligible'], 'Missing-vector eligibility was counted incorrectly.' );
	$assert_same( $baseline['current'], $missing['current'], 'A post without a vector was counted as current.' );
	$assert_same( $baseline['missing'] + 1, $missing['missing'], 'A missing vector was not reported.' );
	$assert_same( false, $missing['ready'], 'An index with a missing vector was reported as ready.' );

	$dimensions   = Embedding_Service::dimensions();
	$content_text = 'Title: ' . $title . "\nSummary: " . $summary;
	$inserted     = $wpdb->insert(
		$tables['post_embeddings'],
		array(
			'source_wp_id'   => $wp_id,
			'content_hash'   => hash( 'sha256', $content_text ),
			'model'          => Embedding_Service::model_id(),
			'dimensions'     => $dimensions,
			'embedding'      => str_repeat( pack( 'g', 0.0 ), $dimensions ),
			'embedding_norm' => 1,
			'updated_at'     => current_time( 'mysql', true ),
		)
	);
	$assert( false !== $inserted, 'Could not insert the current semantic vector fixture.' );

	$current = $semantic->status();
	$assert_same( $baseline['current'] + 1, $current['current'], 'A valid vector was not counted as current.' );
	$assert_same( $baseline['missing'], $current['missing'], 'The valid vector remained marked missing.' );
	$assert_same( $baseline['stale'], $current['stale'], 'The valid vector was marked stale.' );
	$assert_same( $baseline['ready'], $current['ready'], 'Adding a current vector changed readiness unexpectedly.' );

	$wpdb->update(
		$tables['post_embeddings'],
		array( 'content_hash' => str_repeat( '0', 64 ) ),
		array( 'source_wp_id' => $wp_id )
	);
	$stale = $semantic->status();
	$assert_same( $baseline['current'], $stale['current'], 'A stale vector was counted as current.' );
	$assert_same( $baseline['stale'] + 1, $stale['stale'], 'A stale vector was not reported.' );
	$assert_same( false, $stale['ready'], 'An index with a stale vector was reported as ready.' );

	$wpdb->delete( $tables['external_posts'], array( 'source_wp_id' => $wp_id ) );
	$obsolete = $semantic->status();
	$assert_same( $baseline['eligible'], $obsolete['eligible'], 'Deleting the fixture post did not restore eligibility.' );
	$assert_same( $baseline['obsolete'] + 1, $obsolete['obsolete'], 'An orphaned vector was not reported as obsolete.' );
	$assert_same( $baseline['ready'], $obsolete['ready'], 'An obsolete vector incorrectly affected readiness.' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

$restored = $semantic->status();
foreach ( array( 'eligible', 'indexed', 'stored', 'current', 'missing', 'stale', 'obsolete', 'ready' ) as $field ) {
	$assert_same( $baseline[ $field ], $restored[ $field ], "Semantic coverage rollback did not restore {$field}." );
}

/* Verify portable vector export, restoration, and repeat-import idempotence. */
$assert( $restored['ready'], 'The portable-vector regression requires a complete semantic index.' );
$transfer             = new Embedding_Index_Transfer();
$package_file         = get_temp_dir() . 'ehrman-post-embeddings-regression-' . wp_generate_uuid4() . '.jsonl.gz';
$invalid_package_file = get_temp_dir() . 'ehrman-post-embeddings-invalid-' . wp_generate_uuid4() . '.jsonl.gz';
$fixture              = Database::associative_row(
	$wpdb->get_row( "SELECT * FROM {$tables['post_embeddings']} ORDER BY source_wp_id LIMIT 1", ARRAY_A )
);
$assert( null !== $fixture, 'The portable-vector regression could not select a vector fixture.' );

try {
	$exported = $transfer->export( $package_file );
	$assert_same( $restored['eligible'], $exported['count'], 'The vector export did not include every eligible post.' );
	$assert( $exported['bytes'] > 0 && is_file( $package_file ), 'The vector export did not create a nonempty package.' );

	$fixture_wp_id = Database::integer( $fixture['source_wp_id'] ?? null );
	$deleted       = $wpdb->delete( $tables['post_embeddings'], array( 'source_wp_id' => $fixture_wp_id ), array( '%d' ) );
	$assert_same( 1, $deleted, 'The vector fixture could not be removed before import.' );

	$imported = $transfer->import( $package_file );
	$assert_same( $restored['eligible'], $imported['records'], 'The vector importer validated an unexpected record count.' );
	$assert_same( 1, $imported['imported'], 'The vector importer did not restore the missing fixture.' );
	$assert_same( $restored['eligible'] - 1, $imported['skipped'], 'The vector importer did not skip unchanged records.' );
	$assert_same( 0, $imported['missing'], 'The complete vector package reported missing records.' );
	$assert_same( 0, $imported['rejected'], 'The valid vector package reported rejected records.' );

	$repeat = $transfer->import( $package_file );
	$assert_same( 0, $repeat['imported'], 'Repeat vector import was not idempotent.' );
	$assert_same( $restored['eligible'], $repeat['skipped'], 'Repeat vector import did not skip every unchanged record.' );

	$round_trip = $semantic->status();
	foreach ( array( 'eligible', 'indexed', 'stored', 'current', 'missing', 'stale', 'obsolete', 'ready' ) as $field ) {
		$assert_same( $restored[ $field ], $round_trip[ $field ], "Vector round trip changed semantic status field {$field}." );
	}

	$invalid_handle = gzopen( $invalid_package_file, 'wb9' );
	$assert( false !== $invalid_handle, 'The invalid vector-package fixture could not be opened.' );
	$invalid_header = wp_json_encode(
		array(
			'format'       => 'ehrman-post-embeddings',
			'version'      => 1,
			'contentBasis' => 'title-summary-v1',
			'model'        => Embedding_Service::model_id(),
			'dimensions'   => Embedding_Service::dimensions() + 1,
			'count'        => 1,
			'createdAt'    => gmdate( 'c' ),
		)
	);
	$assert( is_string( $invalid_header ) && false !== gzwrite( $invalid_handle, $invalid_header . "\n" ), 'The invalid vector-package fixture could not be written.' );
	gzclose( $invalid_handle );
	$rejected = false;
	try {
		$transfer->import( $invalid_package_file );
	} catch ( RuntimeException $error ) {
		$rejected = str_contains( $error->getMessage(), 'dimensions' );
	}
	$assert( $rejected, 'An incompatible vector package was not rejected.' );
	$after_rejection = $semantic->status();
	foreach ( array( 'eligible', 'indexed', 'stored', 'current', 'missing', 'stale', 'obsolete', 'ready' ) as $field ) {
		$assert_same( $restored[ $field ], $after_rejection[ $field ], "Rejected package changed semantic status field {$field}." );
	}
} finally {
	if ( null !== $fixture ) {
		$wpdb->replace(
			$tables['post_embeddings'],
			array(
				'source_wp_id'   => Database::integer( $fixture['source_wp_id'] ?? null ),
				'content_hash'   => Database::text( $fixture['content_hash'] ?? null ),
				'model'          => Database::text( $fixture['model'] ?? null ),
				'dimensions'     => Database::integer( $fixture['dimensions'] ?? null ),
				'embedding'      => Database::text( $fixture['embedding'] ?? null ),
				'embedding_norm' => Database::text( $fixture['embedding_norm'] ?? null ),
				'updated_at'     => Database::text( $fixture['updated_at'] ?? null ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}
	if ( is_file( $package_file ) ) {
		unlink( $package_file );
	}
	if ( is_file( $invalid_package_file ) ) {
		unlink( $invalid_package_file );
	}
}

/* Verify approval commits the post while a missing API key leaves its vector pending. */
$original_source = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
$original_key    = getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
$ingestion       = new Post_Ingestion_Service();
$ingestion_store = new Post_Ingestion_Repository();
$taxonomy        = $ingestion->vocabulary();
$topic           = null;
foreach ( $taxonomy['topics'] as $candidate ) {
	if ( 'Ignore' !== $candidate['name'] ) {
		$topic = $candidate;
		break;
	}
}
$assert( is_array( $topic ), 'The ingestion regression test requires at least one non-Ignore topic.' );

$ingestion_wp_id = $wp_id + 1;
$ingestion_url   = 'https://example.test/ehrman-ingestion-' . $ingestion_wp_id . '/';
$proposal        = array(
	'description'          => 'Explains how a representative post exercises approval while preserving its assigned topic, summary, description, and searchable metadata for readers.',
	'searchSummary'        => 'Explains how this representative post exercises the administrator approval workflow and its database updates. Describes the selected topic, searchable summary, and associated records created during approval. Confirms that a missing ingestion API key leaves vector generation pending without undoing the approved post.',
	'topics'               => array( $topic['name'] ),
	'topicRationales'      => array(),
	'secondaryKeywords'    => array(),
	'newSecondaryKeywords' => array(),
	'status'               => 'ready',
	'reviewNotes'          => array(),
);
$taxonomy_json    = wp_json_encode( $taxonomy );
$draft_id         = 0;
$approved_post_id = 0;
$assert( is_string( $taxonomy_json ), 'The ingestion regression taxonomy fixture could not be encoded.' );

try {
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' );
	$created = $ingestion_store->create_draft(
		array(
			'source_wp_id' => $ingestion_wp_id,
			'title'        => 'Ingestion approval regression fixture',
			'url'          => $ingestion_url,
			'author'       => 'Regression Test',
			'date_text'    => 'January 2, 2026',
			'published_at' => '2026-01-02 12:00:00',
			'post_text'    => 'Temporary post text retained only until approval.',
		),
		hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' ),
		0
	);
	$assert( ! is_wp_error( $created ), is_wp_error( $created ) ? $created->get_error_message() : 'Could not create the ingestion approval fixture.' );
	$draft_id = (int) $created;
	$ingestion_store->store_analysis(
		$draft_id,
		$proposal,
		array(
			'response_id'         => 'regression-analysis',
			'input_tokens'        => 10,
			'cached_input_tokens' => 0,
			'output_tokens'       => 10,
			'reasoning_tokens'    => 0,
			'estimated_cost_usd'  => 0.0,
		)
	);

	$approved = $ingestion->approve( $draft_id, false );
	$assert( ! is_wp_error( $approved ), is_wp_error( $approved ) ? $approved->get_error_message() : 'Ingestion approval failed.' );
	$assert_same( 'approved', $approved['status'], 'The ingestion draft was not marked approved.' );
	$assert_same( 'pending', $approved['embedding_status'], 'A missing ingestion API key did not leave the vector pending.' );
	$assert( str_contains( $approved['embedding_error'], 'API key' ), 'The pending vector did not retain a useful configuration error.' );
	$approved_post_id = (int) $approved['approved_post_id'];
	$assert( $approved_post_id > 0, 'The approved post identifier was not retained.' );
	$assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['post_topics']} WHERE post_id=%d", $approved_post_id ) ), 'The approved topic relationship was not created.' );
	$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['post_embeddings']} WHERE source_wp_id=%d", $ingestion_wp_id ) ), 'A vector row was created without an API key.' );
} finally {
	if ( $approved_post_id > 0 ) {
		$wpdb->delete( $tables['post_search_terms'], array( 'post_id' => $approved_post_id ) );
		$wpdb->delete( $tables['post_keywords'], array( 'post_id' => $approved_post_id ) );
		$wpdb->delete( $tables['post_topics'], array( 'post_id' => $approved_post_id ) );
	}
	$wpdb->delete( $tables['post_embeddings'], array( 'source_wp_id' => $ingestion_wp_id ) );
	$wpdb->delete( $tables['external_posts'], array( 'source_wp_id' => $ingestion_wp_id ) );
	if ( $draft_id > 0 ) {
		$wpdb->delete( $tables['ingestion_drafts'], array( 'id' => $draft_id ) );
	}
	false === $original_source ? putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ) : putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=' . $original_source );
	false === $original_key ? putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' ) : putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' . $original_key );
}

/* Verify total and average analytics costs include initial and refinement calls. */
$summary_method = new ReflectionMethod( AI_Analytics_Page::class, 'summary' );
$analytics      = $summary_method->invoke(
	null,
	array(
		array(
			'request_id'         => 'request-1',
			'estimated_cost_usd' => '0.10',
			'cache_hit'          => 0,
			'input_tokens'       => 100,
			'output_tokens'      => 20,
			'total_tokens'       => 120,
			'result_recorded'    => 1,
			'result_count'       => 10,
		),
		array(
			'request_id'         => 'request-2',
			'estimated_cost_usd' => '0.00',
			'cache_hit'          => 1,
			'input_tokens'       => 0,
			'output_tokens'      => 0,
			'total_tokens'       => 0,
			'result_recorded'    => 1,
			'result_count'       => 5,
		),
	),
	array(
		array(
			'request_id'         => 'request-1',
			'estimated_cost_usd' => '0.20',
			'cache_hit'          => 0,
			'input_tokens'       => 200,
			'output_tokens'      => 40,
			'total_tokens'       => 240,
		),
	)
);

$assert( abs( $analytics['initial_cost'] - 0.10 ) < 0.000001, 'Initial analytics cost was calculated incorrectly.' );
$assert( abs( $analytics['refinement_cost'] - 0.20 ) < 0.000001, 'Refinement analytics cost was calculated incorrectly.' );
$assert( abs( $analytics['total_cost'] - 0.30 ) < 0.000001, 'Total analytics cost did not include both calls.' );
$assert( abs( $analytics['average_question'] - 0.15 ) < 0.000001, 'Average question cost was calculated incorrectly.' );
$assert_same( 2, $analytics['api_calls'], 'Cached events were counted as paid API calls.' );
$assert_same( 1, $analytics['cache_hits'], 'Analytics cache hits were counted incorrectly.' );
$assert_same( 360, $analytics['total_tokens'], 'Analytics tokens were totaled incorrectly.' );

echo "Plugin regression checks: OK\n";
