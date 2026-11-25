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

	/**
	 * First automatic publication in this native form submission, not later updates.
	 *
	 * @var int
	 */
	private static int $automatic_publication = 0;

	/** Registers the form and saves its preference before first-publication automation. */
	public static function register(): void {
		add_filter( 'use_block_editor_for_post', array( self::class, 'block_editor' ), 20, 2 );
		add_filter( 'enter_title_here', array( self::class, 'title_label' ), 10, 2 );
		add_filter( 'mce_css', array( self::class, 'editor_styles' ) );
		add_filter( 'wp_editor_settings', array( self::class, 'editor_settings' ), 10, 2 );
		add_action( 'add_meta_boxes_post', array( self::class, 'boxes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'edit_form_after_title', array( self::class, 'author_field' ), 5 );
		add_action( 'edit_form_after_title', array( self::class, 'content_heading' ) );
		add_action( 'post_submitbox_misc_actions', array( self::class, 'mode_controls' ) );
		add_action( 'wp_after_insert_post', array( self::class, 'save_preferences' ), 5, 4 );
		add_filter( 'redirect_post_location', array( self::class, 'publication_redirect' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'publication_notice' ) );
		add_filter( 'removable_query_args', array( self::class, 'notice_query_args' ) );
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
	 * Labels the native title field without changing other post editors.
	 *
	 * @param string  $label Existing title placeholder.
	 * @param WP_Post $post Native post.
	 */
	public static function title_label( string $label, WP_Post $post ): string {
		return self::uses_form( $post ) ? __( 'Title', 'ehrman-blog-discovery' ) : $label;
	}

	/**
	 * Matches the writing area's typography to the publisher, preserving other editor styles.
	 *
	 * @param string $styles Existing comma-delimited TinyMCE stylesheets.
	 */
	public static function editor_styles( string $styles ): string {
		if ( null === self::screen_post() ) {
			return $styles;
		}
		$url = add_query_arg( 'ver', (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . 'assets/css/post-publisher-editor.css' ), EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/css/post-publisher-editor.css' );
		return ( '' === $styles ? '' : $styles . ',' ) . $url;
	}

	/**
	 * Hides media insertion only for this form's main post editor.
	 *
	 * @param array<string,mixed> $settings Native editor settings.
	 * @param string              $editor_id Editor identifier.
	 * @return array<string,mixed>
	 */
	public static function editor_settings( array $settings, string $editor_id ): array {
		if ( 'content' === $editor_id && null !== self::screen_post() ) {
			$settings['media_buttons'] = false;
		}
		return $settings;
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
		add_meta_box( 'ehrman-post-metadata', __( 'Post Metadata', 'ehrman-blog-discovery' ), array( self::class, 'metadata_box' ), 'post', 'normal', 'high' );
	}

	/**
	 * Places WordPress's author selector below Title, retaining its native save handling.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function author_field( WP_Post $post ): void {
		if ( ! self::uses_form( $post ) ) {
			return;
		}
		echo '<div id="ehrman-post-author">';
		post_author_meta_box( $post );
		echo '</div>';
	}

	/**
	 * Renders the saved mode beside native publishing controls.
	 *
	 * @param WP_Post $post Native post.
	 */
	public static function mode_controls( WP_Post $post ): void {
		if ( ! self::uses_form( $post ) ) {
			return;
		}
		$html = Template::render(
			'admin/post-publisher-mode.php',
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

	/** Renders the shared metadata component below the editor. */
	public static function metadata_box(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted template escapes labels and values.
		echo Template::render( 'admin/post-publisher.php' );
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
		if ( 'publish' === $post->post_status && self::uses_form( $post ) && 'automatic' === Post_Ingestion_Publishing_Settings::mode( $post_id ) ) {
			self::$automatic_publication = $post_id;
		}
	}

	/**
	 * Leaves the editor only after a successful first automatic publication.
	 *
	 * @param string $location WordPress's normal destination.
	 * @param int    $post_id Saved post ID.
	 */
	public static function publication_redirect( string $location, int $post_id ): string {
		if ( $post_id < 1 || self::$automatic_publication !== $post_id ) {
			return $location;
		}
		self::$automatic_publication = 0;
		$post                        = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! self::uses_form( $post )
			|| 'automatic' !== Post_Ingestion_Publishing_Settings::mode( $post_id ) ) {
			return $location;
		}
		return add_query_arg(
			array(
				'ehrman_published'     => $post_id,
				'ehrman_publish_nonce' => wp_create_nonce( 'ehrman-published-' . $post_id ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Keeps a publication confirmation out of later list navigation and reloads.
	 *
	 * @param string[] $args WordPress's disposable notice parameters.
	 * @return string[]
	 */
	public static function notice_query_args( array $args ): array {
		return array_merge( $args, array( 'ehrman_published', 'ehrman_publish_nonce' ) );
	}

	/** Shows the actual automatic job state, without waiting for or starting AI work. */
	public static function publication_notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'edit-post' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_id = isset( $_GET['ehrman_published'] ) && is_string( $_GET['ehrman_published'] ) ? absint( sanitize_text_field( wp_unslash( $_GET['ehrman_published'] ) ) ) : 0;
		$nonce   = isset( $_GET['ehrman_publish_nonce'] ) && is_string( $_GET['ehrman_publish_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['ehrman_publish_nonce'] ) ) : '';
		if ( $post_id < 1 || ! wp_verify_nonce( $nonce, 'ehrman-published-' . $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! self::uses_form( $post )
			|| 'automatic' !== Post_Ingestion_Publishing_Settings::mode( $post_id ) ) {
			return;
		}
		$draft   = ( new Post_Ingestion_Service() )->latest_for_post( $post_id );
		$message = __( 'Post published, but metadata needs attention.', 'ehrman-blog-discovery' );
		$class   = 'notice-warning';
		$url     = add_query_arg( 'search', $post->post_title, Post_Ingestion_Page::page_url() );
		if ( null !== $draft ) {
			$url = Post_Ingestion_Page::page_url( Database::integer( $draft['id'] ) );
			if ( Post_Ingestion_Automation::busy( $draft ) ) {
				$message = __( 'Post published. Metadata is being generated automatically.', 'ehrman-blog-discovery' );
				$class   = 'notice-success';
			} elseif ( 'approved' === $draft['status'] && in_array( $draft['embedding_status'], array( 'complete', 'not_applicable' ), true ) ) {
				$message = __( 'Post published. Metadata is ready.', 'ehrman-blog-discovery' );
				$class   = 'notice-success';
			}
		}
		?>
		<div id="ehrman-publication-notice" class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?> <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'View Metadata', 'ehrman-blog-discovery' ); ?></a></p>
		</div>
		<?php
	}
}
