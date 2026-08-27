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

	/** Returns whether server-side OpenAI credentials are available. */
	public static function is_configured(): bool {
		return '' !== self::api_key();
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
		if ( empty( $texts ) ) {
			return new WP_Error( 'ehrman_embedding_empty', __( 'There is no text to embed.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_configured() ) {
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
			AI_Usage::record_failure( self::model_id(), 'embedding_request_error', $request_id );
			return new WP_Error( 'ehrman_embedding_request_error', __( 'The semantic search request could not be prepared.', 'ehrman-blog-discovery' ), array( 'status' => 500 ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => $payload,
			)
		);
		if ( is_wp_error( $response ) ) {
			AI_Usage::record_failure( self::model_id(), 'embedding_unavailable', $request_id );
			return new WP_Error( 'ehrman_embedding_unavailable', __( 'Semantic search is temporarily unavailable. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || $status < 200 || $status >= 300 ) {
			if ( is_array( $body ) ) {
				AI_Usage::record_embedding_response( $body, false, 'embedding_response_error', $request_id );
			} else {
				AI_Usage::record_failure( self::model_id(), 'embedding_response_error', $request_id );
			}
			return new WP_Error( 'ehrman_embedding_response_error', __( 'Semantic search is temporarily unavailable. Please try again.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		$data    = is_array( $body['data'] ?? null ) ? $body['data'] : array();
		$vectors = array_fill( 0, count( $input ), null );
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['embedding'] ?? null ) ) {
				continue;
			}
			$index = Database::integer( $item['index'] ?? -1 );
			if ( $index < 0 || $index >= count( $input ) ) {
				continue;
			}
			$vector = array_map( 'floatval', array_values( $item['embedding'] ) );
			if ( self::dimensions() === count( $vector ) ) {
				$vectors[ $index ] = $vector;
			}
		}
		if ( in_array( null, $vectors, true ) ) {
			AI_Usage::record_embedding_response( $body, false, 'embedding_invalid_output', $request_id );
			return new WP_Error( 'ehrman_embedding_invalid_output', __( 'The semantic search service returned an invalid response.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}

		AI_Usage::record_embedding_response( $body, true, '', $request_id );
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

	/**
	 * Restricts configured model identifiers to provider-supported characters.
	 *
	 * @param string $model Configured model identifier.
	 */
	private static function sanitize_model( string $model ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', trim( $model ) );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}
}
