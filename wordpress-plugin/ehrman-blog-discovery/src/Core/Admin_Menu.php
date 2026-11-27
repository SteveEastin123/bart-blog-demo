<?php
/**
 * Shared administration navigation and legacy Tools redirects.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Groups the plugin's administrator screens under Blog Search. */
final class Admin_Menu {
	public const METADATA     = 'ehrman-post-ingestion';
	public const ORGANIZATION = 'ehrman-search-organization';
	public const ANALYTICS    = 'ehrman-ai-analytics';
	public const SETTINGS     = 'ehrman-blog-discovery';
	public const INFORMATION  = 'ehrman-info-documentation';

	/**
	 * Actual hook suffixes returned by WordPress, including translated parents.
	 *
	 * @var array<string,string>
	 */
	private static array $screens = array();

	/** Registers navigation and compatibility routing. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( Admin_Settings_Assets::class, 'enqueue' ) );
		// Redirect before WordPress rejects the former Tools page as unregistered.
		add_action( 'admin_menu', array( self::class, 'redirect_legacy' ), 1 );
	}

	/** Registers the ordered menu and screen-specific request handlers. */
	public static function add_pages(): void {
		add_menu_page(
			__( 'Post Metadata', 'ehrman-blog-discovery' ),
			__( 'Blog Search', 'ehrman-blog-discovery' ),
			'manage_options',
			self::METADATA,
			array( Post_Ingestion_Page::class, 'render' ),
			'dashicons-search'
		);
		$pages = array(
			self::METADATA     => array( __( 'Post Metadata', 'ehrman-blog-discovery' ), array( Post_Ingestion_Page::class, 'render' ) ),
			self::ORGANIZATION => array( __( 'Manage Topics', 'ehrman-blog-discovery' ), array( Topic_Organization_Page::class, 'render' ) ),
			self::ANALYTICS    => array( __( 'AI Analytics', 'ehrman-blog-discovery' ), array( AI_Analytics_Page::class, 'render' ) ),
			self::SETTINGS     => array( __( 'Settings', 'ehrman-blog-discovery' ), array( Plugin::instance(), 'render_admin_page' ) ),
			self::INFORMATION  => array( __( 'Info & Documentation', 'ehrman-blog-discovery' ), array( Plugin::instance(), 'render_information_page' ) ),
		);
		foreach ( $pages as $slug => $page ) {
			$label = self::METADATA === $slug ? Post_Ingestion_Admin_Status::menu_title( $page[0] ) : $page[0];
			$hook  = add_submenu_page( self::METADATA, $page[0], $label, 'manage_options', $slug, $page[1] );
			if ( false !== $hook ) {
				self::$screens[ $slug ] = $hook;
			}
		}
		if ( isset( self::$screens[ self::METADATA ] ) ) {
			add_action( 'load-' . self::$screens[ self::METADATA ], array( Post_Ingestion_Page::class, 'prepare_progress' ) );
		}
		if ( isset( self::$screens[ self::ORGANIZATION ] ) ) {
			add_action( 'load-' . self::$screens[ self::ORGANIZATION ], array( Topic_Organization_Page::class, 'save' ) );
		}
	}

	/**
	 * Checks a screen without relying on WordPress's translated hook prefix.
	 *
	 * @param string $slug Plugin page slug.
	 * @param string $hook Current screen hook or ID.
	 */
	public static function is_screen( string $slug, string $hook ): bool {
		return isset( self::$screens[ $slug ] ) && self::$screens[ $slug ] === $hook;
	}

	/** Redirects only retired plugin Tools URLs, retaining open forms and filters. */
	public static function redirect_legacy(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only; the destination verifies action nonces and permissions.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'tools.php' !== $pagenow || ! in_array( $page, array( self::METADATA, self::ORGANIZATION, self::ANALYTICS, self::SETTINGS, self::INFORMATION ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ehrman-blog-discovery' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Query values are URL-encoded, not executed; destinations validate their own inputs.
		$args         = wp_unslash( $_GET );
		$args['page'] = $page;
		// A 307 preserves a form's POST body so its existing nonce check still runs.
		$status = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ? 307 : 302;
		$url    = admin_url( 'admin.php' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		wp_safe_redirect( $url, $status );
		exit;
	}
}
