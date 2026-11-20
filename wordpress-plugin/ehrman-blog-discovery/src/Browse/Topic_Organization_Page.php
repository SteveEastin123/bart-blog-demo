<?php
/**
 * Protected Topics & Categories administration page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shares search and relationship forms across Topics & Categories tabs. */
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
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Returns a local administration URL.
	 *
	 * @param array<string,int|string> $args Navigation parameters.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Loads the category picker only on this page.
	 *
	 * @param string $hook Current administration screen.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Admin_Menu::is_screen( self::SLUG, $hook ) || ! current_user_can( 'manage_options' ) ) {
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
		$tab   = self::tab();
		$scope = self::scope( $tab );
		check_admin_referer( 'ehrman_' . $scope . '_organization' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalars are sanitized below; category IDs are strictly validated before use.
		$input           = Database::associative_row( wp_unslash( $_POST ) ) ?? array();
		$id_field        = $scope . '_id';
		$id              = self::positive_id( $input[ $id_field ] ?? '0' );
		$ids             = is_array( $input['category_ids'] ?? null ) ? array_map( array( self::class, 'positive_id' ), array_values( $input['category_ids'] ) ) : array();
		self::$submitted = array(
			'id'               => $id,
			'name'             => sanitize_text_field( Database::text( $input['name'] ?? null ) ),
			'description'      => sanitize_textarea_field( Database::text( $input['description'] ?? null ) ),
			'category_ids'     => $ids,
			'subject_area_ids' => self::selected_ids( $input, 'subject_area_ids' ),
			'topic_ids'        => self::selected_ids( $input, 'topic_ids' ),
			'revision'         => sanitize_text_field( Database::text( $input['revision'] ?? null ) ),
			'action'           => sanitize_key( Database::text( $input['organization_action'] ?? null ) ),
		);
		$service         = self::service( $tab );
		$intent          = Database::text( $input['organization_action'] ?? null );
		if ( 'create' === $intent && '0' === Database::text( $input[ $id_field ] ?? null ) ) {
			$result = $service instanceof Category_Organization_Service
				? $service->create( self::$submitted['name'], self::$submitted['description'], self::$submitted['subject_area_ids'], self::$submitted['topic_ids'] )
				: $service->create( self::$submitted['name'], self::$submitted['description'], $ids );
		} elseif ( 'description' === $intent && $id > 0 ) {
			$result = ( new Organization_Description_Service() )->update( $tab, $id, self::$submitted['description'], self::$submitted['revision'] );
		} elseif ( 'links' === $intent && $id > 0 && $service instanceof Category_Organization_Service ) {
			$result = $service->update_links( $id, self::$submitted['subject_area_ids'], self::$submitted['topic_ids'], self::$submitted['revision'] );
		} elseif ( 'categories' === $intent && $id > 0 && ! $service instanceof Category_Organization_Service ) {
			$result = $service->update_categories( $id, $ids, self::$submitted['revision'] );
		} else {
			$result = new \WP_Error( 'ehrman_topic_action', __( 'Invalid organization action. Reload the page and try again.', 'ehrman-blog-discovery' ) );
		}
		if ( is_wp_error( $result ) ) {
			self::$error = $result->get_error_message();
			return;
		}
		wp_safe_redirect(
			self::url(
				array(
					'edit'  => $result,
					'saved' => 'create' === $intent ? 'created' : $intent,
					'tab'   => $tab,
				)
			)
		);
		exit;
	}

	/** Renders search, the optional directory, or one category-link form. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; each value is normalized below.
		$input         = Database::associative_row( wp_unslash( $_GET ) ) ?? array();
		$tab           = self::tab();
		$subject_areas = 'subject-areas' === $tab;
		$category_tab  = 'categories' === $tab;
		$service       = self::service( $tab );
		$id            = null !== self::$submitted ? Database::integer( self::$submitted['id'] ) : self::positive_id( $input['edit'] ?? null );
		$all           = match ( true ) {
			$service instanceof Category_Organization_Service => $service->categories(),
			$service instanceof Subject_Area_Organization_Service => $service->areas(),
			default => $service->topics(),
		};
		$matches  = array_values( array_filter( $all, static fn( array $row ): bool => Database::integer( $row['id'] ) === $id ) );
		$topic    = $matches[0] ?? null;
		$creating = 0 === $id && ( null !== self::$submitted || 'new' === Database::text( $input['action'] ?? null ) );
		$error    = self::$error;
		if ( $id > 0 && ( null === $topic || ( 'topics' === $tab && ! Topic_Organization_Service::editable( $topic ) ) ) ) {
			$error = match ( $tab ) {
				'subject-areas' => __( 'This subject area is unavailable for category changes.', 'ehrman-blog-discovery' ),
				'categories' => __( 'This category is unavailable for relationship changes.', 'ehrman-blog-discovery' ),
				default => __( 'This topic is unavailable for category changes.', 'ehrman-blog-discovery' ),
			};
			$topic = null;
		}
		$editing_description = null !== $topic && ( null !== self::$submitted ? 'description' === self::$submitted['action'] : 'edit' === Database::text( $input['description'] ?? null ) );
		$query               = trim( sanitize_text_field( Database::text( $input['s'] ?? null ) ) );
		$browse              = 'all' === Database::text( $input['view'] ?? null );
		$topics              = array_values( array_filter( $all, static fn( array $row ): bool => '' === $query || false !== mb_stripos( Database::text( $row['name'] ), $query ) ) );
		$pages               = max( 1, (int) ceil( count( $topics ) / 25 ) );
		$page                = min( $pages, max( 1, self::positive_id( $input['pagenum'] ?? null ) ) );
		$linked              = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $topic['categories'] ?? null ) );
		$context             = array(
			'tab'                 => $tab,
			'category_tab'        => $category_tab,
			'scope'               => self::scope( $tab ),
			'subject_areas'       => $subject_areas,
			'url_args'            => array( 'tab' => $tab ),
			'editing_description' => $editing_description,
			'error'               => $error,
			'saved'               => sanitize_key( Database::text( $input['saved'] ?? null ) ),
			'creating'            => $creating,
			'topic'               => $topic,
			'categories'          => ( new Topic_Organization_Service() )->categories(),
			'area_options'        => $category_tab ? ( new Subject_Area_Organization_Service() )->areas() : array(),
			'topic_options'       => $category_tab ? array_values( array_filter( ( new Topic_Organization_Service() )->topics(), array( Topic_Organization_Service::class, 'editable' ) ) ) : array(),
			'values'              => self::$submitted ?? array(
				'name'             => '',
				'description'      => $editing_description ? Database::text( $topic['description'] ) : '',
				'category_ids'     => $linked,
				'subject_area_ids' => array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $topic['subject_areas'] ?? null ) ),
				'topic_ids'        => array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $topic['topics'] ?? null ) ),
				'revision'         => null !== $topic ? ( $editing_description ? Organization_Description_Service::revision( $topic ) : $service::revision( $topic ) ) : '',
			),
			'query'               => $query,
			'browse_all'          => $browse,
			'show_list'           => $browse || '' !== $query,
			'directory'           => 'topics' === $tab ? array_values( array_filter( $all, array( Topic_Organization_Service::class, 'editable' ) ) ) : $all,
			'topics'              => array_slice( $topics, ( $page - 1 ) * 25, 25 ),
			'count'               => count( $topics ),
			'page_number'         => $page,
			'total_pages'         => $pages,
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic values are escaped in the trusted template.
		echo Template::render( 'admin/search-organization.php', $context );
	}

	/** Selects only known tabs; POST actions use the same explicit URL scope. */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; writes check a tab-specific nonce.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( in_array( $tab, array( 'subject-areas', 'categories', 'topics' ), true ) ) {
			return $tab;
		}
		// Preserve older topic deep links; the unscoped landing page now opens Subject Areas.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parameter presence is used only for read-only routing.
		if ( ! isset( $_GET['tab'] ) && array_intersect( array( 'edit', 'action', 's', 'view', 'pagenum' ), array_keys( $_GET ) ) ) {
			return 'topics';
		}
		return 'subject-areas';
	}

	/**
	 * Selects a tab's service.
	 *
	 * @param string $tab Validated tab.
	 */
	private static function service( string $tab ): Topic_Organization_Service|Subject_Area_Organization_Service|Category_Organization_Service {
		return match ( $tab ) {
			'subject-areas' => new Subject_Area_Organization_Service(),
			'categories' => new Category_Organization_Service(),
			default => new Topic_Organization_Service(),
		};
	}

	/**
	 * Distinguishes nonce and record-ID fields across tabs.
	 *
	 * @param string $tab Validated tab.
	 */
	private static function scope( string $tab ): string {
		return match ( $tab ) {
			'subject-areas' => 'subject_area',
			'categories' => 'category',
			default => 'topic',
		};
	}

	/**
	 * Normalizes an untrusted list of relationship IDs without accepting malformed IDs.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @param string              $key Field name.
	 * @return list<int> Selected IDs.
	 */
	private static function selected_ids( array $input, string $key ): array {
		return is_array( $input[ $key ] ?? null ) ? array_map( array( self::class, 'positive_id' ), array_values( $input[ $key ] ) ) : array();
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
