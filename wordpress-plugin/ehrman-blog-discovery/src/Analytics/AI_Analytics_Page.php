<?php
/**
 * Ask AI analytics administration entry point.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers and coordinates the protected analytics administration page. */
final class AI_Analytics_Page {
	/** Registers administrator hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_ehrman_ai_analytics_csv', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_ehrman_ai_analytics_reset', array( self::class, 'reset_test_analytics' ) );
	}

	/** Adds the analytics page under Tools. */
	public static function add_page(): void {
		add_management_page(
			__( 'AI Search Analytics', 'ehrman-blog-discovery' ),
			__( 'AI Search Analytics', 'ehrman-blog-discovery' ),
			'manage_options',
			'ehrman-ai-analytics',
			array( self::class, 'render' )
		);
	}

	/** Renders the analytics dashboard. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request = new AI_Analytics_Request();
		( new AI_Analytics_Renderer() )->render(
			$request->filters(),
			max( 1, absint( $request->query_value( 'paged', '1' ) ) ),
			'1' === $request->query_value( 'analytics_reset' )
		);
	}

	/** Clears test analytics through the protected reset workflow. */
	public static function reset_test_analytics(): void {
		( new AI_Analytics_Reset_Handler() )->handle();
	}

	/** Exports all filtered rows as a protected CSV download. */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export AI search analytics.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( 'ehrman_ai_analytics_csv' );

		$request = new AI_Analytics_Request();
		( new AI_Analytics_Exporter() )->export( $request->filters(), $request->query_value( 'dataset' ) );
	}
}
