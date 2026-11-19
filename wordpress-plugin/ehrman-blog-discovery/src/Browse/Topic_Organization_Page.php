<?php
/**
 * Protected Search Organization administration page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides topic creation and category-link editing without editing existing definitions. */
final class Topic_Organization_Page {
	private const SLUG = 'ehrman-search-organization';
	/**
	 * Failed form values, retained for this request only.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $submitted = null;
	/**
	 * Validation error for this request.
	 *
	 * @var string
	 */
	private static string $error = '';

	/** Registers only authenticated administration hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'load-tools_page_' . self::SLUG, array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Adds Search Organization beneath Tools. */
	public static function add_page(): void {
		add_management_page( __( 'Search Organization', 'ehrman-blog-discovery' ), __( 'Search Organization', 'ehrman-blog-discovery' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Returns a local administration URL.
	 *
	 * @param array<string,int|string> $args Navigation parameters.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Loads the category picker only on this page.
	 *
	 * @param string $hook Current administration screen.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( array( 'js', 'css' ) as $extension ) {
			$file    = 'assets/' . $extension . '/search-organization.' . $extension;
			$version = (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . $file );
			if ( 'js' === $extension ) {
				wp_enqueue_script( 'ehrman-search-organization', EHRMAN_DISCOVERY_PLUGIN_URL . $file, array( 'wp-i18n' ), $version, true );
			} else {
				wp_enqueue_style( 'ehrman-search-organization', EHRMAN_DISCOVERY_PLUGIN_URL . $file, array( 'dashicons' ), $version );
			}
		}
	}

	/** Handles a nonce-protected POST; validation errors keep the form populated. */
	public static function save(): void {
		self::$submitted = null;
		self::$error     = '';

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage search organization.', 'ehrman-blog-discovery' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ehrman_topic_organization' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalars are sanitized below; category IDs are strictly validated before use.
		$input           = Database::associative_row( wp_unslash( $_POST ) ) ?? array();
		$id              = self::positive_id( $input['topic_id'] ?? '0' );
		$ids             = is_array( $input['category_ids'] ?? null ) ? array_map( array( self::class, 'positive_id' ), array_values( $input['category_ids'] ) ) : array();
		self::$submitted = array(
			'id'           => $id,
			'name'         => sanitize_text_field( Database::text( $input['name'] ?? null ) ),
			'description'  => sanitize_textarea_field( Database::text( $input['description'] ?? null ) ),
			'category_ids' => $ids,
			'revision'     => sanitize_text_field( Database::text( $input['revision'] ?? null ) ),
		);
		$service         = new Topic_Organization_Service();
		$intent          = Database::text( $input['organization_action'] ?? null );
		if ( 'create' === $intent && '0' === Database::text( $input['topic_id'] ?? null ) ) {
			$result = $service->create( self::$submitted['name'], self::$submitted['description'], $ids );
		} elseif ( 'categories' === $intent && $id > 0 ) {
			$result = $service->update_categories( $id, $ids, self::$submitted['revision'] );
		} else {
			$result = new \WP_Error( 'ehrman_topic_action', __( 'Invalid topic action. Reload the page and try again.', 'ehrman-blog-discovery' ) );
		}
		if ( is_wp_error( $result ) ) {
			self::$error = $result->get_error_message();
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'edit'  => $result,
					'saved' => 'create' === $intent ? 'created' : 'categories',
				)
			)
		);
		exit;
	}

	/** Renders topic search, the optional directory, or one topic form. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; each value is normalized below.
		$input    = Database::associative_row( wp_unslash( $_GET ) ) ?? array();
		$service  = new Topic_Organization_Service();
		$id       = null !== self::$submitted ? Database::integer( self::$submitted['id'] ) : self::positive_id( $input['edit'] ?? null );
		$topic    = $id > 0 ? $service->topic( $id ) : null;
		$creating = 0 === $id && ( null !== self::$submitted || 'new' === Database::text( $input['action'] ?? null ) );
		$error    = self::$error;
		if ( $id > 0 && ( null === $topic || ! Topic_Organization_Service::editable( $topic ) ) ) {
			$error = __( 'This topic is unavailable for category changes.', 'ehrman-blog-discovery' );
			$topic = null;
		}
		$query   = trim( sanitize_text_field( Database::text( $input['s'] ?? null ) ) );
		$all     = $service->topics();
		$browse  = 'all' === Database::text( $input['view'] ?? null );
		$topics  = array_values( array_filter( $all, static fn( array $row ): bool => '' === $query || false !== mb_stripos( Database::text( $row['name'] ), $query ) ) );
		$pages   = max( 1, (int) ceil( count( $topics ) / 25 ) );
		$page    = min( $pages, max( 1, self::positive_id( $input['pagenum'] ?? null ) ) );
		$linked  = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $topic['categories'] ?? null ) );
		$context = array(
			'error'       => $error,
			'saved'       => sanitize_key( Database::text( $input['saved'] ?? null ) ),
			'creating'    => $creating,
			'topic'       => $topic,
			'categories'  => $service->categories(),
			'values'      => self::$submitted ?? array(
				'name'         => '',
				'description'  => '',
				'category_ids' => $linked,
				'revision'     => null !== $topic ? Topic_Organization_Service::revision( $topic ) : '',
			),
			'query'       => $query,
			'browse_all'  => $browse,
			'show_list'   => $browse || '' !== $query,
			'directory'   => array_values( array_filter( $all, array( Topic_Organization_Service::class, 'editable' ) ) ),
			'topics'      => array_slice( $topics, ( $page - 1 ) * 25, 25 ),
			'count'       => count( $topics ),
			'page_number' => $page,
			'total_pages' => $pages,
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
		echo Template::render( 'admin/search-organization.php', $context );
	}

	/**
	 * Rejects non-scalar, negative, fractional and malformed IDs.
	 *
	 * @param mixed $value Untrusted ID.
	 */
	private static function positive_id( $value ): int {
		$text = Database::text( $value );
		return preg_match( '/^[1-9][0-9]*$/D', $text ) && (float) $text <= PHP_INT_MAX ? (int) $text : 0;
	}
}
