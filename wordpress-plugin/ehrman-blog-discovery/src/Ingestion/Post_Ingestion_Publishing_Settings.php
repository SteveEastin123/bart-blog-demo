<?php
/**
 * Publishing defaults and per-post metadata preferences.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps the automatic publishing choice separate from API and source settings. */
final class Post_Ingestion_Publishing_Settings {
	public const OPTION = 'ehrman_ingestion_default_mode';
	public const GROUP  = 'ehrman_ingestion_publishing';
	public const META   = '_ehrman_metadata_mode';

	/** Registers settings and the protected Gutenberg post attribute. */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'rest_api_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'init', array( self::class, 'register_meta' ) );
	}

	/** Registers a server-side option; WordPress checks capability and nonce on save. */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'string',
				'default'           => 'manual',
				'sanitize_callback' => static fn( $value ): string => 'automatic' === $value ? 'automatic' : 'manual',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'manual', 'automatic' ),
					),
				),
			)
		);
	}

	/**
	 * Loads autosave controls only on the administrator's publishing settings page.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Admin_Menu::is_screen( Admin_Menu::SETTINGS, $hook ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/js/post-ingestion-publishing.js';
		wp_enqueue_script(
			'ehrman-post-ingestion-publishing',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/post-ingestion-publishing.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			is_file( $path ) ? (string) filemtime( $path ) : EHRMAN_DISCOVERY_VERSION,
			true
		);
		wp_localize_script(
			'ehrman-post-ingestion-publishing',
			'EhrmanPublishingSettings',
			array(
				'restPath' => '/wp/v2/settings',
				'option'   => self::OPTION,
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/** Registers the per-post override without granting access to other private metadata. */
	public static function register_meta(): void {
		register_post_meta(
			'post',
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'inherit',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'inherit', 'manual', 'automatic' ),
					),
				),
				'sanitize_callback' => static fn( $value ): string => in_array( $value, array( 'manual', 'automatic' ), true ) ? $value : 'inherit',
				'auth_callback'     => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'manage_options' ) && current_user_can( 'edit_post', Database::integer( $post_id ) ),
			)
		);
	}

	/** Returns the safe default for posts without an override. */
	public static function default_mode(): string {
		return 'automatic' === get_option( self::OPTION, 'manual' ) ? 'automatic' : 'manual';
	}

	/**
	 * Resolves an explicitly saved override or the current site default.
	 *
	 * @param int  $post_id Native WordPress post ID.
	 * @param bool $fresh Whether to bypass request caches before automatic approval.
	 */
	public static function mode( int $post_id, bool $fresh = false ): string {
		if ( $fresh ) {
			wp_cache_delete( $post_id, 'post_meta' );
		}
		$mode = get_post_meta( $post_id, self::META, true );
		if ( in_array( $mode, array( 'manual', 'automatic' ), true ) ) {
			return $mode;
		}
		if ( $fresh ) {
			$wpdb = Database::client();
			return 'automatic' === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, self::OPTION ) ) ? 'automatic' : 'manual';
		}
		return self::default_mode();
	}
}
