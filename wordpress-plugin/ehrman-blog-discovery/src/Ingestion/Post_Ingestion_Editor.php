<?php
/**
 * WordPress post-editor assets for search metadata ingestion.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds the protected Search Metadata panel to the post editor. */
final class Post_Ingestion_Editor {
	/** Registers administrator-only editor assets. */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_assets' ) );
	}

	/** Enqueues the Gutenberg document panel for administrators editing posts. */
	public static function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'post' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$script_path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/js/post-ingestion-editor.js';
		$style_path  = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/css/post-ingestion-editor.css';
		wp_enqueue_script(
			'ehrman-post-ingestion-editor',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/post-ingestion-editor.js',
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			is_file( $script_path ) ? (string) filemtime( $script_path ) : EHRMAN_DISCOVERY_VERSION,
			true
		);
		wp_enqueue_style(
			'ehrman-post-ingestion-editor',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/css/post-ingestion-editor.css',
			array( 'wp-components' ),
			is_file( $style_path ) ? (string) filemtime( $style_path ) : EHRMAN_DISCOVERY_VERSION
		);
		self::enqueue_selection_styles();
		wp_localize_script(
			'ehrman-post-ingestion-editor',
			'EhrmanPostIngestion',
			array(
				'restBase'    => '/ehrman-discovery/v1/ingestion/post/',
				'modeMeta'    => Post_Ingestion_Publishing_Settings::META,
				'defaultMode' => Post_Ingestion_Publishing_Settings::default_mode(),
			)
		);
	}

	/** Shares term-removal styling between the editor panel and metadata review page. */
	public static function enqueue_selection_styles(): void {
		$path = EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/css/post-ingestion-selections.css';
		wp_enqueue_style( 'ehrman-post-ingestion-selections', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/css/post-ingestion-selections.css', array( 'dashicons' ), is_file( $path ) ? (string) filemtime( $path ) : EHRMAN_DISCOVERY_VERSION );
	}
}
