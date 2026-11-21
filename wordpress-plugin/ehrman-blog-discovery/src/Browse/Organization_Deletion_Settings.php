<?php
/**
 * Opt-in permission for organization deletion.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Defaults closed; WordPress checks administrator capability and nonce on save. */
final class Organization_Deletion_Settings {
	public const OPTION = 'ehrman_organization_deletion';
	public const GROUP  = 'ehrman_organization_deletion_settings';

	/** Registers the setting on WordPress's administrator-only settings endpoint. */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'string',
				'default'           => 'disabled',
				'sanitize_callback' => static fn( $value ): string => 'enabled' === $value ? 'enabled' : 'disabled',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'disabled', 'enabled' ),
					),
				),
			)
		);
	}

	/** Returns the default-off administrator choice. */
	public static function enabled(): bool {
		return 'enabled' === get_option( self::OPTION, 'disabled' );
	}
}
