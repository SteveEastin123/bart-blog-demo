<?php
/**
 * Offline WP-CLI integration checks for the local guest wrapper.
 * Run with both the expanded index and frozen pilot installed, with no active public searches.
 * All HTTP and service log writes are intercepted in this process.
 *
 * @package EhrmanSectionPublic
 */

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- Test-only namespace interception prevents writes to frozen logs.
namespace EhrmanSectionPilot {

	/**
	 * Deny any accidental execution of a frozen benchmark's log writer.
	 *
	 * @param string $filename Rejected destination.
	 * @param string $data Rejected contents.
	 * @param int    $flags Rejected write flags.
	 * @return int|false Native writer contract; this guard always throws.
	 * @throws \RuntimeException When a frozen log write is attempted.
	 */
	function file_put_contents( string $filename, string $data, int $flags = 0 ): int|false {
		throw new \RuntimeException( 'Guest tests must never write the frozen pilot log.' );
	}
}

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Keep the test-only writer in the real service namespace.
namespace EhrmanSectionPublic {

	/**
	 * Capture the expanded Service's unqualified log writer without touching disk.
	 *
	 * @param string $filename Audit destination.
	 * @param string $data JSON line.
	 * @param int    $flags Write flags.
	 * @return int|false Native writer contract, returning the number of bytes captured.
	 */
	function file_put_contents( string $filename, string $data, int $flags = 0 ): int|false {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || Index::DIRECTORY . '/requests.jsonl' !== $filename || ( FILE_APPEND | LOCK_EX ) !== $flags ) {
			throw new \RuntimeException( 'Unexpected file write during guest tests.' );
		}
		if ( ! empty( $GLOBALS['ehrman_section_public_test_log_failure'] ) ) {
			return false;
		}
		$GLOBALS['ehrman_section_public_test_logs'][] = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
		return strlen( $data );
	}
}

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Isolate the harness from both intercepted service namespaces.
namespace EhrmanSectionPublic\Tests {

	use EhrmanBlogDiscovery\Database;
	use EhrmanSectionPilot\Evidence_Review;
	use EhrmanSectionPilot\Index as FrozenIndex;
	use EhrmanSectionPublic\Index;
	use function EhrmanSectionPublic\acquire_lock;
	use function EhrmanSectionPublic\enabled;
	use function EhrmanSectionPublic\ip_rate_key;
	use function EhrmanSectionPublic\release_lock;
	use function EhrmanSectionPublic\same_origin;
	use const EhrmanSectionPublic\GLOBAL_RATE;
	use const EhrmanSectionPublic\LOCK_OPTION;
	use const EhrmanSectionPublic\EXPECTED_POSTS;

	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		exit;
	}

	require_once __DIR__ . '/ehrman-section-public.php';

	/**
	 * Fingerprint every production-plugin table, not just row counts.
	 *
	 * @return array Table digests.
	 */
	function production_fingerprint(): array {
		$result = array();
		foreach ( Database::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only fingerprint; identifiers come exclusively from Database::tables().
			$rows = Database::client()->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			if ( '' !== Database::client()->last_error ) {
				throw new \RuntimeException( 'Could not fingerprint production data.' );
			}
			$rows = array_map( 'wp_json_encode', $rows );
			sort( $rows, SORT_STRING );
			$result[ $table ] = hash( 'sha256', wp_json_encode( $rows ) );
		}
		return $result;
	}

	/** Exercise the real service through the guest REST route with mocked transports. */
	function run(): void {
		global $wpdb;
		$checks = 0;
		$assert = static function ( $condition, string $message ) use ( &$checks ) {
			++$checks;
			if ( ! $condition ) {
				throw new \RuntimeException( $message );
			}
		};
		$assert( \EhrmanSectionPilot\local_enabled(), 'Enable the installed local 500-post pilot before testing.' );
		$assert( false === get_option( LOCK_OPTION ) && false === get_transient( GLOBAL_RATE ), 'Wait for public searches and their rate window to finish before testing.' );
		$GLOBALS['ehrman_section_public_test_log_failure'] = true;

		$log_failed = false;
		try {
			\EhrmanSectionPublic\Service::log( array( 'test' => true ) );
		} catch ( \RuntimeException $error ) {
			$log_failed = true;
		} finally {
			unset( $GLOBALS['ehrman_section_public_test_log_failure'] );
		}
		$assert( $log_failed, 'Audit-write failure must not be silently ignored.' );
		$user       = get_current_user_id();
		$key        = getenv( 'OPENAI_API_KEY' );
		$model      = getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
		$address    = $_SERVER['REMOTE_ADDR'] ?? null;
		$forwarded  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
		$leases     = array();
		$rate_keys  = array( GLOBAL_RATE );
		$calls      = array();
		$mode       = 'selected';
		$reentrant  = false;
		$enabled    = static fn() => '1';
		$disabled   = static fn() => '0';
		$route      = '/ehrman-section-public/v1/search';
		$production = production_fingerprint();
		$data       = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true, 512, JSON_THROW_ON_ERROR );
		$pilot      = json_decode( file_get_contents( FrozenIndex::DIRECTORY . '/index.json' ), true, 512, JSON_THROW_ON_ERROR );
		$ids        = array_map( 'strval', array_column( $data['posts'], 'wpId' ) );
		$pilot_ids  = array_map( 'strval', array_column( $pilot['posts'], 'wpId' ) );
		$assert( 3986 === count( $ids ) && 3986 === count( array_unique( $ids ) ), 'Expanded index must contain exactly 3,986 unique eligible posts.' );
		$public_directory = ( new \ReflectionClass( Index::class ) )->getConstant( 'DIRECTORY' );
		$pilot_directory  = ( new \ReflectionClass( FrozenIndex::class ) )->getConstant( 'DIRECTORY' );
		$assert( '/opt/ehrman-section-public' === $public_directory && $pilot_directory !== $public_directory, 'Expanded runtime must be isolated from the frozen pilot.' );
		$assert( 500 === count( array_unique( $pilot_ids ) ) && array() === array_diff( $pilot_ids, $ids ), 'Expanded corpus lost or changed the frozen 500-post membership.' );
		$expanded = array_values( array_filter( $data['posts'], static fn( $post ) => ! in_array( (string) $post['wpId'], $pilot_ids, true ) ) );
		$assert( 3486 === count( $expanded ), 'Expected all 3,486 remaining eligible posts.' );
		$vector = Index::vector( $expanded[0]['vector']['embedding'] );
		$files  = array_merge(
			glob( WP_PLUGIN_DIR . '/ehrman-section-pilot/*' ),
			array( FrozenIndex::DIRECTORY . '/index.json', Index::DIRECTORY . '/index.json', Index::DIRECTORY . '/sections-300.jsonl.gz' )
		);
		foreach ( array( '300', '400', '500' ) as $size ) {
			$files[] = FrozenIndex::DIRECTORY . '/sections-' . $size . '.jsonl.gz';
		}
		foreach ( array( FrozenIndex::DIRECTORY, Index::DIRECTORY ) as $directory ) {
			if ( is_file( $directory . '/requests.jsonl' ) ) {
				$files[] = $directory . '/requests.jsonl';
			}
		}
		$frozen = array();
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$frozen[ $file ] = hash_file( 'sha256', $file );
			}
		}
		$request                                    = static function ( $question = '[guest mock] Paul and Jesus', array $extra = array() ) use ( $route ) {
			$request = new \WP_REST_Request( 'POST', $route );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'question', $question );
			foreach ( $extra as $name => $value ) {
				$request->set_param( $name, $value );
			}
			return $request;
		};
		$guard                                      = static function ( $sql ) use ( $wpdb ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $sql ) ) {
				$own_table = preg_match( '/\b(?:INTO|UPDATE|FROM)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $match ) && $wpdb->options === $match[1];
				$own_key   = preg_match( "/'(?:(?:_transient_(?:timeout_)?)?ehrman_section_public_(?:global|ip_[a-f0-9]+)|ehrman_section_public_lock)'/", $sql );
				if ( ! $own_table || ! $own_key ) {
					throw new \RuntimeException( 'A test attempted a write outside its own limits/lock options.' );
				}
			}
			return $sql;
		};
		$mock                                       = static function ( $pre, $arguments, $url ) use ( &$calls, &$mode, &$reentrant, $assert, $request, $vector ) {
			/** @var string $mode Mutated between deferred transport invocations. */
			/** @var bool $reentrant Enabled by the concurrent-request test before this callback runs. */
			if ( ! in_array( $url, array( 'https://api.openai.com/v1/embeddings', 'https://api.openai.com/v1/responses' ), true ) ) {
				throw new \RuntimeException( 'Unexpected HTTP request; all network access is blocked.' );
			}
			$payload = json_decode( $arguments['body'], true, 512, JSON_THROW_ON_ERROR );
			$calls[] = array(
				'url'     => $url,
				'payload' => $payload,
			);
			$lease   = get_option( LOCK_OPTION );
			$assert( is_array( $lease ) && $lease['expires'] > time(), 'Global lock was not held during a paid phase.' );
			if ( $reentrant ) {
				$reentrant = false;
				$assert( 503 === rest_do_request( $request() )->get_status(), 'Concurrent request was not rejected.' );
				$assert( get_option( LOCK_OPTION ) === $lease, 'Busy request deleted the active lock.' );
			}
			if ( 'network-error' === $mode ) {
				return new \WP_Error( 'mock_private', 'SECRET PRIVATE TRANSPORT DETAILS' );
			}
			if ( str_ends_with( $url, '/embeddings' ) ) {
				$assert( 512 === $payload['dimensions'] && 'text-embedding-3-small' === $payload['model'], 'Wrong query embedding settings.' );
				$body = array(
					'model' => 'text-embedding-3-small',
					'usage' => array( 'prompt_tokens' => 10 ),
					'data'  => array(
						array(
							'index'     => 0,
							'embedding' => $vector,
						),
					),
				);
			} else {
				$posts = json_decode( explode( "Candidate posts:\n", $payload['input'], 2 )[1], true, 512, JSON_THROW_ON_ERROR );
				$assert( 100 === count( $posts ), 'Expected 100 section-ranked reviewer candidates.' );
				$assert( 100 === count( array_unique( array_column( $posts, 'id' ) ) ), 'Duplicate reviewer candidates.' );
				foreach ( $posts as $post ) {
					$assert( array_keys( $post ) === array( 'id', 'title', 'summary', 'matching_passage' ) && '' !== $post['matching_passage'], 'Missing frozen passage evidence.' );
				}
				$selected = 'empty' === $mode ? array() : array(
					array(
						'id'             => $posts[0]['id'],
						'relevance_tier' => 'direct',
					),
				);
				$body     = array(
					'model'  => 'gpt-5.4-mini',
					'status' => 'incomplete' === $mode ? 'incomplete' : 'completed',
					'usage'  => array(
						'input_tokens'  => 100,
						'output_tokens' => 20,
					),
					'output' => array(
						array(
							'content' => array(
								array(
									'type' => 'output_text',
									'text' => 'bad-json' === $mode ? '{broken' : wp_json_encode( array( 'selected_posts' => $selected ) ),
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
		$GLOBALS['ehrman_section_public_test_logs'] = array();
		add_filter( 'pre_http_request', $mock, PHP_INT_MAX, 3 );
		add_filter( 'query', $guard, PHP_INT_MAX );
		add_filter( 'pre_option_ehrman_section_public_enabled', $enabled );
		try {
			putenv( 'OPENAI_API_KEY=mock-never-sent' );
			putenv( 'EHRMAN_DISCOVERY_AI_MODEL=gpt-5.4-mini' );
			wp_set_current_user( 0 );
			$_SERVER['REMOTE_ADDR'] = '192.0.2.45';
			$ip_key                 = ip_rate_key();
			$assert( false === get_transient( $ip_key ), 'Test IP already has an active budget.' );
			$rate_keys[]                     = $ip_key;
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8';
			$assert( ip_rate_key() === $ip_key, 'Forwarded headers changed the rate-limit identity.' );
			do_action( 'rest_api_init' );
			$routes = rest_get_server()->get_routes();
			$assert( isset( $routes[ $route ][0]['methods']['POST'] ) && ! isset( $routes[ $route ][0]['methods']['GET'] ), 'Public route must be POST-only.' );
			$assert( enabled() && 0 === get_current_user_id(), 'Guest access requires an account.' );
			$assert( shortcode_exists( 'ehrman_section_public' ), 'Public shortcode was not registered.' );
			$assert( false !== has_action( 'ehrman_demo_extra_search_links', 'EhrmanSectionPublic\navigation_link' ), 'Navigation hook is missing.' );
			$assert( false !== has_action( 'wp_enqueue_scripts', 'EhrmanSectionPublic\enqueue_assets' ), 'Early asset hook is missing.' );
			$assert( false !== has_action( 'template_redirect', 'EhrmanSectionPublic\prevent_cache' ), 'Early nonce-page cache headers are missing.' );

			foreach ( array( null, array(), false, 42, '', '   ', '<b></b>', str_repeat( 'x', 801 ), str_repeat( "\u{00E9}", 801 ) ) as $invalid ) {
				$assert( 400 === rest_do_request( $request( $invalid ) )->get_status(), 'Invalid question reached the service.' );
			}
			foreach ( array( 'mode', 'arm', 'evidence', 'controlled' ) as $field ) {
				$assert( 400 === rest_do_request( $request( 'question', array( $field => null ) ) )->get_status(), 'A configuration override was accepted.' );
			}
			$denied = $request();
			$denied->remove_header( 'X-WP-Nonce' );
			$assert( 403 === rest_do_request( $denied )->get_status(), 'Missing nonce accepted.' );
			$denied->set_param( '_wpnonce', array( 'not-a-nonce' ) );
			$assert( 403 === rest_do_request( $denied )->get_status(), 'Array nonce accepted.' );
			$denied->set_header( 'X-WP-Nonce', 'bad-nonce' );
			$assert( 403 === rest_do_request( $denied )->get_status(), 'Invalid nonce accepted.' );
			foreach ( array( 'null', 'https://example.invalid', home_url() . '.attacker.invalid', home_url() . '/path' ) as $origin ) {
				$denied = $request();
				$denied->set_header( 'Origin', $origin );
				$assert( 403 === rest_do_request( $denied )->get_status(), 'Cross-origin request accepted.' );
			}
			$home   = wp_parse_url( home_url() );
			$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$assert( same_origin( null ) && same_origin( $origin ) && ! same_origin( 'ftp://' . $home['host'] ), 'Origin normalization failed.' );
			foreach ( array( 'pre_option_ehrman_section_public_enabled', 'pre_option_ehrman_section_pilot_enabled' ) as $filter ) {
				add_filter( $filter, $disabled, 20 );
				try {
					$assert( ! enabled() && 403 === rest_do_request( $request() )->get_status(), 'Disabled opt-in accepted.' );
				} finally {
					remove_filter( $filter, $disabled, 20 );
				}
			}
			foreach ( $routes as $path => $handlers ) {
				if ( str_starts_with( $path, '/ehrman-section-pilot/v1/' ) && isset( $handlers[0]['methods']['POST'] ) ) {
					$assert( in_array( rest_do_request( new \WP_REST_Request( 'POST', $path ) )->get_status(), array( 401, 403 ), true ), 'A frozen benchmark endpoint permits guests.' );
				}
			}
			$assert( array() === $calls && false === get_option( LOCK_OPTION ) && false === get_transient( GLOBAL_RATE ), 'Denied requests consumed API or limit state.' );

			$reentrant = true;
			$valid     = $request( $expanded[0]['title'] );
			$valid->set_header( 'Origin', $origin );
			$response = rest_do_request( $valid );
			$assert( 200 === $response->get_status() && 2 === count( $calls ), 'Guest search did not execute exactly two mocked phases.' );
			$result = $response->get_data();
			$assert( array_keys( $result ) === array( 'requestId', 'question', 'posts', 'tiers', 'costUsd', 'seconds', 'notice', 'sampleCount' ), 'Public response field leak.' );
			$assert( EXPECTED_POSTS === $result['sampleCount'] && 1 === count( $result['posts'] ) && $result['costUsd'] > 0 && $result['seconds'] >= 0, 'Wrong expanded guest result scope or accounting.' );
			$assert( array_keys( $result['posts'][0] ) === array( 'id', 'wpId', 'title', 'url', 'description' ), 'Private post data leaked.' );
			$assert( 'direct' === $result['tiers'][ $result['posts'][0]['id'] ], 'Relevance tier was lost.' );
			$assert( in_array( $result['posts'][0]['wpId'], $ids, true ), 'Post escaped the 3,986-post eligible corpus.' );
			$ranked  = ( new Index() )->rank( $result['question'], $vector, '300' );
			$fixture = array(
				'model'      => 'gpt-5.4-mini',
				'question'   => $result['question'],
				'candidates' => array_map(
					static fn( $post ) => array(
						'id'      => $post['id'],
						'title'   => $post['title'],
						'summary' => $post['search_summary'],
						'passage' => $post['matching_passage'],
					),
					$ranked
				),
			);
			$assert( Evidence_Review::payload( $fixture, true ) === $calls[1]['payload'], 'Frozen prompt, evidence, or reviewer settings changed.' );
			$assert( false === get_option( LOCK_OPTION ), 'Success did not release its lock.' );
			$assert( 3 === count( $GLOBALS['ehrman_section_public_test_logs'] ), 'Actual service audit phases were not captured.' );

			$mode     = 'empty';
			$fallback = rest_do_request( $request() )->get_data();
			$assert( 25 === count( $fallback['posts'] ) && '' !== $fallback['notice'] && array() === $fallback['tiers'], 'Production fallback behavior changed.' );
			foreach ( array( 'network-error', 'incomplete', 'bad-json' ) as $failure ) {
				$mode  = $failure;
				$error = rest_do_request( $request() );
				$assert( 502 === $error->get_status(), 'Failed service response became a successful fallback.' );
				$assert( 'Search could not be completed. Please try again later.' === $error->get_data()['message'], 'Private error details leaked.' );
				$assert( false === get_option( LOCK_OPTION ), 'Failure did not release its lock.' );
			}
			$mode = 'selected';
			$assert( 5 === count( get_transient( GLOBAL_RATE ) ), 'Failed paid attempts were not counted.' );

			set_transient( $ip_key, array_fill( 0, 19, time() ), 300 );
			$assert( 200 === rest_do_request( $request() )->get_status(), 'Twentieth IP request should be allowed.' );
			$offset = count( $calls );
			$assert( 429 === rest_do_request( $request() )->get_status() && count( $calls ) === $offset, 'Twenty-first IP request reached the API.' );
			set_transient( $ip_key, array( time() - 301 ), 300 );
			set_transient( GLOBAL_RATE, array_fill( 0, 59, time() ), 300 );
			$assert( 200 === rest_do_request( $request() )->get_status(), 'Sixtieth global request or expired IP window was rejected.' );
			$_SERVER['REMOTE_ADDR'] = '192.0.2.46';
			$other_key              = ip_rate_key();
			$assert( false === get_transient( $other_key ), 'Second test IP already has a budget.' );
			$rate_keys[] = $other_key;
			$offset      = count( $calls );
			$assert( 429 === rest_do_request( $request() )->get_status() && count( $calls ) === $offset, 'Global limit can be bypassed by changing IP.' );
			$assert( false === get_option( LOCK_OPTION ), 'Rate-limited request retained its lock.' );

			$expired = array(
				'token'   => wp_generate_uuid4(),
				'expires' => time() - 1,
			);
			$assert( add_option( LOCK_OPTION, $expired, '', false ), 'Could not seed expired test lease.' );
			$leases[] = $expired;
			$started  = time();
			$lease    = acquire_lock();
			$assert( ! is_wp_error( $lease ), 'Expired lease did not recover.' );
			$leases[] = $lease;
			$assert( $lease['expires'] >= $started + 300 && $lease['expires'] <= time() + 300, 'Wrong lease expiration.' );
			$assert( is_wp_error( acquire_lock() ) && get_option( LOCK_OPTION ) === $lease, 'Active lock was deleted or replaced.' );
			$assert( ! release_lock( $expired ) && get_option( LOCK_OPTION ) === $lease, 'Old owner deleted a replacement lock.' );
			$assert( release_lock( $lease ), 'Owned lock was not released.' );
			foreach ( $frozen as $file => $hash ) {
				$assert( hash_file( 'sha256', $file ) === $hash, 'A frozen experiment file changed.' );
			}
			$assert( production_fingerprint() === $production, 'Production data fingerprint changed.' );
		} finally {
			foreach ( $leases as $lease ) {
				release_lock( $lease );
			}
			foreach ( $rate_keys as $rate_key ) {
				delete_transient( $rate_key );
			}
			remove_filter( 'pre_option_ehrman_section_public_enabled', $enabled );
			remove_filter( 'query', $guard, PHP_INT_MAX );
			remove_filter( 'pre_http_request', $mock, PHP_INT_MAX );
			wp_set_current_user( $user );
			putenv( false === $key ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $key );
			putenv( false === $model ? 'EHRMAN_DISCOVERY_AI_MODEL' : 'EHRMAN_DISCOVERY_AI_MODEL=' . $model );
			if ( null === $address ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $address;
			}
			if ( null === $forwarded ) {
				unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
			} else {
				$_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
			}
			unset( $GLOBALS['ehrman_section_public_test_logs'] );
		}
		\WP_CLI::line(
			wp_json_encode(
				array(
					'passed'                  => $checks,
					'paidCalls'               => 0,
					'mockedHttpCalls'         => count( $calls ),
					'productionDataUnchanged' => true,
					'frozenFilesUnchanged'    => true,
				)
			)
		);
	}

	run();
}
