<?php
/**
 * Post-ingestion configuration.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides the environment-backed settings shared by ingestion collaborators. */
final class Post_Ingestion_Settings {
	private const API_URL        = 'https://api.openai.com/v1/responses';
	private const DEFAULT_MODEL  = 'gpt-5.6-sol';
	private const PROMPT_VERSION = '8';

	/** Returns the Responses API endpoint. */
	public static function api_url(): string {
		return self::API_URL;
	}

	/** Returns whether the dedicated ingestion project key is configured. */
	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/** Returns the ingestion-only API key. */
	public static function api_key(): string {
		$value = defined( 'EHRMAN_INGESTION_OPENAI_API_KEY' )
			? constant( 'EHRMAN_INGESTION_OPENAI_API_KEY' )
			: getenv( 'EHRMAN_INGESTION_OPENAI_API_KEY' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Returns the configured ingestion model. */
	public static function model_id(): string {
		$value = defined( 'EHRMAN_INGESTION_OPENAI_MODEL' )
			? constant( 'EHRMAN_INGESTION_OPENAI_MODEL' )
			: getenv( 'EHRMAN_INGESTION_OPENAI_MODEL' );
		$model = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $model ) {
			return self::DEFAULT_MODEL;
		}
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '', $model );
		return is_string( $sanitized ) && '' !== $sanitized ? $sanitized : self::DEFAULT_MODEL;
	}

	/** Returns the editorial-prompt version recorded with each draft. */
	public static function prompt_version(): string {
		return self::PROMPT_VERSION;
	}

	/** Returns a supported configured reasoning effort. */
	public static function reasoning_effort(): string {
		$value  = defined( 'EHRMAN_INGESTION_REASONING_EFFORT' )
			? constant( 'EHRMAN_INGESTION_REASONING_EFFORT' )
			: getenv( 'EHRMAN_INGESTION_REASONING_EFFORT' );
		$effort = strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
		return in_array( $effort, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $effort : 'high';
	}

	/** Returns the active post source-of-truth mode. */
	public static function post_source(): string {
		$value = defined( 'EHRMAN_DISCOVERY_POST_SOURCE' )
			? constant( 'EHRMAN_DISCOVERY_POST_SOURCE' )
			: getenv( 'EHRMAN_DISCOVERY_POST_SOURCE' );
		return 'mysql' === strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) ) ? 'mysql' : 'json';
	}

	/** Returns whether approval may modify the live discovery index. */
	public static function database_is_authoritative(): bool {
		return 'mysql' === self::post_source();
	}
}
