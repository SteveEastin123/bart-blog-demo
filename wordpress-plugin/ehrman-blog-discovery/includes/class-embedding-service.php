<?php
/**
 * OpenAI embedding generation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts search questions and post summaries into normalized vectors. */
final class Embedding_Service {
	private const API_URL            = 'https://api.openai.com/v1/embeddings';
	private const DEFAULT_MODEL      = 'text-embedding-3-small';
	private const DEFAULT_DIMENSIONS = 512;
	private const MAX_TEXT_LENGTH    = 8000;

	/**
	 * Optional API key reserved for a non-search workflow.
	 *
	 * @var string|null
	 */
	private ?string $api_key_override;

	/**
	 * Whether this request should be included in reader-search analytics.
	 *
	 * @var bool
	 */
	private bool $record_usage;

	/**
	 * Metrics from the most recent API response.
	 *
	 * @var array<string,int|float|string>
	 */
	private array $last_metrics = array();

	/**
	 * Creates an embedding client.
	 *
	 * @param string|null $api_key_override Optional workflow-specific API key.
	 * @param bool        $record_usage     Include calls in reader-search analytics.
	 */
	public function __construct( ?string $api_key_override = null, bool $record_usage = true ) {
		$this->api_key_override = null === $api_key_override ? null : trim( $api_key_override );
		$this->record_usage     = $record_usage;
	}

	/** Returns whether server-side OpenAI credentials are available. */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/** Returns whether this client instance has credentials. */
	public function configured(): bool {
		return '' !== $this->api_key_value();
	}

	/**
	 * Returns metrics from the most recent embedding response.
	 *
	 * @return array<string,int|float|string>
	 */
	public function last_metrics(): array {
		return $this->last_metrics;
	}

	/** Returns the configured embedding model. */
	public static function model_id(): string {
		if ( defined( 'EHRMAN_DISCOVERY_EMBEDDING_MODEL' ) ) {
			$model = constant( 'EHRMAN_DISCOVERY_EMBEDDING_MODEL' );
			return is_scalar( $model ) ? self::sanitize_model( (string) $model ) : self::DEFAULT_MODEL;
		}
		$model = trim( (string) getenv( 'EHRMAN_DISCOVERY_EMBEDDING_MODEL' ) );
		return '' === $model ? self::DEFAULT_MODEL : self::sanitize_model( $model );
	}

	/** Returns the configured vector dimensions. */
	public static function dimensions(): int {
		$value = getenv( 'EHRMAN_DISCOVERY_EMBEDDING_DIMENSIONS' );
		if ( defined( 'EHRMAN_DISCOVERY_EMBEDDING_DIMENSIONS' ) ) {
			$value = constant( 'EHRMAN_DISCOVERY_EMBEDDING_DIMENSIONS' );
		}
		$dimensions = is_numeric( $value ) ? (int) $value : self::DEFAULT_DIMENSIONS;
		return max( 256, min( 1536, $dimensions ) );
	}

	/**
	 * Generates vectors for one or more text values.
	 *
	 * @param array<int,string> $texts      Text values to embed.
	 * @param string            $request_id Correlation identifier for usage analytics.
	 * @return list<list<float>>|WP_Error Embedding vectors or an API error.
	 */
	public function embed( array $texts, string $request_id = '' ) {
		$this->last_metrics = array();
		if ( empty( $texts ) ) {
			return new WP_Error( 'ehrman_embedding_empty', __( 'There is no text to embed.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		if ( ! $this->configured() ) {
			return new WP_Error( 'ehrman_embedding_not_configured', __( 'Semantic search is not configured on this site.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}

		$input = array();
		foreach ( $texts as $text ) {
			$text = trim( wp_strip_all_tags( $text ) );
			$text = function_exists( 'mb_substr' )
				? mb_substr( $text, 0, self::MAX_TEXT_LENGTH )
				: substr( $text, 0, self::MAX_TEXT_LENGTH );
			if ( '' === $text ) {
				return new WP_Error( 'ehrman_embedding_empty', __( 'There is no text to embed.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
			}
			$input[] = $text;
		}

		$payload = wp_json_encode(
			array(
				'model'           => self::model_id(),
				'input'           => $input,
				'encoding_format' => 'float',
				'dimensions'      => self::dimensions(),
			)
		);
		if ( ! is_string( $payload ) ) {
			$this->record_failure( 'embedding_request_error', $request_id );
			return new WP_Error( 'ehrman_embedding_request_error', __( 'The semantic search request could not be prepared.', 'ehrman-blog-discovery' ), array( 'status' => 500 ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key_value(),
					'Content-Type'  => 'application/json',
				),
				'body'    => $payload,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->record_failure( 'embedding_unavailable', $request_id );
			return new WP_Error( 'ehrman_embedding_unavailable', __( 'Semantic search is temporarily unavailable. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = Database::associative_row( json_decode( wp_remote_retrieve_body( $response ), true ) );
		if ( null === $body || $status < 200 || $status >= 300 ) {
			if ( null !== $body ) {
				$this->record_response( $body, false, 'embedding_response_error', $request_id );
			} else {
				$this->record_failure( 'embedding_response_error', $request_id );
			}
			return new WP_Error( 'ehrman_embedding_response_error', __( 'Semantic search is temporarily unavailable. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$data    = Database::associative_rows( $body['data'] ?? null );
		$vectors = array_fill( 0, count( $input ), null );
		foreach ( $data as $item ) {
			$vector = self::numeric_vector( $item['embedding'] ?? null );
			if ( null === $vector ) {
				continue;
			}
			$index = Database::integer( $item['index'] ?? -1 );
			if ( $index < 0 || $index >= count( $input ) ) {
				continue;
			}
			if ( self::dimensions() === count( $vector ) ) {
				$vectors[ $index ] = $vector;
			}
		}
		if ( in_array( null, $vectors, true ) ) {
			$this->record_response( $body, false, 'embedding_invalid_output', $request_id );
			return new WP_Error( 'ehrman_embedding_invalid_output', __( 'The semantic search service returned an invalid response.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$this->record_response( $body, true, '', $request_id );
		/**
		 * Validated embedding vectors returned in input order.
		 *
		 * @var list<list<float>> $vectors
		 */
		return $vectors;
	}

	/** Returns the API key without exposing it to the browser. */
	private static function api_key(): string {
		if ( defined( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' ) ) {
			$key = constant( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' );
			return is_scalar( $key ) ? trim( (string) $key ) : '';
		}
		return trim( (string) getenv( 'OPENAI_API_KEY' ) );
	}

	/** Returns the workflow-specific key or the default discovery key. */
	private function api_key_value(): string {
		return null === $this->api_key_override ? self::api_key() : $this->api_key_override;
	}

	/**
	 * Records a local failure when this client participates in search analytics.
	 *
	 * @param string $error_code Stable local error code.
	 * @param string $request_id Correlation identifier.
	 */
	private function record_failure( string $error_code, string $request_id ): void {
		if ( $this->record_usage ) {
			AI_Usage::record_failure( self::model_id(), $error_code, $request_id );
		}
	}

	/**
	 * Captures embedding metrics and optionally forwards them to search analytics.
	 *
	 * @param array<string,mixed> $body       Decoded response body.
	 * @param bool                $succeeded  Whether usable vectors were returned.
	 * @param string              $error_code Stable local error code.
	 * @param string              $request_id Correlation identifier.
	 */
	private function record_response( array $body, bool $succeeded, string $error_code, string $request_id ): void {
		$usage              = is_array( $body['usage'] ?? null ) ? $body['usage'] : array();
		$input_tokens       = max( 0, Database::integer( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null ) );
		$this->last_metrics = array(
			'response_id'        => is_scalar( $body['id'] ?? null ) ? sanitize_text_field( (string) $body['id'] ) : '',
			'model'              => is_scalar( $body['model'] ?? null ) ? sanitize_text_field( (string) $body['model'] ) : self::model_id(),
			'input_tokens'       => $input_tokens,
			'estimated_cost_usd' => ( $input_tokens * 0.02 ) / 1000000,
			'succeeded'          => $succeeded ? 1 : 0,
			'error_code'         => sanitize_key( $error_code ),
		);
		if ( $this->record_usage ) {
			AI_Usage::record_embedding_response( $body, $succeeded, $error_code, $request_id );
		}
	}

	/**
	 * Restricts configured model identifiers to provider-supported characters.
	 *
	 * @param string $model Configured model identifier.
	 */
	private static function sanitize_model( string $model ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', trim( $model ) );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}

	/**
	 * Validates an embedding vector and converts its numeric values to floats.
	 *
	 * @param mixed $value Candidate vector.
	 * @return list<float>|null Numeric vector, or null.
	 */
	private static function numeric_vector( $value ): ?array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$vector = array();
		foreach ( $value as $coordinate ) {
			if ( ! is_numeric( $coordinate ) ) {
				return null;
			}
			$vector[] = (float) $coordinate;
		}
		return $vector;
	}
}
