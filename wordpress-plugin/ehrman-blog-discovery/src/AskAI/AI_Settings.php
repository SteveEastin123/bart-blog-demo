<?php
/**
 * Server-side configuration for AI candidate review.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves the surviving search model and credentials without exposing secrets. */
final class AI_Settings {
	private const DEFAULT_MODEL = 'gpt-5.4-mini';

	/** Returns whether server-side AI credentials are available. */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/** Returns the API key for server-side requests only. */
	public static function api_key(): string {
		if ( defined( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' ) ) {
			$key = constant( 'EHRMAN_DISCOVERY_OPENAI_API_KEY' );
			return is_scalar( $key ) ? trim( (string) $key ) : '';
		}
		return trim( (string) getenv( 'OPENAI_API_KEY' ) );
	}

	/** Returns the configured candidate-review model identifier. */
	public static function model_id(): string {
		if ( defined( 'EHRMAN_DISCOVERY_AI_MODEL' ) ) {
			$model = constant( 'EHRMAN_DISCOVERY_AI_MODEL' );
			return is_scalar( $model ) ? self::sanitize_model( (string) $model ) : self::DEFAULT_MODEL;
		}
		$model = trim( (string) getenv( 'EHRMAN_DISCOVERY_AI_MODEL' ) );
		return '' === $model ? self::DEFAULT_MODEL : self::sanitize_model( $model );
	}

	/**
	 * Returns a model identifier containing only provider-supported characters.
	 *
	 * @param string $model Configured model identifier.
	 */
	private static function sanitize_model( string $model ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', trim( $model ) );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}
}
