<?php
/**
 * Authoritative JSON source loading.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads and fingerprints the authoritative import documents. */
final class Import_Source_Loader {
	/** Required source files keyed by logical dataset name. */
	private const SOURCE_FILES = array(
		'posts'           => 'ehrman_post_search_index.json',
		'topics'          => 'ehrman_post_topics.json',
		'categories'      => 'ehrman_post_categories.json',
		'subject_areas_1' => 'ehrman_post_subject_areas.json',
		'subject_areas_2' => 'ehrman_post_subject_areas_2.json',
	);

	/**
	 * Directory containing authoritative JSON source files.
	 *
	 * @var string
	 */
	private string $source_directory;

	/**
	 * Creates a loader for one source directory.
	 *
	 * @param string $source_directory Directory containing source documents.
	 */
	public function __construct( string $source_directory ) {
		$this->source_directory = rtrim( $source_directory, '/\\' );
	}

	/** Returns whether every required source file is readable. */
	public function sources_available(): bool {
		foreach ( self::SOURCE_FILES as $filename ) {
			if ( ! is_readable( $this->source_directory . '/' . $filename ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Loads and decodes the required JSON documents.
	 *
	 * @return array{posts:list<mixed>,topics:list<mixed>,categories:list<mixed>,subject_areas_1:list<mixed>,subject_areas_2:list<mixed>}
	 * @throws RuntimeException When a source file is missing or invalid.
	 */
	public function load(): array {
		if ( ! $this->sources_available() ) {
			throw new RuntimeException( 'One or more authoritative JSON import files are unavailable.' );
		}

		$documents = array();
		foreach ( self::SOURCE_FILES as $key => $filename ) {
			$path = $this->source_directory . '/' . $filename;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a trusted local import file from the configured private source directory.
			$json = file_get_contents( $path );
			if ( false === $json ) {
				throw new RuntimeException( "Could not read {$filename}." );
			}

			try {
				$documents[ $key ] = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new RuntimeException( "Invalid JSON in {$filename}: {$error->getMessage()}" );
			}
		}

		$data = array(
			'posts'           => $documents['posts'],
			'topics'          => is_array( $documents['topics'] ) ? ( $documents['topics']['topics'] ?? null ) : null,
			'categories'      => is_array( $documents['categories'] ) ? ( $documents['categories']['categories'] ?? null ) : null,
			'subject_areas_1' => is_array( $documents['subject_areas_1'] ) ? ( $documents['subject_areas_1']['subjectAreas'] ?? null ) : null,
			'subject_areas_2' => is_array( $documents['subject_areas_2'] ) ? ( $documents['subject_areas_2']['subjectAreas'] ?? null ) : null,
		);

		foreach ( $data as $key => $records ) {
			if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
				throw new RuntimeException( "The {$key} source must contain a JSON list." );
			}
		}

		/**
		 * Validated logical source lists.
		 *
		 * @var array{posts:list<mixed>,topics:list<mixed>,categories:list<mixed>,subject_areas_1:list<mixed>,subject_areas_2:list<mixed>} $data
		 */
		return $data;
	}

	/**
	 * Builds one checksum from all required source files.
	 *
	 * @return string SHA-256 source checksum.
	 * @throws RuntimeException When a source file cannot be checksummed.
	 */
	public function checksum(): string {
		$context = hash_init( 'sha256' );
		foreach ( self::SOURCE_FILES as $filename ) {
			hash_update( $context, $filename . "\0" );
			if ( ! hash_update_file( $context, $this->source_directory . '/' . $filename ) ) {
				throw new RuntimeException( "Could not checksum {$filename}." );
			}
		}

		return hash_final( $context );
	}
}
