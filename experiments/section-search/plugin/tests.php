<?php
/** Local WP-CLI regression tests. All outbound API calls are mocked. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Index;
use EhrmanSectionPilot\Service;
use EhrmanBlogDiscovery\Database;

$checks  = 0;
$assert  = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$index   = new Index();
$data    = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true );
$vector  = Index::vector( $data['posts'][0]['vector']['embedding'] );
$allowed = array_fill_keys( array_column( $data['posts'], 'wpId' ), true );
$assert( count( $allowed ) === 500, 'The corpus must have 500 unique posts.' );
$assert( abs( Index::cosine( $vector, $vector ) - 1.0 ) < .00001, 'Cosine self-match failed.' );
$assert( count( $data['sourceHashes'] ) >= 2, 'Production code fingerprint is required.' );
foreach ( array( 'baseline', '300', '400', '500' ) as $mode ) {
	$rows = $index->rank( 'Paul and Jesus', $vector, $mode );
	$assert( count( $rows ) === 100, 'Candidate limit changed for ' . $mode );
	$assert( count( array_unique( array_column( $rows, 'source_wp_id' ) ) ) === 100, 'Duplicate parent posts.' );
	foreach ( $rows as $row ) {
		$assert( isset( $allowed[ $row['source_wp_id'] ] ), 'A post escaped the pilot scope.' );
	}
	if ( 'baseline' !== $mode ) {
		$assert( strlen( $rows[0]['matching_passage'] ) > 0, 'Missing matching passage.' );
	}
}
$rows = $index->rank( '', $vector, 'baseline' );
$assert( (string) $rows[0]['source_wp_id'] === (string) $data['posts'][0]['wpId'], 'Summary baseline did not preserve semantic-only order.' );
$failed = false;
try {
	$index->rank( 'test', $vector, 'invalid' );
} catch ( InvalidArgumentException $e ) {
	$failed = true; }
$assert( $failed, 'Unknown section size must fail.' );
$tables = Database::tables();
$before = array();
foreach ( array( 'ai_usage', 'ai_requests', 'ai_refinements', 'post_embeddings' ) as $key ) {
	$before[ $key ] = Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] );
}
$mock = static function ( $pre, $arguments, $url ) use ( $vector ) {
	if ( ! str_starts_with( $url, 'https://api.openai.com/v1/' ) ) {
		return $pre;
	}
	if ( str_ends_with( $url, '/embeddings' ) ) {
		$body = array(
			'data'  => array(
				array(
					'index'     => 0,
					'embedding' => $vector,
				),
			),
			'model' => 'text-embedding-3-small',
			'usage' => array( 'prompt_tokens' => 10 ),
		);
	} else {
		$payload    = json_decode( $arguments['body'], true );
		$parts      = explode( "Candidate posts:\n", $payload['input'], 2 );
		$candidates = json_decode( $parts[1], true );
		$output     = array(
			'selected_posts' => array(
				array(
					'id'             => $candidates[0]['id'],
					'relevance_tier' => 'direct',
				),
			),
		);
		$body       = array(
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
	foreach ( array( 'baseline', '300', '400', '500' ) as $mode ) {
		$result = ( new Service() )->search( '[mock regression] Paul and Jesus', $mode );
		$assert( count( $result['posts'] ) === 1, 'Reviewer selection failed.' );
		$assert( false === $result['cacheHit'], 'A benchmark must not reuse a result cache.' );
		$assert( count( $result['usage'] ) === 2, 'Embedding and review usage must both be recorded.' );
		$assert( $result['costUsd'] > 0, 'Both phases need estimated costs.' );
	}
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
foreach ( $before as $key => $count ) {
	$assert( Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $tables[ $key ] ) === $count, 'Pilot polluted production table ' . $key );
}
do_action( 'rest_api_init' );
wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'POST', '/ehrman-section-pilot/v1/search' ) );
$assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous paid searches must be denied.' );
$enabled = get_option( 'ehrman_section_pilot_enabled', '0' );
try {
	update_option( 'ehrman_section_pilot_enabled', '0' );
	$assert( ! \EhrmanSectionPilot\local_enabled(), 'Disabled experiment must fail closed.' );
} finally {
	update_option( 'ehrman_section_pilot_enabled', $enabled );
}
echo wp_json_encode(
	array(
		'passed'                  => $checks,
		'paidCalls'               => 0,
		'productionDataUnchanged' => true,
	)
);
