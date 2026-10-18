<?php
/**
 * WP-CLI integration checks against the installed frozen fixtures and real DB helpers.
 * Run: wp eval-file wp-content/plugins/ehrman-dimension-pilot/tests.php
 * Every HTTP request is intercepted, including permission tests; no real API key is needed.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use EhrmanBlogDiscovery\Database;
use EhrmanDimensionPilot\Dimension_Run;
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Evidence_Review;
use EhrmanSectionPilot\Index;

define( 'EHRMAN_DIMENSION_TEST', true );
require_once __DIR__ . '/dimension-pilot.php';

$checks        = 0;
$assert        = static function ( $condition, string $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$reject        = static function ( callable $action, string $message ) use ( $assert ) {
	$failed = false;
	try {
		$action();
	} catch ( RuntimeException | InvalidArgumentException | TypeError $error ) {
		$failed = true;
	}
	$assert( $failed, $message );
};
$property      = static function ( object $instance, string $name, $value ): void {
	( new ReflectionProperty( $instance, $name ) )->setValue( $instance, $value );
};
$calls         = array();
$captured      = array();
$mode          = 'selected';
$bad_dimension = 0;
$vector512     = array();
$vector1536    = array();
$mock          = static function ( $pre, $arguments, $url ) use ( &$calls, &$captured, &$mode, &$bad_dimension, &$vector512, &$vector1536 ) {
	/** @var string $mode Changed by each test before this deferred HTTP callback runs. */
	// Fail closed for unexpected URLs too. Never fall through to a network transport.
	if ( ! in_array( $url, array( 'https://api.openai.com/v1/embeddings', 'https://api.openai.com/v1/responses' ), true ) ) {
		throw new RuntimeException( 'Unexpected HTTP request in offline tests: ' . $url );
	}
	$payload = json_decode( $arguments['body'], true, 512, JSON_THROW_ON_ERROR );
	$calls[] = array(
		'url'     => $url,
		'payload' => $payload,
	);
	if ( str_ends_with( $url, '/embeddings' ) ) {
		$dimensions = $payload['dimensions'];
		$embedding  = 512 === $dimensions ? $vector512 : $vector1536;
		if ( $bad_dimension === $dimensions ) {
			$embedding = array_slice( $embedding, 1 );
		}
		$body = array(
			'model' => 'text-embedding-3-small',
			'usage' => array( 'prompt_tokens' => 512 === $dimensions ? 20 : 30 ),
			'data'  => array(
				array(
					'index'     => 0,
					'embedding' => $embedding,
				),
			),
		);
	} else {
		$captured[] = $payload;
		$posts      = json_decode( explode( "Candidate posts:\n", $payload['input'], 2 )[1], true, 512, JSON_THROW_ON_ERROR );
		$output     = array( 'selected_posts' => array() );
		if ( 'selected' === $mode ) {
			$output['selected_posts'] = array(
				array(
					'id'             => $posts[2]['id'],
					'relevance_tier' => 'background',
				),
				array(
					'id'             => 'not-a-candidate',
					'relevance_tier' => 'direct',
				),
				array(
					'id'             => $posts[0]['id'],
					'relevance_tier' => 'direct',
				),
				array(
					'id'             => $posts[0]['id'],
					'relevance_tier' => 'related',
				),
				array(
					'id'             => $posts[1]['id'],
					'relevance_tier' => 'related',
				),
				array(
					'id'             => $posts[3]['id'],
					'relevance_tier' => 'unknown-tier',
				),
			);
		} elseif ( 'malformed' === $mode ) {
			$output = array( 'unexpected' => array() );
		} elseif ( 'invalid-item' === $mode ) {
			$output['selected_posts'] = array(
				array(
					'id'             => array(),
					'relevance_tier' => 'direct',
				),
			);
		}
		$body = array(
			'id'     => 'mock-dimension-' . count( $calls ),
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
							'text' => 'bad-json' === $mode ? '{broken' : wp_json_encode( $output ),
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
$read_only     = static function ( $sql ) {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $sql ) ) {
		throw new RuntimeException( 'Database write attempted during dimension tests.' );
	}
	return $sql;
};
$key           = getenv( 'OPENAI_API_KEY' );
$model         = getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
$user          = get_current_user_id();
$disabled      = static fn() => '0';
add_filter( 'pre_http_request', $mock, PHP_INT_MAX, 3 );
add_filter( 'query', $read_only, PHP_INT_MAX );
putenv( 'OPENAI_API_KEY=dimension-tests-http-is-mocked' );
putenv( 'EHRMAN_DISCOVERY_AI_MODEL=gpt-5.4-mini' );

try {
	$assert( \EhrmanSectionPilot\local_enabled(), 'Enable the local-only pilot before running integration checks.' );
	$before = array();
	foreach ( Database::tables() as $table ) {
		$before[ $table ] = Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $table );
	}
	$runner           = new Dimension_Run();
	$index            = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true, 512, JSON_THROW_ON_ERROR );
	$manifest         = json_decode( file_get_contents( Index::DIRECTORY . '/' . Dimension_Run::RUN . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	$questions        = json_decode( file_get_contents( Index::DIRECTORY . '/' . Dimension_Run::RUN . '/questions.json' ), true, 512, JSON_THROW_ON_ERROR )['questions'];
	$old              = array_column( $index['posts'], null, 'wpId' );
	$vector512        = Index::vector( $index['posts'][0]['vector']['embedding'] );
	$vector1536       = array_merge( $vector512, array_fill( 0, 1024, 0.0 ) );
	$sections         = iterator_to_array( Baseline::invoke( $runner, 'read_sections', array( Index::DIRECTORY . '/sections-300.jsonl.gz' ) ), false );
	$padded           = array_map(
		static function ( $row ) {
			$row['embedding'] = base64_encode( base64_decode( $row['embedding'], true ) . str_repeat( "\0", 4096 ) );
			return $row;
		},
		$sections
	);
	$synthetic_runner = clone $runner;
	$property( $synthetic_runner, 'sections', $padded );
	$validated = Baseline::invoke( $runner, 'validate_sections', array( $padded, new ArrayIterator( $sections ) ) );
	$assert( count( $validated ) === $manifest['sectionCount'], 'Matching padded fixtures rejected.' );
	unset( $validated );
	$question = sanitize_text_field( $questions[0]['question'] );
	$baseline = Baseline::invoke( $runner, 'baseline_rows', array( $question, $vector512 ) );
	$assert( 500 === count( $baseline ), 'Baseline rows were truncated before section fusion.' );
	$assert( array_slice( $baseline, 0, 100 ) === ( new Index() )->rank( $question, $vector512, 'baseline' ), 'Full baseline does not match Index.' );
	foreach ( array(
		'ask-ai-2'     => 'baseline',
		'sections-512' => '300',
	) as $arm => $index_mode ) {
		$expected = ( new Index() )->rank( $question, $vector512, $index_mode );
		$assert( $runner->rank( $question, $vector512, $arm ) === $expected, '512 control lost exact Index parity: ' . $arm );
	}
	$assert(
		$synthetic_runner->rank( $question, $vector512, 'sections-1536', $vector1536 ) === ( new Index() )->rank( $question, $vector512, '300' ),
		'1536 fusion differs from Index with identical zero-padded 512 vectors.'
	);

	// Manufactured ties distinguish numeric source IDs, stable passage choice and final date/semantic ordering.
	$unit         = array_fill( 0, 1536, 0.0 );
	$unit[0]      = 1.0;
	$encoded      = base64_encode( pack( 'g*', ...$unit ) );
	$tie_rows     = array();
	$tie_sections = array();
	for ( $id = 500; $id >= 1; --$id ) {
		$tie_rows[]     = array(
			'id'              => (string) ( 700000 + $id ),
			'source_wp_id'    => (string) $id,
			'retrieval_score' => 1.0 - 1.0 / ( 60.0 + $id ),
			'semantic_score'  => $id >= 499 ? 1.0 : 0.5,
			'published_at'    => 499 === $id ? '2026-01-02' : '2026-01-01',
		);
		$tie_sections[] = array(
			'wpId'      => (string) $id,
			'section'   => 1,
			'text'      => 'first',
			'embedding' => $encoded,
		);
		$tie_sections[] = array(
			'wpId'      => (string) $id,
			'section'   => 0,
			'text'      => 'second',
			'embedding' => $encoded,
		);
	}
	$tied = Baseline::invoke( $runner, 'fuse', array( $tie_rows, $unit, $tie_sections ) );
	$assert( array_slice( array_column( $tied, 'source_wp_id' ), 0, 4 ) === array( '499', '500', '498', '497' ), 'Final semantic/date/stable tie rules changed.' );
	foreach ( $tied as $row ) {
		$assert( 1.0 === $row['retrieval_score'] && 'first' === $row['matching_passage'], 'Numeric section-rank ties or first-passage ties changed.' );
	}
	$outside = array_reverse( $tie_rows );
	foreach ( $outside as &$row ) {
		$row['retrieval_score'] = 0.0;
		$row['semantic_score']  = 0.0;
	}
	unset( $row );
	$orthogonal       = $unit;
	$orthogonal[0]    = 0.0;
	$orthogonal[1]    = 1.0;
	$orthogonal       = base64_encode( pack( 'g*', ...$orthogonal ) );
	$outside_sections = array_map(
		static function ( $row ) use ( $orthogonal ) {
			if ( '500' !== $row['wpId'] ) {
				$row['embedding'] = $orthogonal;
			}
			return $row;
		},
		$tie_sections
	);
	$promoted         = Baseline::invoke( $runner, 'fuse', array( $outside, $unit, $outside_sections ) );
	$assert( '500' === $promoted[0]['source_wp_id'], 'Section fusion cannot promote a row outside the baseline top 100.' );
	$reject( static fn() => Baseline::invoke( $runner, 'fuse', array( array_slice( $baseline, 0, 100 ), $unit, $tie_sections ) ), 'Truncated fusion accepted.' );

	foreach ( array( 512, 1536 ) as $dimensions ) {
		$valid    = array_fill( 0, $dimensions, 0.0 );
		$valid[0] = 1.0;
		foreach ( array(
			array(),
			array_slice( $valid, 1 ),
			array_fill( 0, $dimensions, 0.0 ),
			array_replace( $valid, array( 0 => 2.0 ) ),
			array_replace( $valid, array( 0 => INF ) ),
			array_replace( $valid, array( 0 => NAN ) ),
			array_replace( $valid, array( 0 => '1' ) ),
			array_combine( range( 1, $dimensions ), $valid ),
		) as $bad ) {
			$reject( static fn() => Baseline::invoke( $runner, 'validate_vector', array( $bad, $dimensions ) ), 'Invalid query vector accepted.' );
		}
		$assert( Dimension_Run::vector( base64_encode( pack( 'g*', ...$valid ) ), $dimensions ) === $valid, 'Valid packed vector changed.' );
		foreach ( array(
			'!',
			base64_encode( 'short' ),
			base64_encode( str_repeat( "\0", 4 * $dimensions ) ),
			base64_encode( pack( 'g*', ...array_replace( $valid, array( 0 => NAN ) ) ) ),
		) as $bad ) {
			$reject( static fn() => Dimension_Run::vector( $bad, $dimensions ), 'Malformed packed vector accepted.' );
		}
	}
	$reject( static fn() => Dimension_Run::vector( $encoded, 768 ), 'Unsupported dimensions accepted.' );
	$reject( static fn() => $runner->rank( $question, $vector1536, 'ask-ai-2' ), '1536 title/summary query accepted.' );
	$reject( static fn() => $runner->rank( $question, $vector512, 'sections-1536', $vector512 ), '512 passage query accepted for 1536 arm.' );
	$reject( static fn() => $runner->rank( $question, $vector512, 'sections-1536' ), 'Missing passage query accepted.' );
	$reject( static fn() => $runner->rank( $question, $vector512, 'sections-512', $vector1536 ), 'Extra query accepted for control.' );
	$reject( static fn() => $runner->rank( $question, $vector512, 'unknown' ), 'Unknown retrieval arm accepted.' );
	foreach ( array(
		'wpId'       => '999999999',
		'size'       => 400,
		'section'    => -1,
		'text'       => 'changed',
		'hash'       => str_repeat( '0', 64 ),
		'embedding'  => $sections[0]['embedding'],
		'dimensions' => 512,
		'model'      => 'wrong',
	) as $field => $value ) {
		$bad              = $padded;
		$bad[0][ $field ] = $value;
		$reject( static fn() => Baseline::invoke( $runner, 'validate_sections', array( $bad, new ArrayIterator( $sections ) ) ), 'Changed section accepted: ' . $field );
	}
	$reject( static fn() => Baseline::invoke( $runner, 'validate_sections', array( array_slice( $padded, 0, -1 ), new ArrayIterator( $sections ) ) ), 'Missing section accepted.' );
	$reject( static fn() => Baseline::invoke( $runner, 'validate_sections', array( array_merge( $padded, array( $padded[0] ) ), new ArrayIterator( $sections ) ) ), 'Extra section accepted.' );
	$bad    = $padded;
	$bad[1] = $bad[0];
	$reject( static fn() => Baseline::invoke( $runner, 'validate_sections', array( $bad, new ArrayIterator( $sections ) ) ), 'Duplicate section accepted.' );
	$bad_runner   = clone $runner;
	$bad_manifest = $manifest;
	++$bad_manifest['sectionCount'];
	$property( $bad_runner, 'manifest', $bad_manifest );
	$reject( static fn() => Baseline::invoke( $bad_runner, 'validate_sections', array( $padded, new ArrayIterator( $sections ) ) ), 'Wrong exact section count accepted.' );
	foreach ( array( 'promptHashes', 'settingsHash' ) as $field ) {
		$changed = $manifest;
		if ( 'promptHashes' === $field ) {
			$changed[ $field ]['sections-1536'] = str_repeat( '0', 64 );
		} else {
			$changed[ $field ] = str_repeat( '0', 64 );
		}
		$property( $bad_runner, 'manifest', $changed );
		$reject( static fn() => Baseline::invoke( $bad_runner, 'review_payload', array( $question, array(), 'sections-1536' ) ), 'Changed reviewer fingerprint accepted.' );
	}
	$reject( static fn() => Baseline::invoke( $runner, 'fingerprint', array( __DIR__ . '/dimension-pilot.php', str_repeat( '0', 64 ) ) ), 'Changed runner hash accepted.' );
	$assert( 0 === count( $calls ), 'Pure validation/ranking checks made HTTP calls.' );
	unset( $bad, $padded, $sections, $synthetic_runner, $bad_runner, $tie_sections, $outside_sections );

	$runs = array();
	foreach ( array( 'ask-ai-2', 'sections-512', 'sections-1536' ) as $arm ) {
		$offset       = count( $calls );
		$run          = $runner->run( 1, $arm );
		$runs[ $arm ] = $run;
		$phases       = 'sections-1536' === $arm ? 3 : 2;
		$assert( count( $calls ) - $offset === $phases && count( $run['usage'] ) === $phases, 'Wrong billable phase count.' );
		$assert( array_column( array_column( array_slice( $calls, $offset, $phases - 1 ), 'payload' ), 'dimensions' ) === ( 3 === $phases ? array( 512, 1536 ) : array( 512 ) ), 'Wrong query dimensions or ordering.' );
		$assert( array_sum( array_column( $run['usage'], 'estimated_cost_usd' ) ) === $run['costUsd'], 'Not all costs counted.' );
		$assert( 20 * .02 / 1000000 === $run['usage'][0]['estimated_cost_usd'], '512 cost missing.' );
		if ( 3 === $phases ) {
			$assert( 30 * .02 / 1000000 === $run['usage'][1]['estimated_cost_usd'], '1536 cost missing.' );
		}
		$assert( Dimension_Run::RUN === $run['run'] && $arm === $run['arm'] && 1 === $run['questionId'] && $run['test'], 'Wrong run identity.' );
		$assert( $run['group'] === $questions[0]['group'] && 500 === $run['sampleCount'] && $run['sampleHash'] === $manifest['sampleHash'], 'Wrong sample/group.' );
		$assert( ! $run['cacheHit'] && ! $run['broaderFallback'] && $run['seconds'] >= 0 && wp_is_uuid( $run['requestId'] ), 'Invalid request diagnostics.' );
		$assert(
			100 === count( $run['candidates'] ) && 100 === count( array_unique( array_column( $run['candidates'], 'wpId' ) ) )
			&& 100 === count( array_unique( array_column( $run['candidates'], 'id' ) ) ),
			'Expected 100 unique candidates.'
		);
		$assert( array_column( $run['posts'], 'id' ) === array_column( array_slice( $run['candidates'], 0, 3 ), 'id' ), 'Production tier ordering/deduplication changed.' );
		$assert( array_values( $run['tiers'] ) === array( 'background', 'direct', 'related' ), 'Tier labels changed.' );
		$payload  = end( $captured );
		$metadata = json_decode( explode( "Candidate posts:\n", $payload['input'], 2 )[1], true, 512, JSON_THROW_ON_ERROR );
		$assert( 100 === count( $metadata ) && hash( 'sha256', wp_json_encode( $metadata ) ) === $run['candidateHash'], 'Actual evidence hash/count mismatch.' );
		$assert( $run['promptHash'] === $manifest['promptHashes'][ $arm ] && $run['settingsHash'] === $manifest['settingsHash'], 'Prompt/settings mismatch.' );
		foreach ( $run['candidates'] as $i => $post ) {
			$assert( isset( $old[ $post['wpId'] ] ) && $post['summary'] === $old[ $post['wpId'] ]['searchSummary'], 'Original summary or source wpId changed.' );
			$assert( $post['title'] === $old[ $post['wpId'] ]['title'] && sanitize_text_field( $post['summary'] ) === $metadata[ $i ]['summary'], 'Reviewer metadata changed.' );
			$assert( array_key_exists( 'passage', $post ) && ( 'ask-ai-2' === $arm ? '' === $post['passage'] : $post['passage'] === $metadata[ $i ]['matching_passage'] ), 'Passage missing or leaked into control.' );
			$assert( array_keys( $metadata[ $i ] ) === ( 'ask-ai-2' === $arm ? array( 'id', 'title', 'summary' ) : array( 'id', 'title', 'summary', 'matching_passage' ) ), 'Unexpected evidence fields.' );
		}
		if ( 'ask-ai-2' === $arm ) {
			$expected          = Baseline::invoke( Baseline::reviewer( 'gpt-5.4-mini' ), 'request_payload', array( $question, $metadata ) );
			$expected['store'] = false;
		} else {
			$expected = Evidence_Review::payload(
				array(
					'model'      => 'gpt-5.4-mini',
					'question'   => $question,
					'candidates' => $run['candidates'],
				),
				true
			);
		}
		$assert( $expected === $payload, 'Exact frozen review payload changed.' );
		$expected_rows = $runner->rank( $question, $vector512, $arm, 'sections-1536' === $arm ? $vector1536 : null );
		$assert( array_column( $run['candidates'], 'wpId' ) === array_map( 'strval', array_column( $expected_rows, 'source_wp_id' ) ), 'Wrong exported source identities.' );
		$assert( array_column( $run['posts'], 'passage' ) === array_column( array_slice( $run['candidates'], 0, 3 ), 'passage' ), 'Selected passages changed.' );
	}
	$assert( $captured[1]['instructions'] === $captured[2]['instructions'], 'Section arm prompts differ.' );
	$assert( count( array_unique( array_column( $runs, 'requestId' ) ) ) === 3, 'Request IDs reused.' );
	$offset = count( $calls );
	foreach ( array( array( 0, 'ask-ai-2' ), array( 51, 'sections-512' ), array( 1, 'unknown' ) ) as $invalid ) {
		$reject( static fn() => $runner->run( $invalid[0], $invalid[1] ), 'Invalid request accepted.' );
	}
	putenv( 'EHRMAN_DISCOVERY_AI_MODEL=wrong-model' );
	$reject( static fn() => $runner->run( 1, 'ask-ai-2' ), 'Wrong model accepted.' );
	putenv( 'EHRMAN_DISCOVERY_AI_MODEL=gpt-5.4-mini' );
	$assert( count( $calls ) === $offset, 'Invalid requests made HTTP calls.' );
	foreach ( array( 'ask-ai-2', 'sections-512', 'sections-1536' ) as $arm ) {
		$mode = 'empty';
		$run  = $runner->run( 1, $arm );
		$assert( $run['broaderFallback'] && 25 === count( $run['posts'] ) && array_slice( $run['candidates'], 0, 25 ) === $run['posts'] && array() === $run['tiers'], 'Production fallback changed.' );
	}
	foreach ( array( 'incomplete', 'malformed', 'invalid-item', 'bad-json' ) as $failure ) {
		$mode = $failure;
		$reject( static fn() => $runner->run( 1, 'sections-1536' ), 'Invalid review treated as successful fallback: ' . $failure );
	}
	$mode = 'selected';
	foreach ( array(
		512  => 1,
		1536 => 2,
	) as $dimensions => $expected_calls ) {
		$bad_dimension = $dimensions;
		$offset        = count( $calls );
		$reject( static fn() => $runner->run( 1, 'sections-1536' ), 'Malformed API vector accepted.' );
		$assert( count( $calls ) - $offset === $expected_calls, 'Bad query triggered later billed phases.' );
	}
	$bad_dimension = 0;

	// Inspect only this run's raw responses, preserving the existing audit log intact.
	$audit  = array();
	$handle = fopen( Index::DIRECTORY . '/requests.jsonl', 'rb' );
	try {
		$line = fgets( $handle );
		while ( false !== $line ) {
			$row = json_decode( $line, true );
			if ( is_array( $row ) && in_array( $row['requestId'] ?? '', array_column( $runs, 'requestId' ), true ) ) {
				$audit[ $row['requestId'] ][] = $row;
			}
			$line = fgets( $handle );
		}
	} finally {
		fclose( $handle );
	}
	foreach ( $runs as $arm => $run ) {
		$rows = $audit[ $run['requestId'] ];
		$assert(
			'dimension-comparison-start' === $rows[0]['type'] && Dimension_Run::RUN === $rows[0]['run']
			&& 1 === $rows[0]['questionId'] && $arm === $rows[0]['arm'] && true === $rows[0]['test'],
			'Missing start audit context.'
		);
		$raw = array_values( array_filter( $rows, static fn( $row ) => 'end-to-end-api-response' === ( $row['type'] ?? '' ) ) );
		$assert( count( $raw ) === count( $run['usage'] ) && array_column( $raw, 'usage' ) === $run['usage'], 'Raw responses or usage are not fully auditable.' );
		$assert( array_column( $raw, 'endpoint' ) === ( 'sections-1536' === $arm ? array( 'embeddings', 'embeddings', 'responses' ) : array( 'embeddings', 'responses' ) ), 'Audit phase order changed.' );
		$assert( count( $raw[0]['response']['data'][0]['embedding'] ) === 512 && isset( $raw[0]['payloadHash'] ), 'Raw embedding response/hash missing.' );
		if ( 'sections-1536' === $arm ) {
			$assert( count( $raw[1]['response']['data'][0]['embedding'] ) === 1536, 'Raw 1536 response missing.' );
		}
	}

	// Leave HTTP interception active even when exercising supposedly denied requests.
	do_action( 'rest_api_init' );
	$route  = '/ehrman-section-pilot/v1/compare-dimensions';
	$routes = rest_get_server()->get_routes();
	$assert( isset( $routes[ $route ] ), 'Dimension endpoint not registered.' );
	$handler = $routes[ $route ][0];
	$assert( isset( $handler['methods']['POST'] ) && ! isset( $handler['methods']['GET'] ), 'Dimension endpoint is not POST-only.' );
	$offset = count( $calls );
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_param( 'questionId', 1 );
	$request->set_param( 'arm', 'sections-1536' );
	$response = rest_do_request( $request );
	$assert( in_array( $response->get_status(), array( 401, 403 ), true ), 'Anonymous request permitted.' );
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$assert( ! empty( $admins ), 'An existing local administrator is required.' );
	wp_set_current_user( (int) $admins[0] );
	$assert( $handler['permission_callback'](), 'Local administrator denied.' );
	$no_admin = static function ( $caps ) {
		$caps['manage_options'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $no_admin );
	try {
		$assert( 403 === rest_do_request( $request )->get_status(), 'Authenticated non-admin request permitted.' );
	} finally {
		remove_filter( 'user_has_cap', $no_admin );
	}
	add_filter( 'pre_option_ehrman_section_pilot_enabled', $disabled );
	try {
		$assert( ! $handler['permission_callback'](), 'Disabled experiment permitted.' );
		$reject( static fn() => $runner->run( 1, 'ask-ai-2' ), 'Disabled direct runner permitted.' );
	} finally {
		remove_filter( 'pre_option_ehrman_section_pilot_enabled', $disabled );
	}
	$assert( count( $calls ) === $offset, 'Denied requests made HTTP calls.' );
	$request->set_param( 'arm', 'unknown' );
	$assert( 400 === rest_do_request( $request )->get_status(), 'Endpoint accepted unknown arm.' );
	$assert( count( $calls ) === $offset, 'Invalid endpoint request made HTTP calls.' );
	$request->set_param( 'arm', 'sections-1536' );
	$response = rest_do_request( $request );
	$assert( 200 === $response->get_status() && 'sections-1536' === $response->get_data()['arm'], 'Valid administrator endpoint request failed.' );
	$assert( 3 === count( $calls ) - $offset, 'Endpoint did not use three fresh mocked phases.' );
	foreach ( $before as $table => $count ) {
		$assert( Database::client()->get_var( 'SELECT COUNT(*) FROM ' . $table ) === $count, 'Production row count changed.' );
	}
} finally {
	remove_filter( 'pre_option_ehrman_section_pilot_enabled', $disabled );
	wp_set_current_user( $user );
	putenv( false === $key ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $key );
	putenv( false === $model ? 'EHRMAN_DISCOVERY_AI_MODEL' : 'EHRMAN_DISCOVERY_AI_MODEL=' . $model );
	remove_filter( 'query', $read_only, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $mock, PHP_INT_MAX );
}

echo wp_json_encode(
	array(
		'checks'                    => $checks,
		'paidCalls'                 => 0,
		'mockedHttpCalls'           => count( $calls ),
		'productionCountsUnchanged' => true,
	)
);
