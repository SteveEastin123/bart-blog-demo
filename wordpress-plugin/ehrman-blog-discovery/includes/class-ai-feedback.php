<?php
/**
 * Ask AI interpretation feedback storage and reporting.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores anonymous interpretation feedback for a limited period. */
final class AI_Feedback {
	private const RETENTION_DAYS = 90;

	/**
	 * Records one feedback response after validating its controlled terms.
	 *
	 * @param string       $question     Reader question.
	 * @param array<mixed> $terms        Selected term labels.
	 * @param bool         $helpful      Whether the terms reflected the question.
	 * @param int          $result_count Number of matching posts.
	 * @return bool Whether feedback was stored.
	 */
	public static function record( string $question, array $terms, bool $helpful, int $result_count ): bool {
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' ) ? mb_substr( $question, 0, 800 ) : substr( $question, 0, 800 );
		$terms    = self::validated_terms( $terms );
		if ( '' === trim( $question ) || empty( $terms ) ) {
			return false;
		}

		self::delete_expired();
		$wpdb   = Database::client();
		$table  = Database::tables()['ai_feedback'];
		$stored = $wpdb->insert(
			$table,
			array(
				'created_at'     => current_time( 'mysql', true ),
				'question'       => $question,
				'selected_terms' => (string) wp_json_encode( $terms ),
				'helpful'        => $helpful ? 1 : 0,
				'result_count'   => max( 0, $result_count ),
				'model'          => AI_Interpreter::model_id(),
				'prompt_version' => AI_Interpreter::prompt_version(),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return false !== $stored;
	}

	/**
	 * Returns aggregate feedback and recent negative examples.
	 *
	 * @return array{total:int,helpful:int,not_helpful:int,helpful_rate:float,recent_negative:list<array<string,mixed>>}
	 */
	public static function report(): array {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_feedback'];
		if ( ! Database::table_exists( $table ) ) {
			return self::empty_report();
		}
		self::delete_expired();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$row = Database::associative_row( $wpdb->get_row( "SELECT COUNT(*) AS total,SUM(helpful) AS helpful FROM {$table}", ARRAY_A ) );
		if ( null === $row ) {
			return self::empty_report();
		}
		$total      = Database::integer( $row['total'] ?? 0 );
		$helpful    = Database::integer( $row['helpful'] ?? 0 );
		$recent_sql = "SELECT created_at,question,selected_terms,result_count,model,prompt_version FROM {$table} "
			. 'WHERE helpful=0 ORDER BY id DESC LIMIT 20';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$recent_rows = Database::associative_rows( $wpdb->get_results( $recent_sql, ARRAY_A ) );
		return array(
			'total'           => $total,
			'helpful'         => $helpful,
			'not_helpful'     => max( 0, $total - $helpful ),
			'helpful_rate'    => $total > 0 ? ( $helpful / $total ) * 100 : 0.0,
			'recent_negative' => $recent_rows,
		);
	}

	/**
	 * Keeps only terms from the controlled search vocabulary.
	 *
	 * @param array<mixed> $terms Candidate term labels.
	 * @return list<string> Validated term labels.
	 */
	private static function validated_terms( array $terms ): array {
		$wpdb      = Database::client();
		$tables    = Database::tables();
		$topic_sql = "SELECT name FROM {$tables['topics']} WHERE display_in_browser=1 AND name<>'Ignore'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$labels = Database::strings( $wpdb->get_col( $topic_sql ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$labels   = array_merge( $labels, Database::strings( $wpdb->get_col( "SELECT label FROM {$tables['keywords']}" ) ) );
		$approved = array();
		foreach ( $labels as $label ) {
			$approved[ Search_Service::normalize( $label ) ] = $label;
		}
		$validated = array();
		foreach ( array_slice( $terms, 0, Search_Service::MAX_TERMS ) as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $term );
			if ( isset( $approved[ $normalized ] ) && ! in_array( $approved[ $normalized ], $validated, true ) ) {
				$validated[] = $approved[ $normalized ];
			}
		}
		return $validated;
	}

	/** Deletes feedback beyond the disclosed retention period. */
	private static function delete_expired(): void {
		$wpdb   = Database::client();
		$table  = Database::tables()['ai_feedback'];
		$cutoff = esc_sql( gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		$sql    = "DELETE FROM {$table} WHERE created_at < '{$cutoff}'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier and escaped date are generated internally.
		$wpdb->query( $sql );
	}

	/**
	 * Returns an empty report with a stable shape.
	 *
	 * @return array{total:int,helpful:int,not_helpful:int,helpful_rate:float,recent_negative:list<array<string,mixed>>}
	 */
	private static function empty_report(): array {
		return array(
			'total'           => 0,
			'helpful'         => 0,
			'not_helpful'     => 0,
			'helpful_rate'    => 0.0,
			'recent_negative' => array(),
		);
	}
}
