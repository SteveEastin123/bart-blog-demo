<?php
/**
 * Plugin Name: Ehrman Reviewer Tools
 * Description: Optional demo-only taxonomy outlines, indexes, and exports. Not part of the production handoff.
 * Version:     1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: ehrman-blog-discovery
 * Author:      Steve Eastin
 * License:     GPL-2.0-or-later
 * Text Domain: ehrman-reviewer-tools
 *
 * @package EhrmanReviewerTools
 */

namespace EhrmanReviewerTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EHRMAN_REVIEWER_VERSION', '1.0.0' );
define( 'EHRMAN_REVIEWER_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Structure_Review_Renderer.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'register' ), 20 );
