<?php
/**
 * Keyword-search result ranking.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Applies metadata boosts and deterministic ordering to matching posts. */
final class Search_Post_Ranker {
	/**
	 * Sorts posts by relevance or publication date.
	 *
	 * @param array<int,array<string,mixed>> $posts  Post records.
	 * @param string                         $sort   Sort mode.
	 * @param array<int,string>              $terms  Search terms.
	 * @param array<int,int>                 $scores Indexed scores keyed by post ID.
	 * @return array<int,array<string,mixed>> Sorted posts.
	 */
	public function sort( array $posts, string $sort, array $terms, array $scores ): array {
		if ( 'ranked' === $sort ) {
			foreach ( $posts as $post ) {
				$post_id = Database::integer( $post['id'] ?? null );
				$score   = (int) ( $scores[ $post_id ] ?? 0 );
				foreach ( $terms as $term ) {
					$score += $this->title_boost( Database::text( $post['title'] ?? null ), $term );
					$score += $this->description_boost( Database::text( $post['description'] ?? null ), $term );
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
	 */
	private function title_boost( string $title, string $term ): int {
		$title = Search_Service::normalize( $title );
		$term  = $this->ranking_term( $term );
		if ( '' === $title || '' === $term ) {
			return 0;
		}
		if ( str_contains( " {$title} ", " {$term} " ) ) {
			return 4;
		}
		if ( ! str_contains( $term, ' ' ) && in_array( $term, explode( ' ', $title ), true ) ) {
			return 1;
		}
		$anchor = $this->ranking_anchor( $term );
		return '' !== $anchor && in_array( $anchor, explode( ' ', $title ), true ) ? 2 : 0;
	}

	/**
	 * Calculates the ranking boost for a term found in a description.
	 *
	 * @param string $description Post description.
	 * @param string $term        Search term.
	 */
	private function description_boost( string $description, string $term ): int {
		$description = Search_Service::normalize( $description );
		$term        = $this->ranking_term( $term );
		if ( '' === $description || '' === $term ) {
			return 0;
		}
		if ( str_contains( " {$description} ", " {$term} " ) ) {
			return 2;
		}
		$anchor = $this->ranking_anchor( $term );
		return '' !== $anchor && in_array( $anchor, explode( ' ', $description ), true ) ? 1 : 0;
	}

	/**
	 * Removes display-only general qualifiers before ranking.
	 *
	 * @param string $term Search term.
	 */
	private function ranking_term( string $term ): string {
		$normalized = Search_Service::normalize( $term );
		return str_ends_with( $normalized, ' general' )
			? rtrim( substr( $normalized, 0, -strlen( ' general' ) ) )
			: $normalized;
	}

	/**
	 * Selects a meaningful phrase token for partial ranking boosts.
	 *
	 * @param string $term Search term.
	 */
	private function ranking_anchor( string $term ): string {
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
		$term      = $this->ranking_term( $term );
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
}
