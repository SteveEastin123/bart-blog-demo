<?php
/**
 * Transactional persistence for approved ingestion proposals.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an approved post and its searchable relationships atomically.
 *
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 */
final class Post_Ingestion_Approval_Writer {

	/**
	 * Applies one approved proposal inside a transaction.
	 *
	 * @param int                 $draft_id      Draft identifier.
	 * @param array<string,mixed> $draft         Draft record.
	 * @param array<string,mixed> $proposal      Approved proposal.
	 * @param array<string,mixed> $taxonomy      Current vocabulary.
	 * @param string              $approved_json Encoded approved proposal.
	 * @return int|WP_Error New internal post identifier or error.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When approval state cannot be persisted.
	 */
	public function approve( int $draft_id, array $draft, array $proposal, array $taxonomy, string $approved_json ) {
		$wpdb = Database::client();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ehrman_ingestion_transaction', __( 'The approval transaction could not be started.', 'ehrman-blog-discovery' ) );
		}
		try {
			$post_id = $this->insert_approved_post( $draft, $proposal, $taxonomy );
			$updated = $wpdb->update(
				Database::tables()['ingestion_drafts'],
				array(
					'status'           => 'approved',
					'proposal_json'    => $approved_json,
					'post_text'        => null,
					'error_message'    => null,
					'approved_at'      => current_time( 'mysql', true ),
					'approved_post_id' => $post_id,
					'embedding_status' => in_array( 'Ignore', $proposal['topics'], true ) ? 'not_applicable' : 'pending',
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				throw new RuntimeException( 'The ingestion audit record could not be finalized.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The approval transaction could not be committed.' );
			}
			return $post_id;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ehrman_ingestion_apply_failed', sanitize_text_field( $error->getMessage() ) );
		}
	}

	/**
	 * Inserts one post and every search relationship inside the caller transaction.
	 *
	 * @param array<string,mixed> $draft    Draft record.
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @param array<string,mixed> $taxonomy Vocabulary.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When any row cannot be inserted.
	 */
	private function insert_approved_post( array $draft, array $proposal, array $taxonomy ): int {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$url    = Database::text( $draft['url'] ?? null );
		$ok     = $wpdb->insert(
			$tables['external_posts'],
			array(
				'source_wp_id'   => Database::integer( $draft['source_wp_id'] ?? null ),
				'title'          => Database::text( $draft['title'] ?? null ),
				'url'            => $url,
				'url_hash'       => hash( 'sha256', $url, true ),
				'author'         => Database::text( $draft['author'] ?? null ),
				'date_text'      => Database::text( $draft['date_text'] ?? null ),
				'published_at'   => Database::text( $draft['published_at'] ?? null ),
				'description'    => $proposal['description'],
				'search_summary' => $proposal['searchSummary'],
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'The approved post could not be inserted: ' . $wpdb->last_error );
		}
		$post_id = (int) $wpdb->insert_id;

		$topic_ids = array();
		foreach ( $taxonomy['topics'] as $topic ) {
			$topic_ids[ $topic['name'] ] = $topic['id'];
		}
		$keyword_ids = array();
		foreach ( $taxonomy['keywords'] as $keyword ) {
			$keyword_ids[ $keyword['normalized'] ] = $keyword['id'];
		}
		foreach ( $proposal['newSecondaryKeywords'] as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			if ( false === $wpdb->insert(
				$tables['keywords'],
				array(
					'label'      => $keyword,
					'normalized' => $normalized,
				),
				array( '%s', '%s' )
			) ) {
				throw new RuntimeException( 'A new secondary keyword could not be inserted: ' . $wpdb->last_error );
			}
			$keyword_ids[ $normalized ] = (int) $wpdb->insert_id;
		}

		$search_terms = array();
		foreach ( $proposal['topics'] as $topic_name ) {
			$topic_id = $topic_ids[ $topic_name ] ?? 0;
			if ( $topic_id < 1 || false === $wpdb->insert(
				$tables['post_topics'],
				array(
					'post_id'  => $post_id,
					'topic_id' => $topic_id,
				),
				array( '%d', '%d' )
			) ) {
				throw new RuntimeException( 'A post-topic relationship could not be inserted: ' . $wpdb->last_error );
			}
			$search_terms[ Search_Service::normalize( $topic_name ) . '|topic' ] = array( $topic_name, Search_Service::normalize( $topic_name ), 'topic', 6 );
			$alias_sql = $wpdb->prepare( 'SELECT label,normalized FROM %i WHERE topic_id=%d ORDER BY label', $tables['topic_aliases'], $topic_id );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifier is internal.
			$aliases = Database::associative_rows( $wpdb->get_results( $alias_sql, ARRAY_A ) );
			foreach ( $aliases as $alias ) {
				$normalized = Database::text( $alias['normalized'] ?? null );
				if ( '' !== $normalized ) {
					$search_terms[ $normalized . '|alias' ] = array( $topic_name, $normalized, 'alias', 6 );
				}
			}
		}

		foreach ( array_merge( $proposal['secondaryKeywords'], $proposal['newSecondaryKeywords'] ) as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			$keyword_id = $keyword_ids[ $normalized ] ?? 0;
			if ( $keyword_id < 1 || false === $wpdb->insert(
				$tables['post_keywords'],
				array(
					'post_id'    => $post_id,
					'keyword_id' => $keyword_id,
				),
				array( '%d', '%d' )
			) ) {
				throw new RuntimeException( 'A post-keyword relationship could not be inserted: ' . $wpdb->last_error );
			}
			$search_terms[ $normalized . '|secondary' ] = array( $keyword, $normalized, 'secondary', 3 );
		}
		foreach ( $search_terms as $term ) {
			if ( false === $wpdb->insert(
				$tables['post_search_terms'],
				array(
					'post_id'    => $post_id,
					'label'      => $term[0],
					'normalized' => $term[1],
					'kind'       => $term[2],
					'weight'     => $term[3],
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			) ) {
				throw new RuntimeException( 'A post search term could not be inserted: ' . $wpdb->last_error );
			}
		}
		return $post_id;
	}
}
