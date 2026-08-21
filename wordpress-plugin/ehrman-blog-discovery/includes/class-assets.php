<?php
/**
 * Front-end asset registration.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads the styles, scripts, endpoints, and translated strings used by discovery pages. */
final class Assets {

	/**
	 * Tracks whether the JavaScript configuration has already been localized.
	 *
	 * @var bool
	 */
	private static bool $localized = false;

	/** Enqueues and configures the discovery interface assets. */
	public static function enqueue(): void {
		wp_enqueue_style(
			'ehrman-blog-discovery',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/css/discovery.css',
			array(),
			EHRMAN_DISCOVERY_VERSION
		);
		wp_enqueue_script(
			'ehrman-blog-discovery',
			EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/discovery.js',
			array(),
			EHRMAN_DISCOVERY_VERSION,
			true
		);

		if ( self::$localized ) {
			return;
		}
		self::$localized = true;
		wp_localize_script(
			'ehrman-blog-discovery',
			'EhrmanDiscovery',
			array(
				'suggestionsUrl' => esc_url_raw( rest_url( 'ehrman-discovery/v1/suggestions' ) ),
				'searchUrl'      => esc_url_raw( rest_url( 'ehrman-discovery/v1/search' ) ),
				'interpretUrl'   => esc_url_raw( rest_url( 'ehrman-discovery/v1/interpret' ) ),
				'feedbackUrl'    => esc_url_raw( rest_url( 'ehrman-discovery/v1/feedback' ) ),
				'statusUrl'      => esc_url_raw( rest_url( 'ehrman-discovery/v1/status' ) ),
				'strings'        => array(
					'topic'          => __( 'Topic', 'ehrman-blog-discovery' ),
					'topicKeyword'   => __( 'Keyword', 'ehrman-blog-discovery' ),
					'keyword'        => __( 'Keyword', 'ehrman-blog-discovery' ),
					'post'           => __( 'post', 'ehrman-blog-discovery' ),
					'posts'          => __( 'posts', 'ehrman-blog-discovery' ),
					'previous'       => __( 'Previous', 'ehrman-blog-discovery' ),
					'next'           => __( 'Next', 'ehrman-blog-discovery' ),
					'page'           => __( 'Page', 'ehrman-blog-discovery' ),
					'of'             => __( 'of', 'ehrman-blog-discovery' ),
					'showing'        => __( 'Showing', 'ehrman-blog-discovery' ),
					'noResults'      => __( 'No posts matched this request.', 'ehrman-blog-discovery' ),
					'manyResults'    => __( 'Many posts match. Add another topic or keyword to narrow the results.', 'ehrman-blog-discovery' ),
					'fewResults'     => __( 'Only a few posts match all the selected terms. Remove a term to broaden the results.', 'ehrman-blog-discovery' ),
					'zeroResults'    => __( 'No posts match all the selected terms. Remove a term or try a different search.', 'ehrman-blog-discovery' ),
					'requestFailed'  => __( 'The search could not be completed. Please try again.', 'ehrman-blog-discovery' ),
					'unknownAuthor'  => __( 'unknown author', 'ehrman-blog-discovery' ),
					'feedbackThanks' => __( 'Thank you. Your feedback will help improve the search.', 'ehrman-blog-discovery' ),
					'feedbackFailed' => __( 'The feedback could not be saved. Please try again.', 'ehrman-blog-discovery' ),
				),
			)
		);
	}
}
