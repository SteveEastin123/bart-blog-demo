<?php
/**
 * Plugin Name: Ehrman Full-Corpus Comparison (Local Only)
 * Description: Frozen administrator-only comparison; never writes production search tables.
 * Version: 0.1.0
 */
namespace EhrmanFullComparison;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/Index.php';
require_once __DIR__ . '/Service.php';

const RUN = 'full-corpus-250-2026-10-18';

function permission(): bool {
	return current_user_can( 'manage_options' ) && function_exists( '\EhrmanSectionPilot\local_enabled' )
		&& \EhrmanSectionPilot\local_enabled() && '1' === (string) get_option( 'ehrman_full_comparison_enabled', '0' );
}

function fixture( int $number, string $arm ): array {
	if ( ! in_array( $arm, array( 'ask-ai-2', 'ask-ai-3' ), true ) || $number < 1 || $number > 250 ) {
		throw new \InvalidArgumentException( 'Invalid frozen question or arm.' );
	}
	$manifest = json_decode( file_get_contents( Index::DIRECTORY . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( $manifest['runtimeHashes'] as $name => $hash ) {
		$path = str_starts_with( $name, 'plugin/' ) ? __DIR__ . '/' . substr( $name, 7 ) : Index::DIRECTORY . '/' . $name;
		if ( ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) {
			throw new \RuntimeException( 'Frozen benchmark file changed: ' . $name );
		}
	}
	$questions = json_decode( file_get_contents( Index::DIRECTORY . '/questions.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( RUN !== $manifest['run'] || 250 !== count( $questions['questions'] ) ) {
		throw new \RuntimeException( 'Invalid frozen benchmark.' );
	}
	return $questions['questions'][ $number - 1 ];
}

function paths( int $number, string $arm ): array {
	$base = Index::DIRECTORY . '/results/q' . sprintf( '%03d', $number ) . '-' . $arm;
	return array(
		'result' => $base . '.json',
		'claim'  => $base . '.claim',
		'error'  => $base . '.error.json',
	);
}

function run( \WP_REST_Request $request ) {
	$number = (int) $request->get_param( 'questionId' );
	$arm    = (string) $request->get_param( 'arm' );
	try {
		$question = fixture( $number, $arm );
		$files    = paths( $number, $arm );
		if ( file_exists( $files['result'] ) ) {
			return new \WP_Error( 'benchmark_completed', 'This paid search is already saved. Use the read-only status endpoint.', array( 'status' => 409 ) );
		}
		$claim = fopen( $files['claim'], 'x' );
		if ( false === $claim ) {
			return new \WP_Error( 'benchmark_claimed', 'A previous attempt needs inspection before any retry.', array( 'status' => 409 ) );
		}
		fclose( $claim );
		Service::log(
			array(
				'type'       => 'benchmark-start',
				'run'        => RUN,
				'questionId' => $number,
				'arm'        => $arm,
				'time'       => gmdate( 'c' ),
			)
		);
		$sections = 'ask-ai-3' === $arm;
		$result   = ( new Service() )->search( $question['question'], $sections ? '300' : 'baseline', $sections, true );
		$result  += array(
			'run'        => RUN,
			'questionId' => $number,
			'arm'        => $arm,
			'group'      => $question['group'],
		);
		if ( false === file_put_contents( $files['result'] . '.tmp', wp_json_encode( $result ), LOCK_EX )
			|| ! rename( $files['result'] . '.tmp', $files['result'] ) ) {
			throw new \RuntimeException( 'Could not save benchmark result.' );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	} catch ( \Throwable $error ) {
		if ( isset( $files ) ) {
			file_put_contents(
				$files['error'],
				wp_json_encode(
					array(
						'message' => $error->getMessage(),
						'time'    => gmdate( 'c' ),
					)
				),
				LOCK_EX
			);
		}
		return new \WP_Error( 'benchmark_failed', $error->getMessage(), array( 'status' => 500 ) );
	}
}

function status( \WP_REST_Request $request ) {
	$number = (int) $request->get_param( 'questionId' );
	$arm    = (string) $request->get_param( 'arm' );
	try {
		fixture( $number, $arm );
		$files = paths( $number, $arm );
		if ( file_exists( $files['result'] ) ) {
			return rest_ensure_response( json_decode( file_get_contents( $files['result'] ), true, 512, JSON_THROW_ON_ERROR ) );
		}
		return rest_ensure_response( array( 'state' => file_exists( $files['claim'] ) ? 'claimed' : 'not-started' ) );
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'benchmark_status', $error->getMessage(), array( 'status' => 400 ) );
	}
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'ehrman-full-comparison/v1',
			'/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'callback'            => __NAMESPACE__ . '\\run',
			)
		);
		register_rest_route(
			'ehrman-full-comparison/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'callback'            => __NAMESPACE__ . '\\status',
			)
		);
	}
);
