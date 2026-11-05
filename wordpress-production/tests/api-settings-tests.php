<?php
/** Credential precedence checks in separate PHP processes; no provider requests. */

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
	putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY= ingestion-test-key ' );
	$expected = '';
	switch ( $argv[2] ) {
		case 'missing':
			break;
		case 'legacy':
			putenv( 'OPENAI_API_KEY= legacy-test-key ' );
			$expected = 'legacy-test-key';
			break;
		case 'dedicated':
			putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY= search-test-key ' );
			$expected = 'search-test-key';
			break;
		case 'precedence':
			putenv( 'OPENAI_API_KEY=legacy-test-key' );
			putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY= search-test-key ' );
			$expected = 'search-test-key';
			break;
		case 'blank-environment':
			putenv( 'OPENAI_API_KEY= legacy-test-key ' );
			putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY=   ' );
			$expected = 'legacy-test-key';
			break;
		case 'constant':
		case 'blank-constant':
		case 'invalid-constant':
			putenv( 'OPENAI_API_KEY=legacy-test-key' );
			putenv( 'EHRMAN_DISCOVERY_OPENAI_API_KEY=search-test-key' );
			$value = 'constant' === $argv[2] ? ' constant-test-key ' : ( 'blank-constant' === $argv[2] ? ' ' : array() );
			define( 'EHRMAN_DISCOVERY_OPENAI_API_KEY', $value );
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
	$check( 'ingestion-test-key' === Post_Ingestion_Settings::api_key(), 'Ingestion key isolation changed.' );
	putenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
	$check( ! Post_Ingestion_Settings::is_configured(), 'Ingestion borrowed a reader-search key.' );
	exit( 0 );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function (): void {
	foreach ( array( 'missing', 'legacy', 'dedicated', 'precedence', 'blank-environment', 'constant', 'blank-constant', 'invalid-constant' ) as $case ) {
		$process = proc_open(
			array( PHP_BINARY, __FILE__, 'credential-case', $case, EHRMAN_DISCOVERY_PLUGIN_DIR ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start credential test.' );
		}
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'Credential test failed: ' . $case . '. ' . $output );
		}
	}
	echo "Search/embedding credential precedence and ingestion isolation: OK (8 cases)\n";
} )();
