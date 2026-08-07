<?php
/**
 * Plugin Name: Ehrman Blog Discovery
 * Plugin URI:  https://github.com/SteveEastin123/bart-blog-demo
 * Description: WordPress foundation for browsing and searching the Ehrman Blog index.
 * Version:     0.4.15
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Steve Eastin
 * License:     GPL-2.0-or-later
 * Text Domain: ehrman-blog-discovery
 */

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

define('EBD_VERSION', '0.4.15');
define('EBD_SCHEMA_VERSION', '1.0.0');
define('EBD_PLUGIN_FILE', __FILE__);
define('EBD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EBD_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EBD_PLUGIN_DIR . 'includes/class-database.php';
require_once EBD_PLUGIN_DIR . 'includes/class-importer.php';
require_once EBD_PLUGIN_DIR . 'includes/class-search-service.php';
require_once EBD_PLUGIN_DIR . 'includes/class-browse-service.php';
require_once EBD_PLUGIN_DIR . 'includes/class-parity-service.php';
require_once EBD_PLUGIN_DIR . 'includes/class-assets.php';
require_once EBD_PLUGIN_DIR . 'includes/class-page-controller.php';
require_once EBD_PLUGIN_DIR . 'includes/class-activator.php';
require_once EBD_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once EBD_PLUGIN_DIR . 'includes/class-cli-command.php';
require_once EBD_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(EBD_PLUGIN_FILE, array(Activator::class, 'activate'));

add_action(
    'plugins_loaded',
    static function (): void {
        Plugin::instance()->register();
    }
);
