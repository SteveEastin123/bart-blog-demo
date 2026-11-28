<?php
/**
 * Protected Manage Topics administration page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shares search and relationship forms across Manage Topics tabs. */
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
		add_action( 'admin_init', array( Organization_Deletion_Settings::class, 'register_setting' ) );
		add_action( 'rest_api_init', array( Organization_Deletion_Settings::class, 'register_setting' ) );
		Organization_Export_Page::register();
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
	 * Loads the organization pickers only on this page.
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
		if ( 'export' === self::tab() ) {
			Organization_Export_Page::enqueue();
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
		$tab = self::tab();
		if ( 'export' === $tab ) {
			wp_die( esc_html__( 'Use the download buttons to export the structure.', 'ehrman-blog-discovery' ), '', array( 'response' => 400 ) );
		}
		$scope = self::scope( $tab );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalars are sanitized below; category IDs are strictly validated before use.
		$input  = Database::associative_row( wp_unslash( $_POST ) ) ?? array();
		$intent = Database::text( $input['organization_action'] ?? null );
		check_admin_referer( 'ehrman_' . $scope . '_organization' . ( 'delete' === $intent ? '_delete' : '' ) );
		$id_field        = $scope . '_id';
		$id              = self::positive_id( $input[ $id_field ] ?? '0' );
		$ids             = self::selected_ids( $input, 'category_ids' );
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
		if ( 'delete' === $intent && $id > 0 && 'yes' === ( $input['confirm_delete'] ?? null ) ) {
			$result = ( new Organization_Deletion_Service() )->delete( $tab, $id, self::$submitted['revision'] );
		} elseif ( 'create' === $intent && '0' === Database::text( $input[ $id_field ] ?? null ) ) {
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
					'edit'  => 'delete' === $intent ? 0 : $result,
					'saved' => 'create' === $intent ? 'created' : $intent,
					'tab'   => $tab,
				)
			)
		);
		exit;
	}

	/** Renders search, the optional directory, or one organization form. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 'export' === self::tab() ) {
			Organization_Export_Page::render();
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; each value is normalized below.
		$input         = Database::associative_row( wp_unslash( $_GET ) ) ?? array();
		$tab           = self::tab();
		$subject_areas = 'subject-areas' === $tab;
		$category_tab  = 'categories' === $tab;
		$service       = self::service( $tab );
		$id            = null !== self::$submitted ? Database::integer( self::$submitted['id'] ) : self::positive_id( $input['edit'] ?? null );
		$item          = $id > 0 ? match ( true ) {
			$service instanceof Category_Organization_Service => $service->category( $id ),
			$service instanceof Subject_Area_Organization_Service => $service->area( $id ),
			default => $service->topic( $id ),
		} : null;
		$creating = 0 === $id && ( null !== self::$submitted || 'new' === Database::text( $input['action'] ?? null ) );
		$error    = self::$error;
		if ( $id > 0 && ( null === $item || ( 'topics' === $tab && ! Topic_Organization_Service::editable( $item ) ) ) ) {
			$error = match ( $tab ) {
				'subject-areas' => __( 'This subject area is unavailable for category changes.', 'ehrman-blog-discovery' ),
				'categories' => __( 'This category is unavailable for relationship changes.', 'ehrman-blog-discovery' ),
				default => __( 'This topic is unavailable for category changes.', 'ehrman-blog-discovery' ),
			};
			$item = null;
		}
		$editing_description = null !== $item && ( null !== self::$submitted ? 'description' === self::$submitted['action'] : 'edit' === Database::text( $input['description'] ?? null ) );
		$confirming_delete   = null !== $item && ( 'delete' === Database::text( $input['action'] ?? null ) || 'delete' === ( self::$submitted['action'] ?? '' ) );
		$is_form             = $creating || null !== $item;
		$relationship_form   = $is_form && ! $editing_description && ! $confirming_delete;
		// Direct edits need only the selected record; directories belong to the search view.
		$all = $is_form ? array() : match ( true ) {
			$service instanceof Category_Organization_Service => $service->categories(),
			$service instanceof Subject_Area_Organization_Service => $service->areas(),
			default => $service->topics(),
		};
		$query   = trim( sanitize_text_field( Database::text( $input['s'] ?? null ) ) );
		$browse  = 'all' === Database::text( $input['view'] ?? null );
		$items   = array_values( array_filter( $all, static fn( array $row ): bool => '' === $query || false !== mb_stripos( Database::text( $row['name'] ), $query ) ) );
		$pages   = max( 1, (int) ceil( count( $items ) / 25 ) );
		$page    = min( $pages, max( 1, self::positive_id( $input['pagenum'] ?? null ) ) );
		$linked  = array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $item['categories'] ?? null ) );
		$context = array(
			'deletion'            => null !== $item ? ( new Organization_Deletion_Service() )->preview( $tab, $id ) : null,
			'confirming_delete'   => $confirming_delete,
			'tab'                 => $tab,
			'category_tab'        => $category_tab,
			'scope'               => self::scope( $tab ),
			'subject_areas'       => $subject_areas,
			'url_args'            => array( 'tab' => $tab ),
			'labels'              => Organization_Page_Labels::for_tab( $tab ),
			'item_id'             => Database::integer( $item['id'] ?? null ),
			'is_form'             => $is_form,
			'edit_label'          => __( 'Edit Relationships', 'ehrman-blog-discovery' ),
			'editing_description' => $editing_description,
			'error'               => $error,
			'saved'               => sanitize_key( Database::text( $input['saved'] ?? null ) ),
			'creating'            => $creating,
			'item'                => $item,
			'categories'          => $relationship_form && ! $category_tab ? ( new Topic_Organization_Service() )->categories() : array(),
			'area_options'        => $relationship_form && $category_tab ? ( new Subject_Area_Organization_Service() )->options() : array(),
			'topic_options'       => $relationship_form && $category_tab ? ( new Topic_Organization_Service() )->options() : array(),
			'values'              => self::$submitted ?? array(
				'name'             => '',
				'description'      => $editing_description ? Database::text( $item['description'] ) : '',
				'category_ids'     => $linked,
				'subject_area_ids' => array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $item['subject_areas'] ?? null ) ),
				'topic_ids'        => array_map( static fn( array $row ): int => Database::integer( $row['id'] ), Database::associative_rows( $item['topics'] ?? null ) ),
				'revision'         => null !== $item ? ( $editing_description ? Organization_Description_Service::revision( $item ) : $service::revision( $item ) ) : '',
			),
			'query'               => $query,
			'browse_all'          => $browse,
			'show_list'           => $browse || '' !== $query,
			'directory'           => 'topics' === $tab ? array_values( array_filter( $all, array( Topic_Organization_Service::class, 'editable' ) ) ) : $all,
			'items'               => array_slice( $items, ( $page - 1 ) * 25, 25 ),
			'count'               => count( $items ),
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
		if ( in_array( $tab, array( 'subject-areas', 'categories', 'topics', 'export' ), true ) ) {
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
		if ( ! array_key_exists( $key, $input ) ) {
			return array();
		}
		return is_array( $input[ $key ] ) ? array_map( array( self::class, 'positive_id' ), array_values( $input[ $key ] ) ) : array( 0 );
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
