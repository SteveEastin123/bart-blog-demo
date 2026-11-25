<?php
/**
 * Guarded, atomic deletion of unused organization records.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Removes only a selected record and its links, never posts or child records. */
final class Organization_Deletion_Service {
	/**
	 * Builds the confirmation and the same safety checks used at deletion time.
	 *
	 * @param string $tab Organization tab.
	 * @param int    $id Record ID.
	 * @return array<string,mixed>|\WP_Error Confirmation or safe error.
	 */
	public function preview( string $tab, int $id ): array|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_delete_permission', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		try {
			return $this->snapshot( $tab, $id );
		} catch ( Throwable $error ) {
			return new \WP_Error( 'ehrman_delete_preview', $error->getMessage() );
		}
	}

	/**
	 * Rechecks the setting, revision and references while holding writer locks.
	 *
	 * @param string $tab Organization tab.
	 * @param int    $id Record ID.
	 * @param string $revision Confirmation snapshot.
	 * @return int|\WP_Error Removed ID or safe failure.
	 * @throws RuntimeException Caught internally to roll back all partial changes.
	 */
	public function delete( string $tab, int $id, string $revision ): int|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'ehrman_delete_permission', __( 'You cannot manage search organization.', 'ehrman-blog-discovery' ) );
		}
		$wpdb = Database::client();
		$t    = Database::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'ehrman_delete_transaction', __( 'Deletion could not be started. Please try again.', 'ehrman-blog-discovery' ) );
		}
		try {
			// Bypass option caches and prevent a concurrent disable until this transaction ends.
			$setting = $this->read( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s LOCK IN SHARE MODE', $wpdb->options, Organization_Deletion_Settings::OPTION ) );
			if ( 'enabled' !== ( $setting[0]['option_value'] ?? null ) ) {
				throw new RuntimeException( __( 'Deletion is disabled in Blog Search settings.', 'ehrman-blog-discovery' ) );
			}
			// Match existing writers: path, topics, subject areas, categories, then links.
			// These small vocabulary tables are locked only during an explicit admin deletion.
			$this->read( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s AND is_active=1 FOR UPDATE', $t['browse_paths'], 'browse-topics' ) );
			foreach ( array( 'topics', 'subject_areas', 'categories' ) as $key ) {
				$this->read( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id FOR UPDATE', $t[ $key ] ) );
			}
			$snapshot = $this->snapshot( $tab, $id );
			if ( ! hash_equals( $snapshot['revision'], $revision ) ) {
				throw new RuntimeException( __( 'This item or its relationships changed. Reopen the deletion confirmation before trying again.', 'ehrman-blog-discovery' ) );
			}
			if ( $snapshot['blockers'] ) {
				throw new RuntimeException( implode( ' ', $snapshot['blockers'] ) );
			}
			$links = match ( $tab ) {
				'subject-areas' => array( 'subject_area_categories' => 'subject_area_id' ),
				'categories' => array(
					'subject_area_categories' => 'category_id',
					'topic_categories'        => 'category_id',
				),
				default => array(
					'topic_categories' => 'topic_id',
					'topic_aliases'    => 'topic_id',
				),
			};
			foreach ( $links as $table => $field ) {
				$this->checked( $wpdb->delete( $t[ $table ], array( $field => $id ), array( '%d' ) ) );
			}
			$this->checked( $wpdb->delete( $t[ $snapshot['table'] ], array( 'id' => $id ), array( '%d' ) ) );
			$this->checked( $wpdb->query( 'COMMIT' ) );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'ehrman_delete_failed', $error->getMessage() );
		}
		AI_Usage::invalidate_search_caches();
		return $id;
	}

	/**
	 * Reads actual relationships, including hidden topics, without trusting submitted counts.
	 *
	 * @param string $tab Organization tab.
	 * @param int    $id Record ID.
	 * @return array{table:string,record:array<string,mixed>,groups:array<string,list<array<string,mixed>>>,aliases:int,blockers:list<string>,revision:string} Confirmation snapshot.
	 * @throws RuntimeException For unsupported, missing or protected records.
	 */
	private function snapshot( string $tab, int $id ): array {
		$table = match ( $tab ) {
			'subject-areas' => 'subject_areas',
			'categories' => 'categories',
			'topics' => 'topics',
			default => '',
		};
		if ( '' === $table || $id < 1 ) {
			throw new RuntimeException( __( 'This item is unavailable for deletion.', 'ehrman-blog-discovery' ) );
		}
		$wpdb   = Database::client();
		$t      = Database::tables();
		$record = $this->read( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $t[ $table ], $id ) )[0] ?? null;
		$path   = $this->read( $wpdb->prepare( 'SELECT id FROM %i WHERE slug=%s AND is_active=1', $t['browse_paths'], 'browse-topics' ) )[0] ?? null;
		if ( null === $record || null === $path || ( 'subject-areas' === $tab && $record['browse_path_id'] !== $path['id'] ) || ( 'topics' === $tab && ! Topic_Organization_Service::editable( $record ) ) ) {
			throw new RuntimeException( __( 'This item is unavailable for deletion. Internal topics and retired subject areas are protected.', 'ehrman-blog-discovery' ) );
		}
		$blockers = array();
		$groups   = array();
		$aliases  = array();
		$posts    = 0;
		if ( 'subject-areas' === $tab ) {
			$groups[ __( 'Categories', 'ehrman-blog-discovery' ) ] = $this->read( $wpdb->prepare( 'SELECT c.id,c.name FROM %i sc JOIN %i c ON c.id=sc.category_id WHERE sc.subject_area_id=%d ORDER BY c.name,c.id', $t['subject_area_categories'], $t['categories'], $id ) );
		} elseif ( 'categories' === $tab ) {
			$groups[ __( 'Subject Areas', 'ehrman-blog-discovery' ) ] = $this->read( $wpdb->prepare( 'SELECT sa.id,sa.name,sa.browse_path_id FROM %i sc JOIN %i sa ON sa.id=sc.subject_area_id WHERE sc.category_id=%d ORDER BY sa.name,sa.id', $t['subject_area_categories'], $t['subject_areas'], $id ) );
			$groups[ __( 'Topics', 'ehrman-blog-discovery' ) ]        = $this->read( $wpdb->prepare( 'SELECT t.id,t.name FROM %i tc JOIN %i t ON t.id=tc.topic_id WHERE tc.category_id=%d ORDER BY t.name,t.id', $t['topic_categories'], $t['topics'], $id ) );
			$orphans = $this->read( $wpdb->prepare( 'SELECT t.name FROM %i tc JOIN %i t ON t.id=tc.topic_id WHERE tc.category_id=%d AND NOT EXISTS (SELECT 1 FROM %i other_link JOIN %i c ON c.id=other_link.category_id WHERE other_link.topic_id=t.id AND c.id<>%d) ORDER BY t.name', $t['topic_categories'], $t['topics'], $id, $t['topic_categories'], $t['categories'], $id ) );
			if ( $orphans ) {
				/* translators: %s: Topic names. */
				$blockers[] = sprintf( __( 'Link these Topics to another Category first: %s.', 'ehrman-blog-discovery' ), implode( ', ', Database::strings( array_column( $orphans, 'name' ) ) ) );
			}
		} else {
			$groups[ __( 'Categories', 'ehrman-blog-discovery' ) ] = $this->read( $wpdb->prepare( 'SELECT c.id,c.name FROM %i tc JOIN %i c ON c.id=tc.category_id WHERE tc.topic_id=%d ORDER BY c.name,c.id', $t['topic_categories'], $t['categories'], $id ) );
			$aliases = $this->read( $wpdb->prepare( 'SELECT label,normalized FROM %i WHERE topic_id=%d ORDER BY normalized', $t['topic_aliases'], $id ) );
			$posts   = Database::integer( $this->read( $wpdb->prepare( 'SELECT COUNT(*) total FROM %i WHERE topic_id=%d', $t['post_topics'], $id ) )[0]['total'] ?? null );
			if ( $posts > 0 ) {
				/* translators: %s: Number of assigned posts. */
				$blockers[] = sprintf( _n( 'This Topic is assigned to %s post. Remove or replace that assignment before deleting it.', 'This Topic is assigned to %s posts. Remove or replace those assignments before deleting it.', $posts, 'ehrman-blog-discovery' ), number_format_i18n( $posts ) );
			}
		}
		return array(
			'table'    => $table,
			'record'   => $record,
			'groups'   => $groups,
			'aliases'  => count( $aliases ),
			'blockers' => $blockers,
			'revision' => hash( 'sha256', (string) wp_json_encode( array( $record, $groups, $aliases, $posts, $blockers ) ) ),
		);
	}

	/**
	 * Fails closed on a database read error instead of interpreting it as zero references.
	 *
	 * @param string|null $sql Internally prepared SQL; null indicates preparation failed.
	 * @return list<array<string,mixed>> Rows.
	 * @throws RuntimeException When a query fails.
	 */
	private function read( ?string $sql ): array {
		if ( null === $sql ) {
			throw new RuntimeException( __( 'Deletion checks could not be prepared. Please reload and try again.', 'ehrman-blog-discovery' ) );
		}
		$wpdb = Database::client();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller prepares its query with internal table identifiers.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( __( 'Deletion checks could not be completed. Please reload and try again.', 'ehrman-blog-discovery' ) );
		}
		return Database::associative_rows( $rows );
	}

	/**
	 * Rolls back a failed write without exposing SQL details.
	 *
	 * @param int|bool $result Database result.
	 * @throws RuntimeException When a write fails.
	 */
	private function checked( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( __( 'The item could not be deleted. No changes were saved.', 'ehrman-blog-discovery' ) );
		}
	}
}
