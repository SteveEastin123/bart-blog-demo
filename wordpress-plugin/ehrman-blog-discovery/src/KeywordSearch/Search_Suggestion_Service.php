<?php
/**
 * Scoped keyword-search autocomplete suggestions.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds ranked topic and keyword suggestions from eligible posts. */
final class Search_Suggestion_Service {

	/**
	 * Creates the suggestion service.
	 *
	 * @param Search_Post_Repository $repository Database access for post matching.
	 */
	public function __construct( private Search_Post_Repository $repository ) {}

	/**
	 * Builds autocomplete suggestions from the currently eligible posts.
	 *
	 * Selected terms must already be cleaned and their modes resolved by the
	 * coordinating Search_Service.
	 *
	 * @param string            $query          Partial user input.
	 * @param array<int,string> $selected       Previously selected terms.
	 * @param string            $category_slug  Optional category scope.
	 * @param string            $topic_slug     Optional topic scope.
	 * @param array<int,string> $selected_modes Search modes aligned with selected terms.
	 * @return array<int,array<string,mixed>> Ranked topic and keyword suggestions.
	 */
	public function suggestions(
		string $query,
		array $selected,
		string $category_slug,
		string $topic_slug,
		array $selected_modes
	): array {
		$wpdb = Database::client();

		$tables              = Database::tables();
		$query_normalized    = Search_Service::normalize( $query );
		$selected_normalized = array_values(
			array_unique( array_map( array( Search_Service::class, 'normalize' ), $selected ) )
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
			$category = $this->repository->record_by_slug( 'categories', $category_slug );
			if ( null === $category ) {
				return array();
			}
			$category_id = Database::integer( $category['id'] ?? null );
			$eligible    = $this->repository->category_post_ids( $category_id );
			$sql         = "SELECT t.name FROM {$tables['topics']} t "
				. "JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
				. 'WHERE tc.category_id=%d AND t.display_in_browser=1';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query placeholders are prepared here; identifiers come from Database::tables().
			$allowed_category_topics = Database::strings( $wpdb->get_col( $wpdb->prepare( $sql, $category_id ) ) );
		}

		if ( '' !== $topic_slug ) {
			$topic = $this->repository->record_by_slug( 'topics', $topic_slug );
			if ( null === $topic ) {
				return array();
			}
			$eligible = self::intersect_id_sets( $eligible, $this->repository->topic_post_ids( Database::integer( $topic['id'] ?? null ) ) );
		}

		foreach ( $selected as $index => $term ) {
			$eligible = self::intersect_id_sets(
				$eligible,
				array_fill_keys(
					array_keys( $this->repository->post_scores_for_term( $term, $selected_modes[ $index ] ?? Search_Service::TERM_MODE_COMBINED ) ),
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
			$topic_descriptions[ Search_Service::normalize( Database::text( $row['name'] ?? null ) ) ] = Database::text( $row['description'] ?? null );
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
					? ( $topic_descriptions[ Search_Service::normalize( Database::text( $row['label'] ?? null ) ) ] ?? '' )
					: '',
			);
			if ( $has_topic ) {
				$topic_count = count( $topic_posts[ $normalized ] ?? array() );
				if ( $topic_count > 0 && ( null === $context_post_count || $topic_count < $context_post_count ) ) {
					$suggestions[] = $base + array(
						'postCount' => $topic_count,
						'mode'      => Search_Service::TERM_MODE_TOPIC,
						'typeRank'  => 3,
					);
				}
			}
			if ( $has_topic && $has_keyword && ( null === $context_post_count || $post_count < $context_post_count ) ) {
				$suggestions[] = $base + array(
					'postCount' => $post_count,
					'mode'      => Search_Service::TERM_MODE_COMBINED,
					'typeRank'  => 2,
				);
			} elseif ( ! $has_topic && ( null === $context_post_count || $post_count < $context_post_count ) ) {
				$suggestions[] = $base + array(
					'postCount' => $post_count,
					'mode'      => Search_Service::TERM_MODE_KEYWORD,
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
				'isTopic'     => Search_Service::TERM_MODE_TOPIC === $item['mode'],
				'isCombined'  => Search_Service::TERM_MODE_COMBINED === $item['mode'],
				'description' => $item['description'],
			),
			$suggestions
		);
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
}
