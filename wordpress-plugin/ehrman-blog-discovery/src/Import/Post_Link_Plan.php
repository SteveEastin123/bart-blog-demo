<?php
/**
 * Read-only identity checks for linking imported metadata to native posts.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Requires preserved source IDs, matching titles and matching permalink paths. */
final class Post_Link_Plan {
	/**
	 * Builds a reviewable plan; optionally locks rows in the caller's transaction.
	 *
	 * @param string $source_site Original WordPress home URL, explicitly supplied by the operator.
	 * @param bool   $lock Whether to lock rows for application.
	 * @return array{fingerprint:string,rows:list<array<string,int|string>>,ready:int,unresolved:int,linked:int} Plan.
	 * @throws RuntimeException When a URL or database read is invalid.
	 */
	public function build( string $source_site, bool $lock = false ): array {
		$source = self::site( $source_site );
		$target = self::site( home_url( '/' ) );
		$wpdb   = Database::client();
		$tables = Database::tables();
		$suffix = $lock ? ' FOR UPDATE' : '';
		$posts  = $this->rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $tables['external_posts'] ) . $suffix );
		$drafts = $this->rows( $wpdb->prepare( 'SELECT id,source_wp_id,wordpress_post_id,url_hash FROM %i ORDER BY id', $tables['ingestion_drafts'] ) . $suffix );
		$owners = array();
		$counts = array();
		foreach ( $posts as $post ) {
			$id            = Database::integer( $post['source_wp_id'] );
			$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			$native        = Database::integer( $post['wordpress_post_id'] );
			if ( $native > 0 ) {
				$owners[ $native ] = true;
			}
		}
		$history = array();
		$urls    = array();
		foreach ( $drafts as $draft ) {
			$history[ Database::integer( $draft['source_wp_id'] ) ]      = true;
			$history[ Database::integer( $draft['wordpress_post_id'] ) ] = true;
			$urls[ Database::text( $draft['url_hash'] ) ]                = true;
		}
		$result = array(
			'fingerprint' => '',
			'rows'        => array(),
			'ready'       => 0,
			'unresolved'  => 0,
			'linked'      => 0,
		);
		$hash   = hash_init( 'sha256' );
		// Include the site, database identity and records, not just the visible match labels.
		hash_update( $hash, serialize( array( $source, $target, $wpdb->prefix, $wpdb->get_var( 'SELECT DATABASE()' ), $drafts ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		foreach ( $posts as $post ) {
			$id     = Database::integer( $post['source_wp_id'] );
			$native = $this->rows( $wpdb->prepare( 'SELECT * FROM %i WHERE ID=%d', $wpdb->posts, $id ) . $suffix );
			$record = $native[0] ?? null;
			$url    = null !== $record ? get_permalink( new \WP_Post( (object) $record ) ) : false;
			$row    = array(
				'index_id'      => Database::integer( $post['id'] ),
				'source_id'     => $id,
				'title'         => Database::text( $post['title'] ),
				'url'           => Database::text( $post['url'] ),
				'wordpress_url' => is_string( $url ) ? $url : '',
				'status'        => 'unresolved',
				'reason'        => '',
			);
			if ( Database::integer( $post['wordpress_post_id'] ) > 0 ) {
				$row['status'] = 'linked';
				$row['reason'] = 'Already linked; unchanged.';
			} elseif ( $id < 1 || $counts[ $id ] > 1 || isset( $owners[ $id ] ) || isset( $history[ $id ] ) || isset( $urls[ Database::text( $post['url_hash'] ) ] ) ) {
				$row['reason'] = 'Conflicting ID, ownership or ingestion history; manual investigation required.';
			} elseif ( null === $record ) {
				$row['reason'] = 'Source WordPress ID was not found on this site.';
			} elseif ( 'post' !== $record['post_type'] || 'publish' !== $record['post_status'] ) {
				$row['reason'] = 'Target is not a published standard WordPress post.';
			} elseif ( self::title( Database::text( $record['post_title'] ) ) !== self::title( Database::text( $post['title'] ) ) ) {
				$row['reason'] = 'Article titles do not match.';
			} else {
				$source_path = self::relative_url( Database::text( $post['url'] ), $source );
				$target_path = self::relative_url( $row['wordpress_url'], $target );
				if ( null === $source_path || null === $target_path || $source_path !== $target_path ) {
					$row['reason'] = 'Article URLs do not match the declared sites and permalink paths.';
				} else {
					$row['status'] = 'ready';
					$row['reason'] = 'Source ID, title and permalink path match.';
				}
			}
			hash_update( $hash, serialize( array( $post, $record, $row ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			++$result[ $row['status'] ];
			$result['rows'][] = $row;
		}
		$result['fingerprint'] = hash_final( $hash );
		return $result;
	}

	/**
	 * Reads prepared SQL, failing closed instead of mistaking a read error for missing data.
	 *
	 * @param string $sql Prepared SQL with an optional fixed lock suffix.
	 * @return list<array<string,mixed>> Rows.
	 * @throws RuntimeException When reading fails.
	 */
	private function rows( string $sql ): array {
		$wpdb = Database::client();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each caller prepares identifiers and values.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Could not read records for post linking.' );
		}
		return Database::associative_rows( $rows );
	}

	/**
	 * Validates a site root without guessing hosts or discarding query parameters.
	 *
	 * @param string $url Site URL.
	 * @return array{host:string,port:int,path:string} Site identity.
	 * @throws RuntimeException When the site URL is invalid.
	 */
	private static function site( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			throw new RuntimeException( 'Supply an absolute --source-site home URL without credentials, a query or a fragment.' );
		}
		return array(
			'host' => strtolower( $parts['host'] ),
			'port' => $parts['port'] ?? 0,
			'path' => rtrim( $parts['path'] ?? '', '/' ) . '/',
		);
	}

	/**
	 * Matches paths only within an explicitly verified site root.
	 *
	 * @param string                                  $url Article URL.
	 * @param array{host:string,port:int,path:string} $site Site identity.
	 */
	private static function relative_url( string $url, array $site ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			|| strtolower( $parts['host'] ?? '' ) !== $site['host'] || ( $parts['port'] ?? 0 ) !== $site['port']
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$path = rtrim( $parts['path'] ?? '', '/' ) . '/';
		if ( ! str_starts_with( $path, $site['path'] ) ) {
			return null;
		}
		return substr( $path, strlen( $site['path'] ) ) . '?' . ( $parts['query'] ?? '' );
	}

	/**
	 * Normalizes only presentation whitespace and HTML entities, not article meaning.
	 *
	 * @param string $title Article title.
	 */
	private static function title( string $title ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
