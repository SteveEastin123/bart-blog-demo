<?php
/**
 * Plugin Name: Ehrman Blog Discovery
 * Plugin URI:  https://github.com/SteveEastin123/bart-blog-demo
 * Description: WordPress foundation for browsing and searching the Ehrman Blog index.
 * Version:     0.5.6
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Steve Eastin
 * License:     GPL-2.0-or-later
 * Text Domain: ehrman-blog-discovery
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EHRMAN_DISCOVERY_VERSION', '0.5.6' );
define( 'EHRMAN_DISCOVERY_SCHEMA_VERSION', '1.4.0' );
define( 'EHRMAN_DISCOVERY_PLUGIN_FILE', __FILE__ );
define( 'EHRMAN_DISCOVERY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EHRMAN_DISCOVERY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-database.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-importer.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-search-service.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-browse-service.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-usage.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-requests.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-refinements.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-analytics-page.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-interpreter.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-ai-feedback.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-parity-service.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-assets.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-page-controller.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-activator.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-cli-command.php';
require_once EHRMAN_DISCOVERY_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( EHRMAN_DISCOVERY_PLUGIN_FILE, array( Activator::class, 'activate' ) );

add_action(
	'plugins_loaded',
	/** Loads the plugin after WordPress has loaded all active plugins. */
	static function (): void {
		Plugin::instance()->register();
	}
);
