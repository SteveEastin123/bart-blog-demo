<?php
/**
 * Authoritative JSON-to-WordPress database importer.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates authoritative JSON files and imports them into custom tables.
 *
 * @phpstan-type SourceRecord array<string,mixed>
 * @phpstan-type SourceLists array{
 *     posts:list<mixed>,
 *     topics:list<mixed>,
 *     categories:list<mixed>,
 *     subject_areas_1:list<mixed>,
 *     subject_areas_2:list<mixed>
 * }
 * @phpstan-type ValidSourceData array{
 *     posts:list<SourceRecord>,
 *     topics:list<SourceRecord>,
 *     categories:list<SourceRecord>,
 *     subject_areas_1:list<SourceRecord>,
 *     subject_areas_2:list<SourceRecord>
 * }
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
		$wpdb = Database::client();

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

			$this->query_or_throw( 'START TRANSACTION', 'start import transaction' );
			try {
				$this->clear_tables();
				$expected = $this->insert_data( $data );
				$actual   = Database::counts();
				$this->assert_counts( $expected, $actual );
				$this->query_or_throw( 'COMMIT', 'commit import transaction' );
			} catch ( Throwable $error ) {
				$wpdb->query( 'ROLLBACK' );
				throw $error;
			}

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
	 * Inserts validated source data and relationship rows.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Validated source datasets.
	 * @phpstan-param ValidSourceData $data
	 * @return array<string,int> Expected row counts by table key.
	 * @throws RuntimeException When a record or relationship cannot be inserted.
	 */
	private function insert_data( array $data ): array {
		$wpdb = Database::client();

		$tables                = Database::tables();
		$category_ids          = array();
		$topic_ids             = array();
		$keyword_ids           = array();
		$category_positions    = array();
		$subject_category_rows = array();
		$topic_category_rows   = array();
		$topic_alias_rows      = array();
		$post_topic_rows       = array();
		$post_keyword_rows     = array();
		$search_term_rows      = array();

		$path_ids = array();
		foreach ( array( 1, 2 ) as $path_number ) {
			$this->insert_record(
				$tables['browse_paths'],
				array(
					'name'        => "Browse Topics {$path_number}",
					'slug'        => "browse-topics-{$path_number}",
					'description' => "Browse Topics {$path_number} hierarchy.",
					'position'    => $path_number,
					'is_active'   => 1,
				),
				array( '%s', '%s', '%s', '%d', '%d' ),
				"browse path {$path_number}"
			);
			$path_ids[ $path_number ] = (int) $wpdb->insert_id;
		}

		$category_slugs = array();
		foreach ( $data['categories'] as $category ) {
			$name = $this->clean( $category['name'] );
			$this->insert_record(
				$tables['categories'],
				array(
					'name'        => $name,
					'slug'        => $this->unique_slug( $name, $category_slugs ),
					'description' => $this->clean( $category['description'] ),
				),
				array( '%s', '%s', '%s' ),
				"category {$name}"
			);
			$category_ids[ $name ]       = (int) $wpdb->insert_id;
			$category_positions[ $name ] = array();
			foreach ( $this->string_list( $category['topicOrder'] ) as $position => $topic_name ) {
				$category_positions[ $name ][ $topic_name ] = $position + 1;
			}
		}

		foreach ( array( 1, 2 ) as $path_number ) {
			$used_slugs = array();
			foreach ( $data[ "subject_areas_{$path_number}" ] as $position => $subject_area ) {
				$name = $this->clean( $subject_area['name'] );
				$this->insert_record(
					$tables['subject_areas'],
					array(
						'browse_path_id' => $path_ids[ $path_number ],
						'name'           => $name,
						'slug'           => $this->unique_slug( $name, $used_slugs ),
						'description'    => $this->clean( $subject_area['description'] ),
						'position'       => $position + 1,
					),
					array( '%d', '%s', '%s', '%s', '%d' ),
					"subject area {$name}"
				);
				$subject_area_id = (int) $wpdb->insert_id;
				foreach ( $this->string_list( $subject_area['categories'] ) as $category_position => $category_name ) {
					$subject_category_rows[] = array(
						$subject_area_id,
						$category_ids[ $category_name ],
						$category_position + 1,
					);
				}
			}
		}

		$topic_slugs   = array();
		$topic_aliases = array();
		foreach ( $data['topics'] as $topic ) {
			$name                   = $this->clean( $topic['name'] );
			$topic_aliases[ $name ] = $this->string_list( $topic['aliases'] ?? array() );
			$this->insert_record(
				$tables['topics'],
				array(
					'name'               => $name,
					'slug'               => $this->unique_slug( $name, $topic_slugs ),
					'description'        => $this->clean( $topic['description'] ),
					'display_in_browser' => isset( $topic['displayInBrowser'] ) && false === $topic['displayInBrowser'] ? 0 : 1,
				),
				array( '%s', '%s', '%s', '%d' ),
				"topic {$name}"
			);
			$topic_ids[ $name ] = (int) $wpdb->insert_id;
			foreach ( $topic_aliases[ $name ] as $alias ) {
				$normalized_alias = $this->normalize( $alias );
				if ( '' !== $normalized_alias ) {
					$topic_alias_rows[] = array( $topic_ids[ $name ], $alias, $normalized_alias );
				}
			}
		}

		foreach ( $data['topics'] as $topic ) {
			$topic_name = $this->clean( $topic['name'] );
			foreach ( $this->string_list( $topic['categories'] ) as $category_name ) {
				$topic_category_rows[] = array(
					$topic_ids[ $topic_name ],
					$category_ids[ $category_name ],
					$category_positions[ $category_name ][ $topic_name ] ?? 0,
				);
			}
		}

		$keyword_labels = array();
		foreach ( $data['posts'] as $post ) {
			foreach ( $this->string_list( $post['secondaryKeywords'] ) as $keyword ) {
				$normalized = $this->normalize( $keyword );
				if ( ! isset( $keyword_labels[ $normalized ] ) ) {
					$keyword_labels[ $normalized ] = $keyword;
				}
			}
		}
		foreach ( $keyword_labels as $normalized => $label ) {
			$this->insert_record(
				$tables['keywords'],
				array(
					'label'      => $label,
					'normalized' => $normalized,
				),
				array( '%s', '%s' ),
				"keyword {$label}"
			);
			$keyword_ids[ $normalized ] = (int) $wpdb->insert_id;
		}

		foreach ( $data['posts'] as $post ) {
			$date = $this->parse_date( $this->clean( $post['dateText'] ) );
			if ( null === $date ) {
				throw new RuntimeException( 'A publication date became invalid after validation.' );
			}
			$url = $this->clean( $post['url'] );
			$this->insert_record(
				$tables['external_posts'],
				array(
					'source_wp_id'   => (int) $this->clean( $post['wpId'] ),
					'title'          => $this->clean( $post['title'] ),
					'url'            => $url,
					'url_hash'       => hash( 'sha256', $url, true ),
					'author'         => $this->clean( $post['author'] ),
					'date_text'      => $date['display'],
					'published_at'   => $date['published'],
					'description'    => $this->clean( $post['description'] ),
					'search_summary' => $this->clean( $post['searchSummary'] ?? '' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'post ' . $this->clean( $post['wpId'] )
			);
			$post_id = (int) $wpdb->insert_id;

			foreach ( $this->string_list( $post['topics'] ) as $topic_name ) {
				$normalized         = $this->normalize( $topic_name );
				$post_topic_rows[]  = array( $post_id, $topic_ids[ $topic_name ] );
				$search_term_rows[] = array( $post_id, $topic_name, $normalized, 'topic', 6 );
				foreach ( $topic_aliases[ $topic_name ] ?? array() as $alias ) {
					$normalized_alias = $this->normalize( $alias );
					if ( '' !== $normalized_alias ) {
						$search_term_rows[] = array( $post_id, $topic_name, $normalized_alias, 'alias', 6 );
					}
				}
			}
			foreach ( $this->string_list( $post['secondaryKeywords'] ) as $keyword ) {
				$normalized          = $this->normalize( $keyword );
				$post_keyword_rows[] = array( $post_id, $keyword_ids[ $normalized ] );
				$search_term_rows[]  = array( $post_id, $keyword, $normalized, 'secondary', 3 );
			}
		}

		$this->batch_insert(
			$tables['subject_area_categories'],
			array( 'subject_area_id', 'category_id', 'position' ),
			$subject_category_rows,
			array( '%d', '%d', '%d' )
		);
		$this->batch_insert(
			$tables['topic_aliases'],
			array( 'topic_id', 'label', 'normalized' ),
			$topic_alias_rows,
			array( '%d', '%s', '%s' )
		);
		$this->batch_insert(
			$tables['topic_categories'],
			array( 'topic_id', 'category_id', 'position' ),
			$topic_category_rows,
			array( '%d', '%d', '%d' )
		);
		$this->batch_insert(
			$tables['post_topics'],
			array( 'post_id', 'topic_id' ),
			$post_topic_rows,
			array( '%d', '%d' )
		);
		$this->batch_insert(
			$tables['post_keywords'],
			array( 'post_id', 'keyword_id' ),
			$post_keyword_rows,
			array( '%d', '%d' )
		);
		$this->batch_insert(
			$tables['post_search_terms'],
			array( 'post_id', 'label', 'normalized', 'kind', 'weight' ),
			$search_term_rows,
			array( '%d', '%s', '%s', '%s', '%d' )
		);

		return array(
			'browse_paths'            => count( $path_ids ),
			'subject_areas'           => count( $data['subject_areas_1'] ) + count( $data['subject_areas_2'] ),
			'categories'              => count( $category_ids ),
			'topics'                  => count( $topic_ids ),
			'topic_aliases'           => count( $topic_alias_rows ),
			'external_posts'          => count( $data['posts'] ),
			'keywords'                => count( $keyword_ids ),
			'subject_area_categories' => count( $subject_category_rows ),
			'topic_categories'        => count( $topic_category_rows ),
			'post_topics'             => count( $post_topic_rows ),
			'post_keywords'           => count( $post_keyword_rows ),
			'post_search_terms'       => count( $search_term_rows ),
		);
	}

	/** Removes imported rows in dependency-safe order. */
	private function clear_tables(): void {
		$tables = Database::tables();
		foreach (
			array(
				'post_search_terms',
				'post_keywords',
				'post_topics',
				'topic_categories',
				'subject_area_categories',
				'external_posts',
				'keywords',
				'topic_aliases',
				'topics',
				'subject_areas',
				'categories',
				'browse_paths',
			) as $key
		) {
			$this->query_or_throw( "DELETE FROM {$tables[$key]}", "clear {$key}" );
		}
	}

	/**
	 * Inserts relationship rows in bounded batches.
	 *
	 * Table and column identifiers are internal values supplied by this importer.
	 *
	 * @param string                      $table   Destination table name.
	 * @param array<int,string>           $columns Destination columns.
	 * @param array<int,array<int,mixed>> $rows Row values.
	 * @param array<int,string>           $formats WordPress placeholder formats.
	 * @throws RuntimeException When batch structure or insertion fails.
	 */
	private function batch_insert( string $table, array $columns, array $rows, array $formats ): void {
		$wpdb = Database::client();

		if ( empty( $rows ) ) {
			return;
		}
		if ( count( $columns ) !== count( $formats ) ) {
			throw new RuntimeException( "Invalid batch format for {$table}." );
		}

		$column_sql      = implode( ',', array_map( static fn( string $column ): string => "`{$column}`", $columns ) );
		$row_placeholder = '(' . implode( ',', $formats ) . ')';
		foreach ( array_chunk( $rows, 250 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), $row_placeholder ) );
			$values       = array();
			foreach ( $chunk as $row ) {
				if ( count( $row ) !== count( $columns ) ) {
					throw new RuntimeException( "Invalid batch row for {$table}." );
				}
				array_push( $values, ...$row );
			}

			$sql = "INSERT INTO {$table} ({$column_sql}) VALUES {$placeholders}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders and identifiers are internal importer values.
			$prepared = $wpdb->prepare( $sql, $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; identifiers and placeholder structure are internal importer values.
			if ( ! is_string( $prepared ) || false === $wpdb->query( $prepared ) ) {
				throw new RuntimeException( "Could not insert batch into {$table}: {$wpdb->last_error}" );
			}
		}
	}

	/**
	 * Inserts one record and raises a contextual error on failure.
	 *
	 * @param string              $table   Destination table name.
	 * @param array<string,mixed> $data  Column values.
	 * @param array<int,string>   $formats WordPress placeholder formats.
	 * @param string              $context Human-readable operation context.
	 * @throws RuntimeException When WordPress cannot insert the record.
	 */
	private function insert_record( string $table, array $data, array $formats, string $context ): void {
		$wpdb = Database::client();

		if ( false === $wpdb->insert( $table, $data, $formats ) ) {
			throw new RuntimeException( "Could not insert {$context}: {$wpdb->last_error}" );
		}
	}

	/**
	 * Executes an internal maintenance query and raises an error on failure.
	 *
	 * @param string $sql     Internal SQL statement.
	 * @param string $context Human-readable operation context.
	 * @throws RuntimeException When the query fails.
	 */
	private function query_or_throw( string $sql, string $context ): void {
		$wpdb = Database::client();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass only fixed transaction statements or trusted table identifiers.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( "Could not {$context}: {$wpdb->last_error}" );
		}
	}

	/**
	 * Confirms that imported row counts match expectations.
	 *
	 * @param array<string,int> $expected Expected counts.
	 * @param array<string,int> $actual   Actual counts.
	 * @throws RuntimeException When an imported count does not match.
	 */
	private function assert_counts( array $expected, array $actual ): void {
		foreach ( $expected as $key => $count ) {
			if ( ! array_key_exists( $key, $actual ) || (int) $actual[ $key ] !== (int) $count ) {
				$actual_count = $actual[ $key ] ?? 'missing';
				throw new RuntimeException( "Imported {$key} count mismatch: expected {$count}, found {$actual_count}." );
			}
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

	/**
	 * Cleans a list while discarding empty values.
	 *
	 * @param mixed $value Candidate list.
	 * @return array<int,string> Cleaned values.
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( fn( $item ): string => $this->clean( $item ), $value ),
				static fn( string $item ): bool => '' !== $item
			)
		);
	}

	/**
	 * Converts a scalar-like value to a trimmed string.
	 *
	 * @param mixed $value Value to clean.
	 * @return string Cleaned value.
	 */
	private function clean( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Normalizes text for case-insensitive matching and uniqueness checks.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string Normalized text.
	 */
	private function normalize( $value ): string {
		$text = strtolower( str_replace( '&', ' and ', $this->clean( $value ) ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
		return (string) preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Creates a unique slug within a caller-managed set.
	 *
	 * @param string             $name Name to slugify.
	 * @param array<string,bool> $used Slugs already used, updated by reference.
	 * @return string Unique slug.
	 */
	private function unique_slug( string $name, array &$used ): string {
		$base   = $this->slugify( $name );
		$slug   = $base;
		$suffix = 2;
		while ( isset( $used[ $slug ] ) ) {
			$slug = $base . '-' . $suffix;
			++$suffix;
		}
		$used[ $slug ] = true;
		return $slug;
	}

	/**
	 * Converts a name to a stable import slug.
	 *
	 * @param string $name Name to slugify.
	 * @return string Stable slug.
	 */
	private function slugify( string $name ): string {
		$normalized = $this->normalize( $name );
		return '' === $normalized ? 'item' : str_replace( ' ', '-', $normalized );
	}

	/**
	 * Parses a publication date into display and database formats.
	 *
	 * @param string $text Source date text.
	 * @return array{display:string,published:string}|null Parsed date, or null.
	 */
	private function parse_date( string $text ): ?array {
		try {
			$date = new DateTimeImmutable( $text, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception $error ) {
			return null;
		}

		$issues = DateTimeImmutable::getLastErrors();
		if ( is_array( $issues ) && ( $issues['warning_count'] > 0 || $issues['error_count'] > 0 ) ) {
			return null;
		}

		return array(
			'display'   => str_contains( $text, 'T' ) ? $date->format( 'F j, Y' ) : $text,
			'published' => $date->format( 'Y-m-d' ) . ' 00:00:00',
		);
	}
}
