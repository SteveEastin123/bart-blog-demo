<?php
/** Read-only adapter to the installed production algorithm. Never shipped to Render. */
namespace EhrmanSectionPilot;

use EhrmanBlogDiscovery\AI_Candidate_Reviewer;
use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Semantic_Search_Service;

final class Baseline {

	/** Invoke an unchanged production pure helper without adding experimental public APIs. */
	public static function invoke( object $instance, string $method, array $arguments ) {
		$reflection = new \ReflectionMethod( $instance, $method );
		return $reflection->invokeArgs( $instance, $arguments );
	}

	/** Rank the frozen sample using the actual Ask AI 2 implementation. */
	public static function rank( string $question, array $posts ): array {
		usort(
			$posts,
			static function ( $a, $b ) {
				$order = $b['semantic_score'] <=> $a['semantic_score'];
				return 0 !== $order ? $order : strcmp( $b['published_at'], $a['published_at'] );
			}
		);
		$ids      = array_map( static fn( $p ) => (int) $p['id'], $posts );
		$table    = Database::tables()['post_search_terms'];
		$expected = "SELECT post_id,normalized,kind FROM {$table} WHERE kind IN ('topic','alias','secondary')";
		$scoped   = static function ( $sql ) use ( $expected, $ids ) {
			return $sql === $expected ? $sql . ' AND post_id IN (' . implode( ',', $ids ) . ')' : $sql;
		};
		// Scope only this one SELECT so ranks and IDF are calculated within the same 500 posts.
		add_filter( 'query', $scoped );
		try {
			return self::invoke( new Semantic_Search_Service(), 'hybrid_rank', array( $question, $posts ) );
		} finally {
			remove_filter( 'query', $scoped );
		}
	}

	public static function reviewer( string $model ): AI_Candidate_Reviewer {
		// Only pure payload/parser helpers are called; production caches and analytics are untouched.
		return new AI_Candidate_Reviewer( '', $model );
	}
}
