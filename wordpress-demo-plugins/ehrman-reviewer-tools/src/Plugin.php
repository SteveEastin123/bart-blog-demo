<?php
/**
 * Optional demo page lifecycle and integration.
 *
 * @package EhrmanReviewerTools
 */

namespace EhrmanReviewerTools;

use EhrmanBlogDiscovery\Assets;
use EhrmanBlogDiscovery\Browse_Service;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Discovery_Markup;
use EhrmanBlogDiscovery\Discovery_Request;
use EhrmanBlogDiscovery\Plugin as Discovery_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps evaluation tooling optional, with no discovery-table writes. */
final class Plugin {
	// Reuse the original option so existing demo links and page IDs remain valid.
	private const PAGE_OPTION = 'ehrman_discovery_page_structure_review';

	/** Returns whether the matching production plugin is available. */
	private static function available(): bool {
		return defined( 'EHRMAN_DISCOVERY_VERSION' )
			&& version_compare( EHRMAN_DISCOVERY_VERSION, '0.10.4', '>=' )
			&& class_exists( Discovery_Plugin::class );
	}

	/** Registers demo-only hooks after the production plugin has loaded. */
	public static function register(): void {
		if ( ! self::available() ) {
			add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
			return;
		}
		add_shortcode( 'ehrman_structure_review', array( self::class, 'render' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_action( 'ehrman_demo_extra_search_links', array( self::class, 'navigation' ) );
		add_action( 'ehrman_demo_evaluation_note', array( self::class, 'evaluation_note' ) );
	}

	/** Explains an unavailable dependency without loading any search services. */
	public static function dependency_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Reviewer Tools requires Ehrman Blog Discovery 0.10.4 or newer to be active.', 'ehrman-reviewer-tools' )
				. '</p></div>';
		}
	}

	/** Creates or reconnects the demo page only when this add-on is activated. */
	public static function activate(): void {
		if ( ! self::available() ) {
			wp_die( esc_html__( 'Activate Ehrman Blog Discovery 0.10.4 or newer before Reviewer Tools.', 'ehrman-reviewer-tools' ) );
		}
		$page_id = Database::integer( get_option( self::PAGE_OPTION, 0 ) );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			$page = get_page_by_path( 'structure-review', OBJECT, 'page' );
		}
		if ( $page instanceof \WP_Post ) {
			if ( ! self::contains_shortcode( $page->post_content ) ) {
				wp_die( esc_html__( 'The Structure Review page contains unrelated content. Resolve the page conflict before activating Reviewer Tools.', 'ehrman-reviewer-tools' ) );
			}
			$result = wp_update_post(
				array(
					'ID'          => $page->ID,
					'post_status' => 'publish',
				),
				true
			);
		} else {
			$result = wp_insert_post(
				array(
					'post_title'     => 'Structure Review',
					'post_name'      => 'structure-review',
					'post_content'   => '[ehrman_structure_review]',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				),
				true
			);
		}
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		update_option( self::PAGE_OPTION, $result, false );
	}

	/**
	 * Recognizes existing content even before WordPress registers this add-on.
	 *
	 * @param string $content Page content.
	 */
	private static function contains_shortcode( string $content ): bool {
		return 1 === preg_match( '/' . get_shortcode_regex( array( 'ehrman_structure_review' ) ) . '/s', $content );
	}

	/** Hides the managed page without deleting it or touching discovery data. */
	public static function deactivate(): void {
		$value   = get_option( self::PAGE_OPTION, 0 );
		$page_id = is_scalar( $value ) ? absint( $value ) : 0;
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		if ( $page instanceof \WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status
			&& self::contains_shortcode( $page->post_content ) ) {
			wp_update_post(
				array(
					'ID'          => $page_id,
					'post_status' => 'draft',
				)
			);
		}
	}

	/** Returns the published demo page URL, or an empty string. */
	private static function page_url(): string {
		$page_id = Database::integer( get_option( self::PAGE_OPTION, 0 ) );
		$url     = $page_id > 0 && 'publish' === get_post_status( $page_id ) ? get_permalink( $page_id ) : false;
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Excludes the review page from search-engine indexing.
	 *
	 * @param array<string,bool|string> $robots Existing directives.
	 * @return array<string,bool|string> Updated directives.
	 */
	public static function robots( array $robots ): array {
		$page_id = Database::integer( get_option( self::PAGE_OPTION, 0 ) );
		$post    = get_post();
		if ( ( $page_id > 0 && is_page( $page_id ) ) || ( is_singular() && $post instanceof \WP_Post && self::contains_shortcode( $post->post_content ) ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Renders the existing outline or index with its optional assets. */
	public static function render(): string {
		Assets::enqueue();
		if ( 'complete' !== Discovery_Plugin::status_data()['import_state'] ) {
			return '<div class="ebd-notice">' . esc_html__( 'Discovery data has not been imported yet.', 'ehrman-blog-discovery' ) . '</div>';
		}
		wp_enqueue_style( 'ehrman-reviewer-tools', EHRMAN_REVIEWER_URL . 'assets/css/reviewer.css', array( 'ehrman-blog-discovery' ), EHRMAN_REVIEWER_VERSION );
		wp_enqueue_script( 'ehrman-reviewer-tools', EHRMAN_REVIEWER_URL . 'assets/js/reviewer.js', array( 'ehrman-blog-discovery' ), EHRMAN_REVIEWER_VERSION, true );
		$request  = new Discovery_Request();
		$renderer = new Structure_Review_Renderer( new Browse_Service(), new Discovery_Markup() );
		$url      = self::page_url();
		return $renderer->render(
			'2' === $request->value( 'ebd_path' ) ? 2 : 1,
			$request->key( 'ebd_view' ),
			'' === $url ? home_url( '/' ) : $url
		);
	}

	/** Adds a menu link only while the optional demo page is published. */
	public static function navigation(): void {
		$url = self::page_url();
		if ( '' === $url ) {
			return;
		}
		echo '<a href="' . esc_url( add_query_arg( 'ebd_path', '1', $url ) ) . '"'
			. ( is_page( Database::integer( get_option( self::PAGE_OPTION, 0 ) ) ) ? ' aria-current="page"' : '' ) . '>'
			. esc_html__( 'Reviewer Tools', 'ehrman-reviewer-tools' ) . '</a>';
	}

	/** Adds the demo landing-page explanation only when the tools are available. */
	public static function evaluation_note(): void {
		if ( '' === self::page_url() ) {
			return;
		}
		?>
		<aside class="ehrman-reviewer-note" aria-label="<?php esc_attr_e( 'Reviewer tools evaluation note', 'ehrman-reviewer-tools' ); ?>">
			<span class="ehrman-reviewer-label"><?php esc_html_e( 'Evaluation only', 'ehrman-reviewer-tools' ); ?></span>
			<p><?php echo wp_kses_post( __( '<strong>Reviewer Tools</strong> provides expandable outlines of both Browse Topics structures, along with complete category and topic indexes for reviewing how the content is organized. These tools are included only for evaluation and will not appear in the final version; they are intended to confirm that subject areas, categories, and topics are organized appropriately before the structure is finalized.', 'ehrman-reviewer-tools' ) ); ?></p>
		</aside>
		<?php
	}
}
