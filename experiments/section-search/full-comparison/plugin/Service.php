<?php
/** Expanded guest service; preserves pilot ranking/review, with separate private audit. */
namespace EhrmanFullComparison;

use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Evidence_Review;

use EhrmanBlogDiscovery\AI_Usage;

final class Service {
	private array $usage     = array();
	private string $audit_id = '';

	private function call( string $endpoint, array $payload ): array {
		$key = (string) getenv( 'OPENAI_API_KEY' );
		if ( '' === $key ) {
			throw new \RuntimeException( 'The local Ask AI API key is not configured.' );
		}
		$response = wp_remote_post(
			'https://api.openai.com/v1/' . $endpoint,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'The AI service could not be reached; no cached result was substituted.' );
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			throw new \RuntimeException( 'The AI service returned HTTP ' . $status . '.' );
		}
		$metrics       = 'embeddings' === $endpoint
			? array(
				'input_tokens'        => $body['usage']['prompt_tokens'],
				'cached_input_tokens' => 0,
				'output_tokens'       => 0,
				'estimated_cost_usd'  => $body['usage']['prompt_tokens'] * .02 / 1000000,
				'model'               => $body['model'],
			)
			: AI_Usage::response_metrics( $body );
		$this->usage[] = $metrics;
		if ( '' !== $this->audit_id ) {
			self::log(
				array(
					'type'        => 'end-to-end-api-response',
					'requestId'   => $this->audit_id,
					'endpoint'    => $endpoint,
					'payloadHash' => hash( 'sha256', wp_json_encode( $payload ) ),
					'usage'       => $metrics,
					'response'    => $body,
				)
			);
		}
		return $body;
	}

	public function search( string $question, string $mode, bool $evidence = false, bool $controlled = false ): array {
		$this->usage    = array();
		$start          = microtime( true );
		$request_id     = wp_generate_uuid4();
		$this->audit_id = $controlled ? $request_id : '';
		$question       = sanitize_text_field( $question );
		if ( '' === trim( $question ) || mb_strlen( $question ) > 800 ) {
			throw new \InvalidArgumentException( 'Enter a question of 1 to 800 characters.' );
		}
		if ( ! in_array( $mode, array( 'baseline', '300' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown pilot configuration.' );
		}
		$index = new Index();
		try {
			$body         = $this->call(
				'embeddings',
				array(
					'model'      => 'text-embedding-3-small',
					'dimensions' => 512,
					'input'      => array( $question ),
				)
			);
			$embedded_at  = microtime( true );
			$query        = $body['data'][0]['embedding'] ?? array();
			$candidates   = $index->rank( $question, $query, $mode );
			$retrieved_at = microtime( true );
			$model        = 'gpt-5.4-mini-2026-03-17';
			$reviewer     = Baseline::reviewer( $model );
			$review_rows  = $candidates;
			if ( $evidence && ! $controlled ) {
				foreach ( $review_rows as &$post ) {
					$post['search_summary'] .= "\nMatching passage from the post: " . $post['matching_passage'];
				}
				unset( $post );
			}
			$metadata = Baseline::invoke( $reviewer, 'candidates', array( $review_rows ) );
			$payload  = Baseline::invoke( $reviewer, 'request_payload', array( $question, $metadata ) );
			if ( $controlled && $evidence ) {
				$fixture = array(
					'model'      => $model,
					'question'   => $question,
					'candidates' => array_map(
						static fn( $p ) => array(
							'id'      => $p['id'],
							'title'   => $p['title'],
							'summary' => $p['search_summary'],
							'passage' => $p['matching_passage'],
						),
						$candidates
					),
				);
				$payload = Evidence_Review::payload( $fixture, true );
			}
			$payload['store'] = false;
			$settings         = $payload;
			unset( $settings['input'], $settings['instructions'] );
			$review_started = microtime( true );
			$body           = $this->call( 'responses', $payload );
			$reviewed_at    = microtime( true );
			if ( 'completed' !== ( $body['status'] ?? '' ) ) {
				throw new \RuntimeException( 'AI review was incomplete; this is a failed run, not a zero-result search.' );
			}
			$text    = Baseline::invoke( $reviewer, 'output_text', array( $body ) );
			$decoded = json_decode( $text, true );
			$parsed  = is_array( $decoded ) ? Baseline::invoke( $reviewer, 'parse_reviewed_posts', array( $decoded, $metadata ) ) : null;
			if ( null === $parsed ) {
				throw new \RuntimeException( 'Invalid reviewer output; this run is excluded from quality scores.' );
			}
			$by_id    = array_column( $candidates, null, 'id' );
			$selected = array_map( static fn( $id ) => $by_id[ $id ], $parsed['post_ids'] );
			$fallback = empty( $selected );
			if ( $fallback ) {
				// Match the current Ask AI 2 behavior, but make the fallback explicit in the evidence.
				$selected = array_slice( $candidates, 0, 25 );
			}
			$public = static fn( $p ) => array(
				'id'          => (string) $p['id'],
				'wpId'        => (string) $p['source_wp_id'],
				'title'       => $p['title'],
				'url'         => $p['url'],
				'description' => $p['description'],
				'summary'     => $p['search_summary'],
				'passage'     => $p['matching_passage'] ?? '',
			);
			$result = array(
				'controlled'       => $controlled,
				'promptHash'       => hash( 'sha256', $payload['instructions'] ),
				'settingsHash'     => hash( 'sha256', wp_json_encode( $settings ) ),
				'candidateHash'    => hash( 'sha256', wp_json_encode( $metadata ) ),
				'requestId'        => $request_id,
				'question'         => $question,
				'mode'             => $mode,
				'evidence'         => $evidence,
				'sampleHash'       => $index->identity(),
				'sampleCount'      => 3986,
				'model'            => $model,
				'cacheHit'         => false,
				'broaderFallback'  => $fallback,
				'notice'           => $fallback ? 'AI did not select a narrower set; broader matches are shown, as in current Ask AI 2.' : '',
				'posts'            => array_map( $public, $selected ),
				'candidates'       => array_map( $public, $candidates ),
				'tiers'            => $parsed['post_tiers'],
				'usage'            => $this->usage,
				'costUsd'          => array_sum( array_column( $this->usage, 'estimated_cost_usd' ) ),
				'seconds'          => microtime( true ) - $start,
				'embeddingSeconds' => $embedded_at - $start,
				'retrievalSeconds' => $retrieved_at - $embedded_at,
				'reviewSeconds'    => $reviewed_at - $review_started,
				'createdAt'        => gmdate( 'c' ),
			);
			self::log( $result );
			return $result;
		} catch ( \Throwable $error ) {
			self::log(
				array(
					'requestId' => $request_id,
					'question'  => $question,
					'mode'      => $mode,
					'failed'    => true,
					'error'     => $error->getMessage(),
					'usage'     => $this->usage,
					'seconds'   => microtime( true ) - $start,
					'createdAt' => gmdate( 'c' ),
				)
			);
			throw $error;
		}
	}

	public static function log( array $row ): void {
		$file = Index::DIRECTORY . '/requests.jsonl';
		if ( false === file_put_contents( $file, wp_json_encode( $row ) . "\n", FILE_APPEND | LOCK_EX ) ) {
			throw new \RuntimeException( 'Could not persist the experiment audit log.' );
		}
	}
}
