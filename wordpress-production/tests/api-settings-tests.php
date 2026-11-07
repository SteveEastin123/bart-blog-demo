<?php
/** Shared credential checks in separate PHP processes; no provider requests. */

use EhrmanBlogDiscovery\AI_Settings;
use EhrmanBlogDiscovery\Autoloader;
use EhrmanBlogDiscovery\Embedding_Service;
use EhrmanBlogDiscovery\Post_Ingestion_Settings;

// Constants cannot be undefined, so each case gets a fresh interpreter.
if ( 'credential-case' === ( $argv[1] ?? '' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	require $argv[3] . '/src/Core/Autoloader.php';
	Autoloader::register();
	putenv( 'OPENAI_API_KEY' );
	putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY=retired-search-key' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY=retired-ingestion-key' );
	$expected = '';
	switch ( $argv[2] ) {
		case 'missing':
			break;
		case 'environment':
			putenv( 'OPENAI_API_KEY= shared-test-key ' );
			$expected = 'shared-test-key';
			break;
		case 'blank-environment':
			putenv( 'OPENAI_API_KEY=   ' );
			break;
		case 'legacy-constants':
		case 'legacy-constants-only':
			define( 'EHRMAN_DISCOVERY_OPENAI_API_KEY', 'retired-search-constant' );
			define( 'EHRMAN_INGESTION_OPENAI_API_KEY', 'retired-ingestion-constant' );
			if ( 'legacy-constants' === $argv[2] ) {
				putenv( 'OPENAI_API_KEY=shared-test-key' );
				$expected = 'shared-test-key';
			}
			break;
		case 'constant':
		case 'blank-constant':
		case 'invalid-constant':
			putenv( 'OPENAI_API_KEY=shared-test-key' );
			$value = 'constant' === $argv[2] ? ' constant-test-key ' : ( 'blank-constant' === $argv[2] ? ' ' : array() );
			define( 'OPENAI_API_KEY', $value );
			$expected = 'constant' === $argv[2] ? 'constant-test-key' : '';
			break;
		default:
			throw new RuntimeException( 'Unknown credential test case.' );
	}
	$check = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};
	$check( $expected === AI_Settings::api_key(), 'Search credential precedence changed.' );
	$check( ( '' !== $expected ) === AI_Settings::is_configured(), 'Search configured status is incorrect.' );
	$check( ( '' !== $expected ) === Embedding_Service::is_configured(), 'Embedding configured status is incorrect.' );
	$key_method = new ReflectionMethod( Embedding_Service::class, 'api_key_value' );
	$check( $expected === $key_method->invoke( new Embedding_Service() ), 'Search and embedding credentials differ.' );
	$check( 'override-test-key' === $key_method->invoke( new Embedding_Service( ' override-test-key ', false ) ), 'Embedding workflow override was lost.' );
	$check( '' === $key_method->invoke( new Embedding_Service( '', false ) ), 'An empty workflow override borrowed the search key.' );
	$check( $expected === Post_Ingestion_Settings::api_key(), 'Ingestion and search credentials differ.' );
	$check( ( '' !== $expected ) === Post_Ingestion_Settings::is_configured(), 'Ingestion configured status is incorrect.' );
	putenv( 'EHRMAN_DISCOVERY_AI_MODEL=search-test-model' );
	putenv( 'EHRMAN_INGESTION_OPENAI_MODEL=ingestion-test-model' );
	$check( 'search-test-model' === AI_Settings::model_id(), 'Shared credentials changed the search model setting.' );
	$check( 'ingestion-test-model' === Post_Ingestion_Settings::model_id(), 'Shared credentials changed the ingestion model setting.' );
	exit( 0 );
}

if ( 'source-case' === ( $argv[1] ?? '' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	require $argv[3] . '/src/Core/Autoloader.php';
	Autoloader::register();
	putenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
	$expected = 'mysql';
	switch ( $argv[2] ) {
		case 'missing':
			break;
		case 'blank-environment':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=   ' );
			break;
		case 'mysql-environment':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE= MySQL ' );
			break;
		case 'json-environment':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE= JSON ' );
			$expected = 'json';
			break;
		case 'invalid-environment':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=misspelled' );
			$expected = 'json';
			break;
		case 'mysql-constant':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
			define( 'EHRMAN_DISCOVERY_POST_SOURCE', ' mysql ' );
			break;
		case 'blank-constant':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=json' );
			define( 'EHRMAN_DISCOVERY_POST_SOURCE', ' ' );
			break;
		case 'json-constant':
		case 'invalid-constant':
			putenv( 'EHRMAN_DISCOVERY_POST_SOURCE=mysql' );
			define( 'EHRMAN_DISCOVERY_POST_SOURCE', 'json-constant' === $argv[2] ? ' JSON ' : array() );
			$expected = 'json';
			break;
		default:
			throw new RuntimeException( 'Unknown source-mode test case.' );
	}
	if ( $expected !== Post_Ingestion_Settings::post_source()
		|| ( 'mysql' === $expected ) !== Post_Ingestion_Settings::database_is_authoritative() ) {
		throw new RuntimeException( 'Source-mode default or override changed.' );
	}
	exit( 0 );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function (): void {
	$cases = array(
		'credential-case' => array( 'missing', 'environment', 'blank-environment', 'legacy-constants', 'legacy-constants-only', 'constant', 'blank-constant', 'invalid-constant' ),
		'source-case' => array( 'missing', 'blank-environment', 'mysql-environment', 'json-environment', 'invalid-environment', 'mysql-constant', 'blank-constant', 'json-constant', 'invalid-constant' ),
	);
	foreach ( $cases as $mode => $mode_cases ) {
		foreach ( $mode_cases as $case ) {
			$process = proc_open(
				array( PHP_BINARY, __FILE__, $mode, $case, EHRMAN_DISCOVERY_PLUGIN_DIR ),
				array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start settings test.' );
			}
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			if ( 0 !== proc_close( $process ) ) {
				throw new RuntimeException( 'Settings test failed: ' . $mode . '/' . $case . '. ' . $output );
			}
		}
	}
	echo "Shared credentials, separate models and default database-managed approval: OK (17 cases)\n";
} )();
