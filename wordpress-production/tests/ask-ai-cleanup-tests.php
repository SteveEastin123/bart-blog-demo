<?php
/** Ask AI cleanup regressions; HTTP is intercepted and all test records are removed. */

use EhrmanBlogDiscovery\AI_Candidate_Reviewer;
use EhrmanBlogDiscovery\AI_Settings;
use EhrmanBlogDiscovery\AI_Usage;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Search_Service;
use EhrmanBlogDiscovery\Semantic_Ask_AI_REST_Controller;
use EhrmanBlogDiscovery\Semantic_Search_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert( ! class_exists( 'EhrmanBlogDiscovery\\AI_Interpreter' ), 'Retired interpreter is still packaged.' );
$assert( ! class_exists( 'EhrmanBlogDiscovery\\AI_Term_Selector' ), 'Retired term selector is still packaged.' );
$assert( ! class_exists( 'EhrmanSectionPublic\\Index' ), 'Experimental section plugin is active.' );
$asset_config = wp_scripts()->get_data( 'ehrman-blog-discovery', 'data' );
$assert( is_string( $asset_config ) && ! str_contains( $asset_config, 'interpretUrl' ) && ! str_contains( $asset_config, 'refineUrl' ), 'Retired endpoints are still advertised to the browser.' );

/* Preserve the old model/key precedence, defaults and sanitization. */
$saved_model = getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
$saved_key   = getenv( 'OPENAI_API_KEY' );
try {
	if ( ! defined( 'EHRMAN_DISCOVERY_AI_MODEL' ) ) {
		putenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
		$assert_same( 'gpt-5.4-mini', AI_Settings::model_id(), 'Default review model changed.' );
		putenv( 'EHRMAN_DISCOVERY_AI_MODEL= review-model_1.2/ ' );
		$assert_same( 'review-model_1.2', AI_Settings::model_id(), 'Review model sanitization changed.' );
	}
	if ( ! defined( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' ) ) {
		putenv( 'OPENAI_API_KEY' );
		$assert_same( false, AI_Settings::is_configured(), 'Missing API key is reported as configured.' );
		putenv( 'OPENAI_API_KEY= test-only-key ' );
		$assert_same( 'test-only-key', AI_Settings::api_key(), 'Review API-key resolution changed.' );
	}
} finally {
	false === $saved_model ? putenv( 'EHRMAN_DISCOVERY_AI_MODEL' ) : putenv( 'EHRMAN_DISCOVERY_AI_MODEL=' . $saved_model );
	false === $saved_key ? putenv( 'OPENAI_API_KEY' ) : putenv( 'OPENAI_API_KEY=' . $saved_key );
}

$cleanup_questions = array();
$cleanup_caches    = array();
$cleanup_calls     = 0;
$cleanup_mode      = 'success';
$cleanup_expected  = array();
$cleanup_seed_id   = wp_generate_uuid4();
$cleanup_http      = static function ( $preempt, $args, $url ) use ( &$cleanup_calls, &$cleanup_mode, &$cleanup_expected, &$cleanup_caches, $assert, $assert_same ) {
	++$cleanup_calls;
	$assert_same( 'https://api.openai.com/v1/responses', $url, 'Unexpected HTTP call, including uncached embeddings.' );
	$body = json_decode( $args['body'], true );
	$assert_same( AI_Settings::model_id(), $body['model'], 'Candidate review uses the wrong model.' );
	$assert_same( 800, $body['max_output_tokens'], 'Candidate-review token limit changed.' );
	$assert_same( 'ehrman_refined_posts', $body['text']['format']['name'], 'Candidate-review response schema changed.' );
	$parts = explode( "\n\nCandidate posts:\n", $body['input'], 2 );
	$posts = json_decode( $parts[1], true );
	$assert_same( $cleanup_expected, $posts, 'Review did not receive the original ordered candidate titles/summaries.' );
	$question = substr( $parts[0], strlen( "Reader question:\n" ) );
	$cleanup_caches[] = 'ebd_ai_refine_' . hash( 'sha256', Search_Service::normalize( $question ) . '|' . wp_json_encode( $posts ) . '|' . AI_Settings::model_id() . '|' . AI_Candidate_Reviewer::prompt_version() . '|' . AI_Usage::cache_version() );
	if ( 'error' === $cleanup_mode ) {
		return new WP_Error( 'test_unavailable', 'Simulated provider outage.' );
	}
	$selection = 'empty' === $cleanup_mode ? array() : array( $posts[1]['id'], $posts[0]['id'] );
	$output = 'ordered' === AI_Candidate_Reviewer::result_grouping()
		? array( 'selected_ids' => $selection )
		: array( 'selected_posts' => array_map( static fn( $id ) => array( 'id' => $id, 'relevance_tier' => 'direct' ), $selection ) );
	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'model' => AI_Settings::model_id(), 'output_text' => 'invalid' === $cleanup_mode ? 'not-json' : wp_json_encode( $output ), 'usage' => array( 'input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110 ) ) ),
	);
};
add_filter( 'pre_http_request', $cleanup_http, PHP_INT_MAX, 3 );
try {
	$service    = new Semantic_Search_Service();
	$controller = new Semantic_Ask_AI_REST_Controller( new AI_Candidate_Reviewer( 'test-only-key', AI_Settings::model_id() ), $service );
	foreach ( array( 'success', 'empty', 'error', 'invalid' ) as $cleanup_mode ) {
		$question = 'Cleanup regression ' . $cleanup_mode . ' ' . wp_generate_uuid4();
		$cleanup_questions[] = $question;
		$key = 'ebd_semantic_query_' . hash( 'sha256', Search_Service::normalize( $question ) . '|' . Embedding_Service::model_id() . '|' . Embedding_Service::dimensions() . '|' . AI_Usage::cache_version() );
		$vector = array_fill( 0, Embedding_Service::dimensions(), 0.0 );
		$vector[0] = 1.0;
		set_transient( $key, $vector, MINUTE_IN_SECONDS );
		$cleanup_caches[] = $key;
		$candidates = $service->search( $question, $cleanup_seed_id );
		$assert( ! is_wp_error( $candidates ) && count( $candidates['posts'] ) >= 2, 'Could not retrieve test candidates from the existing index.' );
		$cleanup_expected = array_map( static fn( $post ) => array( 'id' => (string) $post['id'], 'title' => sanitize_text_field( $post['title'] ), 'summary' => sanitize_text_field( $post['search_summary'] ?: $post['description'] ) ), $candidates['posts'] );
		$request = new WP_REST_Request( 'POST', '/ehrman-discovery/v1/semantic-search' );
		$request->set_param( 'question', $question );
		$response = $controller->semantic_search( $request );
		$assert( $response instanceof WP_REST_Response, 'Semantic endpoint returned an unexpected error.' );
		$data = $response->get_data();
		$assert_same( $candidates['posts'], $data['broader']['posts'], 'Broader candidates or ranking changed.' );
		if ( 'success' === $cleanup_mode ) {
			$assert_same( true, $data['refined'], 'Successful review did not produce refined results.' );
			$assert_same( array( $cleanup_expected[1]['id'], $cleanup_expected[0]['id'] ), array_map( 'strval', array_column( $data['posts'], 'id' ) ), 'Selected post order changed.' );
			$before_calls = $cleanup_calls;
			$cached = $controller->semantic_search( $request )->get_data();
			$assert_same( $data['posts'], $cached['posts'], 'Cached review changed the posts.' );
			$assert_same( $before_calls, $cleanup_calls, 'A cached review unexpectedly called the provider.' );
		} else {
			$assert_same( false, $data['refined'], 'Fallback was incorrectly reported as refined.' );
			$assert_same( array_slice( $candidates['posts'], 0, Search_Service::POSTS_PER_PAGE ), $data['posts'], 'Fallback did not preserve broader results.' );
			$assert( '' !== $data['notice'], 'Fallback did not explain the result.' );
		}
	}
	$assert_same( 4, $cleanup_calls, 'Unexpected number of mocked review calls.' );
} finally {
	remove_filter( 'pre_http_request', $cleanup_http, PHP_INT_MAX );
	foreach ( $cleanup_caches as $key ) {
		delete_transient( $key );
	}
	foreach ( $cleanup_questions as $question ) {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT request_id FROM {$tables['ai_requests']} WHERE question=%s", $question ) );
		$review_ids = $wpdb->get_col( $wpdb->prepare( "SELECT refinement_id FROM {$tables['ai_refinements']} WHERE question=%s", $question ) );
		foreach ( array_merge( $ids, $review_ids ) as $id ) {
			$wpdb->delete( $tables['ai_usage'], array( 'request_id' => $id ) );
		}
		$wpdb->delete( $tables['ai_refinements'], array( 'question' => $question ) );
		$wpdb->delete( $tables['ai_requests'], array( 'question' => $question ) );
	}
	$wpdb->delete( $tables['ai_usage'], array( 'request_id' => $cleanup_seed_id ) );
}
