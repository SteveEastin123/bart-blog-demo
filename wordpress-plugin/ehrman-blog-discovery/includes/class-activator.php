<?php
/**
 * Plugin activation services.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Installs the schema and initializes persistent plugin state. */
final class Activator {

	/** Activates the plugin and prepares its database and options. */
	public static function activate(): void {
		Database::install();
		update_option( 'ehrman_discovery_plugin_version', EHRMAN_DISCOVERY_VERSION, false );
		delete_option( 'ehrman_discovery_pages_version' );

		if ( false === get_option( 'ehrman_discovery_import_status', false ) ) {
			add_option(
				'ehrman_discovery_import_status',
				array(
					'state'   => 'not_imported',
					'message' => 'The WordPress/MySQL environment is ready for the Phase 3 importer.',
				),
				'',
				false
			);
		}

		delete_option( 'ebd_plugin_version' );
		delete_option( 'ebd_schema_version' );
		delete_option( 'ebd_import_status' );
	}
}
