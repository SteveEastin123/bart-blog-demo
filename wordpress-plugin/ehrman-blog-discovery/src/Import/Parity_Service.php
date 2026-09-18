<?php
/**
 * Cross-implementation parity-test services.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Produces deterministic search, suggestion, and browse payloads for parity tests. */
final class Parity_Service {

	/** Parity response schema version. */
	public const SCHEMA_VERSION = 1;

	/** Maximum test cases accepted in one request. */
	public const MAX_BATCH_CASES = 200;

	/** Authoritative files included in the data fingerprint. */
	private const SOURCE_FILES = array(
		'data/index/ehrman_post_search_index.json'    => 'ehrman_post_search_index.json',
		'data/index/ehrman_post_topics.json'          => 'ehrman_post_topics.json',
		'data/index/ehrman_post_categories.json'      => 'ehrman_post_categories.json',
		'data/index/ehrman_post_subject_areas.json'   => 'ehrman_post_subject_areas.json',
		'data/index/ehrman_post_subject_areas_2.json' => 'ehrman_post_subject_areas_2.json',
	);

	/**
	 * Post search service.
	 *
	 * @var Search_Service
	 */
	private Search_Service $search;

	/** Initializes the parity service. */
	public function __construct() {
		$this->search = new Search_Service();
	}

	/**
	 * Returns the configured bearer token for parity endpoints.
	 *
	 * @return string Configured token, or an empty string when disabled.
	 */
	public static function configured_token(): string {
		if ( defined( 'EHRMAN_DISCOVERY_PARITY_TOKEN' ) ) {
			return trim( Database::text( constant( 'EHRMAN_DISCOVERY_PARITY_TOKEN' ) ) );
		}

		return trim( Database::text( getenv( 'EHRMAN_DISCOVERY_PARITY_TOKEN' ) ) );
	}

	/**
	 * Executes a validated batch of parity cases.
	 *
	 * @param mixed $cases Candidate list of parity cases.
	 * @return array<string,mixed> Manifest and case results.
	 * @throws InvalidArgumentException When the batch structure is invalid.
	 */
	public function run_batch( $cases ): array {
		if ( ! is_array( $cases ) || ! array_is_list( $cases ) ) {
			throw new InvalidArgumentException( 'cases must be a list' );
		}
		if ( empty( $cases ) ) {
			throw new InvalidArgumentException( 'cases must not be empty' );
		}
		if ( count( $cases ) > self::MAX_BATCH_CASES ) {
			throw new InvalidArgumentException(
				'a batch supports at most ' . self::MAX_BATCH_CASES . ' cases'
			);
		}

		$results = array();
		foreach ( $cases as $test_case ) {
			$case_record = Database::associative_row( $test_case );
			if ( null === $case_record ) {
				$results[] = array(
					'id'    => '',
					'ok'    => false,
					'error' => 'case must be an object',
				);
				continue;
			}
			try {
				$results[] = $this->execute_case( $case_record );
			} catch ( InvalidArgumentException | RuntimeException $error ) {
				$results[] = array(
					'id'    => $this->clean_string( $case_record['id'] ?? null ),
					'ok'    => false,
					'error' => $error->getMessage(),
				);
			} catch ( Throwable $error ) {
				$results[] = array(
					'id'    => $this->clean_string( $case_record['id'] ?? null ),
					'ok'    => false,
					'error' => 'Parity case failed.',
				);
			}
		}

		return array_merge( $this->manifest(), array( 'results' => $results ) );
	}

	/**
	 * Executes one validated parity case.
	 *
	 * @param array<string,mixed> $test_case Parity case definition.
	 * @return array<string,mixed> Case result.
	 * @throws InvalidArgumentException When case validation fails.
	 * @phpstan-throws InvalidArgumentException|RuntimeException
	 */
	private function execute_case( array $test_case ): array {
		$id = $this->clean_string( $test_case['id'] ?? null );
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'each case requires a non-empty id' );
		}

		$operation = $this->clean_string( $test_case['operation'] ?? null );
		if ( 'search' === $operation ) {
			$result = $this->search_case( $test_case );
		} elseif ( 'suggest' === $operation ) {
			$result = $this->suggest_case( $test_case );
		} elseif ( 'browse' === $operation ) {
			$result = $this->browse_case();
		} else {
			throw new InvalidArgumentException( "unknown operation: {$operation}" );
		}

		return array_merge(
			array(
				'id' => $id,
				'ok' => true,
			),
			$result
		);
	}

	/**
	 * Executes a search parity case.
	 *
	 * @param array<string,mixed> $test_case Search case definition.
	 * @return array<string,mixed> Serialized search result.
	 * @throws InvalidArgumentException When the search scope is invalid.
	 */
	private function search_case( array $test_case ): array {
		$terms = $this->string_list( $test_case['terms'] ?? null, 'terms', Search_Service::MAX_TERMS );
		$sort  = $this->normalized_sort( $test_case['sort'] ?? null );
		$scope = $test_case['scope'] ?? array( 'type' => 'global' );
		if ( ! is_array( $scope ) || array_is_list( $scope ) ) {
			throw new InvalidArgumentException( 'scope must be an object' );
		}

		$scope_type    = $this->clean_string( $scope['type'] ?? null );
		$scope_type    = '' === $scope_type ? 'global' : $scope_type;
		$scope_slug    = $this->clean_string( $scope['slug'] ?? null );
		$display_terms = $terms;

		if ( 'global' === $scope_type ) {
			$result = $this->search->search( $terms, $sort );
		} elseif ( 'category' === $scope_type ) {
			if ( '' === $scope_slug ) {
				throw new InvalidArgumentException( 'category scope requires a slug' );
			}
			$category = $this->record_by_slug( 'categories', $scope_slug );
			if ( null === $category ) {
				throw new InvalidArgumentException( "unknown category slug: {$scope_slug}" );
			}
			$result = $this->search->search( $terms, $sort, $scope_slug );
		} elseif ( 'topic' === $scope_type ) {
			if ( '' === $scope_slug ) {
				throw new InvalidArgumentException( 'topic scope requires a slug' );
			}
			$topic = $this->record_by_slug( 'topics', $scope_slug );
			if ( null === $topic ) {
				throw new InvalidArgumentException( "unknown topic slug: {$scope_slug}" );
			}
			$display_terms = empty( $terms ) ? array( Database::text( $topic['name'] ?? null ) ) : $terms;
			$ranking_terms = empty( $terms ) ? $display_terms : $terms;
			$result        = $this->search->search( $ranking_terms, $sort, '', $scope_slug );
		} else {
			throw new InvalidArgumentException( "unknown search scope: {$scope_type}" );
		}

		return array(
			'operation'    => 'search',
			'terms'        => $terms,
			'displayTerms' => $display_terms,
			'sort'         => $sort,
			'scope'        => array(
				'type' => $scope_type,
				'slug' => $scope_slug,
			),
			'resultCount'  => $result['count'],
			'posts'        => $this->serialize_posts( $result['posts'] ),
		);
	}

	/**
	 * Executes an autocomplete-suggestion parity case.
	 *
	 * @param array<string,mixed> $test_case Suggestion case definition.
	 * @return array<string,mixed> Serialized suggestion result.
	 */
	private function suggest_case( array $test_case ): array {
		$selected      = $this->string_list(
			$test_case['selected'] ?? null,
			'selected',
			Search_Service::MAX_TERMS
		);
		$query         = $this->clean_string( $test_case['query'] ?? null );
		$category_slug = $this->clean_string( $test_case['categorySlug'] ?? null );
		$topic_slug    = $this->clean_string( $test_case['topicSlug'] ?? null );
		$suggestions   = $this->search->suggestions(
			$query,
			$selected,
			$category_slug,
			$topic_slug
		);

		return array(
			'operation'       => 'suggest',
			'query'           => $query,
			'normalizedQuery' => Search_Service::normalize( $query ),
			'selected'        => $selected,
			'categorySlug'    => $category_slug,
			'topicSlug'       => $topic_slug,
			'suggestionCount' => count( $suggestions ),
			'suggestions'     => $suggestions,
		);
	}

	/**
	 * Builds the complete browse parity payload.
	 *
	 * @return array<string,mixed> Browse records grouped by entity type.
	 * @throws RuntimeException When browse fingerprint generation fails.
	 */
	private function browse_case(): array {
		return array(
			'operation'     => 'browse',
			'subjectAreas1' => $this->subject_area_records( 'browse-topics-1' ),
			'subjectAreas2' => $this->subject_area_records( 'browse-topics-2' ),
			'categories'    => $this->category_records(),
			'topics'        => $this->topic_records(),
		);
	}

	/**
	 * Builds subject-area records for one browse hierarchy.
	 *
	 * @param string $path_slug Browse-path slug.
	 * @return array<int,array<string,mixed>> Subject-area records.
	 */
	private function subject_area_records( string $path_slug ): array {
		$wpdb    = Database::client();
		$tables  = Database::tables();
		$path_id = Database::integer(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$tables['browse_paths']} WHERE slug=%s", $path_slug )
			)
		);
		$areas   = Database::associative_rows(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['subject_areas']} WHERE browse_path_id=%d ORDER BY id",
					$path_id
				),
				ARRAY_A
			)
		);
		$records = array();

		foreach ( $areas as $area ) {
			$area_id     = Database::integer( $area['id'] ?? null );
			$categories  = Database::associative_rows(
				$wpdb->get_results(
					$wpdb->prepare(
						'SELECT c.name,c.slug,c.description,COUNT(DISTINCT tc.topic_id) topic_count,'
						. "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['subject_area_categories']} sac "
						. "JOIN {$tables['categories']} c ON c.id=sac.category_id "
						. "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
						. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
						. 'WHERE sac.subject_area_id=%d GROUP BY c.id '
						. 'ORDER BY sac.position,c.name',
						$area_id
					),
					ARRAY_A
				)
			);
			$topic_count = Database::integer(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT tc.topic_id) FROM {$tables['subject_area_categories']} sac "
						. "JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
						. 'WHERE sac.subject_area_id=%d',
						$area_id
					)
				)
			);
			$post_count  = Database::integer(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['subject_area_categories']} sac "
						. "JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
						. "JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
						. 'WHERE sac.subject_area_id=%d',
						$area_id
					)
				)
			);

			$records[] = array(
				'name'          => Database::text( $area['name'] ?? null ),
				'slug'          => Database::text( $area['slug'] ?? null ),
				'description'   => Database::text( $area['description'] ?? null ),
				'categoryCount' => count( $categories ),
				'topicCount'    => $topic_count,
				'postCount'     => $post_count,
				'categories'    => array_map(
					static fn( array $category ): array => array(
						'name'        => Database::text( $category['name'] ?? null ),
						'slug'        => Database::text( $category['slug'] ?? null ),
						'description' => Database::text( $category['description'] ?? null ),
						'topicCount'  => Database::integer( $category['topic_count'] ?? null ),
						'postCount'   => Database::integer( $category['post_count'] ?? null ),
					),
					$categories
				),
			);
		}

		return $records;
	}

	/**
	 * Builds all category records and their visible topics.
	 *
	 * @return array<int,array<string,mixed>> Category records.
	 */
	private function category_records(): array {
		$wpdb       = Database::client();
		$tables     = Database::tables();
		$categories = Database::associative_rows(
			$wpdb->get_results(
				"SELECT * FROM {$tables['categories']} ORDER BY name",
				ARRAY_A
			)
		);
		$records    = array();

		foreach ( $categories as $category ) {
			$category_id = Database::integer( $category['id'] ?? null );
			$topics      = Database::associative_rows(
				$wpdb->get_results(
					$wpdb->prepare(
						'SELECT t.name,t.slug,t.description,t.display_in_browser,'
						. "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['topics']} t "
						. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
						. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
						. 'WHERE tc.category_id=%d AND t.display_in_browser=1 GROUP BY t.id '
						. 'ORDER BY CASE WHEN tc.position>0 THEN 0 ELSE 1 END,tc.position,t.name',
						$category_id
					),
					ARRAY_A
				)
			);
			$post_count  = Database::integer(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['post_topics']} pt "
						. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id "
						. 'WHERE tc.category_id=%d',
						$category_id
					)
				)
			);
			$records[]   = array(
				'name'        => Database::text( $category['name'] ?? null ),
				'slug'        => Database::text( $category['slug'] ?? null ),
				'description' => Database::text( $category['description'] ?? null ),
				'topicCount'  => count( $topics ),
				'postCount'   => $post_count,
				'topics'      => array_map(
					static fn( array $topic ): array => array(
						'name'        => Database::text( $topic['name'] ?? null ),
						'slug'        => Database::text( $topic['slug'] ?? null ),
						'description' => Database::text( $topic['description'] ?? null ),
						'postCount'   => Database::integer( $topic['post_count'] ?? null ),
					),
					$topics
				),
			);
		}

		return $records;
	}

	/**
	 * Builds all topic records and their category links.
	 *
	 * @return array<int,array<string,mixed>> Topic records.
	 */
	private function topic_records(): array {
		$wpdb    = Database::client();
		$tables  = Database::tables();
		$topics  = Database::associative_rows(
			$wpdb->get_results(
				'SELECT t.name,t.slug,t.description,t.display_in_browser,'
				. "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['topics']} t "
				. "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
				. 'GROUP BY t.id ORDER BY t.name',
				ARRAY_A
			)
		);
		$records = array();

		foreach ( $topics as $topic ) {
			$topic_slug = Database::text( $topic['slug'] ?? null );
			$categories = Database::associative_rows(
				$wpdb->get_results(
					$wpdb->prepare(
						"SELECT c.name,c.slug FROM {$tables['categories']} c "
						. "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
						. "JOIN {$tables['topics']} t ON t.id=tc.topic_id "
						. 'WHERE t.slug=%s ORDER BY c.name',
						$topic_slug
					),
					ARRAY_A
				)
			);
			$records[]  = array(
				'name'             => Database::text( $topic['name'] ?? null ),
				'slug'             => $topic_slug,
				'description'      => Database::text( $topic['description'] ?? null ),
				'displayInBrowser' => 1 === Database::integer( $topic['display_in_browser'] ?? null ),
				'postCount'        => Database::integer( $topic['post_count'] ?? null ),
				'categories'       => array_map(
					static fn( array $category ): array => array(
						'name' => Database::text( $category['name'] ?? null ),
						'slug' => Database::text( $category['slug'] ?? null ),
					),
					$categories
				),
			);
		}

		return $records;
	}

	/**
	 * Converts database post rows to the stable parity schema.
	 *
	 * @param array<int,array<string,mixed>> $posts Post rows.
	 * @return array<int,array<string,mixed>> Serialized post records.
	 */
	private function serialize_posts( array $posts ): array {
		$records = array();
		foreach ( $posts as $index => $post ) {
			$records[] = array(
				'position' => $index + 1,
				'url'      => Database::text( $post['url'] ?? null ),
				'wpId'     => empty( $post['source_wp_id'] ) ? '' : Database::text( $post['source_wp_id'] ),
				'title'    => Database::text( $post['title'] ?? null ),
				'dateIso'  => substr( Database::text( $post['published_at'] ?? null ), 0, 10 ),
			);
		}
		return $records;
	}

	/**
	 * Builds implementation, runtime, and imported-data metadata.
	 *
	 * @return array<string,mixed> Parity manifest.
	 */
	private function manifest(): array {
		$wpdb        = Database::client();
		$tables      = Database::tables();
		$path_counts = array();
		foreach ( array(
			1 => 'browse-topics-1',
			2 => 'browse-topics-2',
		) as $number => $slug ) {
			$path_counts[ $number ] = Database::integer(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$tables['subject_areas']} sa "
						. "JOIN {$tables['browse_paths']} bp ON bp.id=sa.browse_path_id WHERE bp.slug=%s",
						$slug
					)
				)
			);
		}

		return array(
			'schemaVersion'   => self::SCHEMA_VERSION,
			'implementation'  => 'wordpress-mysql',
			'commit'          => Database::text( getenv( 'RENDER_GIT_COMMIT' ) ),
			'dataFingerprint' => $this->source_fingerprints(),
			'runtime'         => array(
				'php'       => PHP_VERSION,
				'mysql'     => (string) $wpdb->db_version(),
				'wordpress' => get_bloginfo( 'version' ),
			),
			'counts'          => array(
				'posts'             => Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['external_posts']}" ) ),
				'subjectAreas1'     => $path_counts[1],
				'subjectAreas2'     => $path_counts[2],
				'categories'        => Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['categories']}" ) ),
				'topics'            => Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['topics']}" ) ),
				'secondaryKeywords' => Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['keywords']}" ) ),
				'searchTerms'       => Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['post_search_terms']}" ) ),
			),
		);
	}

	/**
	 * Hashes the authoritative import files used by this implementation.
	 *
	 * @return array<string,mixed> Aggregate and per-file fingerprints.
	 * @throws RuntimeException When an authoritative source file cannot be fingerprinted.
	 */
	private function source_fingerprints(): array {
		$importer = new Importer();
		$context  = hash_init( 'sha256' );
		$files    = array();
		foreach ( self::SOURCE_FILES as $relative_path => $filename ) {
			$path = $importer->source_directory() . '/' . $filename;
			if ( ! is_readable( $path ) ) {
				throw new RuntimeException( "Unable to fingerprint {$filename}." );
			}
			$digest = hash_file( 'sha256', $path );
			if ( false === $digest ) {
				throw new RuntimeException( "Unable to fingerprint {$filename}." );
			}
			hash_update( $context, $relative_path . "\0" . $digest . "\0" );
			$files[] = array(
				'path'   => $relative_path,
				'sha256' => $digest,
				'bytes'  => (int) filesize( $path ),
			);
		}

		return array(
			'sha256' => hash_final( $context ),
			'files'  => $files,
		);
	}

	/**
	 * Fetches one category or topic by slug.
	 *
	 * @param string $table_key Allowlisted table key.
	 * @param string $slug      Record slug.
	 * @return array<string,mixed>|null Matching record, or null.
	 */
	private function record_by_slug( string $table_key, string $slug ): ?array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables[$table_key]} WHERE slug=%s LIMIT 1", $slug ),
			ARRAY_A
		);
		return Database::associative_row( $row );
	}

	/**
	 * Validates and normalizes a list of string values.
	 *
	 * @param mixed    $value   Candidate list.
	 * @param string   $field   Field name used in validation messages.
	 * @param int|null $maximum Optional maximum unique values.
	 * @return array<int,string> Unique normalized values.
	 * @throws InvalidArgumentException When the value is not a valid string list.
	 */
	private function string_list( $value, string $field, ?int $maximum = null ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( "{$field} must be a list of strings" );
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new InvalidArgumentException( "{$field} must be a list of strings" );
			}
		}
		$values = Search_Service::unique_terms( $value );
		if ( null !== $maximum && count( $values ) > $maximum ) {
			throw new InvalidArgumentException( "{$field} supports at most {$maximum} unique values" );
		}
		return $values;
	}

	/**
	 * Normalizes a requested sort mode.
	 *
	 * @param mixed $value Candidate sort value.
	 * @return string Supported sort mode.
	 */
	private function normalized_sort( $value ): string {
		$sort = $this->clean_string( $value );
		return in_array( $sort, array( 'ranked', 'newest', 'oldest' ), true ) ? $sort : 'ranked';
	}

	/**
	 * Converts a scalar-like value to a trimmed string.
	 *
	 * @param mixed $value Value to clean.
	 * @return string Cleaned value.
	 */
	private function clean_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
