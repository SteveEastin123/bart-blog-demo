<?php
/** Offline integration checks: block all network traffic and audit writes. */
// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- Test-only interception keeps audit writes in memory.
namespace EhrmanFullComparison {

	function file_put_contents( string $filename, string $data, int $flags = 0 ): int|false {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || Index::DIRECTORY . '/requests.jsonl' !== $filename ) {
			throw new \RuntimeException( 'Unexpected test write.' );
		}
		if ( ! empty( $GLOBALS['comparison_test_log_failure'] ) ) {
			return false;
		}
		$GLOBALS['comparison_test_logs'][] = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
		return strlen( $data );
	}
}

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden,Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- Isolate the test harness from its intercepted writer.
namespace EhrmanFullComparison\Tests {
	use EhrmanFullComparison\Index;
	use EhrmanFullComparison\Service;
	use EhrmanSectionPilot\Baseline;
	use EhrmanSectionPilot\Evidence_Review;
	use EhrmanBlogDiscovery\Semantic_Search_Service;

	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		exit; }
	require_once __DIR__ . '/full-comparison.php';
	$checks   = 0;
	$assert   = static function ( $condition, $message ) use ( &$checks ) {
		++$checks;
		if ( ! $condition ) {
			throw new \RuntimeException( $message ); }
	};
	$data     = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true, 512, JSON_THROW_ON_ERROR );
	$vector   = Index::vector( $data['posts'][123]['vector']['embedding'] );
	$question = 'What did Paul think about resurrection?';
	$guard    = static function ( $sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE|RENAME)\b/i', $sql ) ) {
			throw new \RuntimeException( 'Benchmark attempted a database write.' );
		}
		return $sql;
	};
	$calls    = array();
	$mode     = 'selected';
	$mock     = static function ( $pre, $args, $url ) use ( &$calls, &$mode, $vector, $assert ) {
		/** @var string $mode Mutated between deferred mock invocations. */
		$payload = json_decode( $args['body'], true, 512, JSON_THROW_ON_ERROR );
		$calls[] = $payload;
		if ( str_ends_with( $url, '/embeddings' ) ) {
			$assert( 512 === $payload['dimensions'] && 'text-embedding-3-small' === $payload['model'], 'Wrong embedding configuration.' );
			$body = array(
				'model' => $payload['model'],
				'usage' => array( 'prompt_tokens' => 12 ),
				'data'  => array( array( 'embedding' => $vector ) ),
			);
		} elseif ( str_ends_with( $url, '/responses' ) ) {
			$assert( 'gpt-5.4-mini-2026-03-17' === $payload['model'] && false === $payload['store'], 'Wrong review configuration.' );
			$posts = json_decode( explode( "Candidate posts:\n", $payload['input'], 2 )[1], true, 512, JSON_THROW_ON_ERROR );
			$assert( 100 === count( $posts ), 'Candidate limit differs.' );
			$selected = 'empty' === $mode ? array() : array(
				array(
					'id'             => $posts[0]['id'],
					'relevance_tier' => 'direct',
				),
			);
			$body     = array(
				'model'  => $payload['model'],
				'status' => 'incomplete' === $mode ? 'incomplete' : 'completed',
				'usage'  => array(
					'input_tokens'         => 200,
					'input_tokens_details' => array( 'cached_tokens' => 100 ),
					'output_tokens'        => 20,
				),
				'output' => array(
					array(
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => wp_json_encode( array( 'selected_posts' => $selected ) ),
							),
						),
					),
				),
			);
		} else {
			throw new \RuntimeException( 'Unexpected HTTP request.' ); }
		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	};
	add_filter( 'query', $guard, PHP_INT_MAX );
	add_filter( 'pre_http_request', $mock, PHP_INT_MAX, 3 );
	$key = getenv( 'OPENAI_API_KEY' );
	try {
		putenv( 'OPENAI_API_KEY=mock-never-sent' );
		$index      = new Index();
		$baseline   = $index->rank( $question, $vector, 'baseline' );
		$production = new Semantic_Search_Service();
		$rows       = Baseline::invoke( $production, 'candidate_rows', array() );
		$ids        = array_column( $data['posts'], 'wpId' );
		$rows       = array_values( array_filter( $rows, static fn( $p ) => in_array( (string) $p['source_wp_id'], $ids, true ) ) );
		$assert( 3986 === count( $rows ), 'Production baseline scope differs.' );
		$query_norm = Baseline::invoke( $production, 'vector_norm', array( $vector ) );
		foreach ( $rows as &$row ) {
			$post_vector           = Baseline::invoke( $production, 'unpack_vector', array( $row['embedding'] ) );
			$row['semantic_score'] = Baseline::invoke( $production, 'dot_product', array( $vector, $post_vector ) ) / ( $query_norm * (float) $row['embedding_norm'] );
		}
		unset( $row );
		$live = array_slice( Baseline::rank( $question, $rows ), 0, 100 );
		$assert( array_column( $live, 'id' ) === array_column( $baseline, 'id' ), 'Baseline differs from scoped production retrieval.' );
		$section = $index->rank( $question, $vector, '300' );
		$guest   = ( new \EhrmanSectionPublic\Index() )->rank( $question, $vector, '300' );
		$assert( $section === $guest, 'Ask AI 3 differs from guest section retrieval.' );
		foreach ( array( false, true ) as $evidence ) {
			$offset = count( $calls );
			$result = ( new Service() )->search( $question, $evidence ? '300' : 'baseline', $evidence, true );
			$assert( count( $calls ) === $offset + 2 && false === $result['cacheHit'], 'Paid phases missing or cache substituted.' );
			$assert( 1 === count( $result['posts'] ) && 100 === count( $result['candidates'] ), 'Wrong selection.' );
			$assert( abs( $result['costUsd'] - ( 12 * .02 + 100 * .75 + 100 * .075 + 20 * 4.5 ) / 1000000 ) < 1e-10, 'Cost accounting differs.' );
			$reviewer = Baseline::reviewer( 'gpt-5.4-mini-2026-03-17' );
			$metadata = Baseline::invoke( $reviewer, 'candidates', array( $evidence ? $section : $baseline ) );
			$expected = Baseline::invoke( $reviewer, 'request_payload', array( $question, $metadata ) );
			if ( $evidence ) {
				$expected = Evidence_Review::payload(
					array(
						'model'      => 'gpt-5.4-mini-2026-03-17',
						'question'   => $question,
						'candidates' => array_map(
							static fn( $p ) => array(
								'id'      => $p['id'],
								'title'   => $p['title'],
								'summary' => $p['search_summary'],
								'passage' => $p['matching_passage'],
							),
							$section
						),
					),
					true
				);
			}
			$expected['store'] = false;
			$assert( $calls[ $offset + 1 ] === $expected, 'Existing review prompt or settings changed.' );
			foreach ( array( 'embeddingSeconds', 'retrievalSeconds', 'reviewSeconds' ) as $field ) {
				$assert( $result[ $field ] >= 0 && $result[ $field ] <= $result['seconds'], 'Invalid phase time.' );
			}
		}
		$mode     = 'empty';
		$fallback = ( new Service() )->search( $question, 'baseline', false, true );
		$assert( true === $fallback['broaderFallback'] && 25 === count( $fallback['posts'] ), 'Fallback differs.' );
		$mode   = 'incomplete';
		$failed = false;
		try {
			( new Service() )->search( $question, 'baseline', false, true );
		} catch ( \RuntimeException $error ) {
			$failed = true; }
		$assert( $failed, 'Incomplete review accepted.' );
		wp_set_current_user( 0 );
		$assert( ! \EhrmanFullComparison\permission(), 'Anonymous benchmark access.' );
		$assert( 1 === \EhrmanFullComparison\fixture( 1, 'ask-ai-2' )['id'], 'Frozen question validation failed.' );
		\WP_CLI::line(
			wp_json_encode(
				array(
					'checks'         => $checks,
					'mockHttpCalls'  => count( $calls ),
					'paidCalls'      => 0,
					'databaseWrites' => 0,
					'baselineParity' => true,
					'sectionParity'  => true,
				)
			)
		);
	} finally {
		remove_filter( 'query', $guard, PHP_INT_MAX );
		remove_filter( 'pre_http_request', $mock, PHP_INT_MAX );
		putenv( false === $key ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $key );
	}
}
