<?php
/**
 * Redirect and status confirmation after native automatic publication.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reports existing metadata work without starting or waiting for generation. */
final class Post_Publication_Confirmation {
	/**
	 * First automatic publication in this native form submission, not later updates.
	 *
	 * @var int
	 */
	private static int $automatic_publication = 0;

	/** Registers the native redirect and dismissible Posts-list confirmation. */
	public static function register(): void {
		add_filter( 'redirect_post_location', array( self::class, 'publication_redirect' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'publication_notice' ) );
		add_filter( 'removable_query_args', array( self::class, 'notice_query_args' ) );
	}

	/**
	 * Records a first automatic publication already validated by the publisher's save hook.
	 *
	 * @param int $post_id Native post ID, retained only for the current request.
	 */
	public static function remember_publication( int $post_id ): void {
		self::$automatic_publication = $post_id;
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
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! Post_Publisher::uses_form( $post )
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
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! Post_Publisher::uses_form( $post )
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
