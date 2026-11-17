<?php
/**
 * Explicit, transactional linking of imported records to this WordPress site.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Applies only the matches from an operator-reviewed preview. Never runs on import. */
final class Post_Linker {
	/**
	 * Applies verified links and imported baselines atomically, without AI or article writes.
	 *
	 * @param string $source_site Original site home URL.
	 * @param string $expected Fingerprint from a fresh preview on this site.
	 * @return array{fingerprint:string,rows:list<array<string,int|string>>,ready:int,unresolved:int,linked:int} Applied plan.
	 * @throws RuntimeException When validation, locking or any write fails.
	 * @throws Throwable Rethrows unexpected failures after rolling back.
	 */
	public function apply( string $source_site, string $expected ): array {
		if ( ! Post_Ingestion_Settings::database_is_authoritative() ) {
			throw new RuntimeException( 'Linking requires database-managed publishing. Do not link the JSON-managed external demo.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			throw new RuntimeException( 'Preview first and supply its fingerprint with --expect.' );
		}
		$wpdb   = Database::client();
		$tables = Database::tables();
		$lock   = 'ehrman-install-' . hash( 'sha1', Database::text( $wpdb->get_var( 'SELECT DATABASE()' ) ) . ':' . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			throw new RuntimeException( 'Another installation or linking command is running.' );
		}
		$started = false;
		try {
			foreach ( array_merge( array_values( $tables ), array( $wpdb->posts, $wpdb->options ) ) as $table ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
				if ( 'innodb' !== strtolower( Database::text( $engine ) ) ) {
					throw new RuntimeException( 'Post linking requires existing InnoDB tables: ' . $table );
				}
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Could not start the post-linking transaction.' );
			}
			$started = true;
			$plan    = ( new Post_Link_Plan() )->build( $source_site, true );
			if ( ! hash_equals( $expected, $plan['fingerprint'] ) ) {
				throw new RuntimeException( 'Posts, metadata or site settings changed since preview. Preview again before applying links.' );
			}
			$repository = new Post_Ingestion_Repository();
			$taxonomy   = $repository->vocabulary_hash( $repository->vocabulary() );
			// Advance only a previously matching installation receipt; never bless earlier editorial drift.
			( new Data_Installer() )->preserve_receipt(
				static function () use ( $plan, $wpdb, $tables, $taxonomy ): void {
					foreach ( $plan['rows'] as $row ) {
						if ( 'ready' !== $row['status'] ) {
							continue;
						}
						$sql = $wpdb->prepare( 'UPDATE %i SET wordpress_post_id=%d WHERE id=%d AND source_wp_id=%d AND wordpress_post_id IS NULL', $tables['external_posts'], $row['source_id'], $row['index_id'], $row['source_id'] );
						if ( ! is_string( $sql ) ) {
							throw new RuntimeException( 'Could not prepare the ownership update.' );
						}
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
						$updated = $wpdb->query( $sql );
						if ( 1 !== $updated ) {
							throw new RuntimeException( 'Post ownership changed during linking. No links were applied.' );
						}
						$post = Database::associative_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $row['index_id'] ), ARRAY_A ) );
						if ( null === $post || '' !== $wpdb->last_error ) {
							throw new RuntimeException( 'Could not reload the linked discovery record.' );
						}
						Imported_Metadata_Record::create( $post, $taxonomy );
					}
				}
			);
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit post links.' );
			}
			$started = false;
			return $plan;
		} catch ( Throwable $error ) {
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		} finally {
			wp_cache_flush();
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
