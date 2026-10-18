<?php
/** Frozen replay checks with no paid requests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
use EhrmanSectionPilot\Evidence_Review;
use EhrmanBlogDiscovery\Database;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
for ( $number = 1; $number <= 50; ++$number ) {
	$fixture = Evidence_Review::fixture( $number );
	$left    = Evidence_Review::payload( $fixture, false );
	$right   = Evidence_Review::payload( $fixture, true );
	$parts   = explode( "Candidate posts:\n", $left['input'], 2 );
	$summary = json_decode( $parts[1], true );
	$parts   = explode( "Candidate posts:\n", $right['input'], 2 );
	$passage = json_decode( $parts[1], true );
	$assert( 100 === count( $summary ) && 100 === count( $passage ), 'Candidate count changed.' );
	foreach ( $passage as &$post ) {
		$assert( ! empty( $post['matching_passage'] ), 'Passage missing.' );
		unset( $post['matching_passage'] );
	}
	unset( $post );
	$assert( $summary === $passage, 'Candidate IDs, titles, summaries, or order differ.' );
	unset( $left['input'], $right['input'] );
	$assert( $left === $right, 'Prompt or API settings differ between arms.' );
	$assert( 800 === $left['max_output_tokens'], 'Output budget changed.' );
}
$tables = Database::tables();
$before = array();
foreach ( array( 'ai_usage', 'ai_requests', 'ai_refinements', 'post_embeddings' ) as $key ) {
	$before[ $key ] = Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] );
}
$mock = static function ( $pre, $arguments, $url ) {
	if ( ! str_starts_with( $url, 'https://api.openai.com/v1/' ) ) {
		return $pre;
	}
	if ( ! str_ends_with( $url, '/responses' ) ) {
		throw new RuntimeException( 'Replay must not re-embed or retrieve candidates.' );
	}
	$payload = json_decode( $arguments['body'], true );
	$parts   = explode( "Candidate posts:\n", $payload['input'], 2 );
	$rows    = json_decode( $parts[1], true );
	$body    = array(
		'id'          => 'mock-evidence',
		'status'      => 'completed',
		'model'       => 'gpt-5.4-mini',
		'usage'       => array(
			'input_tokens'  => 100,
			'output_tokens' => 20,
		),
		'output_text' => wp_json_encode(
			array(
				'selected_posts' => array(
					array(
						'id'             => $rows[0]['id'],
						'relevance_tier' => 'direct',
					),
				),
			)
		),
	);
	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $body ),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	foreach ( array( false, true ) as $evidence ) {
		$result = ( new Evidence_Review() )->run( 1, $evidence );
		$assert( count( $result['posts'] ) === 1 && ! $result['cacheHit'], 'Review selection or cache contract failed.' );
		$assert( $result['costUsd'] > 0, 'Missing review cost.' );
	}
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
foreach ( $before as $key => $count ) {
	$assert( Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] ) === $count, 'Production data changed.' );
}
do_action( 'rest_api_init' );
wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'POST', '/ehrman-section-pilot/v1/review-evidence' ) );
$assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous access must fail.' );
echo wp_json_encode(
	array(
		'checks'                    => $checks,
		'paidCalls'                 => 0,
		'productionCountsUnchanged' => true,
	)
);
