<?php
/**
 * Approved ingestion vocabulary reads.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the current topic and keyword vocabulary used for post analysis.
 *
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 */
final class Post_Ingestion_Vocabulary_Reader {
	/**
	 * Returns approved vocabulary for review controls.
	 *
	 * @phpstan-return Vocabulary
	 */
	public function vocabulary(): array {
		$wpdb            = Database::client();
		$tables          = Database::tables();
		$topic_alias_sql = "SELECT topic_id,label FROM {$tables['topic_aliases']} ORDER BY topic_id,label";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is internal.
		$topic_alias_rows = Database::associative_rows( $wpdb->get_results( $topic_alias_sql, ARRAY_A ) );
		$topic_aliases    = array();
		foreach ( $topic_alias_rows as $row ) {
			$topic_id = Database::integer( $row['topic_id'] ?? null );
			$label    = Database::text( $row['label'] ?? null );
			if ( $topic_id > 0 && '' !== $label ) {
				$topic_aliases[ $topic_id ][] = $label;
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is internal.
		$topic_rows  = Database::associative_rows( $wpdb->get_results( "SELECT id,name,description FROM {$tables['topics']} ORDER BY CASE WHEN name='Ignore' THEN 1 ELSE 0 END,name", ARRAY_A ) );
		$topics      = array_map(
			static function ( array $row ) use ( $topic_aliases ): array {
				$id = Database::integer( $row['id'] ?? null );
				return array(
					'id'          => $id,
					'name'        => Database::text( $row['name'] ?? null ),
					'description' => Database::text( $row['description'] ?? null ),
					'aliases'     => array_values( array_unique( $topic_aliases[ $id ] ?? array() ) ),
				);
			},
			$topic_rows
		);
		$keyword_sql = "SELECT k.id,k.label,k.normalized,COUNT(pk.post_id) usage_count FROM {$tables['keywords']} k "
			. "LEFT JOIN {$tables['post_keywords']} pk ON pk.keyword_id=k.id GROUP BY k.id,k.label,k.normalized ORDER BY k.label";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are internal.
		$keyword_rows = Database::associative_rows( $wpdb->get_results( $keyword_sql, ARRAY_A ) );
		$keywords     = array_map(
			static fn( array $row ): array => array(
				'id'         => Database::integer( $row['id'] ?? null ),
				'label'      => Database::text( $row['label'] ?? null ),
				'normalized' => Database::text( $row['normalized'] ?? null ),
				'count'      => Database::integer( $row['usage_count'] ?? null ),
			),
			$keyword_rows
		);
		return array(
			'topics'   => $topics,
			'keywords' => $keywords,
		);
	}

	/**
	 * Returns a stable hash of an approved vocabulary snapshot.
	 *
	 * @param array<string,mixed> $taxonomy Approved vocabulary.
	 * @phpstan-param Vocabulary $taxonomy
	 */
	public function vocabulary_hash( array $taxonomy ): string {
		$taxonomy_json = wp_json_encode( $taxonomy );
		return hash( 'sha256', is_string( $taxonomy_json ) ? $taxonomy_json : '' );
	}
}
