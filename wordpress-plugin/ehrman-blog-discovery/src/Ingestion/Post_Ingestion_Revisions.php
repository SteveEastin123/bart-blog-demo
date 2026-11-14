<?php
/**
 * Isolated revisions of already approved native-post metadata.
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

/** Keeps the live index and approved history intact while revisions are reviewed. */
final class Post_Ingestion_Revisions {
	/**
	 * Copies the current approved proposal into a separate editable record.
	 *
	 * @param int $parent_id Approved record to revise.
	 * @return array<string,mixed>|WP_Error Revision or error.
	 * @throws RuntimeException When a transaction step fails; caught and returned as an error.
	 */
	public static function start( int $parent_id ): array|WP_Error {
		$repository = new Post_Ingestion_Repository();
		$parent     = $repository->draft( $parent_id );
		if ( null === $parent || 'approved' !== $parent['status'] ) {
			return new WP_Error( 'ehrman_ingestion_review_changed', __( 'Reload the current approved metadata before editing.', 'ehrman-blog-discovery' ) );
		}
		$post = Post_Ingestion_Post_Source::published_post( $parent );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( null === $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'ehrman_ingestion_forbidden', __( 'Revisions require an editable, published WordPress post linked to this metadata.', 'ehrman-blog-discovery' ) );
		}
		$validator = new Post_Ingestion_Validator();
		$input     = $validator->validate_submission( Post_Ingestion_Post_Source::input( $post ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ehrman_ingestion_transaction', __( 'The revision could not be started.', 'ehrman-blog-discovery' ) );
		}
		try {
			// Serialize revision creation with publication/deletion and approval for this native post.
			$native = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID=%d AND post_status=%s FOR UPDATE', $wpdb->posts, $post->ID, 'publish' ) );
			$latest = $repository->latest_for_source_wp_id( $post->ID );
			if ( null === $native || Database::integer( $latest['id'] ?? null ) !== $parent_id ) {
				throw new RuntimeException( 'A newer metadata record exists. Reload it before starting a revision.' );
			}
			$live = self::live_post( $parent, true );
			if ( null === $live || Database::integer( $parent['approved_post_id'] ?? null ) !== Database::integer( $live['id'] ) ) {
				throw new RuntimeException( 'The approved search entry is no longer available for this post.' );
			}
			$proposal = $validator->decode_proposal( Database::text( $parent['proposal_json'] ?? null ) );
			// Previously approved new keywords are now part of the shared vocabulary.
			$proposal['secondaryKeywords']    = array_values( array_unique( array_merge( Database::strings( $proposal['secondaryKeywords'] ?? null ), Database::strings( $proposal['newSecondaryKeywords'] ?? null ) ) ) );
			$proposal['newSecondaryKeywords'] = array();
			$proposal['status']               = 'ready';
			$id                               = $repository->create_draft( $input, $repository->vocabulary_hash( $repository->vocabulary() ), get_current_user_id(), $post->ID );
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( $id->get_error_message() );
			}
			if ( false === $wpdb->update(
				$tables['ingestion_drafts'],
				array(
					'status'             => 'ready',
					'proposal_json'      => wp_json_encode( $proposal ),
					'revision_parent_id' => $parent_id,
					'revision_base_hash' => self::fingerprint( $live ),
					'usage_run_id'       => wp_generate_uuid4(),
					'model'              => '',
					'prompt_version'     => '',
				),
				array( 'id' => $id )
			) ) {
				throw new RuntimeException( 'The revision could not be stored.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'The revision could not be committed.' );
			}
			return $repository->draft( $id ) ?? new WP_Error( 'ehrman_ingestion_storage_error', 'The revision could not be reloaded.' );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ehrman_ingestion_review_changed', sanitize_text_field( $error->getMessage() ) );
		}
	}

	/**
	 * Reads only the explicitly linked native search entry.
	 *
	 * @param array<string,mixed> $draft Ingestion record.
	 * @param bool                $lock Whether the caller holds an approval transaction.
	 * @return array<string,mixed>|null Live record.
	 */
	public static function live_post( array $draft, bool $lock = false ): ?array {
		$wpdb = Database::client();
		$sql  = $wpdb->prepare( 'SELECT * FROM %i WHERE wordpress_post_id=%d AND source_wp_id=%d', Database::tables()['external_posts'], Database::integer( $draft['wordpress_post_id'] ?? null ), Database::integer( $draft['source_wp_id'] ?? null ) );
		if ( $lock ) {
			$sql .= ' FOR UPDATE';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; the lock suffix is fixed.
		return Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
	}

	/**
	 * Detects an intervening import or edit before replacing live metadata.
	 *
	 * @param array<string,mixed> $post Live search entry.
	 */
	public static function fingerprint( array $post ): string {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$values = array();
		foreach ( array( 'id', 'source_wp_id', 'wordpress_post_id', 'title', 'url', 'author', 'date_text', 'published_at', 'description', 'search_summary' ) as $field ) {
			$values[ $field ] = Database::text( $post[ $field ] ?? null );
		}
		foreach ( array(
			'post_topics'   => 'topic_id',
			'post_keywords' => 'keyword_id',
		) as $table => $column ) {
			$values[ $table ] = $wpdb->get_col( $wpdb->prepare( 'SELECT %i FROM %i WHERE post_id=%d ORDER BY %i', $column, $tables[ $table ], Database::integer( $post['id'] ?? null ), $column ) );
		}
		return hash( 'sha256', (string) wp_json_encode( $values ) );
	}

	/**
	 * Validates that the revision still replaces the current approved entry.
	 *
	 * @param array<string,mixed>      $draft Pending revision.
	 * @param array<string,mixed>|null $live Live search entry.
	 */
	public static function matches( array $draft, ?array $live ): bool {
		if ( null === $live || ! hash_equals( Database::text( $draft['revision_base_hash'] ?? null ), self::fingerprint( $live ) ) ) {
			return false;
		}
		$wpdb = Database::client();
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wordpress_post_id=%d AND status=%s ORDER BY id DESC LIMIT 1', Database::tables()['ingestion_drafts'], Database::integer( $draft['wordpress_post_id'] ?? null ), 'approved' ) );
		return Database::integer( $id ) === Database::integer( $draft['revision_parent_id'] ?? null );
	}
}
