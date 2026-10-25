<?php
/**
 * Local capture commands for the separate developer test bundle.
 *
 * @package EhrmanDiscoveryTests
 */

namespace EhrmanDiscoveryTests;

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Search_Service;
use RuntimeException;
use WP_CLI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runs comparisons without the optional HTTP endpoint or external API calls. */
final class Harness {
	/**
	 * Rejects production sites and missing production-plugin dependencies.
	 *
	 * @throws RuntimeException When the site is not suitable for developer tests.
	 */
	public static function require_test_site(): void {
		if ( ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) ) {
			throw new RuntimeException( 'Run developer tests on a staging/test site, not production.' );
		}
		if ( ! class_exists( Search_Service::class ) ) {
			throw new RuntimeException( 'Activate Ehrman Blog Discovery before running developer tests.' );
		}
	}

	/**
	 * Captures one batch to a new JSON file. Existing baselines cannot be overwritten.
	 *
	 * @param array<int,string> $args Case file and output file supplied through WP-CLI.
	 * @throws RuntimeException When the input, site, capture or output is invalid.
	 */
	public static function capture( array $args ): void {
		self::require_test_site();
		if ( count( $args ) !== 2 ) {
			throw new RuntimeException( 'Usage: wp eval-file capture.php <cases.json> <new-output.json>' );
		}
		if ( ! is_file( $args[0] ) || false === realpath( $args[0] ) ) {
			throw new RuntimeException( 'Cases must be a local file.' );
		}
		$directory = realpath( dirname( $args[1] ) );
		$web_root  = realpath( ABSPATH );
		if ( false === $directory || false === $web_root || file_exists( $args[1] )
			|| str_starts_with( wp_normalize_path( $directory ) . '/', wp_normalize_path( $web_root ) . '/' ) ) {
			throw new RuntimeException( 'Output must be a new file outside the public web root.' );
		}
		$input = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture, not a URL.
		if ( false === $input ) {
			throw new RuntimeException( 'Could not read the case file.' );
		}
		$cases = json_decode( $input, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $cases ) || ! array_is_list( $cases ) ) {
			throw new RuntimeException( 'Cases must be a JSON list.' );
		}
		$ids = array();
		foreach ( $cases as $value ) {
			$case = Database::associative_row( $value );
			$id   = $case['id'] ?? null;
			if ( ! is_string( $id ) || '' === trim( $id ) || isset( $ids[ trim( $id ) ] ) ) {
				throw new RuntimeException( 'Every case must have a unique, nonempty string ID.' );
			}
			$ids[ trim( $id ) ] = true;
		}
		$http_calls = 0;
		$block_http = static function () use ( &$http_calls ): WP_Error {
			++$http_calls;
			return new WP_Error( 'developer_tests_http_blocked', 'Developer comparison cannot call external APIs.' );
		};
		add_filter( 'pre_http_request', $block_http );
		try {
			$result = ( new Parity_Service() )->run_batch( $cases );
		} finally {
			remove_filter( 'pre_http_request', $block_http );
		}
		if ( 0 !== $http_calls ) {
			throw new RuntimeException( 'Developer comparison attempted an external HTTP request.' );
		}
		foreach ( Database::associative_rows( $result['results'] ?? array() ) as $row ) {
			if ( true !== ( $row['ok'] ?? false ) ) {
				throw new RuntimeException( 'A comparison case failed; no baseline was written.' );
			}
		}
		$result['testCases'] = $cases;
		$json                = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new RuntimeException( 'Could not encode comparison results.' );
		}
		// Exclusive creation protects a previously approved baseline from accidental replacement.
		$file = fopen( $args[1], 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $file ) {
			throw new RuntimeException( 'Output must be a new writable file outside the public web root.' );
		}
		try {
			$written = fwrite( $file, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( strlen( $json ) + 1 !== $written ) {
				throw new RuntimeException( 'Incomplete capture file; do not use it as a baseline.' );
			}
		} finally {
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		WP_CLI::success( 'Captured ' . count( $cases ) . ' cases with no AI calls.' );
	}
}
