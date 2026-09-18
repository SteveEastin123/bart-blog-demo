<?php
/**
 * Persistent WordPress administration visibility for post ingestion.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps unapproved search metadata visible until an administrator resolves it. */
final class Post_Ingestion_Admin_Status {
	private const COLUMN = 'ehrman_search_metadata';

	/**
	 * Cached number of proposals awaiting review.
	 *
	 * @var int|null
	 */
	private static ?int $review_count = null;

	/**
	 * Cached ingestion records for the posts on the current list screen.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private static ?array $records = null;

	/** Registers administration notices and post-list status hooks. */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_review_notice' ) );
		add_filter( 'manage_post_posts_columns', array( self::class, 'add_post_column' ) );
		add_action( 'manage_post_posts_custom_column', array( self::class, 'render_post_column' ), 10, 2 );
	}

	/**
	 * Adds the pending-review count to the Tools submenu label.
	 *
	 * @param string $label Base menu label.
	 */
	public static function menu_title( string $label ): string {
		$count = self::review_count();
		if ( $count < 1 ) {
			return $label;
		}
		return sprintf(
			'%1$s <span class="awaiting-mod count-%2$d"><span class="pending-count">%3$s</span></span>',
			esc_html( $label ),
			$count,
			esc_html( number_format_i18n( $count ) )
		);
	}

	/** Shows a persistent notice while proposals require review or approval. */
	public static function render_review_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null !== $screen && 'tools_page_ehrman-post-ingestion' === $screen->id ) {
			return;
		}
		$count = self::review_count();
		if ( $count < 1 ) {
			return;
		}
		$message = sprintf(
			/* translators: %s: number of posts awaiting review. */
			_n( '%s post is awaiting search-metadata review or approval.', '%s posts are awaiting search-metadata review or approval.', $count, 'ehrman-blog-discovery' ),
			number_format_i18n( $count )
		);
		?>
		<div class="notice notice-warning">
			<p><strong><?php echo esc_html( $message ); ?></strong> <a href="<?php echo esc_url( Post_Ingestion_Page::page_url() ); ?>"><?php echo esc_html__( 'Review pending posts', 'ehrman-blog-discovery' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Adds Search Metadata before the publication-date column.
	 *
	 * @param array<string,string> $columns Existing post-list columns.
	 * @return array<string,string> Updated columns.
	 */
	public static function add_post_column( array $columns ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}
		$updated = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated[ self::COLUMN ] = __( 'Search Metadata', 'ehrman-blog-discovery' );
			}
			$updated[ $key ] = $label;
		}
		if ( ! isset( $updated[ self::COLUMN ] ) ) {
			$updated[ self::COLUMN ] = __( 'Search Metadata', 'ehrman-blog-discovery' );
		}
		return $updated;
	}

	/**
	 * Renders one post's ingestion status and review link.
	 *
	 * @param string $column  Current column key.
	 * @param int    $post_id WordPress post identifier.
	 */
	public static function render_post_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$records = self::records_for_current_list( $post_id );
		$record  = $records[ $post_id ] ?? null;
		if ( ! is_array( $record ) ) {
			echo esc_html__( 'Not analyzed', 'ehrman-blog-discovery' );
			return;
		}

		$label = self::status_label( $post_id, $record );
		printf(
			'<a href="%1$s"><strong>%2$s</strong></a>',
			esc_url( Post_Ingestion_Page::page_url( Database::integer( $record['id'] ?? null ) ) ),
			esc_html( $label )
		);
	}

	/** Returns the cached number of proposals awaiting review. */
	private static function review_count(): int {
		if ( null === self::$review_count ) {
			self::$review_count = current_user_can( 'manage_options' ) ? ( new Post_Ingestion_Service() )->review_count() : 0;
		}
		return self::$review_count;
	}

	/**
	 * Loads ingestion records for all posts on the current list screen at once.
	 *
	 * @param int $post_id Current WordPress post identifier.
	 * @return array<int,array<string,mixed>> Records keyed by WordPress post ID.
	 */
	private static function records_for_current_list( int $post_id ): array {
		if ( null !== self::$records ) {
			return self::$records;
		}
		$post_ids = array( $post_id );
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			foreach ( $wp_query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$post_ids[] = $post->ID;
				}
			}
		}
		self::$records = ( new Post_Ingestion_Service() )->latest_for_posts( array_values( array_unique( $post_ids ) ) );
		return self::$records;
	}

	/**
	 * Returns the administrator-facing workflow label for one ingestion record.
	 *
	 * @param int                 $post_id WordPress post identifier.
	 * @param array<string,mixed> $record  Ingestion record.
	 */
	private static function status_label( int $post_id, array $record ): string {
		$status = sanitize_key( Database::text( $record['status'] ?? null ) );
		$post   = get_post( $post_id );
		if ( 'approved' !== $status && $post instanceof WP_Post && Post_Ingestion_Editor::source_changed( $post, $record ) ) {
			return __( 'Needs reanalysis', 'ehrman-blog-discovery' );
		}
		return match ( $status ) {
			'queued'    => __( 'Analysis queued', 'ehrman-blog-discovery' ),
			'analyzing' => __( 'Analysis running', 'ehrman-blog-discovery' ),
			'ready'     => __( 'Awaiting approval', 'ehrman-blog-discovery' ),
			'held'      => __( 'Review required', 'ehrman-blog-discovery' ),
			'approved'  => __( 'Indexed', 'ehrman-blog-discovery' ),
			'error'     => __( 'Error', 'ehrman-blog-discovery' ),
			default     => __( 'Not analyzed', 'ehrman-blog-discovery' ),
		};
	}
}
