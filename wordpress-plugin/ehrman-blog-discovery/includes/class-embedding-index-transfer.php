<?php
/**
 * Portable semantic-index export and import.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transfers the production title-and-summary embedding index as compressed JSON Lines. */
final class Embedding_Index_Transfer {
	private const FORMAT          = 'ehrman-post-embeddings';
	private const FORMAT_VERSION  = 1;
	private const CONTENT_BASIS   = 'title-summary-v1';
	private const MAX_LINE_LENGTH = 1048576;

	/**
	 * Exports every current eligible post vector.
	 *
	 * @param string $file Destination .jsonl.gz path.
	 * @return array{file:string,count:int,bytes:int,model:string,dimensions:int} Export summary.
	 * @throws \RuntimeException When the index or destination is invalid or the package cannot be written.
	 * @throws \Throwable When an unexpected file or database failure occurs.
	 */
	public function export( string $file ): array {
		$file   = self::normalize_path( $file, false );
		$status = ( new Semantic_Search_Service() )->status();
		if ( $status['eligible'] < 1 || $status['current'] !== $status['eligible'] ) {
			throw new \RuntimeException(
				sprintf(
					'Semantic index is incomplete: %d current of %d eligible (%d missing and %d stale).',
					$status['current'],
					$status['eligible'],
					$status['missing'],
					$status['stale']
				)
			);
		}

		$rows = $this->current_rows();
		if ( count( $rows ) !== $status['eligible'] ) {
			throw new \RuntimeException( 'The current vector rows do not match the eligible post count.' );
		}

		$temporary = $file . '.tmp-' . wp_generate_uuid4();
		$handle    = gzopen( $temporary, 'wb9' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The temporary vector export file could not be opened.' );
		}

		try {
			$this->write_line(
				$handle,
				array(
					'format'        => self::FORMAT,
					'version'       => self::FORMAT_VERSION,
					'contentBasis'  => self::CONTENT_BASIS,
					'model'         => Embedding_Service::model_id(),
					'dimensions'    => Embedding_Service::dimensions(),
					'count'         => count( $rows ),
					'createdAt'     => gmdate( 'c' ),
					'pluginVersion' => EHRMAN_DISCOVERY_VERSION,
				)
			);

			foreach ( $rows as $row ) {
				$this->write_line(
					$handle,
					array(
						'type'          => 'embedding',
						'sourceWpId'    => Database::integer( $row['source_wp_id'] ?? null ),
						'contentHash'   => Database::text( $row['content_hash'] ?? null ),
						'model'         => Database::text( $row['model'] ?? null ),
						'dimensions'    => Database::integer( $row['dimensions'] ?? null ),
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Portable JSON cannot contain raw binary vectors.
						'embedding'     => base64_encode( Database::text( $row['embedding'] ?? null ) ),
						'embeddingNorm' => (float) Database::text( $row['embedding_norm'] ?? null ),
						'updatedAt'     => Database::text( $row['updated_at'] ?? null ),
					)
				);
			}
		} catch ( \Throwable $error ) {
			gzclose( $handle );
			if ( is_file( $temporary ) ) {
				wp_delete_file( $temporary );
			}
			throw $error;
		}
		gzclose( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- A same-directory rename atomically publishes the completed CLI export.
		if ( ! rename( $temporary, $file ) ) {
			if ( is_file( $temporary ) ) {
				wp_delete_file( $temporary );
			}
			throw new \RuntimeException( 'The completed vector export could not be moved to its destination.' );
		}

		clearstatcache( true, $file );
		$bytes = filesize( $file );
		return array(
			'file'       => $file,
			'count'      => count( $rows ),
			'bytes'      => false === $bytes ? 0 : (int) $bytes,
			'model'      => Embedding_Service::model_id(),
			'dimensions' => Embedding_Service::dimensions(),
		);
	}

	/**
	 * Validates and imports one complete embedding package.
	 *
	 * @param string $file Source .jsonl.gz path.
	 * @return array{file:string,records:int,imported:int,skipped:int,removed:int,missing:int,rejected:int} Import summary.
	 * @throws \RuntimeException When package validation or the database transaction fails.
	 * @throws \Throwable When an unexpected file or database failure occurs.
	 */
	public function import( string $file ): array {
		$file     = self::normalize_path( $file, true );
		$eligible = $this->eligible_posts();
		if ( empty( $eligible ) ) {
			throw new \RuntimeException( 'No posts are eligible for semantic indexing.' );
		}

		$handle = gzopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'The vector import file could not be opened.' );
		}

		try {
			$line_number = 0;
			$header      = $this->read_record( $handle, $line_number );
			if ( null === $header ) {
				throw new \RuntimeException( 'The vector package is empty.' );
			}
			$expected_count = $this->validate_header( $header );
			$records        = array();
			while ( true ) {
				$row = $this->read_record( $handle, $line_number );
				if ( null === $row ) {
					break;
				}
				$record = $this->validate_record( $row, $line_number, $eligible );
				$wp_id  = $record['source_wp_id'];
				if ( isset( $records[ $wp_id ] ) ) {
					throw new \RuntimeException( sprintf( 'Duplicate sourceWpId %d appears on line %d.', $wp_id, $line_number ) );
				}
				$records[ $wp_id ] = $record;
			}
		} finally {
			gzclose( $handle );
		}

		if ( count( $records ) !== $expected_count ) {
			throw new \RuntimeException(
				sprintf( 'Package header declares %d records, but %d were found.', $expected_count, count( $records ) )
			);
		}
		$missing_ids = array_diff_key( $eligible, $records );
		if ( ! empty( $missing_ids ) ) {
			throw new \RuntimeException(
				sprintf(
					'Vector package is missing %d eligible posts; first missing WordPress ID: %d.',
					count( $missing_ids ),
					(int) array_key_first( $missing_ids )
				)
			);
		}

		$wpdb     = Database::client();
		$table    = Database::tables()['post_embeddings'];
		$existing = $this->stored_rows();
		$imported = 0;
		$skipped  = 0;
		$removed  = 0;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'The vector import transaction could not be started.' );
		}

		try {
			foreach ( $records as $wp_id => $record ) {
				$current = $existing[ $wp_id ] ?? null;
				if ( is_array( $current ) && self::records_match( $record, $current ) ) {
					++$skipped;
					continue;
				}

				$stored = $wpdb->replace(
					$table,
					array(
						'source_wp_id'   => $wp_id,
						'content_hash'   => $record['content_hash'],
						'model'          => $record['model'],
						'dimensions'     => $record['dimensions'],
						'embedding'      => $record['embedding'],
						'embedding_norm' => number_format( $record['embedding_norm'], 12, '.', '' ),
						'updated_at'     => $record['updated_at'],
					),
					array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
				if ( false === $stored ) {
					throw new \RuntimeException( sprintf( 'Vector for WordPress post %d could not be stored.', $wp_id ) );
				}
				++$imported;
			}

			foreach ( array_keys( $existing ) as $wp_id ) {
				if ( isset( $records[ $wp_id ] ) ) {
					continue;
				}
				$deleted = $wpdb->delete( $table, array( 'source_wp_id' => $wp_id ), array( '%d' ) );
				if ( false === $deleted ) {
					throw new \RuntimeException( sprintf( 'Obsolete vector for WordPress post %d could not be removed.', $wp_id ) );
				}
				$removed += (int) $deleted;
			}

			$status = ( new Semantic_Search_Service() )->status();
			if ( $status['current'] !== $status['eligible'] || $status['missing'] > 0 || $status['stale'] > 0 || $status['obsolete'] > 0 ) {
				throw new \RuntimeException( 'Imported vectors did not produce a complete, current semantic index.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'The vector import transaction could not be committed.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		return array(
			'file'     => $file,
			'records'  => count( $records ),
			'imported' => $imported,
			'skipped'  => $skipped,
			'removed'  => $removed,
			'missing'  => 0,
			'rejected' => 0,
		);
	}

	/**
	 * Loads current eligible post vectors for export.
	 *
	 * @return list<array<string,mixed>> Current vector rows.
	 */
	private function current_rows(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = $wpdb->prepare(
			// @phpstan-ignore-next-line The query uses internally generated table identifiers.
			"SELECT e.* FROM {$tables['post_embeddings']} e JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id "
			. "WHERE p.source_wp_id IS NOT NULL AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore') "
			. 'AND e.model=%s AND e.dimensions=%d AND e.embedding_norm>0 AND OCTET_LENGTH(e.embedding)=e.dimensions*4 '
			. "AND e.content_hash=SHA2(CONCAT('Title: ',COALESCE(p.title,''),CHAR(10),'Summary: ',COALESCE(p.search_summary,'')),256) "
			. 'ORDER BY e.source_wp_id',
			Embedding_Service::model_id(),
			Embedding_Service::dimensions()
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are internal and values were prepared above.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Loads posts eligible for the selected semantic index, keyed by WordPress ID.
	 *
	 * @return array<int,array<string,mixed>> Eligible posts.
	 */
	private function eligible_posts(): array {
		$map = array();
		foreach ( ( new Semantic_Search_Service() )->eligible_posts() as $post ) {
			$map[ Database::integer( $post['source_wp_id'] ?? null ) ] = $post;
		}
		return $map;
	}

	/**
	 * Loads every stored post vector, keyed by WordPress ID.
	 *
	 * @return array<int,array<string,mixed>> Stored vectors.
	 */
	private function stored_rows(): array {
		$table = Database::tables()['post_embeddings'];
		$rows  = Database::associative_rows( Database::client()->get_results( "SELECT * FROM {$table}", ARRAY_A ) );
		$map   = array();
		foreach ( $rows as $row ) {
			$map[ Database::integer( $row['source_wp_id'] ?? null ) ] = $row;
		}
		return $map;
	}

	/**
	 * Validates the package header and returns its declared record count.
	 *
	 * @param array<string,mixed> $header Package header.
	 * @throws \RuntimeException When package metadata is missing or incompatible.
	 */
	private function validate_header( array $header ): int {
		if ( self::FORMAT !== Database::text( $header['format'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package format is not recognized.' );
		}
		if ( self::FORMAT_VERSION !== Database::integer( $header['version'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package version is not supported.' );
		}
		if ( self::CONTENT_BASIS !== Database::text( $header['contentBasis'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package uses an incompatible content basis.' );
		}
		if ( Embedding_Service::model_id() !== Database::text( $header['model'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package embedding model does not match this site.' );
		}
		if ( Embedding_Service::dimensions() !== Database::integer( $header['dimensions'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package dimensions do not match this site.' );
		}
		$count = Database::integer( $header['count'] ?? null );
		if ( $count < 1 ) {
			throw new \RuntimeException( 'The vector package does not declare a positive record count.' );
		}
		if ( '' === Database::text( $header['createdAt'] ?? null ) ) {
			throw new \RuntimeException( 'The vector package does not include its creation time.' );
		}
		return $count;
	}

	/**
	 * Validates and normalizes one vector record.
	 *
	 * @param array<string,mixed>            $row         Package record.
	 * @param int                            $line_number Source line number.
	 * @param array<int,array<string,mixed>> $eligible    Eligible posts keyed by WordPress ID.
	 * @return array{source_wp_id:int,content_hash:string,model:string,dimensions:int,embedding:string,embedding_norm:float,updated_at:string} Validated record.
	 * @throws \RuntimeException When the record is malformed, stale, unknown, or incompatible.
	 */
	private function validate_record( array $row, int $line_number, array $eligible ): array {
		if ( 'embedding' !== Database::text( $row['type'] ?? null ) ) {
			throw new \RuntimeException( sprintf( 'Line %d is not an embedding record.', $line_number ) );
		}
		$wp_id = Database::integer( $row['sourceWpId'] ?? null );
		if ( $wp_id < 1 || ! isset( $eligible[ $wp_id ] ) ) {
			throw new \RuntimeException( sprintf( 'Line %d references an unknown or ineligible WordPress post.', $line_number ) );
		}
		$content_hash = Database::text( $row['contentHash'] ?? null );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) {
			throw new \RuntimeException( sprintf( 'Line %d contains an invalid content hash.', $line_number ) );
		}
		if ( ! hash_equals( Semantic_Search_Service::content_hash( $eligible[ $wp_id ] ), $content_hash ) ) {
			throw new \RuntimeException( sprintf( 'Line %d contains a stale content hash for WordPress post %d.', $line_number, $wp_id ) );
		}

		$model      = Database::text( $row['model'] ?? null );
		$dimensions = Database::integer( $row['dimensions'] ?? null );
		if ( Embedding_Service::model_id() !== $model || Embedding_Service::dimensions() !== $dimensions ) {
			throw new \RuntimeException( sprintf( 'Line %d contains an incompatible model or dimension count.', $line_number ) );
		}
		$encoded = Database::text( $row['embedding'] ?? null );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the documented binary-vector transport field.
		$embedding = base64_decode( $encoded, true );
		if ( false === $embedding || strlen( $embedding ) !== $dimensions * 4 ) {
			throw new \RuntimeException( sprintf( 'Line %d contains invalid vector bytes.', $line_number ) );
		}
		$values = unpack( 'g*', $embedding );
		if ( ! is_array( $values ) || count( $values ) !== $dimensions ) {
			throw new \RuntimeException( sprintf( 'Line %d could not be decoded as a float32 vector.', $line_number ) );
		}
		$sum = 0.0;
		foreach ( $values as $value ) {
			$number = is_numeric( $value ) ? (float) $value : NAN;
			if ( ! is_finite( $number ) ) {
				throw new \RuntimeException( sprintf( 'Line %d contains a non-finite vector value.', $line_number ) );
			}
			$sum += $number * $number;
		}
		$calculated_norm = sqrt( $sum );
		$declared_norm   = (float) Database::text( $row['embeddingNorm'] ?? null );
		$tolerance       = max( 0.000000001, $calculated_norm * 0.000001 );
		if ( $declared_norm <= 0.0 || ! is_finite( $declared_norm ) || abs( $calculated_norm - $declared_norm ) > $tolerance ) {
			throw new \RuntimeException( sprintf( 'Line %d contains an invalid vector norm.', $line_number ) );
		}
		$updated_at = Database::text( $row['updatedAt'] ?? null );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated_at ) ) {
			throw new \RuntimeException( sprintf( 'Line %d contains an invalid update time.', $line_number ) );
		}

		return array(
			'source_wp_id'   => $wp_id,
			'content_hash'   => $content_hash,
			'model'          => $model,
			'dimensions'     => $dimensions,
			'embedding'      => $embedding,
			'embedding_norm' => $calculated_norm,
			'updated_at'     => $updated_at,
		);
	}

	/**
	 * Reads and decodes the next nonblank JSON Lines record.
	 *
	 * @param resource $handle      Gzip file handle.
	 * @param int      $line_number Current line number, updated by reference.
	 * @return array<string,mixed>|null Decoded record or null at end of file.
	 * @throws \RuntimeException When the package cannot be read or decoded.
	 */
	private function read_record( $handle, int &$line_number ): ?array {
		while ( ! gzeof( $handle ) ) {
			$line = gzgets( $handle, self::MAX_LINE_LENGTH + 1 );
			if ( false === $line ) {
				throw new \RuntimeException( 'The vector package could not be read.' );
			}
			++$line_number;
			if ( strlen( $line ) > self::MAX_LINE_LENGTH ) {
				throw new \RuntimeException( sprintf( 'Line %d exceeds the maximum package line length.', $line_number ) );
			}
			if ( '' === trim( $line ) ) {
				continue;
			}
			try {
				$value = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new \RuntimeException( sprintf( 'Line %d is not valid JSON: %s', $line_number, $error->getMessage() ) );
			}
			$row = Database::associative_row( $value );
			if ( null === $row ) {
				throw new \RuntimeException( sprintf( 'Line %d is not a JSON object.', $line_number ) );
			}
			return $row;
		}
		return null;
	}

	/**
	 * Writes one JSON Lines record.
	 *
	 * @param resource            $handle Gzip file handle.
	 * @param array<string,mixed> $record Record to encode.
	 * @throws \RuntimeException When the record cannot be encoded or written.
	 */
	private function write_line( $handle, array $record ): void {
		$encoded = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || false === gzwrite( $handle, $encoded . "\n" ) ) {
			throw new \RuntimeException( 'A vector package record could not be written.' );
		}
	}

	/**
	 * Compares one validated package record with a stored database row.
	 *
	 * @param array{source_wp_id:int,content_hash:string,model:string,dimensions:int,embedding:string,embedding_norm:float,updated_at:string} $record  Package record.
	 * @param array<string,mixed>                                                                                                             $current Stored row.
	 */
	private static function records_match( array $record, array $current ): bool {
		return hash_equals( $record['content_hash'], Database::text( $current['content_hash'] ?? null ) )
			&& Database::text( $current['model'] ?? null ) === $record['model']
			&& Database::integer( $current['dimensions'] ?? null ) === $record['dimensions']
			&& hash_equals( $record['embedding'], Database::text( $current['embedding'] ?? null ) )
			&& 0.000000001 > abs( $record['embedding_norm'] - (float) Database::text( $current['embedding_norm'] ?? null ) );
	}

	/**
	 * Resolves and validates a package path without creating directories.
	 *
	 * @param string $file       Requested path.
	 * @param bool   $must_exist Whether the source file must exist.
	 * @throws \RuntimeException When the path, source file, or destination directory is invalid.
	 */
	private static function normalize_path( string $file, bool $must_exist ): string {
		$file = trim( $file );
		if ( '' === $file || str_contains( $file, "\0" ) || 1 !== preg_match( '/\.jsonl\.gz$/i', $file ) ) {
			throw new \RuntimeException( 'Use a nonempty file path ending in .jsonl.gz.' );
		}
		$directory = realpath( dirname( $file ) );
		if ( false === $directory || ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'The vector package directory does not exist.' );
		}
		$resolved = wp_normalize_path( $directory . DIRECTORY_SEPARATOR . basename( $file ) );
		if ( $must_exist && ( ! is_file( $resolved ) || ! is_readable( $resolved ) ) ) {
			throw new \RuntimeException( 'The vector import file does not exist or is not readable.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- CLI export validates its explicit destination before opening it.
		if ( ! $must_exist && ! is_writable( $directory ) ) {
			throw new \RuntimeException( 'The vector export directory is not writable.' );
		}
		return $resolved;
	}
}
