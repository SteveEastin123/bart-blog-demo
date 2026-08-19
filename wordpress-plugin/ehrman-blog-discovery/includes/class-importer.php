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

	/** Required source files keyed by their logical dataset names. */
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
		foreach ( self::SOURCE_FILES as $filename ) {
			if ( ! is_readable( $this->source_directory . '/' . $filename ) ) {
				return false;
			}
		}

		return true;
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
			$data     = $this->load_sources();
			$checksum = $this->source_checksum();
			$warnings = $this->validate( $data );

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
	 * Loads and decodes the required JSON documents.
	 *
	 * @return SourceLists Logical source datasets.
	 * @throws RuntimeException When a source file is missing or invalid.
	 */
	private function load_sources(): array {
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
		 * Validated source lists.
		 *
		 * @var SourceLists $data
		 */
		return $data;
	}

	/**
	 * Builds one checksum from all required source files.
	 *
	 * @return string SHA-256 source checksum.
	 * @throws RuntimeException When a source file cannot be checksummed.
	 */
	private function source_checksum(): string {
		$context = hash_init( 'sha256' );
		foreach ( self::SOURCE_FILES as $filename ) {
			hash_update( $context, $filename . "\0" );
			if ( ! hash_update_file( $context, $this->source_directory . '/' . $filename ) ) {
				throw new RuntimeException( "Could not checksum {$filename}." );
			}
		}

		return hash_final( $context );
	}

	/**
	 * Validates source structure and cross-file references.
	 *
	 * @param array<string,array<int,mixed>> $data Source datasets.
	 * @phpstan-param SourceLists $data
	 * @return list<string> Non-fatal validation warnings.
	 * @phpstan-assert ValidSourceData $data
	 * @throws RuntimeException When source validation fails.
	 */
	private function validate( array $data ): array {
		$errors         = array();
		$warnings       = array();
		$topic_names    = $this->named_record_map( $data['topics'], 'topic', $errors );
		$category_names = $this->named_record_map( $data['categories'], 'category', $errors );
		$this->assert_unique_slugs( array_keys( $topic_names ), 'topic', $errors );
		$this->assert_unique_slugs( array_keys( $category_names ), 'category', $errors );

		$category_orders = array();
		foreach ( $data['categories'] as $index => $category ) {
			if ( ! $this->is_record( $category ) ) {
				$errors[] = "Category record {$index} is not an object.";
				continue;
			}
			$name = $this->clean( $category['name'] ?? '' );
			$this->require_string_field( $category, 'description', "category {$name}", $errors );
			$order                    = $this->validate_string_list( $category['topicOrder'] ?? null, "category {$name} topicOrder", $errors );
			$category_orders[ $name ] = array_flip( $order );
			foreach ( $order as $topic_name ) {
				if ( ! isset( $topic_names[ $topic_name ] ) ) {
					$errors[] = "Category {$name} references unknown topic {$topic_name}.";
				}
			}
		}

		$canonical_topic_labels = array();
		foreach ( array_keys( $topic_names ) as $topic_name ) {
			$canonical_topic_labels[ $this->normalize( $topic_name ) ] = $topic_name;
		}
		$topic_alias_owners = array();
		foreach ( $data['topics'] as $index => $topic ) {
			if ( ! $this->is_record( $topic ) ) {
				$errors[] = "Topic record {$index} is not an object.";
				continue;
			}
			$name = $this->clean( $topic['name'] ?? '' );
			$this->require_string_field( $topic, 'description', "topic {$name}", $errors );
			if ( isset( $topic['displayInBrowser'] ) && ! is_bool( $topic['displayInBrowser'] ) ) {
				$errors[] = "Topic {$name} has a non-boolean displayInBrowser value.";
			}
			$aliases = $this->validate_string_list( $topic['aliases'] ?? array(), "topic {$name} aliases", $errors, true );
			foreach ( $aliases as $alias ) {
				$normalized_alias = $this->normalize( $alias );
				if ( '' === $normalized_alias || $this->length( $alias ) > 191 || $this->length( $normalized_alias ) > 191 ) {
					$errors[] = "Topic {$name} contains an invalid alias {$alias}.";
					continue;
				}
				if ( isset( $canonical_topic_labels[ $normalized_alias ] ) ) {
					$errors[] = "Topic {$name} alias {$alias} conflicts with canonical topic {$canonical_topic_labels[ $normalized_alias ]}.";
				}
				if ( isset( $topic_alias_owners[ $normalized_alias ] ) && $topic_alias_owners[ $normalized_alias ] !== $name ) {
					$errors[] = "Topic alias {$alias} is assigned to both {$topic_alias_owners[ $normalized_alias ]} and {$name}.";
				}
				$topic_alias_owners[ $normalized_alias ] = $name;
			}
			$categories = $this->validate_string_list( $topic['categories'] ?? null, "topic {$name} categories", $errors );
			if ( empty( $categories ) ) {
				$warnings[] = "Topic {$name} is not linked to a category.";
			}
			foreach ( $categories as $category_name ) {
				if ( ! isset( $category_names[ $category_name ] ) ) {
					$errors[] = "Topic {$name} references unknown category {$category_name}.";
					continue;
				}
				if ( ! isset( $category_orders[ $category_name ][ $name ] ) ) {
					$warnings[] = "Topic {$name} is linked to {$category_name} but is absent from that category's topicOrder.";
				}
			}
		}

		foreach ( array( 1, 2 ) as $path_number ) {
			$records       = $data[ "subject_areas_{$path_number}" ];
			$subject_names = array();
			$subject_slugs = array();
			foreach ( $records as $index => $subject_area ) {
				if ( ! $this->is_record( $subject_area ) ) {
					$errors[] = "Browse path {$path_number} subject-area record {$index} is not an object.";
					continue;
				}
				$name = $this->required_string( $subject_area['name'] ?? null, "Browse path {$path_number} subject-area name", $errors );
				if ( isset( $subject_names[ $name ] ) ) {
					$errors[] = "Browse path {$path_number} repeats subject area {$name}.";
				}
				$subject_names[ $name ] = true;
				$slug                   = $this->slugify( $name );
				if ( isset( $subject_slugs[ $slug ] ) ) {
					$errors[] = "Browse path {$path_number} has a subject-area slug collision for {$name}.";
				}
				$subject_slugs[ $slug ] = true;
				$this->require_string_field( $subject_area, 'description', "subject area {$name}", $errors );
				foreach ( $this->validate_string_list( $subject_area['categories'] ?? null, "subject area {$name} categories", $errors ) as $category_name ) {
					if ( ! isset( $category_names[ $category_name ] ) ) {
						$errors[] = "Subject area {$name} references unknown category {$category_name}.";
					}
				}
			}
		}

		$wp_ids = array();
		$urls   = array();
		foreach ( $data['posts'] as $index => $post ) {
			if ( ! $this->is_record( $post ) ) {
				$errors[] = "Post record {$index} is not an object.";
				continue;
			}
			$context   = 'post ' . ( $index + 1 );
			$wp_id     = $this->required_string( $post['wpId'] ?? null, "{$context} wpId", $errors );
			$title     = $this->required_string( $post['title'] ?? null, "{$context} title", $errors );
			$url       = $this->required_string( $post['url'] ?? null, "{$context} url", $errors );
			$date_text = $this->required_string( $post['dateText'] ?? null, "{$context} dateText", $errors );
			$author    = $this->required_string( $post['author'] ?? null, "{$context} author", $errors );
			$this->require_string_field( $post, 'description', "{$context} description", $errors );

			if ( ! ctype_digit( $wp_id ) || (int) $wp_id < 1 ) {
				$errors[] = "{$context} has invalid wpId {$wp_id}.";
			} elseif ( isset( $wp_ids[ $wp_id ] ) ) {
				$errors[] = "Duplicate wpId {$wp_id}.";
			}
			$wp_ids[ $wp_id ] = true;

			if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$errors[] = "{$context} has an invalid URL.";
			} elseif ( isset( $urls[ $url ] ) ) {
				$errors[] = "Duplicate post URL {$url}.";
			}
			$urls[ $url ] = true;

			if ( $this->length( $author ) > 191 ) {
				$errors[] = "{$context} author exceeds 191 characters.";
			}
			if ( $this->length( $date_text ) > 64 || null === $this->parse_date( $date_text ) ) {
				$errors[] = "{$context} has an invalid publication date {$date_text}.";
			}

			$post_topics = $this->validate_string_list( $post['topics'] ?? null, "{$context} topics", $errors, true );
			foreach ( $post_topics as $topic_name ) {
				if ( ! isset( $topic_names[ $topic_name ] ) ) {
					$errors[] = "{$context} ({$title}) references unknown topic {$topic_name}.";
				}
			}

			$keywords = $this->validate_string_list( $post['secondaryKeywords'] ?? null, "{$context} secondaryKeywords", $errors, true );
			foreach ( $keywords as $keyword ) {
				$normalized = $this->normalize( $keyword );
				if ( '' === $normalized || $this->length( $keyword ) > 191 || $this->length( $normalized ) > 191 ) {
					$errors[] = "{$context} contains an invalid secondary keyword {$keyword}.";
				}
			}
		}

		if ( ! empty( $errors ) ) {
			$visible = array_slice( $errors, 0, 25 );
			$suffix  = count( $errors ) > 25 ? '\n- Additional validation errors: ' . ( count( $errors ) - 25 ) : '';
			throw new RuntimeException( "Import validation failed:\n- " . implode( "\n- ", $visible ) . $suffix );
		}

		return array_values( array_unique( $warnings ) );
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
					'source_wp_id' => (int) $this->clean( $post['wpId'] ),
					'title'        => $this->clean( $post['title'] ),
					'url'          => $url,
					'url_hash'     => hash( 'sha256', $url, true ),
					'author'       => $this->clean( $post['author'] ),
					'date_text'    => $date['display'],
					'published_at' => $date['published'],
					'description'  => $this->clean( $post['description'] ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
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
	 * Validates named records and indexes them by name.
	 *
	 * @param array<int,mixed>  $records Source records.
	 * @param string            $type    Singular record type.
	 * @param array<int,string> $errors  Validation errors collected by reference.
	 * @return array<string,array<string,mixed>> Records indexed by name.
	 */
	private function named_record_map( array $records, string $type, array &$errors ): array {
		$names = array();
		foreach ( $records as $index => $record ) {
			if ( ! $this->is_record( $record ) ) {
				$errors[] = ucfirst( $type ) . " record {$index} is not an object.";
				continue;
			}
			$name = $this->required_string( $record['name'] ?? null, ucfirst( $type ) . ' name', $errors );
			if ( isset( $names[ $name ] ) ) {
				$errors[] = "Duplicate {$type} name {$name}.";
			}
			$names[ $name ] = $record;
			if ( $this->length( $name ) > 191 || $this->length( $this->normalize( $name ) ) > 191 ) {
				$errors[] = ucfirst( $type ) . " name {$name} exceeds the supported length.";
			}
		}

		return $names;
	}

	/**
	 * Determines whether decoded JSON is an object-like record.
	 *
	 * @param mixed $value Candidate record.
	 * @phpstan-assert-if-true array<string,mixed> $value
	 */
	private function is_record( $value ): bool {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}

		return true;
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
	 * Ensures generated slugs are nonempty and unique.
	 *
	 * @param array<int,string> $names  Source names.
	 * @param string            $type   Singular record type.
	 * @param array<int,string> $errors Validation errors collected by reference.
	 */
	private function assert_unique_slugs( array $names, string $type, array &$errors ): void {
		$slugs = array();
		foreach ( $names as $name ) {
			$slug = $this->slugify( $name );
			if ( '' === $slug ) {
				$errors[] = ucfirst( $type ) . " {$name} produces an empty slug.";
			} elseif ( isset( $slugs[ $slug ] ) ) {
				$errors[] = ucfirst( $type ) . " slug collision between {$slugs[$slug]} and {$name}.";
			}
			$slugs[ $slug ] = $name;
		}
	}

	/**
	 * Validates a JSON list of unique, nonempty strings.
	 *
	 * @param mixed             $value              Candidate list.
	 * @param string            $context            Validation context.
	 * @param array<int,string> $errors             Validation errors collected by reference.
	 * @param bool              $use_normalized_key Compare using search normalization.
	 * @return array<int,string> Validated values.
	 */
	private function validate_string_list( $value, string $context, array &$errors, bool $use_normalized_key = false ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$errors[] = "{$context} must be a list.";
			return array();
		}

		$values = array();
		$seen   = array();
		foreach ( $value as $index => $raw_value ) {
			if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) ) {
				$errors[] = "{$context} contains an invalid value at position {$index}.";
				continue;
			}
			$item = trim( $raw_value );
			$key  = $use_normalized_key ? $this->normalize( $item ) : strtolower( $item );
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = "{$context} repeats {$item}.";
				continue;
			}
			$seen[ $key ] = true;
			$values[]     = $item;
		}

		return $values;
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
	 * Validates and returns a required scalar string.
	 *
	 * @param mixed             $value   Candidate value.
	 * @param string            $context Validation context.
	 * @param array<int,string> $errors  Validation errors collected by reference.
	 * @return string Cleaned value, possibly empty when invalid.
	 */
	private function required_string( $value, string $context, array &$errors ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			$errors[] = "{$context} must be a string.";
			return '';
		}
		$clean = $this->clean( $value );
		if ( '' === $clean ) {
			$errors[] = "{$context} is required.";
		}
		return $clean;
	}

	/**
	 * Requires a record field to contain a string.
	 *
	 * @param array<string,mixed> $record  Source record.
	 * @param string              $field   Field name.
	 * @param string              $context Validation context.
	 * @param array<int,string>   $errors  Validation errors collected by reference.
	 */
	private function require_string_field( array $record, string $field, string $context, array &$errors ): void {
		if ( ! array_key_exists( $field, $record ) || ! is_string( $record[ $field ] ) ) {
			$errors[] = "The {$field} field for {$context} must be a string.";
		}
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

	/**
	 * Returns a UTF-8-aware string length when available.
	 *
	 * @param string $value Value to measure.
	 * @return int Character or byte length.
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
