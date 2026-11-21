<?php
/**
 * Administrator-only organization downloads.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps export requests separate from organization writes. */
final class Organization_Export_Page {
	/** Registers only authenticated download requests. */
	public static function register(): void {
		add_action( 'admin_post_ehrman_organization_export', array( self::class, 'download' ) );
	}

	/** Loads the local PDF library only on the Export tab. */
	public static function enqueue(): void {
		wp_enqueue_script( 'ehrman-pdfmake', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/vendor/pdfmake/pdfmake.min.js', array(), '0.3.13', true );
		wp_enqueue_script( 'ehrman-pdfmake-fonts', EHRMAN_DISCOVERY_PLUGIN_URL . 'assets/vendor/pdfmake/vfs_fonts.js', array( 'ehrman-pdfmake' ), '0.3.13', true );
		$file = 'assets/js/organization-export.js';
		wp_enqueue_script( 'ehrman-organization-export', EHRMAN_DISCOVERY_PLUGIN_URL . $file, array( 'wp-i18n', 'ehrman-pdfmake-fonts' ), (string) filemtime( EHRMAN_DISCOVERY_PLUGIN_DIR . $file ), true );
	}

	/** Renders the read-only export controls. */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted template escapes its values.
		echo Template::render( 'admin/organization-export.php', array( 'areas' => ( new Subject_Area_Organization_Service() )->areas() ) );
	}

	/** Returns a protected CSV or the data used by the browser's PDF generator. */
	public static function download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot export this structure.', 'ehrman-blog-discovery' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Use the Export tab to download the structure.', 'ehrman-blog-discovery' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'ehrman_organization_export' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scope/format are allowlisted; IDs are checked against current database records.
		$input  = Database::associative_row( wp_unslash( $_POST ) ) ?? array();
		$format = Database::text( $input['format'] ?? null );
		if ( ! in_array( $format, array( 'csv', 'pdf' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose PDF or CSV.', 'ehrman-blog-discovery' ) ), 400 );
		}
		$export = new Organization_Export();
		$rows   = $export->rows( Database::text( $input['scope'] ?? null ), $input['subject_area_ids'] ?? null );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ), 400 );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		$filename = 'ehrman-browse-structure-' . gmdate( 'Y-m-d' );
		if ( 'pdf' === $format ) {
			wp_send_json_success(
				array(
					'rows'     => $rows,
					'filename' => $filename . '.pdf',
					'date'     => wp_date( 'F j, Y' ),
				)
			);
		}
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_send_json_error( array( 'message' => __( 'The export could not be created.', 'ehrman-blog-discovery' ) ), 500 );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
		$export->write_csv( $output, $rows );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the download stream.
		fclose( $output );
		exit;
	}
}
