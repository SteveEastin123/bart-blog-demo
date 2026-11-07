<?php
/**
 * Server-side OpenAI credentials shared by search and post ingestion.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves the single OpenAI key without exposing it to the browser. */
final class OpenAI_Credentials {
	/** Returns the shared key, allowing a server-side constant to override the environment. */
	public static function api_key(): string {
		$value = defined( 'OPENAI_API_KEY' ) ? constant( 'OPENAI_API_KEY' ) : getenv( 'OPENAI_API_KEY' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
