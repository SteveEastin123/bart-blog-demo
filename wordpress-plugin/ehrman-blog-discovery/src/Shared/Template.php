<?php
/**
 * Plugin template rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders trusted plugin templates with an explicit context array. */
final class Template {
	/**
	 * Captures one template as HTML.
	 *
	 * @param string              $relative_path Path below the templates directory.
	 * @param array<string,mixed> $context       Values exposed to the template.
	 * @throws RuntimeException When the requested template is unavailable.
	 */
	public static function render( string $relative_path, array $context = array() ): string {
		$path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'templates/' . ltrim( $relative_path, '/\\' );
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( "Plugin template {$relative_path} is unavailable." );
		}

		$ehrman_blog_discovery_context = $context;
		ob_start();
		require $path;
		$output = ob_get_clean();
		return is_string( $output ) ? $output : '';
	}
}
