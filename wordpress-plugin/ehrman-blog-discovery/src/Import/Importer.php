<?php
/**
 * Authoritative JSON-to-WordPress database importer.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates source loading, validation, and transactional import.
 *
 * @phpstan-type ImportSummary array{
 *     import_version:string,
 *     source_checksum:string,
 *     started_at:string,
 *     completed_at:string,
 *     duration_ms:int,
 *     counts:array<string,int>,
 *     warnings:list<string>,
 *     skipped:bool
 * }
 */
final class Importer {

	/**
	 * Directory containing authoritative JSON source files.
	 *
	 * @var string
	 */
	private string $source_directory;

	/**
	 * Authoritative source document loader.
	 *
	 * @var Import_Source_Loader
	 */
	private Import_Source_Loader $source_loader;

	/**
	 * Cross-document data validator.
	 *
	 * @var Import_Data_Validator
	 */
	private Import_Data_Validator $validator;

	/**
	 * Transactional database writer.
	 *
	 * @var Import_Database_Writer
	 */
	private Import_Database_Writer $writer;

	/**
	 * Initializes the importer.
	 *
	 * @param string|null $source_directory Optional source directory override.
	 */
	public function __construct( ?string $source_directory = null ) {
		$configured_value       = defined( 'EHRMAN_DISCOVERY_IMPORT_DIR' ) ? constant( 'EHRMAN_DISCOVERY_IMPORT_DIR' ) : '';
		$configured             = is_string( $configured_value ) ? $configured_value : '';
		$fallback               = '' !== $configured ? $configured : WP_CONTENT_DIR . '/ehrman-import';
		$directory              = null !== $source_directory && '' !== $source_directory ? $source_directory : $fallback;
		$this->source_directory = rtrim(
			$directory,
			'/\\'
		);
		$this->source_loader    = new Import_Source_Loader( $this->source_directory );
		$this->validator        = new Import_Data_Validator();
		$this->writer           = new Import_Database_Writer();
	}

	/**
	 * Returns the configured source directory.
	 *
	 * @return string Absolute or WordPress-relative source directory.
	 */
	public function source_directory(): string {
		return $this->source_directory;
	}

	/**
	 * Checks whether every required source file is readable.
	 *
	 * @return bool True when all required files are available.
	 */
	public function sources_available(): bool {
		return $this->source_loader->sources_available();
	}

	/**
	 * Validates and imports all authoritative datasets transactionally.
	 *
	 * @param bool $force Reimport even when the source checksum is unchanged.
	 * @return ImportSummary Import summary.
	 * @throws Throwable When validation or transactional import fails.
	 */
	public function import( bool $force = false ): array {
		$started         = microtime( true );
		$started_at      = gmdate( 'c' );
		$previous_status = get_option( 'ehrman_discovery_import_status', array() );
		update_option(
			'ehrman_discovery_import_status',
			array(
				'state'      => 'validating',
				'started_at' => $started_at,
			),
			false
		);

		try {
			Database::maybe_upgrade();
			$data     = $this->source_loader->load();
			$checksum = $this->source_loader->checksum();
			$warnings = $this->validator->validate( $data );

			$previous_checksum_value = get_option( 'ehrman_discovery_import_checksum', '' );
			$previous_checksum       = is_string( $previous_checksum_value ) ? $previous_checksum_value : '';
			if ( ! $force && hash_equals( $previous_checksum, $checksum ) && is_array( $previous_status ) ) {
				$previous_summary = get_option( 'ehrman_discovery_last_import_summary', array() );
				if ( $this->is_import_summary( $previous_summary ) ) {
					update_option(
						'ehrman_discovery_import_status',
						array(
							'state'        => 'complete',
							'started_at'   => $previous_summary['started_at'],
							'completed_at' => $previous_summary['completed_at'],
						),
						false
					);
					$previous_summary['skipped'] = true;
					return $previous_summary;
				}
			}

			update_option(
				'ehrman_discovery_import_status',
				array(
					'state'      => 'importing',
					'started_at' => $started_at,
				),
				false
			);

			$actual = $this->writer->replace( $data );

			$summary = array(
				'import_version'  => gmdate( 'YmdHis' ),
				'source_checksum' => $checksum,
				'started_at'      => $started_at,
				'completed_at'    => gmdate( 'c' ),
				'duration_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'counts'          => $actual,
				'warnings'        => $warnings,
				'skipped'         => false,
			);

			update_option( 'ehrman_discovery_import_version', $summary['import_version'], false );
			update_option( 'ehrman_discovery_import_checksum', $checksum, false );
			update_option( 'ehrman_discovery_last_import_summary', $summary, false );
			update_option(
				'ehrman_discovery_import_status',
				array(
					'state'        => 'complete',
					'started_at'   => $started_at,
					'completed_at' => $summary['completed_at'],
				),
				false
			);

			wp_cache_flush();
			return $summary;
		} catch ( Throwable $error ) {
			update_option(
				'ehrman_discovery_import_status',
				array(
					'state'      => 'failed',
					'started_at' => $started_at,
					'failed_at'  => gmdate( 'c' ),
					'message'    => $error->getMessage(),
				),
				false
			);
			throw $error;
		}
	}

	/**
	 * Validates a previously stored import summary before reusing it.
	 *
	 * @param mixed $value Candidate summary.
	 * @phpstan-assert-if-true ImportSummary $value
	 */
	private function is_import_summary( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$string_keys = array( 'import_version', 'source_checksum', 'started_at', 'completed_at' );
		foreach ( $string_keys as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) ) {
				return false;
			}
		}

		if ( ! isset( $value['duration_ms'] ) || ! is_int( $value['duration_ms'] ) || ! isset( $value['skipped'] ) || ! is_bool( $value['skipped'] ) ) {
			return false;
		}
		if ( ! isset( $value['counts'] ) || ! is_array( $value['counts'] ) || ! isset( $value['warnings'] ) || ! is_array( $value['warnings'] ) || ! array_is_list( $value['warnings'] ) ) {
			return false;
		}

		foreach ( $value['counts'] as $key => $count ) {
			if ( ! is_string( $key ) || ! is_int( $count ) ) {
				return false;
			}
		}
		foreach ( $value['warnings'] as $warning ) {
			if ( ! is_string( $warning ) ) {
				return false;
			}
		}

		return true;
	}
}
