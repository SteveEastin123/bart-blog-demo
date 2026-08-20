<?php
/**
 * Post search and autocomplete services.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Searches imported post metadata and builds scoped autocomplete suggestions. */
final class Search_Service {

	/** Maximum number of terms accepted by a search. */
	public const MAX_TERMS = 4;

	/** Number of posts shown on each public results page. */
	public const POSTS_PER_PAGE = 25;

	/** Maximum stored and requested search-term length. */
	public const MAX_TERM_LENGTH = 191;

	/** Search only direct topic assignments for a selected label. */
	public const TERM_MODE_TOPIC = 'topic';

	/** Search the union of topic and secondary-keyword matches. */
	public const TERM_MODE_COMBINED = 'topic-keyword';

	/** Search a secondary-keyword label using the normal broad term lookup. */
	public const TERM_MODE_KEYWORD = 'keyword';

	/**
	 * Searches posts using AND semantics across terms and optional scope.
	 *
	 * @param array<int,string> $terms         Search terms.
	 * @param string            $sort          Sort mode.
	 * @param string            $category_slug Optional category scope.
	 * @param string            $topic_slug    Optional topic scope.
	 * @param int               $page          Requested results page.
	 * @param int               $per_page      Page size, or zero to return every matching post.
	 * @param array<int,string> $term_modes    Search mode aligned with each term.
	 * @return array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} Search result payload.
	 */
	public function search(
		array $terms,
		string $sort = 'ranked',
		string $category_slug = '',
		string $topic_slug = '',
		int $page = 1,
		int $per_page = 0,
		array $term_modes = array()
	): array {
		$terms         = self::unique_terms( $terms );
		$term_modes    = $this->resolve_term_modes( $terms, $term_modes );
		$sort          = self::clean_sort( $sort );
		$category_slug = sanitize_title( $category_slug );
		$topic_slug    = sanitize_title( $topic_slug );
		$eligible      = null;
		$topic         = null;

		if ( '' !== $category_slug ) {
			$category = $this->record_by_slug( 'categories', $category_slug );
			if ( null === $category ) {
				return $this->search_result( array(), $terms, $sort, $page, $per_page );
			}
			$eligible = $this->category_post_ids( Database::integer( $category['id'] ?? null ) );
		}

		if ( '' !== $topic_slug ) {
			$topic = $this->record_by_slug( 'topics', $topic_slug );
			if ( null === $topic ) {
				return $this->search_result( array(), $terms, $sort, $page, $per_page );
			}
			$eligible = self::intersect_id_sets( $eligible, $this->topic_post_ids( Database::integer( $topic['id'] ?? null ) ) );
		}

		if ( null === $eligible && empty( $terms ) ) {
			return $this->search_result( array(), $terms, $sort, $page, $per_page );
		}

		$filter_terms = $terms;
		$filter_modes = $term_modes;
		if ( null !== $topic ) {
			$topic_normalized = self::normalize( Database::text( $topic['name'] ?? null ) );
			$filter_terms     = array();
			$filter_modes     = array();
			foreach ( $terms as $index => $term ) {
				if ( self::normalize( $term ) === $topic_normalized ) {
					continue;
				}
				$filter_terms[] = $term;
				$filter_modes[] = $term_modes[ $index ] ?? self::TERM_MODE_COMBINED;
			}
		}

		$scores = null === $eligible ? null : array_fill_keys( array_keys( $eligible ), 0 );
		foreach ( $filter_terms as $index => $term ) {
			$scores = self::intersect_scores(
				$scores,
				$this->post_scores_for_term( $term, $filter_modes[ $index ] ?? self::TERM_MODE_COMBINED )
			);
		}

		if ( null === $scores ) {
			$scores = array();
		}
		if ( empty( $scores ) ) {
			return $this->search_result( array(), $terms, $sort, $page, $per_page );
		}

		$posts = $this->posts_by_ids( array_keys( $scores ) );
		$posts = $this->sort_posts( $posts, $sort, $terms, $scores );

		return $this->search_result( $posts, $terms, $sort, $page, $per_page );
	}

	/**
	 * Identifies selected terms as topics or secondary keywords.
	 *
	 * When the same normalized label exists in both groups, it is identified as
	 * a combined topic-and-keyword search.
	 *
	 * @param array<int,string> $terms Selected search terms.
	 * @return array<string,string> Term types keyed by normalized term.
	 */
	public function term_types( array $terms ): array {
		$normalized_terms = array_values(
			array_unique(
				array_filter(
					array_map( array( self::class, 'normalize' ), self::unique_terms( $terms ) )
				)
			)
		);
		if ( empty( $normalized_terms ) ) {
			return array();
		}

		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT normalized,MAX(CASE WHEN kind IN ('topic','alias') THEN 1 ELSE 0 END) is_topic,"
			. "MAX(CASE WHEN kind='secondary' THEN 1 ELSE 0 END) is_keyword "
			. "FROM {$tables['post_search_terms']} WHERE normalized IN ("
			. implode( ',', array_fill( 0, count( $normalized_terms ), '%s' ) )
			. ') GROUP BY normalized';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated internally and prepared here.
		$rows = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( $sql, $normalized_terms ), ARRAY_A ) );

		$types = array_fill_keys( $normalized_terms, self::TERM_MODE_KEYWORD );
		foreach ( $rows as $row ) {
			$normalized = Database::text( $row['normalized'] ?? null );
			if ( ! isset( $types[ $normalized ] ) ) {
				continue;
			}
			$is_topic   = 1 === Database::integer( $row['is_topic'] ?? null );
			$is_keyword = 1 === Database::integer( $row['is_keyword'] ?? null );
			if ( $is_topic && $is_keyword ) {
				$types[ $normalized ] = self::TERM_MODE_COMBINED;
			} elseif ( $is_topic ) {
				$types[ $normalized ] = self::TERM_MODE_TOPIC;
			}
		}

		return $types;
	}

	/**
	 * Resolves requested modes and infers safe modes for legacy URLs.
	 *
	 * @param array<int,string> $terms Requested terms.
	 * @param array<int,string> $modes Requested modes aligned with terms.
	 * @return array<int,string> Validated modes aligned with terms.
	 */
	public function resolve_term_modes( array $terms, array $modes = array() ): array {
		$terms    = self::unique_terms( $terms );
		$inferred = $this->term_types( $terms );
		$resolved = array();
		foreach ( $terms as $index => $term ) {
			$requested = sanitize_key( is_scalar( $modes[ $index ] ?? null ) ? (string) $modes[ $index ] : '' );
			if ( in_array( $requested, self::term_modes(), true ) ) {
				$resolved[] = $requested;
				continue;
			}
			$resolved[] = $inferred[ self::normalize( $term ) ] ?? self::TERM_MODE_KEYWORD;
		}
		return $resolved;
	}

	/**
	 * Returns the supported selected-term modes.
	 *
	 * @return list<string> Supported selected-term modes.
	 */
	public static function term_modes(): array {
		return array( self::TERM_MODE_TOPIC, self::TERM_MODE_COMBINED, self::TERM_MODE_KEYWORD );
	}

	/**
	 * Builds autocomplete suggestions from the currently eligible posts.
	 *
	 * @param string            $query         Partial user input.
	 * @param array<int,string> $selected      Previously selected terms.
	 * @param string            $category_slug Optional category scope.
	 * @param string            $topic_slug    Optional topic scope.
	 * @param array<int,string> $selected_modes Search modes aligned with selected terms.
	 * @return array<int,array<string,mixed>> Ranked topic and keyword suggestions.
	 */
	public function suggestions(
		string $query,
		array $selected = array(),
		string $category_slug = '',
		string $topic_slug = '',
		array $selected_modes = array()
	): array {
		$wpdb = Database::client();

		$tables              = Database::tables();
		$query_normalized    = self::normalize( $query );
		$selected            = self::unique_terms( $selected );
		$selected_modes      = $this->resolve_term_modes( $selected, $selected_modes );
		$selected_normalized = array_values(
			array_unique( array_map( array( self::class, 'normalize' ), $selected ) )
		);
		sort( $selected_normalized, SORT_STRING );
		$category_slug = sanitize_title( $category_slug );
		$topic_slug    = sanitize_title( $topic_slug );

		if ( '' === $query_normalized && empty( $selected ) && '' === $category_slug && '' === $topic_slug ) {
			return array();
		}

		$eligible                = null;
		$allowed_category_topics = array();
		if ( '' !== $category_slug ) {
			$category = $this->record_by_slug( 'categories', $category_slug );
			if ( null === $category ) {
				return array();
			}
			$category_id = Database::integer( $category['id'] ?? null );
			$eligible    = $this->category_post_ids( $category_id );
			$sql         = "SELECT t.name FROM {$tables['topics']} t "
				. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
				. 'WHERE tc.category_id=%d AND t.display_in_browser=1';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
			$allowed_category_topics = Database::strings( $wpdb->get_col( $wpdb->prepare( $sql, $category_id ) ) );
		}

		if ( '' !== $topic_slug ) {
			$topic = $this->record_by_slug( 'topics', $topic_slug );
			if ( null === $topic ) {
				return array();
			}
			$eligible = self::intersect_id_sets( $eligible, $this->topic_post_ids( Database::integer( $topic['id'] ?? null ) ) );
		}

		foreach ( $selected as $index => $term ) {
			$eligible = self::intersect_id_sets(
				$eligible,
				array_fill_keys(
					array_keys( $this->post_scores_for_term( $term, $selected_modes[ $index ] ?? self::TERM_MODE_COMBINED ) ),
					true
				)
			);
		}
		if ( is_array( $eligible ) && empty( $eligible ) ) {
			return array();
		}
		$context_post_count = ! empty( $selected ) ? count( $eligible ) : null;

		$where  = array( "normalized <> 'ignore'" );
		$params = array( $query_normalized, $query_normalized . '%', '% ' . $query_normalized . '%' );
		if ( '' === $query_normalized ) {
			$where[] = "kind <> 'alias'";
		}
		if ( '' !== $query_normalized ) {
			$where[]  = '(normalized LIKE %s OR normalized LIKE %s)';
			$params[] = $query_normalized . '%';
			$params[] = '% ' . $query_normalized . '%';
		}
		if ( is_array( $eligible ) ) {
			$ids = array_keys( $eligible );
			sort( $ids, SORT_NUMERIC );
			$where[] = 'post_id IN (' . self::integer_list( $ids ) . ')';
		}
		if ( '' !== $category_slug ) {
			if ( ! empty( $allowed_category_topics ) ) {
				$where[] = "(kind NOT IN ('topic','alias') OR label IN ("
					. implode( ',', array_fill( 0, count( $allowed_category_topics ), '%s' ) ) . '))';
				array_push( $params, ...$allowed_category_topics );
			} else {
				$where[] = "kind NOT IN ('topic','alias')";
			}
		}
		if ( ! empty( $selected_normalized ) ) {
			$where[] = 'normalized NOT IN ('
				. implode( ',', array_fill( 0, count( $selected_normalized ), '%s' ) ) . ')';
			array_push( $params, ...$selected_normalized );
		}

		$limit = '' !== $category_slug && '' === $query_normalized && empty( $selected ) && '' === $topic_slug
			? ''
			: ( ! empty( $selected ) ? ' LIMIT 192' : ' LIMIT 48' );
		$sql   = "SELECT COALESCE(MIN(CASE WHEN kind IN ('topic','alias') THEN label END),MIN(label)) label, "
			. 'normalized, COUNT(DISTINCT post_id) post_count, '
			. "MAX(CASE WHEN kind IN ('topic','alias') THEN 1 ELSE 0 END) has_topic, "
			. "MAX(CASE WHEN kind='secondary' THEN 1 ELSE 0 END) has_keyword, "
			. 'CASE WHEN normalized=%s THEN 3 WHEN normalized LIKE %s THEN 2 '
			. 'WHEN normalized LIKE %s THEN 1 ELSE 1 END match_quality '
			. "FROM {$tables['post_search_terms']} WHERE " . implode( ' AND ', $where )
			. ' GROUP BY normalized ORDER BY match_quality DESC,post_count DESC,has_topic DESC,label ASC'
			. $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated internally and prepared here.
		$rows = Database::associative_rows( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) );
		if ( empty( $rows ) ) {
			return array();
		}

		$candidate_normalized = array();
		foreach ( $rows as $row ) {
			$normalized = Database::text( $row['normalized'] ?? null );
			if ( '' !== $normalized ) {
				$candidate_normalized[ $normalized ] = true;
			}
		}
		$count_where = '';
		if ( is_array( $eligible ) ) {
			$ids = array_keys( $eligible );
			sort( $ids, SORT_NUMERIC );
			$count_where = ' WHERE post_id IN (' . self::integer_list( $ids ) . ')';
		}
		$count_rows     = Database::associative_rows(
			$wpdb->get_results(
				"SELECT DISTINCT post_id,normalized,kind FROM {$tables['post_search_terms']}{$count_where}",
				ARRAY_A
			)
		);
		$matching_posts = array_fill_keys( array_keys( $candidate_normalized ), array() );
		$topic_posts    = array_fill_keys( array_keys( $candidate_normalized ), array() );
		foreach ( $count_rows as $count_row ) {
			$indexed = Database::text( $count_row['normalized'] ?? null );
			$post_id = Database::integer( $count_row['post_id'] ?? null );
			foreach ( $candidate_normalized as $candidate => $_unused ) {
				if ( $indexed === $candidate || str_contains( " {$indexed} ", " {$candidate} " ) ) {
					$matching_posts[ $candidate ][ $post_id ] = true;
				}
				if ( $indexed === $candidate && in_array( Database::text( $count_row['kind'] ?? null ), array( 'topic', 'alias' ), true ) ) {
					$topic_posts[ $candidate ][ $post_id ] = true;
				}
			}
		}

		$description_rows   = Database::associative_rows(
			$wpdb->get_results(
				"SELECT name,description FROM {$tables['topics']} WHERE display_in_browser=1",
				ARRAY_A
			)
		);
		$topic_descriptions = array();
		foreach ( $description_rows as $row ) {
			$topic_descriptions[ self::normalize( Database::text( $row['name'] ?? null ) ) ] = Database::text( $row['description'] ?? null );
		}

		$suggestions = array();
		foreach ( $rows as $row ) {
			$normalized = Database::text( $row['normalized'] ?? null );
			$post_count = count( $matching_posts[ $normalized ] ?? array() );
			if ( 0 === $post_count ) {
				continue;
			}
			$has_topic   = 1 === Database::integer( $row['has_topic'] ?? null );
			$has_keyword = 1 === Database::integer( $row['has_keyword'] ?? null );
			$base        = array(
				'label'        => Database::text( $row['label'] ?? null ),
				'normalized'   => $normalized,
				'matchQuality' => Database::integer( $row['match_quality'] ?? null ),
				'description'  => $has_topic
					? ( $topic_descriptions[ self::normalize( Database::text( $row['label'] ?? null ) ) ] ?? '' )
					: '',
			);
			if ( $has_topic ) {
				$topic_count = count( $topic_posts[ $normalized ] ?? array() );
				if ( $topic_count > 0 && ( null === $context_post_count || $topic_count < $context_post_count ) ) {
					$suggestions[] = $base + array(
						'postCount' => $topic_count,
						'mode'      => self::TERM_MODE_TOPIC,
						'typeRank'  => 3,
					);
				}
			}
			if ( $has_topic && $has_keyword && ( null === $context_post_count || $post_count < $context_post_count ) ) {
				$suggestions[] = $base + array(
					'postCount' => $post_count,
					'mode'      => self::TERM_MODE_COMBINED,
					'typeRank'  => 2,
				);
			} elseif ( ! $has_topic && ( null === $context_post_count || $post_count < $context_post_count ) ) {
				$suggestions[] = $base + array(
					'postCount' => $post_count,
					'mode'      => self::TERM_MODE_KEYWORD,
					'typeRank'  => 1,
				);
			}
		}

		usort(
			$suggestions,
			static function ( array $left, array $right ): int {
				foreach ( array( 'matchQuality', 'postCount', 'typeRank' ) as $field ) {
					$comparison = (int) $right[ $field ] <=> (int) $left[ $field ];
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				return strcasecmp( $left['label'], $right['label'] );
			}
		);

		if ( '' !== $limit ) {
			$suggestions = array_slice( $suggestions, 0, 48 );
		}
		return array_map(
			static fn( array $item ): array => array(
				'label'       => $item['label'],
				'normalized'  => $item['normalized'],
				'postCount'   => $item['postCount'],
				'mode'        => $item['mode'],
				'isTopic'     => self::TERM_MODE_TOPIC === $item['mode'],
				'isCombined'  => self::TERM_MODE_COMBINED === $item['mode'],
				'description' => $item['description'],
			),
			$suggestions
		);
	}

	/**
	 * Normalizes a label for comparison and indexed lookup.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string Lowercase alphanumeric search representation.
	 */
	public static function normalize( $value ): string {
		$text = strtolower( str_replace( '&', ' and ', trim( is_scalar( $value ) ? (string) $value : '' ) ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
		return (string) preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Sanitizes, de-duplicates, bounds, and limits search terms.
	 *
	 * @param array<int,mixed> $terms Raw search terms.
	 * @return array<int,string> Unique safe terms.
	 */
	public static function unique_terms( array $terms ): array {
		$values = array();
		$seen   = array();
		foreach ( $terms as $term ) {
			$value      = sanitize_text_field( is_scalar( $term ) ? (string) $term : '' );
			$value      = function_exists( 'mb_substr' )
				? mb_substr( $value, 0, self::MAX_TERM_LENGTH )
				: substr( $value, 0, self::MAX_TERM_LENGTH );
			$normalized = self::normalize( $value );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;
			$values[]            = $value;
			if ( count( $values ) >= self::MAX_TERMS ) {
				break;
			}
		}
		return $values;
	}

	/**
	 * Finds a record by slug in an allowed custom table.
	 *
	 * @param string $table_key Logical custom-table key.
	 * @param string $slug      Record slug.
	 * @return array<string,mixed>|null Record when found.
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
	 * Returns the unique posts connected to a category.
	 *
	 * @param int $category_id Category database identifier.
	 * @return array<int,bool> Post-ID set.
	 */
	private function category_post_ids( int $category_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT DISTINCT pt.post_id FROM {$tables['post_topics']} pt "
			. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id WHERE tc.category_id=%d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $category_id ) );
		return array_fill_keys( array_map( static fn( $id ): int => Database::integer( $id ), $ids ), true );
	}

	/**
	 * Returns the posts assigned directly to a topic.
	 *
	 * @param int $topic_id Topic database identifier.
	 * @return array<int,bool> Post-ID set.
	 */
	private function topic_post_ids( int $topic_id ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = "SELECT post_id FROM {$tables['post_topics']} WHERE topic_id=%d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $topic_id ) );
		return array_fill_keys( array_map( static fn( $id ): int => Database::integer( $id ), $ids ), true );
	}

	/**
	 * Returns matching post IDs and index weights for one term.
	 *
	 * @param string $term Search term.
	 * @param string $mode Selected term mode.
	 * @return array<int,int> Scores keyed by post ID.
	 */
	private function post_scores_for_term( string $term, string $mode = self::TERM_MODE_COMBINED ): array {
		$wpdb       = Database::client();
		$tables     = Database::tables();
		$normalized = self::normalize( $term );
		if ( '' === $normalized ) {
			return array();
		}
		if ( self::TERM_MODE_TOPIC === $mode ) {
			$sql  = 'SELECT post_id,MAX(weight+2) score '
				. "FROM {$tables['post_search_terms']} WHERE normalized=%s AND kind IN ('topic','alias') GROUP BY post_id";
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, $normalized ),
				ARRAY_A
			);
		} else {
			$sql  = 'SELECT post_id,MAX(weight+CASE WHEN normalized=%s THEN 2 ELSE 0 END) score '
				. "FROM {$tables['post_search_terms']} WHERE normalized=%s "
				. "OR CONCAT(' ',normalized,' ') LIKE %s GROUP BY post_id";
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
				$wpdb->prepare( $sql, $normalized, $normalized, "% {$normalized} %" ),
				ARRAY_A
			);
		}
		$matches = array();
		foreach ( Database::associative_rows( $rows ) as $row ) {
			$matches[ Database::integer( $row['post_id'] ?? null ) ] = Database::integer( $row['score'] ?? null );
		}
		return $matches;
	}

	/**
	 * Loads post records for a sanitized set of IDs.
	 *
	 * @param array<int,int|string> $post_ids Post identifiers.
	 * @return array<int,array<string,mixed>> Post records.
	 */
	private function posts_by_ids( array $post_ids ): array {
		$wpdb = Database::client();
		if ( empty( $post_ids ) ) {
			return array();
		}
		$tables = Database::tables();
		$sql    = "SELECT * FROM {$tables['external_posts']} WHERE id IN (" . self::integer_list( $post_ids ) . ')';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The ID list is reduced to integers by integer_list().
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Sorts posts by relevance or publication date.
	 *
	 * @param array<int,array<string,mixed>> $posts  Post records.
	 * @param string                         $sort   Sort mode.
	 * @param array<int,string>              $terms  Search terms.
	 * @param array<int,int>                 $scores Indexed scores keyed by post ID.
	 * @return array<int,array<string,mixed>> Sorted posts.
	 */
	private function sort_posts( array $posts, string $sort, array $terms, array $scores ): array {
		if ( 'ranked' === $sort ) {
			foreach ( $posts as $post ) {
				$post_id = Database::integer( $post['id'] ?? null );
				$score   = (int) ( $scores[ $post_id ] ?? 0 );
				foreach ( $terms as $term ) {
					$score += self::title_boost( Database::text( $post['title'] ?? null ), $term );
					$score += self::description_boost( Database::text( $post['description'] ?? null ), $term );
				}
				$scores[ $post_id ] = $score;
			}
		}

		usort(
			$posts,
			static function ( array $left, array $right ) use ( $sort, $scores ): int {
				if ( 'ranked' === $sort ) {
					$right_id   = Database::integer( $right['id'] ?? null );
					$left_id    = Database::integer( $left['id'] ?? null );
					$comparison = ( $scores[ $right_id ] ?? 0 ) <=> ( $scores[ $left_id ] ?? 0 );
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				$date_comparison = strcmp( Database::text( $left['published_at'] ?? null ), Database::text( $right['published_at'] ?? null ) );
				if ( 0 !== $date_comparison ) {
					return 'oldest' === $sort ? $date_comparison : -$date_comparison;
				}
				$url_comparison = strcasecmp( Database::text( $left['url'] ?? null ), Database::text( $right['url'] ?? null ) );
				return 'oldest' === $sort ? $url_comparison : -$url_comparison;
			}
		);
		return $posts;
	}

	/**
	 * Calculates the ranking boost for a term found in a title.
	 *
	 * @param string $title Post title.
	 * @param string $term  Search term.
	 * @return int Title relevance boost.
	 */
	private static function title_boost( string $title, string $term ): int {
		$title = self::normalize( $title );
		$term  = self::ranking_term( $term );
		if ( '' === $title || '' === $term ) {
			return 0;
		}
		if ( str_contains( " {$title} ", " {$term} " ) ) {
			return 4;
		}
		if ( ! str_contains( $term, ' ' ) && in_array( $term, explode( ' ', $title ), true ) ) {
			return 1;
		}
		$anchor = self::ranking_anchor( $term );
		return '' !== $anchor && in_array( $anchor, explode( ' ', $title ), true ) ? 2 : 0;
	}

	/**
	 * Calculates the ranking boost for a term found in a description.
	 *
	 * @param string $description Post description.
	 * @param string $term        Search term.
	 * @return int Description relevance boost.
	 */
	private static function description_boost( string $description, string $term ): int {
		$description = self::normalize( $description );
		$term        = self::ranking_term( $term );
		if ( '' === $description || '' === $term ) {
			return 0;
		}
		if ( str_contains( " {$description} ", " {$term} " ) ) {
			return 2;
		}
		$anchor = self::ranking_anchor( $term );
		return '' !== $anchor && in_array( $anchor, explode( ' ', $description ), true ) ? 1 : 0;
	}

	/**
	 * Removes display-only general qualifiers before ranking.
	 *
	 * @param string $term Search term.
	 * @return string Ranking form of the term.
	 */
	private static function ranking_term( string $term ): string {
		$normalized = self::normalize( $term );
		return str_ends_with( $normalized, ' general' )
			? rtrim( substr( $normalized, 0, -strlen( ' general' ) ) )
			: $normalized;
	}

	/**
	 * Selects a meaningful phrase token for partial ranking boosts.
	 *
	 * @param string $term Search term.
	 * @return string Ranking anchor or an empty string.
	 */
	private static function ranking_anchor( string $term ): string {
		$stopwords = array_fill_keys(
			array(
				'a',
				'an',
				'and',
				'as',
				'at',
				'belief',
				'beliefs',
				'by',
				'for',
				'from',
				'general',
				'in',
				'into',
				'issue',
				'issues',
				'of',
				'on',
				'or',
				'overview',
				'question',
				'questions',
				'the',
				'to',
				'tradition',
				'traditions',
				'with',
			),
			true
		);
		$term      = self::ranking_term( $term );
		if ( ! str_contains( $term, ' ' ) ) {
			return '';
		}
		$tokens = array_values(
			array_filter(
				explode( ' ', $term ),
				static fn( string $token ): bool => strlen( $token ) >= 4 && ! isset( $stopwords[ $token ] )
			)
		);
		return empty( $tokens ) ? '' : (string) end( $tokens );
	}

	/**
	 * Intersects score maps while adding scores for matching posts.
	 *
	 * @param array<int,int>|null $left  Existing score map.
	 * @param array<int,int>      $right New term score map.
	 * @return array<int,int> Intersected score map.
	 */
	private static function intersect_scores( ?array $left, array $right ): array {
		if ( null === $left ) {
			return $right;
		}
		$intersection = array();
		foreach ( $left as $post_id => $score ) {
			if ( isset( $right[ $post_id ] ) ) {
				$intersection[ $post_id ] = (int) $score + (int) $right[ $post_id ];
			}
		}
		return $intersection;
	}

	/**
	 * Intersects two post-ID sets.
	 *
	 * @param array<int,bool>|null $left  Existing post-ID set.
	 * @param array<int,bool>      $right New post-ID set.
	 * @return array<int,bool> Intersected post-ID set.
	 */
	private static function intersect_id_sets( ?array $left, array $right ): array {
		return null === $left ? $right : array_intersect_key( $left, $right );
	}

	/**
	 * Converts IDs into an integer-only SQL list.
	 *
	 * @param array<int,int|string> $values Raw identifiers.
	 * @return string Comma-separated integers.
	 */
	private static function integer_list( array $values ): string {
		$values = array_map( static fn( $value ): int => Database::integer( $value ), $values );
		return empty( $values ) ? '0' : implode( ',', $values );
	}

	/**
	 * Restricts a requested sort value to supported modes.
	 *
	 * @param string $sort Requested sort mode.
	 * @return string Supported sort mode.
	 */
	private static function clean_sort( string $sort ): string {
		return in_array( $sort, array( 'ranked', 'newest', 'oldest' ), true ) ? $sort : 'ranked';
	}

	/**
	 * Builds the stable public search-result shape.
	 *
	 * @param array<int,array<string,mixed>> $posts Result posts.
	 * @param array<int,string>              $terms Search terms.
	 * @param string                         $sort     Applied sort mode.
	 * @param int                            $page     Requested results page.
	 * @param int                            $per_page Page size, or zero for every post.
	 * @return array{posts:list<array<string,mixed>>,terms:list<string>,sort:string,count:int,page:int,per_page:int,total_pages:int} Search result payload.
	 */
	private function search_result( array $posts, array $terms, string $sort, int $page, int $per_page ): array {
		$count       = count( $posts );
		$page        = max( 1, $page );
		$per_page    = max( 0, $per_page );
		$total_pages = $count > 0 ? 1 : 0;

		if ( $per_page > 0 && $count > 0 ) {
			$total_pages = (int) ceil( $count / $per_page );
			$page        = min( $page, $total_pages );
			$posts       = array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
		} elseif ( 0 === $count ) {
			$page = 1;
		}

		return array(
			'posts'       => array_values( $posts ),
			'terms'       => array_values( $terms ),
			'sort'        => $sort,
			'count'       => $count,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}
}
