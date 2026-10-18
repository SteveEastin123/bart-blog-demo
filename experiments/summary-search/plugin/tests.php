<?php
/** Offline checks: all provider requests are intercepted, no paid calls. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
define( 'EHRMAN_SUMMARY_TEST', true );

use EhrmanBlogDiscovery\Database;
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Index;
use EhrmanSummaryPilot\Summary_Run;

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$before = array();
foreach ( Database::tables() as $name ) {
	$before[ $name ] = Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $name );
}
$index    = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true );
$improved = json_decode( file_get_contents( Index::DIRECTORY . '/' . Summary_Run::RUN . '/improved.json' ), true );
$old      = array_column( $index['posts'], null, 'wpId' );
$new      = array_column( $improved['posts'], null, 'wpId' );
$vector   = Index::vector( $index['posts'][0]['vector']['embedding'] );
$captured = array();
$calls    = 0;
$mode     = 'selected';
$mock     = static function ( $pre, $arguments, $url ) use ( $vector, &$captured, &$calls, &$mode ) {
	if ( ! str_starts_with( $url, 'https://api.openai.com/v1/' ) ) {
		return $pre;
	}
	++$calls;
	/** @var string $mode Mutated by test cases before this callback runs. */
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
		$output     = array( 'selected_posts' => array() );
		if ( 'selected' === $mode ) {
			$output['selected_posts'] = array(
				array(
					'id'             => $posts[2]['id'],
					'relevance_tier' => 'background',
				),
				array(
					'id'             => $posts[0]['id'],
					'relevance_tier' => 'direct',
				),
				array(
					'id'             => $posts[1]['id'],
					'relevance_tier' => 'related',
				),
			);
		}
		$body = array(
			'id'     => 'mock-summary',
			'status' => 'incomplete' === $mode ? 'incomplete' : 'completed',
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
	foreach ( array( 'ask-ai-2', 'improved-summary' ) as $arm ) {
		$run = ( new Summary_Run() )->run( 1, $arm );
		$assert( 100 === count( $run['candidates'] ), 'Expected 100 candidates.' );
		$assert( ! $run['cacheHit'] && ! $run['broaderFallback'], 'Unexpected cache/fallback.' );
		$assert( 2 === count( $run['usage'] ), 'Both paid phases must be counted.' );
		$assert( array_sum( array_column( $run['usage'], 'estimated_cost_usd' ) ) === $run['costUsd'], 'Cost mismatch.' );
		$assert( array_column( $run['posts'], 'wpId' ) === array_column( array_slice( $run['candidates'], 0, 3 ), 'wpId' ), 'Tier ordering changed.' );
		$payload = end( $captured );
		$parts   = explode( "Candidate posts:\n", $payload['input'], 2 );
		$rows    = json_decode( $parts[1], true );
		$assert( 100 === count( $rows ), 'Reviewer must see all candidates.' );
		foreach ( $run['candidates'] as $i => $post ) {
			$expected = 'ask-ai-2' === $arm ? $old[ $post['wpId'] ]['searchSummary'] : $new[ $post['wpId'] ]['summary'];
			$assert( $post['summary'] === $expected, 'Wrong summary source.' );
			$assert( sanitize_text_field( $expected ) === $rows[ $i ]['summary'], 'Reviewer summary differs.' );
			$assert( array_keys( $rows[ $i ] ) === array( 'id', 'title', 'summary' ), 'Extra evidence leaked into review.' );
		}
		$expected          = Baseline::invoke( Baseline::reviewer( 'gpt-5.4-mini' ), 'request_payload', array( $run['question'], $rows ) );
		$expected['store'] = false;
		$assert( $payload === $expected, 'Production review payload changed.' );
		if ( 'ask-ai-2' === $arm ) {
			$ranked = ( new Index() )->rank( $run['question'], $vector, 'baseline' );
			$assert( array_column( $run['candidates'], 'wpId' ) === array_map( 'strval', array_column( $ranked, 'source_wp_id' ) ), 'Baseline retrieval changed.' );
		}
	}
	$assert( $captured[0]['instructions'] === $captured[1]['instructions'], 'Prompts differ.' );
	foreach ( array( array( 0, 'ask-ai-2' ), array( 51, 'ask-ai-2' ), array( 1, 'unknown' ) ) as $invalid ) {
		$failed = false;
		try {
			( new Summary_Run() )->run( $invalid[0], $invalid[1] );
		} catch ( InvalidArgumentException $error ) {
			$failed = true;
		}
		$assert( $failed, 'Invalid request accepted.' );
	}
	$assert( 4 === $calls, 'Invalid requests made API calls.' );
	$mode = 'empty';
	$run  = ( new Summary_Run() )->run( 1, 'improved-summary' );
	$assert( $run['broaderFallback'] && 25 === count( $run['posts'] ), 'Fallback changed.' );
	$mode   = 'incomplete';
	$failed = false;
	try {
		( new Summary_Run() )->run( 1, 'ask-ai-2' );
	} catch ( RuntimeException $error ) {
		$failed = true;
	}
	$assert( $failed, 'Incomplete review was treated as a valid empty result.' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
do_action( 'rest_api_init' );
wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'POST', '/ehrman-section-pilot/v1/compare-summaries' ) );
$assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous paid requests permitted.' );
foreach ( $before as $name => $count ) {
	$assert( Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $name ) === $count, 'Production row count changed.' );
}
echo wp_json_encode(
	array(
		'checks'                    => $checks,
		'paidCalls'                 => 0,
		'productionCountsUnchanged' => true,
	)
);
