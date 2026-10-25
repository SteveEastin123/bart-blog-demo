<?php
/**
 * Coordinated initial installation of discovery metadata and supplied vectors.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates first, then commits metadata, vectors and the installation receipt together. */
final class Data_Installer {
	/** Stable primary-key ordering for the data owned by an installation. */
	private const DATA_TABLES = array(
		'browse_paths'            => 'id',
		'subject_areas'           => 'id',
		'categories'              => 'id',
		'topics'                  => 'id',
		'topic_aliases'           => 'topic_id,normalized',
		'external_posts'          => 'id',
		'keywords'                => 'id',
		'subject_area_categories' => 'subject_area_id,category_id',
		'topic_categories'        => 'topic_id,category_id',
		'post_topics'             => 'post_id,topic_id',
		'post_keywords'           => 'post_id,keyword_id',
		'post_search_terms'       => 'post_id,normalized,kind',
		'post_embeddings'         => 'source_wp_id',
	);

	/**
	 * Prevents package imports from replacing the production editorial index.
	 *
	 * @throws RuntimeException When WordPress/database data is authoritative.
	 */
	public static function require_json_source(): void {
		if ( Post_Ingestion_Settings::database_is_authoritative() ) {
			throw new RuntimeException( 'Package import is disabled while EHRMAN_DISCOVERY_POST_SOURCE=mysql. WordPress-managed data must not be replaced by JSON.' );
		}
	}

	/**
	 * Installs a complete package, or validates an identical previous installation.
	 *
	 * @param string $directory Private directory containing five JSON files and the vector package.
	 * @param bool   $replace_existing Explicit consent to replace an existing JSON-managed index.
	 * @param bool   $validate_only Validate files without schema or data writes.
	 * @return array{skipped:bool,validated_only:bool,posts:int,vectors:int,warnings:list<string>} Installation result.
	 * @throws RuntimeException When a package or installation safety check fails.
	 */
	public function install( string $directory, bool $replace_existing = false, bool $validate_only = false ): array {
		self::require_json_source();
		$resolved = realpath( $directory );
		$web_root = realpath( ABSPATH );
		if ( false === $resolved || ! is_dir( $resolved ) || false === $web_root
			|| str_starts_with( strtolower( wp_normalize_path( $resolved ) . '/' ), strtolower( wp_normalize_path( $web_root ) . '/' ) ) ) {
			throw new RuntimeException( 'Use an existing private --data-dir outside the WordPress directory.' );
		}
		$loader = new Import_Source_Loader( $resolved );
		if ( ! $loader->sources_available() ) {
			throw new RuntimeException( 'One or more authoritative JSON import files are unavailable.' );
		}
		$checksum = $loader->checksum();
		$data     = $loader->load();
		$warnings = ( new Import_Data_Validator() )->validate( $data );
		if ( ! hash_equals( $checksum, $loader->checksum() ) ) {
			throw new RuntimeException( 'Source files changed during validation. Retry with an immutable package.' );
		}
		$eligible = array();
		foreach ( $data['posts'] as $post ) {
			$topics  = array_map( static fn( string $topic ): string => strtolower( trim( $topic ) ), Database::strings( $post['topics'] ) );
			$summary = trim( Database::text( $post['searchSummary'] ?? null ) );
			if ( in_array( 'ignore', $topics, true ) ) {
				continue;
			}
			if ( '' === $summary ) {
				$warnings[] = sprintf( 'WordPress post %s has no search summary and is excluded from the semantic index.', Database::text( $post['wpId'] ) );
				continue;
			}
			$eligible[ (int) Database::text( $post['wpId'] ) ] = array(
				'title'          => trim( Database::text( $post['title'] ) ),
				'search_summary' => $summary,
			);
		}
		$file        = $resolved . '/ehrman_post_embeddings.jsonl.gz';
		$vector_hash = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		$transfer    = new Embedding_Index_Transfer();
		$records     = $transfer->read_package( $file, $eligible );
		if ( false === $vector_hash || ! hash_equals( $vector_hash, (string) hash_file( 'sha256', $file ) ) ) {
			throw new RuntimeException( 'Vector package changed during validation.' );
		}
		$result = array(
			'skipped'        => false,
			'validated_only' => $validate_only,
			'posts'          => count( $data['posts'] ),
			'vectors'        => count( $records ),
			'warnings'       => $warnings,
		);
		if ( $validate_only ) {
			return $result;
		}

		$wpdb = Database::client();
		$lock = 'ehrman-install-' . hash( 'sha1', Database::text( $wpdb->get_var( 'SELECT DATABASE()' ) ) . ':' . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			throw new RuntimeException( 'Another installation is running, or the database does not permit installation locks.' );
		}
		try {
			self::require_json_source();
			// DDL implicitly commits in MySQL/MariaDB; finish it before starting the data transaction.
			Database::install();
			$this->require_transactional_tables();
			$counts  = Database::counts();
			$receipt = Database::associative_row( get_option( 'ehrman_discovery_install_receipt', null ) );
			if ( is_array( $receipt ) ) {
				if ( ! hash_equals( Database::text( $receipt['database_hash'] ?? null ), $this->database_hash() ) ) {
					throw new RuntimeException( 'Discovery data changed after installation. Refusing to overwrite later edits, even with --replace-existing.' );
				}
				if ( ( $receipt['source_checksum'] ?? null ) === $checksum && ( $receipt['vector_checksum'] ?? null ) === $vector_hash ) {
					$result['skipped'] = true;
					return $result;
				}
			}
			if ( ( $counts['ingestion_drafts'] ?? 0 ) > 0 ) {
				throw new RuntimeException( 'Post-ingestion drafts or history exist. Resolve the editorial handoff before replacing discovery data.' );
			}
			$existing = array_sum( array_intersect_key( $counts, self::DATA_TABLES ) );
			if ( $existing > 0 && ! $replace_existing ) {
				throw new RuntimeException( 'Discovery data already exists. Back up the database and use --replace-existing only for an intentional JSON-managed replacement.' );
			}
			$started_at = gmdate( 'c' );
			$started    = microtime( true );
			( new Import_Database_Writer() )->replace(
				$data,
				function ( array $actual ) use ( $transfer, $records, $checksum, $vector_hash, $warnings, $started, $started_at ): void {
					unset( $actual );
					$transfer->store_in_transaction( $records );
					$summary = array(
						'import_version'  => wp_generate_uuid4(),
						'source_checksum' => $checksum,
						'started_at'      => $started_at,
						'completed_at'    => gmdate( 'c' ),
						'duration_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'counts'          => Database::counts(),
						'warnings'        => $warnings,
						'skipped'         => false,
					);
					$this->store_option( 'ehrman_discovery_import_version', $summary['import_version'] );
					$this->store_option( 'ehrman_discovery_import_checksum', $checksum );
					$this->store_option( 'ehrman_discovery_last_import_summary', $summary );
					$this->store_option(
						'ehrman_discovery_import_status',
						array(
							'state'        => 'complete',
							'started_at'   => $started_at,
							'completed_at' => $summary['completed_at'],
						)
					);
					$this->store_option(
						'ehrman_discovery_install_receipt',
						array(
							'source_checksum' => $checksum,
							'vector_checksum' => $vector_hash,
							'database_hash'   => $this->database_hash(),
							'plugin_version'  => EHRMAN_DISCOVERY_VERSION,
						)
					);
				}
			);
			return $result;
		} finally {
			wp_cache_flush();
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Checks that schema setup succeeded and rollback is supported by every affected table.
	 *
	 * @throws RuntimeException When tables are missing or use nontransactional storage.
	 */
	private function require_transactional_tables(): void {
		$wpdb = Database::client();
		foreach ( array_merge( array_values( Database::tables() ), array( $wpdb->options ) ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'innodb' !== strtolower( Database::text( $engine ) ) ) {
				throw new RuntimeException( 'Installation requires all plugin tables and the WordPress options table to exist and use InnoDB: ' . $table );
			}
		}
	}

	/**
	 * Fingerprints imported content and relationships, including binary vector bytes.
	 *
	 * @throws RuntimeException When the database cannot be read completely.
	 */
	private function database_hash(): string {
		$wpdb    = Database::client();
		$tables  = Database::tables();
		$context = hash_init( 'sha256' );
		foreach ( self::DATA_TABLES as $key => $order ) {
			$table = $tables[ $key ];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and primary-key columns are internal constants.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$order}", ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'Could not fingerprint installed data: ' . $key );
			}
			hash_update( $context, $key . "\0" );
			foreach ( $rows as $row ) {
				// Serialization preserves raw binary vectors without invalid UTF-8 in JSON.
				hash_update( $context, serialize( $row ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}
		return hash_final( $context );
	}

	/**
	 * Writes bookkeeping in the same transaction without publishing uncommitted object-cache entries.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 * @throws RuntimeException When the receipt or import status cannot be stored.
	 */
	private function store_option( string $name, $value ): void {
		$wpdb = Database::client();
		$sql  = $wpdb->prepare(
			"INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",
			$wpdb->options,
			$name,
			maybe_serialize( $value )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier and values are prepared immediately above.
		if ( ! is_string( $sql ) || false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'Could not store installation bookkeeping: ' . $name );
		}
	}
}
