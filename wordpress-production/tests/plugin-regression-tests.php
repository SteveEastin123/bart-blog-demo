<?php
/**
 * Focused regression checks for production WordPress plugin workflows.
 *
 * Run through WP-CLI after the authoritative import is complete.
 *
 * @package EhrmanBlogDiscovery
 */

use EhrmanBlogDiscovery\AI_Analytics_Report;
use EhrmanBlogDiscovery\AI_Analytics_Page;
use EhrmanBlogDiscovery\AI_Analytics_Request;
use EhrmanBlogDiscovery\AI_Candidate_Reviewer;
use EhrmanBlogDiscovery\AI_Interpreter;
use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Embedding_Index_Transfer;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Editor;
use EhrmanBlogDiscovery\Post_Ingestion_Queue;
use EhrmanBlogDiscovery\Post_Ingestion_Repository;
use EhrmanBlogDiscovery\Post_Ingestion_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Validator;
use EhrmanBlogDiscovery\Post_Ingestion_Vocabulary_Reader;
use EhrmanBlogDiscovery\Search_Service;
use EhrmanBlogDiscovery\Semantic_Ask_AI_REST_Controller;
use EhrmanBlogDiscovery\Semantic_Index_Service;
use EhrmanBlogDiscovery\Semantic_Search_Service;
use EhrmanBlogDiscovery\Taxonomy_Ask_AI_REST_Controller;

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

/* Verify each Ask AI route is owned by its mechanism-specific controller. */
$rest_routes = rest_get_server()->get_routes();
$callbacks   = array();
foreach ( array( '/ehrman-discovery/v1/interpret', '/ehrman-discovery/v1/refine', '/ehrman-discovery/v1/semantic-search' ) as $route ) {
	$handlers = $rest_routes[ $route ] ?? array();
	foreach ( $handlers as $handler ) {
		if ( is_array( $handler['callback'] ?? null ) && is_object( $handler['callback'][0] ?? null ) ) {
			$callbacks[ $route ] = $handler['callback'][0];
			break;
		}
	}
}
$assert( ( $callbacks['/ehrman-discovery/v1/interpret'] ?? null ) instanceof Taxonomy_Ask_AI_REST_Controller, 'The interpretation route is not owned by the taxonomy Ask AI controller.' );
$assert( ( $callbacks['/ehrman-discovery/v1/refine'] ?? null ) instanceof Taxonomy_Ask_AI_REST_Controller, 'The refinement route is not owned by the taxonomy Ask AI controller.' );
$assert( ( $callbacks['/ehrman-discovery/v1/semantic-search'] ?? null ) instanceof Semantic_Ask_AI_REST_Controller, 'The semantic-search route is not owned by the semantic Ask AI controller.' );

/* Verify each Ask AI shortcode is rendered by its mechanism-specific page renderer. */
$taxonomy_ask_ai_markup = do_shortcode( '[ehrman_ask_question]' );
$semantic_ask_ai_markup = do_shortcode( '[ehrman_ask_ai_2]' );
$assert( str_contains( $taxonomy_ask_ai_markup, 'data-ebd-question-form' ), 'The taxonomy Ask AI shortcode lost its question form.' );
$assert( str_contains( $taxonomy_ask_ai_markup, 'data-ebd-question-interpret' ), 'The taxonomy Ask AI shortcode lost its interpretation control.' );
$assert( str_contains( $semantic_ask_ai_markup, 'data-ebd-semantic-form' ), 'The semantic Ask AI shortcode lost its question form.' );
$assert( str_contains( $semantic_ask_ai_markup, 'data-ebd-semantic-submit' ), 'The semantic Ask AI shortcode lost its submit control.' );

/* Verify analytics hooks and administrator filters retain their public contract. */
$assert( false !== has_action( 'admin_post_ehrman_ai_analytics_csv', array( AI_Analytics_Page::class, 'export_csv' ) ), 'The analytics CSV hook is not owned by the analytics page coordinator.' );
$assert( false !== has_action( 'admin_post_ehrman_ai_analytics_reset', array( AI_Analytics_Page::class, 'reset_test_analytics' ) ), 'The analytics reset hook is not owned by the analytics page coordinator.' );
$original_get = $_GET;
try {
	$_GET = array(
		'view'         => 'ask-ai-2',
		'feedback'     => 'invalid',
		'date_from'    => 'invalid',
		'date_to'      => '2026-09-18',
		'search'       => '<b>Paul</b>',
		'zero_results' => '1',
	);
	$analytics_filters = ( new AI_Analytics_Request() )->filters();
	$assert_same( 'ask-ai-2', $analytics_filters['view'], 'The analytics view was not retained.' );
	$assert_same( 'semantic', $analytics_filters['interface'], 'The analytics view did not select its interface.' );
	$assert_same( 'all', $analytics_filters['feedback'], 'An invalid analytics feedback filter was retained.' );
	$assert_same( '', $analytics_filters['date_from'], 'An invalid analytics start date was retained.' );
	$assert_same( '2026-09-18', $analytics_filters['date_to'], 'A valid analytics end date was discarded.' );
	$assert_same( 'Paul', $analytics_filters['search'], 'The analytics text filter was not sanitized.' );
	$assert_same( '1', $analytics_filters['zero_results'], 'The analytics zero-results filter was discarded.' );
} finally {
	$_GET = $original_get;
}

/* Keep the selected Ask AI 2 pipeline free of the retired metadata-vector experiment. */
$assert( ! isset( $tables['post_metadata_embeddings'] ), 'The retired metadata-vector table returned to the active schema.' );
$assert_same( 'hybrid-1', Semantic_Search_Service::pipeline_version(), 'The selected semantic pipeline version changed.' );

/* Verify complete, missing, stale, and orphaned semantic-vector coverage. */
$semantic = new Semantic_Index_Service();
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

/* Verify vocabulary reads and approval with a pending vector when the API key is absent. */
$original_source   = getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
$original_key      = getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
$ingestion         = new Post_Ingestion_Service();
$ingestion_store   = new Post_Ingestion_Repository();
$review_count      = $ingestion->review_count();
$taxonomy          = $ingestion->vocabulary();
$vocabulary_reader = new Post_Ingestion_Vocabulary_Reader();
$assert_same( $taxonomy, $vocabulary_reader->vocabulary(), 'The repository vocabulary differs from the dedicated reader.' );
$assert_same( $ingestion_store->vocabulary_hash( $taxonomy ), $vocabulary_reader->vocabulary_hash( $taxonomy ), 'The repository changed the vocabulary hash.' );
$assert_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['topics']}" ), count( $taxonomy['topics'] ), 'The vocabulary reader omitted topics.' );
$assert_same( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['keywords']}" ), count( $taxonomy['keywords'] ), 'The vocabulary reader omitted keywords.' );
$topic             = null;
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
$validator       = new Post_Ingestion_Validator();
$valid_proposal  = $proposal;
$valid_proposal['topics'] = array( strtolower( $topic['name'] ) );
$valid_proposal['topicRationales'] = array(
	array(
		'topic'     => strtolower( $topic['name'] ),
		'rationale' => 'This is the central subject of the post.',
	),
);
$valid_proposal['reviewNotes'] = array( '  Reviewed by editor  ', 'Reviewed by editor' );
$checked_proposal = $validator->validate_proposal( $valid_proposal, $taxonomy, false, true );
$assert( ! is_wp_error( $checked_proposal ), is_wp_error( $checked_proposal ) ? $checked_proposal->get_error_message() : 'A valid proposal was rejected.' );
$assert_same( array( $topic['name'] ), $checked_proposal['topics'], 'The proposal validator did not canonicalize its topic.' );
$assert_same( $topic['name'], $checked_proposal['topicRationales'][0]['topic'], 'The proposal validator did not canonicalize its topic rationale.' );
$assert_same( 1, count( array_intersect( $checked_proposal['reviewNotes'], array( 'Reviewed by editor' ) ) ), 'The proposal validator did not deduplicate review notes.' );

$held_proposal = $proposal;
$held_proposal['topics'] = array();
$held_proposal['status'] = 'held';
$held_proposal['reviewNotes'] = array( 'No existing topic fits.' );
$checked_held = $validator->validate_proposal( $held_proposal, $taxonomy, true );
$assert( ! is_wp_error( $checked_held ), is_wp_error( $checked_held ) ? $checked_held->get_error_message() : 'A documented held proposal was rejected.' );
$assert_same( 'held', $checked_held['status'], 'The held proposal lost its status.' );
$rejected_held = $validator->validate_proposal( $held_proposal, $taxonomy, false );
$assert( is_wp_error( $rejected_held ) && str_contains( $rejected_held->get_error_message(), 'cannot be approved' ), 'A held proposal was accepted for approval.' );
$held_proposal['reviewNotes'] = array();
$missing_note = $validator->validate_proposal( $held_proposal, $taxonomy, true );
$assert( is_wp_error( $missing_note ) && str_contains( $missing_note->get_error_message(), 'requires a review note' ), 'A held proposal without a review note was accepted.' );

$invalid_proposal = $proposal;
$invalid_proposal['description'] = 'Ehrman discusses this post.';
$invalid_proposal['searchSummary'] = 'Does this summary begin as a question?';
$invalid_proposal['topics'] = array( 'Not an existing topic' );
$rejected_proposal = $validator->validate_proposal( $invalid_proposal, $taxonomy, false );
$assert( is_wp_error( $rejected_proposal ), 'An invalid proposal was accepted.' );
$assert_same( 'ehrman_ingestion_invalid_proposal', $rejected_proposal->get_error_code(), 'The invalid proposal returned the wrong error code.' );
$assert( str_contains( $rejected_proposal->get_error_message(), 'Unknown topic' ), 'The unknown topic was not reported.' );
$assert( str_contains( $rejected_proposal->get_error_message(), 'declarative statement' ), 'The question-opening summary was not reported.' );

$form_proposal = $validator->proposal_from_input(
	array(
		'topics'             => array( $topic['name'] ),
		'secondary_keywords' => "First keyword\n\nSecond keyword",
		'review_notes'       => "First note\nSecond note",
	)
);
$assert_same( array( 'First keyword', 'Second keyword' ), $form_proposal['secondaryKeywords'], 'Proposal form keywords were not split into lines.' );
$assert_same( array( 'First note', 'Second note' ), $form_proposal['reviewNotes'], 'Proposal form notes were not split into lines.' );
$assert_same( array( 'status' => 'held' ), $validator->decode_proposal( '{"status":"held"}' ), 'Stored proposals were not decoded.' );
$assert_same( array(), $validator->decode_proposal( 'invalid-json' ), 'Malformed stored proposal JSON was not rejected.' );
$taxonomy_json    = wp_json_encode( $taxonomy );
$draft_id         = 0;
$worker_draft_id  = 0;
$approved_post_id = 0;
$assert( is_string( $taxonomy_json ), 'The ingestion regression taxonomy fixture could not be encoded.' );

try {
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' );
	$worker_draft = $ingestion_store->create_draft(
		array(
			'source_wp_id' => $ingestion_wp_id + 1000000,
			'title'        => 'Background analysis regression fixture',
			'url'          => 'https://example.test/ehrman-worker-' . $ingestion_wp_id . '/',
			'author'       => 'Regression Test',
			'date_text'    => 'January 2, 2026',
			'published_at' => '2026-01-02 12:00:00',
			'post_text'    => 'Temporary text for testing background analysis without an API key.',
		),
		$ingestion_store->vocabulary_hash( $taxonomy ),
		0
	);
	$assert( ! is_wp_error( $worker_draft ), is_wp_error( $worker_draft ) ? $worker_draft->get_error_message() : 'Could not create the background analysis fixture.' );
	$worker_draft_id = (int) $worker_draft;
	$worker_result = $ingestion->process_queued( $worker_draft_id );
	$assert( is_wp_error( $worker_result ), 'Background analysis unexpectedly continued without an ingestion API key.' );
	$assert_same( 'ehrman_ingestion_not_configured', $worker_result->get_error_code(), 'The background worker returned the wrong configuration error.' );
	$worker_state = $ingestion_store->draft( $worker_draft_id );
	$assert( is_array( $worker_state ), 'The background worker draft was not retained.' );
	$assert_same( 'error', $worker_state['status'], 'The background worker did not record the analysis error.' );
	$assert_same( 1, (int) $worker_state['analysis_attempt'], 'The background worker did not record its claimed attempt.' );
	$assert_same( null, $ingestion->process_queued( $worker_draft_id ), 'The background worker claimed the same draft twice.' );
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
	$assert_same( $review_count + 1, $ingestion->review_count(), 'A ready proposal was not counted as awaiting review.' );
	$latest_records = $ingestion->latest_for_posts( array( $ingestion_wp_id ) );
	$assert_same( $draft_id, (int) ( $latest_records[ $ingestion_wp_id ]['id'] ?? 0 ), 'The batch post-status lookup did not return the current ingestion record.' );

	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
	$json_approval = $ingestion->approve( $draft_id, false );
	$assert( is_wp_error( $json_approval ), 'Approval modified the index while JSON was authoritative.' );
	$assert_same( 'ehrman_ingestion_json_authoritative', $json_approval->get_error_code(), 'JSON-authoritative approval returned the wrong error.' );
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );

	$held_revision = $ingestion->save_proposal(
		$draft_id,
		array(
			'description'    => $proposal['description'],
			'search_summary' => $proposal['searchSummary'],
			'status'         => 'held',
			'review_notes'   => 'No existing topic fits this post.',
		)
	);
	$assert( ! is_wp_error( $held_revision ), is_wp_error( $held_revision ) ? $held_revision->get_error_message() : 'A held proposal could not be saved.' );
	$assert_same( 'held', $held_revision['status'], 'The review workflow did not retain the held status.' );
	$held_approval = $ingestion->approve( $draft_id, false );
	$assert( is_wp_error( $held_approval ), 'A held proposal was approved.' );
	$assert_same( 'ehrman_ingestion_invalid_proposal', $held_approval->get_error_code(), 'Held-proposal approval returned the wrong error.' );

	$keyword_revision = $ingestion->save_proposal(
		$draft_id,
		array(
			'description'            => $proposal['description'],
			'search_summary'         => $proposal['searchSummary'],
			'topics'                 => $proposal['topics'],
			'new_secondary_keywords' => 'Regression Keyword ' . $ingestion_wp_id,
			'status'                 => 'ready',
			'review_notes'           => 'New keyword requires editorial approval.',
		)
	);
	$assert( ! is_wp_error( $keyword_revision ), is_wp_error( $keyword_revision ) ? $keyword_revision->get_error_message() : 'A proposed new keyword could not be saved.' );
	$new_keyword_approval = $ingestion->approve( $draft_id, false );
	$assert( is_wp_error( $new_keyword_approval ), 'A new keyword was approved without explicit consent.' );
	$assert_same( 'ehrman_ingestion_new_keywords', $new_keyword_approval->get_error_code(), 'New-keyword approval returned the wrong error.' );

	$ready_revision = $ingestion->save_proposal(
		$draft_id,
		array(
			'description'    => $proposal['description'],
			'search_summary' => $proposal['searchSummary'],
			'topics'         => $proposal['topics'],
			'status'         => 'ready',
		)
	);
	$assert( ! is_wp_error( $ready_revision ), is_wp_error( $ready_revision ) ? $ready_revision->get_error_message() : 'The ready proposal could not be restored.' );
	$assert_same( 'ready', $ready_revision['status'], 'The restored proposal was not ready.' );
	$wpdb->update( $tables['ingestion_drafts'], array( 'taxonomy_version' => str_repeat( '0', 64 ) ), array( 'id' => $draft_id ) );
	$changed_taxonomy_approval = $ingestion->approve( $draft_id, false );
	$assert( is_wp_error( $changed_taxonomy_approval ), 'Approval ignored a changed vocabulary hash.' );
	$assert_same( 'ehrman_ingestion_taxonomy_changed', $changed_taxonomy_approval->get_error_code(), 'Changed-vocabulary approval returned the wrong error.' );
	$wpdb->update( $tables['ingestion_drafts'], array( 'taxonomy_version' => $ingestion_store->vocabulary_hash( $taxonomy ) ), array( 'id' => $draft_id ) );
	$assert_same( $review_count + 1, $ingestion->review_count(), 'Review and approval guardrails changed the pending-review count.' );

	$approved = $ingestion->approve( $draft_id, false );
	$assert( ! is_wp_error( $approved ), is_wp_error( $approved ) ? $approved->get_error_message() : 'Ingestion approval failed.' );
	$assert_same( 'approved', $approved['status'], 'The ingestion draft was not marked approved.' );
	$assert_same( 'pending', $approved['embedding_status'], 'A missing ingestion API key did not leave the vector pending.' );
	$assert( str_contains( $approved['embedding_error'], 'API key' ), 'The pending vector did not retain a useful configuration error.' );
	$approved_post_id = (int) $approved['approved_post_id'];
	$assert( $approved_post_id > 0, 'The approved post identifier was not retained.' );
	$assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['post_topics']} WHERE post_id=%d", $approved_post_id ) ), 'The approved topic relationship was not created.' );
	$assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['post_embeddings']} WHERE source_wp_id=%d", $ingestion_wp_id ) ), 'A vector row was created without an API key.' );
	$assert_same( $review_count, $ingestion->review_count(), 'An approved proposal remained in the awaiting-review count.' );
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
	if ( $worker_draft_id > 0 ) {
		$wpdb->delete( $tables['ingestion_drafts'], array( 'id' => $worker_draft_id ) );
	}
	false === $original_source ? putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' ) : putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=' . $original_source );
	false === $original_key ? putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' ) : putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' . $original_key );
}

/* Verify the post-editor bridge is permission-protected and exposes safe workflow state. */
$editor_user_id  = 0;
$editor_post_id  = 0;
$editor_draft_id = 0;
$previous_user   = get_current_user_id();
$editor_key      = getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
try {
	$created_user = wp_insert_user(
		array(
			'user_login' => 'ehrman_ingestion_editor_' . strtolower( wp_generate_password( 8, false, false ) ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'ingestion-editor-' . wp_generate_uuid4() . '@example.test',
			'role'       => 'administrator',
		)
	);
	$assert( ! is_wp_error( $created_user ), is_wp_error( $created_user ) ? $created_user->get_error_message() : 'Could not create the ingestion editor user.' );
	$editor_user_id = is_wp_error( $created_user ) ? 0 : (int) $created_user;
	wp_set_current_user( $editor_user_id );
	$created_post = wp_insert_post(
		array(
			'post_title'   => 'Editor ingestion regression fixture',
			'post_content' => '<!-- wp:paragraph --><p>Explains how the saved WordPress post supplies complete text to the protected search-metadata workflow.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => (int) $editor_user_id,
		),
		true
	);
	$assert( ! is_wp_error( $created_post ), is_wp_error( $created_post ) ? $created_post->get_error_message() : 'Could not create the ingestion editor post.' );
	$editor_post_id = is_wp_error( $created_post ) ? 0 : (int) $created_post;

	$editor_request = new WP_REST_Request( 'GET' );
	$editor_request->set_param( 'post_id', (int) $editor_post_id );
	$assert_same( true, Post_Ingestion_Editor::permission( $editor_request ), 'An administrator could not access the post ingestion editor endpoint.' );

	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' );
	$unconfigured = Post_Ingestion_Editor::analyze( $editor_request );
	$assert( is_wp_error( $unconfigured ), 'Editor analysis unexpectedly called the API without an ingestion key.' );
	$assert_same( 'ehrman_ingestion_not_configured', $unconfigured->get_error_code(), 'The editor did not build valid ingestion input from the saved post.' );

	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=regression-placeholder-key' );
	$editor_taxonomy      = $ingestion_store->vocabulary();
	$editor_taxonomy_json = wp_json_encode( $editor_taxonomy );
	$assert( is_string( $editor_taxonomy_json ), 'The editor-ingestion taxonomy fixture could not be encoded.' );
	$queued_response = Post_Ingestion_Editor::analyze( $editor_request );
	$assert( $queued_response instanceof WP_REST_Response, 'The editor did not return a REST response after queueing analysis.' );
	$assert_same( 202, $queued_response->get_status(), 'The editor did not identify queued analysis as an accepted background request.' );
	$queued_data      = $queued_response->get_data();
	$editor_draft_id = is_array( $queued_data ) && is_array( $queued_data['draft'] ?? null ) ? (int) $queued_data['draft']['id'] : 0;
	$assert( $editor_draft_id > 0, 'The editor did not return the queued ingestion draft.' );
	$assert_same( 'queued', $queued_data['draft']['status'], 'The editor ran analysis inside the REST request instead of queueing it.' );
	$assert_same( true, $queued_data['draft']['analysisActive'], 'The editor did not identify queued analysis as active.' );
	$assert( false !== wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $editor_draft_id ) ), 'The queued ingestion draft did not receive a worker event.' );
	Post_Ingestion_Queue::cancel( $editor_draft_id );
	$assert_same( false, wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $editor_draft_id ) ), 'The ingestion worker event could not be cancelled for recovery testing.' );
	$recovered_response = Post_Ingestion_Editor::status( $editor_request );
	$assert( $recovered_response instanceof WP_REST_Response, 'The editor status endpoint did not return a REST response while repairing the queue.' );
	$assert( false !== wp_next_scheduled( 'ehrman_ingestion_process_draft', array( $editor_draft_id ) ), 'The editor status endpoint did not repair a missing queued worker event.' );
	$assert_same( 'no-store', $recovered_response->get_headers()['Cache-Control'] ?? '', 'The editor workflow response may be cached.' );
	$duplicate_pending = $ingestion->analyze(
		array(
			'source_wp_id' => (int) $editor_post_id,
			'title'        => 'Editor ingestion regression fixture',
			'url'          => (string) get_permalink( (int) $editor_post_id ),
			'author'       => 'Regression Test',
			'date'         => '2026-09-17',
			'post_text'    => 'A second ingestion submission for the same saved WordPress post must not create another background AI request.',
		),
		(int) $editor_user_id
	);
	$assert( is_wp_error( $duplicate_pending ), 'A duplicate pending workflow was accepted for the same WordPress post.' );
	$assert_same( 'ehrman_ingestion_duplicate', $duplicate_pending->get_error_code(), 'The duplicate pending workflow returned the wrong error.' );
	Post_Ingestion_Queue::cancel( $editor_draft_id );

	$first_attempt = $ingestion_store->claim_analysis( $editor_draft_id );
	$assert_same( 1, $first_attempt, 'The first background worker could not claim the queued ingestion draft.' );
	$assert_same( 0, $ingestion_store->claim_analysis( $editor_draft_id ), 'A second background worker claimed the same analysis attempt.' );
	$active_draft = $ingestion_store->draft( $editor_draft_id );
	$assert( is_array( $active_draft ), 'The active ingestion draft could not be reloaded.' );
	$assert_same( false, Post_Ingestion_Service::analysis_is_stale( $active_draft ), 'A newly claimed analysis was marked stale.' );
	$wpdb->update(
		$tables['ingestion_drafts'],
		array( 'analysis_started_at' => gmdate( 'Y-m-d H:i:s', time() - ( 11 * MINUTE_IN_SECONDS ) ) ),
		array( 'id' => $editor_draft_id ),
		array( '%s' ),
		array( '%d' )
	);
	$stalled_draft = $ingestion_store->draft( $editor_draft_id );
	$assert( is_array( $stalled_draft ), 'The stalled ingestion draft could not be reloaded.' );
	$assert_same( true, Post_Ingestion_Service::analysis_is_stale( $stalled_draft ), 'An analysis beyond the recovery threshold was not marked stale.' );
	$assert(
		$ingestion_store->queue_analysis( $editor_draft_id, hash( 'sha256', is_string( $editor_taxonomy_json ) ? $editor_taxonomy_json : '' ) ),
		'The stalled ingestion draft could not be queued for a fresh attempt.'
	);
	$second_attempt = $ingestion_store->claim_analysis( $editor_draft_id );
	$assert_same( 2, $second_attempt, 'The retried worker did not receive a new analysis attempt number.' );
	$metrics = array(
		'response_id'         => 'editor-regression-analysis',
		'input_tokens'        => 10,
		'cached_input_tokens' => 0,
		'output_tokens'       => 10,
		'reasoning_tokens'    => 0,
		'estimated_cost_usd'  => 0.0,
	);
	$assert_same(
		false,
		$ingestion_store->store_analysis( $editor_draft_id, $proposal, $metrics, null, $first_attempt ),
		'A stale worker overwrote the newer analysis attempt.'
	);
	$assert_same(
		true,
		$ingestion_store->store_analysis( $editor_draft_id, $proposal, $metrics, null, $second_attempt ),
		'The current analysis attempt could not store its result.'
	);
	/* Confirm legacy callers can still store validated analysis without a worker lease. */
	$ingestion_store->store_analysis(
		$editor_draft_id,
		$proposal,
		$metrics
	);
	$editor_response = Post_Ingestion_Editor::status( $editor_request );
	$assert( $editor_response instanceof WP_REST_Response, 'The editor status endpoint did not return a REST response.' );
	$editor_data = $editor_response->get_data();
	$assert( is_array( $editor_data ) && is_array( $editor_data['draft'] ?? null ), 'The editor status response omitted the ingestion draft.' );
	$assert_same( $editor_draft_id, (int) $editor_data['draft']['id'], 'The editor status response selected the wrong ingestion draft.' );
	$assert_same( 'ready', $editor_data['draft']['status'], 'The editor status response changed the proposal state.' );
	$assert( ! array_key_exists( 'post_text', $editor_data['draft'] ), 'The editor status response exposed retained full post text.' );
	$original_content = (string) get_post_field( 'post_content', $editor_post_id );
	$updated_post     = wp_update_post(
		array(
			'ID'           => $editor_post_id,
			'post_content' => $original_content . '<!-- wp:paragraph --><p>This saved change must require a fresh search-metadata review.</p><!-- /wp:paragraph -->',
		),
		true
	);
	$assert( ! is_wp_error( $updated_post ), is_wp_error( $updated_post ) ? $updated_post->get_error_message() : 'Could not update the ingestion editor fixture.' );
	$changed_response = Post_Ingestion_Editor::status( $editor_request );
	$changed_data     = $changed_response instanceof WP_REST_Response ? $changed_response->get_data() : array();
	$assert_same( true, is_array( $changed_data ) ? ( $changed_data['sourceChanged'] ?? false ) : false, 'A saved post-content change did not require reanalysis.' );

	wp_set_current_user( 0 );
	$denied = Post_Ingestion_Editor::permission( $editor_request );
	$assert( is_wp_error( $denied ) && 'ehrman_ingestion_forbidden' === $denied->get_error_code(), 'The editor endpoint allowed an unauthenticated request.' );
} finally {
	wp_set_current_user( $previous_user );
	if ( $editor_draft_id > 0 ) {
		$wpdb->delete( $tables['ingestion_drafts'], array( 'id' => $editor_draft_id ) );
	}
	if ( $editor_post_id > 0 ) {
		wp_delete_post( $editor_post_id, true );
	}
	if ( $editor_user_id > 0 ) {
		wp_delete_user( $editor_user_id );
	}
	false === $editor_key ? putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' ) : putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=' . $editor_key );
}

/* Verify AI_Interpreter delegates cached candidate review without changing its contract. */
$review_question   = 'Which candidate best addresses the question?';
$review_model      = 'review-test-model';
$review_request_id = '00000000-0000-4000-8000-000000000042';
$review_posts      = array(
	array(
		'id'             => 'review-post-1',
		'title'          => 'First Candidate',
		'search_summary' => 'Directly addresses the test question.',
	),
	array(
		'id'             => 'review-post-2',
		'title'          => 'Second Candidate',
		'search_summary' => '',
		'description'    => 'Provides useful related context.',
	),
);
$review_candidates = array(
	array(
		'id'      => 'review-post-1',
		'title'   => 'First Candidate',
		'summary' => 'Directly addresses the test question.',
	),
	array(
		'id'      => 'review-post-2',
		'title'   => 'Second Candidate',
		'summary' => 'Provides useful related context.',
	),
);
$review_cache_key  = 'ebd_ai_refine_' . hash(
	'sha256',
	Search_Service::normalize( $review_question ) . '|' . (string) wp_json_encode( $review_candidates ) . '|' . $review_model . '|' . AI_Candidate_Reviewer::prompt_version() . '|' . AI_Usage::cache_version()
);
set_transient(
	$review_cache_key,
	array(
		'post_ids'   => array( 'review-post-1', 'review-post-2' ),
		'post_tiers' => array(
			'review-post-1' => 'direct',
			'review-post-2' => 'related',
			'unknown-post'  => 'background',
		),
	),
	MINUTE_IN_SECONDS
);
try {
	$reviewer    = new AI_Candidate_Reviewer( 'test-api-key', $review_model );
	$interpreter = new AI_Interpreter( $reviewer );
	$review       = $interpreter->refine( $review_question, $review_posts, $review_request_id );
	$assert( ! is_wp_error( $review ), is_wp_error( $review ) ? $review->get_error_message() : 'Candidate review returned an unexpected error.' );
	$assert_same( array( 'review-post-1', 'review-post-2' ), $review['post_ids'], 'Candidate review changed cached post ordering.' );
	$assert_same( array( 'review-post-1' => 'direct', 'review-post-2' => 'related' ), $review['post_tiers'], 'Candidate review changed cached relevance tiers.' );
	$assert_same( 2, $review['candidate_count'], 'Candidate review returned the wrong candidate count.' );
	$assert_same( true, $review['cache_hit'], 'Candidate review did not report its WordPress cache hit.' );
	$assert_same( AI_Candidate_Reviewer::prompt_version(), AI_Interpreter::refine_prompt_version(), 'The compatibility refinement prompt version changed.' );
} finally {
	delete_transient( $review_cache_key );
	$wpdb->delete( $tables['ai_usage'], array( 'request_id' => $review_request_id ) );
}

/* Verify total and average analytics costs include initial and refinement calls. */
$analytics = ( new AI_Analytics_Report() )->summary(
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
