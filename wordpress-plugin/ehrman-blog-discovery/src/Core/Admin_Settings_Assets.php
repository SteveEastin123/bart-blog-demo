<?php
/**
 * Shared assets for the Settings and Status page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gives independent radio groups the same protected autosave behavior. */
final class Admin_Settings_Assets {
	/**
	 * Loads assets only on the administrator's settings screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Admin_Menu::is_screen( Admin_Menu::SETTINGS, $hook ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$script = 'assets/js/admin-settings.js';
		$style  = 'assets/css/admin-settings.css';
		wp_enqueue_script( 'ehrman-admin-settings', EHRMAN_DISCOVERY_PLUGIN_URL . $script, array( 'wp-api-fetch', 'wp-i18n' ), (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . $script ), true );
		wp_enqueue_style( 'ehrman-admin-settings', EHRMAN_DISCOVERY_PLUGIN_URL . $style, array(), (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . $style ) );
		wp_localize_script(
			'ehrman-admin-settings',
			'EhrmanAdminSettings',
			array(
				'restPath' => '/wp/v2/settings',
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'groups'   => array(
					array(
						'selector' => '[data-ebd-publishing-settings]',
						'option'   => Post_Ingestion_Publishing_Settings::OPTION,
						'values'   => array( 'manual', 'automatic' ),
					),
					array(
						'selector' => '[data-ebd-deletion-settings]',
						'option'   => Organization_Deletion_Settings::OPTION,
						'values'   => array( 'disabled', 'enabled' ),
					),
				),
			)
		);
	}
}
