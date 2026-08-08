<?php
/**
 * Development-only constants for static analysis.
 *
 * @package Ehrman_Blog_Discovery
 */

if ( ! defined( 'EHRMAN_DISCOVERY_VERSION' ) ) {
	define( 'EHRMAN_DISCOVERY_VERSION', '0.0.0' );
}

if ( ! defined( 'EHRMAN_DISCOVERY_SCHEMA_VERSION' ) ) {
	define( 'EHRMAN_DISCOVERY_SCHEMA_VERSION', '0.0.0' );
}

if ( ! defined( 'EHRMAN_DISCOVERY_PLUGIN_FILE' ) ) {
	define( 'EHRMAN_DISCOVERY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'EHRMAN_DISCOVERY_PLUGIN_DIR' ) ) {
	define( 'EHRMAN_DISCOVERY_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'EHRMAN_DISCOVERY_PLUGIN_URL' ) ) {
	define( 'EHRMAN_DISCOVERY_PLUGIN_URL', 'https://example.test/wp-content/plugins/ehrman-blog-discovery/' );
}
