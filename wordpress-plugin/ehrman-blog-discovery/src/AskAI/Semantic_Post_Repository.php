<?php
/**
 * Database reads for semantic candidate retrieval.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads eligible post vectors and the assigned-term ranking inputs. */
final class Semantic_Post_Repository {
	/**
	 * Loads current post records with their vectors.
	 *
	 * @return list<array<string,mixed>> Current post and embedding rows.
	 */
	public function candidate_rows(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = $wpdb->prepare(
			"SELECT p.*,e.content_hash,e.embedding,e.embedding_norm FROM {$tables['post_embeddings']} e JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id "
			. "WHERE e.model=%s AND e.dimensions=%d AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')",
			Embedding_Service::model_id(),
			Embedding_Service::dimensions()
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally and values are prepared.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Loads topic, alias and secondary-keyword assignments for ranking.
	 *
	 * @return list<array<string,mixed>> Assigned-term rows.
	 */
	public function assigned_terms(): array {
		$tables = Database::tables();
		$sql    = "SELECT post_id,normalized,kind FROM {$tables['post_search_terms']} WHERE kind IN ('topic','alias','secondary')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier and allowed kinds are internal constants.
		return Database::associative_rows( Database::client()->get_results( $sql, ARRAY_A ) );
	}
}
