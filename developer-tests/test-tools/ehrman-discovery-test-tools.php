<?php
/**
 * Plugin Name: Ehrman Discovery Test Tools
 * Description: Optional staging-only comparison endpoint. Never install on the live site.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: ehrman-blog-discovery
 * License: GPL-2.0-or-later
 * Text Domain: ehrman-blog-discovery
 *
 * @package EhrmanDiscoveryTests
 */

namespace EhrmanDiscoveryTests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/Parity_Service.php';
require_once __DIR__ . '/src/Parity_REST_Controller.php';
require_once __DIR__ . '/src/Plugin.php';
add_action( 'rest_api_init', array( Plugin::class, 'register_routes' ) );
