<?php
/**
 * Historical Ask AI interpretation feedback reporting.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads retained interpretation feedback from before request-based analytics. */
final class AI_Feedback {
	private const RETENTION_DAYS = 90;

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
