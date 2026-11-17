<?php
/**
 * Imported metadata baseline for the existing revision workflow.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Records imported provenance without inventing AI calls or a publisher approval. */
final class Imported_Metadata_Record {
	/**
	 * Stores an immutable baseline in the caller's linking transaction.
	 *
	 * @param array<string,mixed> $post Linked discovery row, not native article content.
	 * @param string              $taxonomy_hash Current vocabulary fingerprint.
	 * @throws RuntimeException When the baseline cannot be stored.
	 */
	public static function create( array $post, string $taxonomy_hash ): void {
		$wpdb     = Database::client();
		$tables   = Database::tables();
		$id       = Database::integer( $post['id'] );
		$proposal = array(
			'description'          => Database::text( $post['description'] ),
			'searchSummary'        => Database::text( $post['search_summary'] ),
			'topics'               => self::terms( $id, 'topics', 'post_topics', 'topic_id', 'name' ),
			'topicRationales'      => array(),
			'secondaryKeywords'    => self::terms( $id, 'keywords', 'post_keywords', 'keyword_id', 'label' ),
			'newSecondaryKeywords' => array(),
			'status'               => 'ready',
			'reviewNotes'          => array(),
		);
		$vector   = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_wp_id=%d', $tables['post_embeddings'], Database::integer( $post['source_wp_id'] ) ), ARRAY_A ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read the imported vector status.' );
		}
		$current = null !== $vector && Semantic_Index_Service::content_hash( $post ) === $vector['content_hash']
			&& Embedding_Service::model_id() === $vector['model'] && Embedding_Service::dimensions() === Database::integer( $vector['dimensions'] )
			&& strlen( Database::text( $vector['embedding'] ) ) === 4 * Embedding_Service::dimensions()
			&& (float) Database::text( $vector['embedding_norm'] ) > 0;
		$now     = current_time( 'mysql', true );
		if ( false === $wpdb->insert(
			$tables['ingestion_drafts'],
			array(
				'status'            => 'approved',
				'approval_mode'     => 'imported',
				'source_wp_id'      => Database::integer( $post['source_wp_id'] ),
				'wordpress_post_id' => Database::integer( $post['wordpress_post_id'] ),
				'title'             => Database::text( $post['title'] ),
				'url'               => Database::text( $post['url'] ),
				'url_hash'          => $post['url_hash'],
				'author'            => Database::text( $post['author'] ),
				'date_text'         => Database::text( $post['date_text'] ),
				'published_at'      => Database::text( $post['published_at'] ),
				'proposal_json'     => wp_json_encode( $proposal ),
				'prompt_version'    => 'imported-metadata-v1',
				'taxonomy_version'  => $taxonomy_hash,
				// A distinct run ID prevents legacy cost backfill from treating supplied vectors as new work.
				'usage_run_id'      => 'link-' . wp_generate_uuid4(),
				'created_by'        => get_current_user_id(),
				'created_at'        => $now,
				'updated_at'        => $now,
				'approved_post_id'  => $id,
				'embedding_status'  => in_array( 'Ignore', $proposal['topics'], true ) ? 'not_applicable' : ( $current ? 'complete' : 'pending' ),
				'embedding_model'   => $current ? Database::text( $vector['model'] ?? null ) : '',
			)
		) ) {
			throw new RuntimeException( 'Could not preserve the imported metadata baseline.' );
		}
	}

	/**
	 * Reads existing assignments without applying today's AI selection rules.
	 *
	 * @param int    $post_id Discovery ID.
	 * @param string $terms Vocabulary table key.
	 * @param string $links Assignment table key.
	 * @param string $foreign Assignment foreign key.
	 * @param string $label Vocabulary label column.
	 * @return list<string> Existing labels.
	 * @throws RuntimeException When assignment reads fail.
	 */
	private static function terms( int $post_id, string $terms, string $links, string $foreign, string $label ): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$values = $wpdb->get_col( $wpdb->prepare( 'SELECT t.%i FROM %i t JOIN %i r ON r.%i=t.id WHERE r.post_id=%d ORDER BY t.id', $label, $tables[ $terms ], $tables[ $links ], $foreign, $post_id ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read imported topic or keyword assignments.' );
		}
		return Database::strings( $values );
	}
}
