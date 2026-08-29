<?php
/**
 * Main plugin orchestration.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers plugin services, administration tools, and public status output. */
final class Plugin {

	/**
	 * Singleton plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * REST API controller.
	 *
	 * @var Rest_Controller
	 */
	private Rest_Controller $rest_controller;

	/**
	 * Authoritative JSON importer.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Public page and shortcode controller.
	 *
	 * @var Page_Controller
	 */
	private Page_Controller $page_controller;

	/** Creates the plugin service graph. */
	private function __construct() {
		$this->rest_controller = new Rest_Controller();
		$this->importer        = new Importer();
		$this->page_controller = new Page_Controller();
	}

	/**
	 * Returns the singleton plugin instance.
	 *
	 * @return self Plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Registers WordPress hooks, shortcodes, REST routes, and WP-CLI commands. */
	public function register(): void {
		Database::maybe_upgrade();
		update_option( 'ehrman_discovery_plugin_version', EHRMAN_DISCOVERY_VERSION, false );
		$this->page_controller->register();
		add_action( 'init', array( $this, 'maybe_ensure_pages' ), 20 );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_ehrman_discovery_import', array( $this, 'handle_admin_import' ) );
		AI_Analytics_Page::register();
		add_shortcode( 'ehrman_discovery_status', array( $this, 'render_status_shortcode' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ehrman-discovery', Cli_Command::class );
		}
	}

	/** Recreates managed pages after a plugin version change. */
	public function maybe_ensure_pages(): void {
		if ( self::scalar_string( get_option( 'ehrman_discovery_pages_version', '' ) ) !== EHRMAN_DISCOVERY_VERSION ) {
			Page_Controller::ensure_pages();
		}
	}

	/** Registers the administration page under Tools. */
	public function register_admin_page(): void {
		add_management_page(
			__( 'Ehrman Discovery', 'ehrman-blog-discovery' ),
			__( 'Ehrman Discovery', 'ehrman-blog-discovery' ),
			'manage_options',
			'ehrman-blog-discovery',
			array( $this, 'render_admin_page' )
		);
	}

	/** Renders the administration status and import page. */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::status_data();
		$notice = get_transient( 'ehrman_discovery_notice_' . get_current_user_id() );
		if ( false !== $notice ) {
			delete_transient( 'ehrman_discovery_notice_' . get_current_user_id() );
		}
		$notice_class = is_array( $notice ) && ! empty( $notice['success'] ) ? 'notice-success' : 'notice-error';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Ehrman Blog Discovery', 'ehrman-blog-discovery' ); ?></h1>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
					<p><?php echo esc_html( self::scalar_string( $notice['message'] ?? '' ) ); ?></p>
				</div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'The plugin is active and its dedicated MySQL search-index tables are installed.', 'ehrman-blog-discovery' ); ?></p>
			<table class="widefat striped" style="max-width: 760px">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plugin version', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( $status['plugin_version'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Schema version', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( $status['schema_version'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Database connection', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo $status['database_connected'] ? esc_html__( 'Connected', 'ehrman-blog-discovery' ) : esc_html__( 'Unavailable', 'ehrman-blog-discovery' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Import state', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( $status['import_state'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Imported posts', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['counts']['external_posts'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Topics', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['counts']['topics'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Secondary keywords', 'ehrman-blog-discovery' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['counts']['keywords'] ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<h2><?php echo esc_html__( 'AI analytics', 'ehrman-blog-discovery' ); ?></h2>
			<p><?php echo esc_html__( 'Questions, feedback, token usage, refinement activity, and estimated costs are consolidated on the AI Search Analytics page.', 'ehrman-blog-discovery' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=ehrman-ai-analytics' ) ); ?>"><?php echo esc_html__( 'Open AI Search Analytics', 'ehrman-blog-discovery' ); ?></a></p>
			<h2><?php echo esc_html__( 'Authoritative JSON import', 'ehrman-blog-discovery' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					$this->importer->sources_available()
						? __( 'All five authoritative JSON files are available.', 'ehrman-blog-discovery' )
						: __( 'One or more authoritative JSON files are unavailable.', 'ehrman-blog-discovery' )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ehrman_discovery_import">
				<?php wp_nonce_field( 'ehrman_discovery_import' ); ?>
				<?php
				submit_button(
					__( 'Import authoritative JSON', 'ehrman-blog-discovery' ),
					'primary',
					'submit',
					true,
					$this->importer->sources_available() ? array() : array( 'disabled' => 'disabled' )
				);
				?>
			</form>
		</div>
		<?php
	}

	/** Handles a nonce-protected administrative import request. */
	public function handle_admin_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import discovery data.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( 'ehrman_discovery_import' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		try {
			$summary = $this->importer->import( true );
			$message = sprintf(
				/* translators: 1: post count, 2: topic count, 3: keyword count */
				__( 'Imported %1$s posts, %2$s topics, and %3$s keywords.', 'ehrman-blog-discovery' ),
				number_format_i18n( (int) $summary['counts']['external_posts'] ),
				number_format_i18n( (int) $summary['counts']['topics'] ),
				number_format_i18n( (int) $summary['counts']['keywords'] )
			);
			set_transient(
				'ehrman_discovery_notice_' . get_current_user_id(),
				array(
					'success' => true,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
		} catch ( \Throwable $error ) {
			set_transient(
				'ehrman_discovery_notice_' . get_current_user_id(),
				array(
					'success' => false,
					'message' => sanitize_text_field( $error->getMessage() ),
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( admin_url( 'tools.php?page=ehrman-blog-discovery' ) );
		exit;
	}

	/**
	 * Renders the optional public status shortcode.
	 *
	 * @return string Status component markup.
	 */
	public function render_status_shortcode(): string {
		Assets::enqueue();
		wp_localize_script(
			'ehrman-blog-discovery',
			'EhrmanDiscoveryStatus',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'ehrman-discovery/v1/status' ) ),
				'loadingText' => __( 'Checking status...', 'ehrman-blog-discovery' ),
				'errorText'   => __( 'The status endpoint could not be reached.', 'ehrman-blog-discovery' ),
			)
		);

		$status = self::status_data();

		ob_start();
		?>
		<section class="ebd-status" aria-labelledby="ebd-status-heading">
			<h2 id="ebd-status-heading"><?php echo esc_html__( 'Ehrman Blog Discovery', 'ehrman-blog-discovery' ); ?></h2>
			<p><?php echo esc_html__( 'The local WordPress and MySQL foundation is running.', 'ehrman-blog-discovery' ); ?></p>
			<dl class="ebd-status__details">
				<div>
					<dt><?php echo esc_html__( 'Plugin', 'ehrman-blog-discovery' ); ?></dt>
					<dd><?php echo esc_html( $status['plugin_version'] ); ?></dd>
				</div>
				<div>
					<dt><?php echo esc_html__( 'Database', 'ehrman-blog-discovery' ); ?></dt>
					<dd><?php echo $status['database_connected'] ? esc_html__( 'Connected', 'ehrman-blog-discovery' ) : esc_html__( 'Unavailable', 'ehrman-blog-discovery' ); ?></dd>
				</div>
				<div>
					<dt><?php echo esc_html__( 'Search data', 'ehrman-blog-discovery' ); ?></dt>
					<dd><?php echo esc_html( $status['import_state'] ); ?></dd>
				</div>
			</dl>
			<button class="ebd-status__refresh" type="button" data-ebd-status-refresh>
				<?php echo esc_html__( 'Refresh status', 'ehrman-blog-discovery' ); ?>
			</button>
			<p class="ebd-status__message" data-ebd-status-message aria-live="polite"></p>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns plugin, database, import, and record-count status.
	 *
	 * @return array{
	 *     plugin_version:string,
	 *     schema_version:string,
	 *     database_connected:bool,
	 *     database_version:string,
	 *     import_state:string,
	 *     counts:array<string,int>
	 * } Status payload.
	 */
	public static function status_data(): array {
		$wpdb = Database::client();

		$connection_check = $wpdb->get_var( 'SELECT 1' );
		$import_status    = get_option( 'ehrman_discovery_import_status', array() );
		$import_state     = is_array( $import_status ) && isset( $import_status['state'] )
			? self::scalar_string( $import_status['state'], 'not_imported' )
			: 'not_imported';
		$schema_version   = self::scalar_string(
			get_option( 'ehrman_discovery_schema_version', EHRMAN_DISCOVERY_SCHEMA_VERSION ),
			EHRMAN_DISCOVERY_SCHEMA_VERSION
		);

		return array(
			'plugin_version'     => EHRMAN_DISCOVERY_VERSION,
			'schema_version'     => $schema_version,
			'database_connected' => '1' === self::scalar_string( $connection_check ),
			'database_version'   => (string) $wpdb->db_version(),
			'import_state'       => $import_state,
			'counts'             => Database::counts(),
		);
	}

	/**
	 * Converts scalar option and transient values to text.
	 *
	 * @param mixed  $value    Candidate value.
	 * @param string $fallback Value returned for compound values.
	 * @return string Scalar text or the fallback.
	 */
	private static function scalar_string( $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Formats a small estimated US-dollar amount without rounding it to zero.
	 *
	 * @param float $amount   US-dollar amount.
	 * @param int   $decimals Number of decimal places.
	 */
	private static function format_usd( float $amount, int $decimals = 4 ): string {
		return '$' . number_format_i18n( $amount, $decimals );
	}
}
