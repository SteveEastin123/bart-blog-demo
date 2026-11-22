<?php
/**
 * Form-style publishing on the native WordPress Add Post screen.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps native post saving, previews, scheduling, media and revisions intact. */
final class Post_Publisher {
	public const FORM_META = '_ehrman_form_editor';

	/** Registers the form and saves its preference before first-publication automation. */
	public static function register(): void {
		add_filter( 'use_block_editor_for_post', array( self::class, 'block_editor' ), 20, 2 );
		add_action( 'add_meta_boxes_post', array( self::class, 'boxes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'edit_form_after_title', array( self::class, 'content_heading' ) );
		add_action( 'wp_after_insert_post', array( self::class, 'save_preferences' ), 5, 4 );
	}

	/**
	 * Uses the form for new posts and posts saved through it, never converting block content.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function uses_form( WP_Post $post ): bool {
		return 'post' === $post->post_type && current_user_can( 'manage_options' )
			&& current_user_can( 'edit_post', $post->ID ) && ! has_blocks( $post->post_content )
			&& ( 'auto-draft' === $post->post_status || '1' === get_post_meta( $post->ID, self::FORM_META, true ) );
	}

	/**
	 * Selects WordPress's built-in form editor only within the publisher's scope.
	 *
	 * @param bool    $enabled Existing editor preference.
	 * @param WP_Post $post Native post.
	 */
	public static function block_editor( bool $enabled, WP_Post $post ): bool {
		return self::uses_form( $post ) ? false : $enabled;
	}

	/**
	 * Locks the saved choice after first publication, including later drafts or restores.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function mode_locked( WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'publish', 'private', 'trash' ), true )
			|| (bool) get_post_meta( $post->ID, Post_Ingestion_Automation::PUBLISHED_META, true );
	}

	/**
	 * Simplifies native boxes without deleting their values or third-party integrations.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function boxes( WP_Post $post ): void {
		if ( ! self::uses_form( $post ) ) {
			return;
		}
		foreach ( array( 'categorydiv', 'tagsdiv-post_tag', 'formatdiv', 'postexcerpt', 'trackbacksdiv', 'postcustom', 'commentstatusdiv', 'commentsdiv', 'slugdiv', 'authordiv' ) as $id ) {
			foreach ( array( 'normal', 'side', 'advanced' ) as $context ) {
				remove_meta_box( $id, 'post', $context );
			}
		}
		add_meta_box( 'ehrman-post-author', __( 'Author', 'ehrman-blog-discovery' ), 'post_author_meta_box', 'post', 'side', 'high' );
		add_meta_box( 'ehrman-post-metadata', __( 'Post Metadata', 'ehrman-blog-discovery' ), array( self::class, 'metadata_box' ), 'post', 'normal', 'high' );
	}

	/**
	 * Renders the saved mode and the shared metadata component's mount point.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function metadata_box( WP_Post $post ): void {
		$html = Template::render(
			'admin/post-publisher.php',
			array(
				'post_id' => $post->ID,
				'default' => Post_Ingestion_Publishing_Settings::default_mode(),
				'mode'    => Post_Ingestion_Publishing_Settings::mode( $post->ID ),
				'locked'  => self::mode_locked( $post ),
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted template escapes labels and values.
		echo $html;
	}

	/**
	 * Gives the article editor its own visible label.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function content_heading( WP_Post $post ): void {
		if ( self::uses_form( $post ) ) {
			echo '<h2 class="ehrman-post-heading">' . esc_html__( 'Post', 'ehrman-blog-discovery' ) . '</h2>';
		}
	}

	/**
	 * Adds a narrowly scoped styling hook.
	 *
	 * @param string $classes Existing body classes.
	 */
	public static function body_class( string $classes ): string {
		return null !== self::screen_post() ? $classes . ' ehrman-post-publisher' : $classes;
	}

	/** Enqueues the native-form adapter and the existing metadata controls. */
	public static function assets(): void {
		$post = self::screen_post();
		if ( null === $post ) {
			return;
		}
		wp_enqueue_script( 'ehrman-post-publisher', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/js/post-publisher.js', array( 'jquery', 'post' ), (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/js/post-publisher.js' ), true );
		wp_enqueue_style( 'ehrman-post-publisher', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/css/post-publisher.css', array(), (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/css/post-publisher.css' ) );
		Post_Ingestion_Editor::enqueue_metadata(
			array(
				'postId'     => $post->ID,
				'postStatus' => $post->post_status,
			)
		);
	}

	/** Returns a form post only on native post editing screens. */
	private static function screen_post(): ?WP_Post {
		global $post;
		$screen = get_current_screen();
		return null !== $screen && 'post' === $screen->base && $post instanceof WP_Post && self::uses_form( $post ) ? $post : null;
	}

	/**
	 * Stores the submitted choice before automatic metadata sees the newly published post.
	 *
	 * @param int          $post_id Native post ID.
	 * @param WP_Post      $post Saved post.
	 * @param bool         $update Whether this was an update.
	 * @param WP_Post|null $before Previously saved post.
	 */
	public static function save_preferences( int $post_id, WP_Post $post, bool $update, ?WP_Post $before ): void {
		if ( 'post' !== $post->post_type || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id )
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		$nonce = isset( $_POST['ehrman_publisher_nonce'] ) && is_string( $_POST['ehrman_publisher_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ehrman_publisher_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ehrman-publisher-' . $post_id ) ) {
			return;
		}
		if ( ! has_blocks( $post->post_content ) ) {
			update_post_meta( $post_id, self::FORM_META, '1' );
		}
		if ( ( null !== $before && self::mode_locked( $before ) ) || get_post_meta( $post_id, Post_Ingestion_Automation::PUBLISHED_META, true ) ) {
			return;
		}
		$mode = isset( $_POST['ehrman_metadata_mode'] ) && is_string( $_POST['ehrman_metadata_mode'] ) ? sanitize_key( wp_unslash( $_POST['ehrman_metadata_mode'] ) ) : '';
		if ( in_array( $mode, array( 'manual', 'automatic' ), true ) ) {
			update_post_meta( $post_id, Post_Ingestion_Publishing_Settings::META, $mode );
		}
	}
}
