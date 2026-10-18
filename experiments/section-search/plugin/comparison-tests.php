<?php
/** Paid calls are mocked. Exercise the exact controlled endpoint and both payloads. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
use EhrmanBlogDiscovery\Database;
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\End_To_End_Run;
use EhrmanSectionPilot\Evidence_Review;
use EhrmanSectionPilot\Index;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$tables = Database::tables();
$before = array();
foreach ( array( 'ai_usage', 'ai_requests', 'ai_refinements', 'post_embeddings', 'external_posts', 'post_search_terms' ) as $key ) {
	$before[ $key ] = Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] );
}
$data     = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true );
$vector   = Index::vector( $data['posts'][0]['vector']['embedding'] );
$captured = array();
$calls    = 0;
$mock     = static function ( $pre, $arguments, $url ) use ( $vector, &$captured, &$calls ) {
	if ( ! str_starts_with( $url, 'https://api.openai.com/v1/' ) ) {
		return $pre;
	}
	++$calls;
	$payload = json_decode( $arguments['body'], true );
	if ( str_ends_with( $url, '/embeddings' ) ) {
		$body = array(
			'model' => 'text-embedding-3-small',
			'usage' => array( 'prompt_tokens' => 20 ),
			'data'  => array(
				array(
					'index'     => 0,
					'embedding' => $vector,
				),
			),
		);
	} else {
		$captured[] = $payload;
		$parts      = explode( "Candidate posts:\n", $payload['input'], 2 );
		$posts      = json_decode( $parts[1], true );
		$output     = array(
			'selected_posts' => array(
				array(
					'id'             => $posts[0]['id'],
					'relevance_tier' => 'direct',
				),
			),
		);
		$body       = array(
			'id'     => 'mock-comparison',
			'status' => 'completed',
			'model'  => 'gpt-5.4-mini',
			'usage'  => array(
				'input_tokens'  => 100,
				'output_tokens' => 20,
			),
			'output' => array(
				array(
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => wp_json_encode( $output ),
						),
					),
				),
			),
		);
	}
	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $body ),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	foreach ( array( 'ask-ai-2', 'sections-300' ) as $arm ) {
		$run = ( new End_To_End_Run() )->run( 1, $arm );
		$assert( 100 === count( $run['candidates'] ), 'Candidate cap changed.' );
		$assert( 1 === count( $run['posts'] ), 'Selection parser failed.' );
		$assert( false === $run['cacheHit'] && $run['controlled'], 'A controlled run must bypass results caching.' );
		$assert( 2 === count( $run['usage'] ), 'Both phases must have usage.' );
		$assert( array_sum( array_column( $run['usage'], 'estimated_cost_usd' ) ) === $run['costUsd'], 'Incomplete total cost.' );
		$payload  = end( $captured );
		$parts    = explode( "Candidate posts:\n", $payload['input'], 2 );
		$metadata = json_decode( $parts[1], true );
		$assert( 100 === count( $metadata ), 'Reviewer must receive all 100 candidates.' );
		foreach ( $metadata as $post ) {
			$assert( isset( $post['matching_passage'] ) === ( 'sections-300' === $arm ), 'Passages leaked into the control or are missing.' );
		}
		if ( 'ask-ai-2' === $arm ) {
			$expected          = Baseline::invoke( Baseline::reviewer( 'gpt-5.4-mini' ), 'request_payload', array( $run['question'], $metadata ) );
			$expected['store'] = false;
			$assert( $payload === $expected, 'Baseline reviewer differs from production.' );
		} else {
			$expected = Evidence_Review::payload( Evidence_Review::fixture( 1 ), true );
			$assert( $payload['instructions'] === $expected['instructions'], 'Passage prompt differs from previous evidence test.' );
		}
	}
	$assert( 4 === $calls, 'Two fresh calls required for each search.' );
	$left  = $captured[0];
	$right = $captured[1];
	unset( $left['input'], $left['instructions'], $right['input'], $right['instructions'] );
	$assert( $left === $right, 'Reviewer model, output limit, reasoning or schema differs.' );
	foreach ( array( array( 0, 'ask-ai-2' ), array( 51, 'ask-ai-2' ), array( 1, 'unknown' ) ) as $invalid ) {
		$failed = false;
		try {
			( new End_To_End_Run() )->run( $invalid[0], $invalid[1] );
		} catch ( InvalidArgumentException $error ) {
			$failed = true;
		}
		$assert( $failed, 'Invalid experiment request was not rejected.' );
	}
	$assert( 4 === $calls, 'Invalid requests must not trigger API calls.' );
	for ( $i = 1; $i <= 50; ++$i ) {
		$assert( End_To_End_Run::question( $i )['id'] === $i, 'Question identity changed.' );
	}
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
do_action( 'rest_api_init' );
wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'POST', '/ehrman-section-pilot/v1/compare-300' ) );
$assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous paid comparison allowed.' );
foreach ( $before as $key => $count ) {
	$assert( Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] ) === $count, 'Production data changed: ' . $key );
}
echo wp_json_encode(
	array(
		'checks'                    => $checks,
		'paidCalls'                 => 0,
		'productionCountsUnchanged' => true,
	)
);
