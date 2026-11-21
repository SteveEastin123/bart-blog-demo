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
	 * @param int                      $draft_id      Draft identifier.
	 * @param array<string,mixed>      $draft         Draft record.
	 * @param array<string,mixed>      $proposal      Approved proposal.
	 * @param array<string,mixed>      $taxonomy      Current vocabulary.
	 * @param string                   $approved_json Encoded approved proposal.
	 * @param array<string,mixed>|null $vector Prepared replacement vector; null for initial ingestion.
	 * @param bool                     $automatic Whether the publishing worker approved this proposal.
	 * @return int|WP_Error New internal post identifier or error.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When approval state cannot be persisted.
	 */
	public function approve( int $draft_id, array $draft, array $proposal, array $taxonomy, string $approved_json, ?array $vector = null, bool $automatic = false ) {
		$wpdb = Database::client();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ehrman_ingestion_transaction', __( 'The approval transaction could not be started.', 'ehrman-blog-discovery' ) );
		}
		try {
			$this->lock_topics( $proposal, $taxonomy );
			$local_id = Database::integer( $draft['wordpress_post_id'] ?? null );
			if ( $local_id > 0 ) {
				// Lock the native post while approving so deletion/unpublishing cannot race this write.
				$native  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID=%d AND post_type=%s AND post_status=%s FOR UPDATE', $wpdb->posts, $local_id, 'post', 'publish' ) );
				$pending = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d AND wordpress_post_id=%d AND status IN (%s,%s) FOR UPDATE', Database::tables()['ingestion_drafts'], $draft_id, $local_id, 'ready', 'held' ), ARRAY_A ) );
				if ( null === $native || null === $pending ) {
					throw new RuntimeException( 'The published post or pending metadata no longer exists. Reload the editor.' );
				}
				if ( $automatic ? ! Post_Ingestion_Automation::authorized( $pending ) : Post_Ingestion_Automation::busy( $pending ) ) {
					throw new RuntimeException( 'Automatic metadata ownership changed. Reload its status before approval.' );
				}
				if ( ! hash_equals( Post_Ingestion_Review_Workflow::revision( $draft ), Post_Ingestion_Review_Workflow::revision( $pending ) )
					|| Post_Ingestion_Post_Source::source_changed( new \WP_Post( sanitize_post( $native, 'raw' ) ), $draft ) ) {
					throw new RuntimeException( 'The post or metadata changed during approval. Reload before trying again.' );
				}
			}
			$replacement_id = 0;
			$row            = Database::associative_row( $vector['row'] ?? null );
			if ( Database::integer( $draft['revision_parent_id'] ?? null ) > 0 ) {
				$live = Post_Ingestion_Revisions::live_post( $draft, true );
				if ( null === $vector || ! Post_Ingestion_Revisions::matches( $draft, $live ) ) {
					throw new RuntimeException( 'The approved metadata changed. Cancel this revision and start from the current version.' );
				}
				$replacement_id = Database::integer( $live['id'] ?? null );
			}
			$post_id = $this->insert_approved_post( $draft, $proposal, $taxonomy, $replacement_id );
			if ( null !== $vector ) {
				$written = null === $row
					? $wpdb->delete( Database::tables()['post_embeddings'], array( 'source_wp_id' => Database::integer( $draft['source_wp_id'] ?? null ) ), array( '%d' ) )
					: $wpdb->replace( Database::tables()['post_embeddings'], $row );
				if ( false === $written ) {
					throw new RuntimeException( 'The replacement vector could not be stored. Existing metadata was retained.' );
				}
			}
			$metrics = Database::associative_row( $vector['metrics'] ?? null ) ?? array();
			$updated = $wpdb->update(
				Database::tables()['ingestion_drafts'],
				array(
					'status'                       => 'approved',
					'approval_mode'                => $automatic ? 'automatic' : 'manual',
					'automatic_status'             => $automatic ? 'running' : '',
					'proposal_json'                => $approved_json,
					'post_text'                    => null,
					'error_message'                => null,
					'approved_at'                  => current_time( 'mysql', true ),
					'approved_post_id'             => $post_id,
					'embedding_status'             => in_array( 'Ignore', $proposal['topics'], true ) ? 'not_applicable' : ( null !== $vector ? 'complete' : 'pending' ),
					'embedding_error'              => null,
					'embedding_model'              => Database::text( $row['model'] ?? null ),
					'embedding_response_id'        => Database::text( $metrics['response_id'] ?? null ),
					'embedding_input_tokens'       => Database::integer( $metrics['input_tokens'] ?? null ),
					'embedding_estimated_cost_usd' => number_format( (float) Database::text( $metrics['estimated_cost_usd'] ?? 0 ), 8, '.', '' ),
					'updated_at'                   => current_time( 'mysql', true ),
				),
				array( 'id' => $draft_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
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
	 * Prevents a deleted Topic from being assigned by an approval already in progress.
	 *
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @param array<string,mixed> $taxonomy Previously validated vocabulary.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When a selected Topic no longer exists.
	 */
	private function lock_topics( array $proposal, array $taxonomy ): void {
		$wpdb     = Database::client();
		$selected = array();
		foreach ( $taxonomy['topics'] as $topic ) {
			if ( in_array( $topic['name'], $proposal['topics'], true ) ) {
				$selected[ $topic['id'] ] = $topic['name'];
			}
		}
		ksort( $selected, SORT_NUMERIC );
		foreach ( $selected as $id => $name ) {
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id=%d LOCK IN SHARE MODE', Database::tables()['topics'], $id ) );
			if ( $current !== $name || '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'A selected Topic no longer exists. Regenerate the metadata before approval.' );
			}
		}
	}

	/**
	 * Inserts one post and every search relationship inside the caller transaction.
	 *
	 * @param array<string,mixed> $draft    Draft record.
	 * @param array<string,mixed> $proposal Approved proposal.
	 * @param array<string,mixed> $taxonomy Vocabulary.
	 * @param int                 $replacement_id Existing search entry, or zero for initial ingestion.
	 * @phpstan-param Proposal $proposal
	 * @phpstan-param Vocabulary $taxonomy
	 * @throws RuntimeException When any row cannot be inserted.
	 */
	private function insert_approved_post( array $draft, array $proposal, array $taxonomy, int $replacement_id = 0 ): int {
		$wpdb     = Database::client();
		$tables   = Database::tables();
		$url      = Database::text( $draft['url'] ?? null );
		$local_id = Database::integer( $draft['wordpress_post_id'] ?? null );
		$data     = array(
			'source_wp_id'      => Database::integer( $draft['source_wp_id'] ?? null ),
			'wordpress_post_id' => $local_id > 0 ? $local_id : null,
			'title'             => Database::text( $draft['title'] ?? null ),
			'url'               => $url,
			'url_hash'          => hash( 'sha256', $url, true ),
			'author'            => Database::text( $draft['author'] ?? null ),
			'date_text'         => Database::text( $draft['date_text'] ?? null ),
			'published_at'      => Database::text( $draft['published_at'] ?? null ),
			'description'       => $proposal['description'],
			'search_summary'    => $proposal['searchSummary'],
		);
		$formats  = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$ok       = $replacement_id > 0
			? $wpdb->update( $tables['external_posts'], $data, array( 'id' => $replacement_id ), $formats, array( '%d' ) )
			: $wpdb->insert( $tables['external_posts'], $data, $formats );
		if ( false === $ok ) {
			throw new RuntimeException( 'The approved post could not be inserted: ' . $wpdb->last_error );
		}
		$post_id = $replacement_id > 0 ? $replacement_id : (int) $wpdb->insert_id;
		if ( $replacement_id > 0 ) {
			foreach ( array( 'post_topics', 'post_keywords', 'post_search_terms' ) as $table ) {
				if ( false === $wpdb->delete( $tables[ $table ], array( 'post_id' => $post_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'The old search relationships could not be replaced.' );
				}
			}
		}

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
