<?php
/**
 * Ask AI request-level analytics storage.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores anonymous Ask AI requests and connects results, usage, and feedback. */
final class AI_Requests {
	private const RETENTION_DAYS = 90;

	/** Generates a public-safe correlation identifier. */
	public static function request_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Stores one submitted question and its interpretation outcome.
	 *
	 * @param string                    $request_id Correlation identifier.
	 * @param string                    $question   Submitted question.
	 * @param list<array<string,mixed>> $terms      Interpreted terms.
	 * @param bool                      $cache_hit   Whether interpretation came from cache.
	 * @param bool                      $succeeded   Whether interpretation succeeded.
	 * @param string                    $error_code  Stable error code when unsuccessful.
	 */
	public static function record( string $request_id, string $question, array $terms, bool $cache_hit, bool $succeeded, string $error_code = '' ): void {
		self::delete_expired();
		$wpdb     = Database::client();
		$table    = Database::tables()['ai_requests'];
		$question = sanitize_text_field( $question );
		$question = function_exists( 'mb_substr' ) ? mb_substr( $question, 0, 800 ) : substr( $question, 0, 800 );
		$wpdb->replace(
			$table,
			array(
				'request_id'        => sanitize_text_field( $request_id ),
				'created_at'        => current_time( 'mysql', true ),
				'question'          => $question,
				'selected_terms'    => (string) wp_json_encode( self::terms( $terms ) ),
				'result_count'      => 0,
				'model'             => AI_Interpreter::model_id(),
				'prompt_version'    => AI_Interpreter::prompt_version(),
				'cache_hit'         => $cache_hit ? 1 : 0,
				'request_succeeded' => $succeeded ? 1 : 0,
				'error_code'        => sanitize_key( $error_code ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Updates the number of posts returned for a request.
	 *
	 * @param string $request_id Correlation identifier.
	 * @param int    $count      Matching post count.
	 */
	public static function set_result_count( string $request_id, int $count ): void {
		if ( ! self::valid_id( $request_id ) ) {
			return;
		}
		Database::client()->update(
			Database::tables()['ai_requests'],
			array(
				'result_count'    => max( 0, $count ),
				'result_recorded' => 1,
			),
			array(
				'request_id'      => $request_id,
				'result_recorded' => 0,
			),
			array( '%d', '%d' ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Records Yes or No feedback on an existing request.
	 *
	 * @param string $request_id Correlation identifier.
	 * @param bool   $helpful    Whether the results were helpful.
	 */
	public static function set_feedback( string $request_id, bool $helpful ): bool {
		if ( ! self::valid_id( $request_id ) ) {
			return false;
		}
		$updated = Database::client()->update(
			Database::tables()['ai_requests'],
			array(
				'feedback'    => $helpful ? 1 : 0,
				'feedback_at' => current_time( 'mysql', true ),
			),
			array( 'request_id' => $request_id ),
			array( '%d', '%s' ),
			array( '%s' )
		);
		return false !== $updated && 0 < $updated;
	}

	/**
	 * Returns filtered request rows and aggregate values.
	 *
	 * @param array<string,string> $filters Filter values.
	 * @param int                  $page    Page number.
	 * @param int                  $per_page Rows per page, or zero for all.
	 * @return array{rows:list<array<string,mixed>>,total:int,yes:int,no:int,unanswered:int,response_rate:float,helpful_rate:float}
	 */
	public static function analytics( array $filters, int $page = 1, int $per_page = 50 ): array {
		self::delete_expired();
		$rows     = self::rows();
		$filtered = array_values( array_filter( $rows, static fn( array $row ): bool => self::matches( $row, $filters ) ) );
		$total    = count( $filtered );
		$yes      = count( array_filter( $filtered, static fn( array $row ): bool => '1' === Database::text( $row['feedback'] ?? null ) ) );
		$no       = count( array_filter( $filtered, static fn( array $row ): bool => '0' === Database::text( $row['feedback'] ?? null ) ) );
		$answered = $yes + $no;
		if ( 0 < $per_page ) {
			$filtered = array_slice( $filtered, ( max( 1, $page ) - 1 ) * $per_page, $per_page );
		}
		return array(
			'rows'          => $filtered,
			'total'         => $total,
			'yes'           => $yes,
			'no'            => $no,
			'unanswered'    => max( 0, $total - $answered ),
			'response_rate' => 0 < $total ? ( $answered / $total ) * 100 : 0.0,
			'helpful_rate'  => 0 < $answered ? ( $yes / $answered ) * 100 : 0.0,
		);
	}

	/**
	 * Loads retained requests with their aggregated usage.
	 *
	 * @return list<array<string,mixed>> Request rows.
	 */
	private static function rows(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$usage  = "SELECT request_id,SUM(input_tokens) input_tokens,SUM(cached_input_tokens) cached_input_tokens,SUM(output_tokens) output_tokens,SUM(estimated_cost_usd) estimated_cost_usd FROM {$tables['ai_usage']} WHERE request_id<>'' GROUP BY request_id";
		$sql    = "SELECT r.*,u.input_tokens,u.cached_input_tokens,u.output_tokens,u.estimated_cost_usd FROM {$tables['ai_requests']} r LEFT JOIN ({$usage}) u ON u.request_id=r.request_id ORDER BY r.id DESC LIMIT 5000";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All table identifiers are generated internally.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns whether one row satisfies the administrator filters.
	 *
	 * @param array<string,mixed>  $row     Request row.
	 * @param array<string,string> $filters Administrator filters.
	 */
	private static function matches( array $row, array $filters ): bool {
		$feedback = $filters['feedback'] ?? 'all';
		$value    = Database::text( $row['feedback'] ?? null );
		if ( 'yes' === $feedback && '1' !== $value ) {
			return false;
		}
		if ( 'no' === $feedback && '0' !== $value ) {
			return false;
		}
		if ( 'unanswered' === $feedback && '' !== $value ) {
			return false;
		}
		if ( '1' === ( $filters['zero_results'] ?? '' ) && 0 !== Database::integer( $row['result_count'] ?? 0 ) ) {
			return false;
		}
		$created = Database::text( $row['created_at'] ?? '' );
		if ( '' !== ( $filters['date_from'] ?? '' ) && $created < $filters['date_from'] . ' 00:00:00' ) {
			return false;
		}
		if ( '' !== ( $filters['date_to'] ?? '' ) && $created > $filters['date_to'] . ' 23:59:59' ) {
			return false;
		}
		$needle   = Search_Service::normalize( $filters['search'] ?? '' );
		$haystack = Search_Service::normalize( Database::text( $row['question'] ?? '' ) . ' ' . Database::text( $row['selected_terms'] ?? '' ) );
		return '' === $needle || str_contains( $haystack, $needle );
	}

	/**
	 * Sanitizes interpreted term labels and modes.
	 *
	 * @param array<mixed> $terms Candidate terms.
	 * @return list<array{label:string,mode:string}> Sanitized terms.
	 */
	private static function terms( array $terms ): array {
		$clean = array();
		foreach ( array_slice( $terms, 0, Search_Service::MAX_TERMS ) as $term ) {
			if ( ! is_array( $term ) || ! is_scalar( $term['label'] ?? null ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) $term['label'] );
			$mode  = 'topic' === ( $term['mode'] ?? '' ) ? 'topic' : 'keyword';
			if ( '' !== $label ) {
				$clean[] = array(
					'label' => $label,
					'mode'  => $mode,
				);
			}
		}
		return $clean;
	}

	/**
	 * Returns whether a correlation identifier is a valid UUID.
	 *
	 * @param string $request_id Correlation identifier.
	 */
	private static function valid_id( string $request_id ): bool {
		return (bool) preg_match( '/^[a-f0-9-]{36}$/', $request_id );
	}

	/** Deletes detailed questions after the retention period. */
	private static function delete_expired(): void {
		$wpdb   = Database::client();
		$table  = Database::tables()['ai_requests'];
		$cutoff = esc_sql( gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		$sql    = "DELETE FROM {$table} WHERE created_at < '{$cutoff}'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier and escaped date are generated internally.
		$wpdb->query( $sql );
	}
}
