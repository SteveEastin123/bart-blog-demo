<?php
/**
 * Read-only exports of the current browsing hierarchy.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds the same alphabetical relationship rows for PDF and CSV. */
final class Organization_Export {
	/**
	 * Reads current database relationships, never JSON or post content.
	 *
	 * @param string $scope Entire structure or selected subject areas.
	 * @param mixed  $selected Untrusted subject-area IDs.
	 * @return list<array{subject_area:string,category:string,topic:string}>|\WP_Error Rows or validation error.
	 */
	public function rows( string $scope, $selected ) {
		if ( ! in_array( $scope, array( 'all', 'selected' ), true ) ) {
			return new \WP_Error( 'ehrman_export_scope', __( 'Choose an export scope.', 'ehrman-blog-discovery' ) );
		}
		$areas = ( new Subject_Area_Organization_Service() )->areas();
		$ids   = array();
		if ( 'selected' === $scope ) {
			if ( ! is_array( $selected ) || ! $selected ) {
				return new \WP_Error( 'ehrman_export_selection', __( 'Select at least one Subject Area.', 'ehrman-blog-discovery' ) );
			}
			$available = array_map( static fn( array $row ): string => Database::text( $row['id'] ), $areas );
			foreach ( $selected as $value ) {
				if ( ( ! is_string( $value ) && ! is_int( $value ) ) || ! in_array( (string) $value, $available, true ) ) {
					return new \WP_Error( 'ehrman_export_selection', __( 'A selected Subject Area is no longer available. Reload and select it again.', 'ehrman-blog-discovery' ) );
				}
				$ids[] = (string) $value;
			}
			$areas = array_values( array_filter( $areas, static fn( array $row ): bool => in_array( Database::text( $row['id'] ), $ids, true ) ) );
		}
		if ( ! $areas ) {
			return new \WP_Error( 'ehrman_export_empty', __( 'There are no Subject Areas to export.', 'ehrman-blog-discovery' ) );
		}
		$categories = array();
		foreach ( ( new Category_Organization_Service() )->categories() as $category ) {
			$categories[ Database::integer( $category['id'] ) ] = $category;
		}
		$rows = array();
		foreach ( $areas as $area ) {
			$links = Database::associative_rows( $area['categories'] );
			if ( ! $links ) {
				$rows[] = array(
					'subject_area' => Database::text( $area['name'] ),
					'category'     => '',
					'topic'        => '',
				);
			}
			foreach ( $links as $link ) {
				$category = $categories[ Database::integer( $link['id'] ) ] ?? null;
				if ( null === $category ) {
					return new \WP_Error( 'ehrman_export_changed', __( 'The structure changed during export. Please try again.', 'ehrman-blog-discovery' ) );
				}
				$topics = Database::associative_rows( $category['topics'] );
				foreach ( $topics ? $topics : array( array( 'name' => '' ) ) as $topic ) {
					$rows[] = array(
						'subject_area' => Database::text( $area['name'] ),
						'category'     => Database::text( $category['name'] ),
						'topic'        => Database::text( $topic['name'] ),
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * Writes Excel-friendly UTF-8 CSV with formula-safe text cells.
	 *
	 * @param resource                                                      $output Writable stream.
	 * @param list<array{subject_area:string,category:string,topic:string}> $rows Relationships.
	 */
	public function write_csv( $output, array $rows ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM on a download stream.
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'Subject Area', 'Category', 'Topic' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			$cells = array_map( static fn( string $cell ): string => preg_match( '/^[\x00-\x20]*[=+@-]/', $cell ) ? "'" . $cell : $cell, array_values( $row ) );
			fputcsv( $output, $cells, ',', '"', '' );
		}
	}
}
