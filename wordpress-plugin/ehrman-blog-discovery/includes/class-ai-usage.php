<?php
/**
 * AI request usage and estimated-cost tracking.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Records privacy-conscious AI usage and provides aggregate reporting. */
final class AI_Usage {
	private const PRICING_VERSION = '2026-08-21';
	private const INPUT_RATE      = 0.75;
	private const CACHED_RATE     = 0.075;
	private const OUTPUT_RATE     = 4.50;

	/**
	 * Records an interpretation served from the WordPress cache.
	 *
	 * @param string $model Configured model identifier.
	 * @param string $request_id Correlation identifier.
	 */
	public static function record_cache_hit( string $model, string $request_id = '' ): void {
		self::insert( $model, 0, 0, 0, 0.0, true, true, '', $request_id );
	}

	/**
	 * Records an OpenAI response, including token usage when available.
	 *
	 * @param array<string,mixed> $response_body Decoded Responses API body.
	 * @param bool                $succeeded     Whether the interpretation was usable.
	 * @param string              $error_code    Stable local error code.
	 * @param string              $request_id    Correlation identifier.
	 */
	public static function record_response( array $response_body, bool $succeeded, string $error_code = '', string $request_id = '' ): void {
		$usage          = is_array( $response_body['usage'] ?? null ) ? $response_body['usage'] : array();
		$details        = is_array( $usage['input_tokens_details'] ?? null ) ? $usage['input_tokens_details'] : array();
		$input_tokens   = self::nonnegative_integer( $usage['input_tokens'] ?? 0 );
		$cached_tokens  = min( $input_tokens, self::nonnegative_integer( $details['cached_tokens'] ?? 0 ) );
		$output_tokens  = self::nonnegative_integer( $usage['output_tokens'] ?? 0 );
		$model          = is_scalar( $response_body['model'] ?? null ) ? sanitize_text_field( (string) $response_body['model'] ) : '';
		$estimated_cost = self::estimate_cost( $input_tokens, $cached_tokens, $output_tokens );

		self::insert( $model, $input_tokens, $cached_tokens, $output_tokens, $estimated_cost, false, $succeeded, $error_code, $request_id );
	}

	/**
	 * Records an API request that failed before a usable response was returned.
	 *
	 * @param string $model      Configured model identifier.
	 * @param string $error_code Stable local error code.
	 * @param string $request_id Correlation identifier.
	 */
	public static function record_failure( string $model, string $error_code, string $request_id = '' ): void {
		self::insert( $model, 0, 0, 0, 0.0, false, false, $error_code, $request_id );
	}

	/**
	 * Returns aggregate usage statistics for the administration page.
	 *
	 * @return array{
	 *   submissions:int,api_requests:int,cache_hits:int,failures:int,
	 *   input_tokens:int,cached_input_tokens:int,output_tokens:int,
	 *   total_cost:float,month_cost:float,today_cost:float,average_cost:float,
	 *   models:list<array<string,mixed>>
	 * } Aggregate usage values.
	 */
	public static function report(): array {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_usage'];
		if ( ! Database::table_exists( $table ) ) {
			return self::empty_report();
		}

		$today_start = gmdate( 'Y-m-d 00:00:00', time() );
		$month_start = gmdate( 'Y-m-01 00:00:00', time() );
		$sql         = $wpdb->prepare(
			// @phpstan-ignore-next-line The query uses an internally generated table identifier.
			"SELECT COUNT(*) AS submissions,
			SUM(CASE WHEN cache_hit=0 THEN 1 ELSE 0 END) AS api_requests,
			SUM(cache_hit) AS cache_hits,
			SUM(CASE WHEN request_succeeded=0 THEN 1 ELSE 0 END) AS failures,
			SUM(input_tokens) AS input_tokens,
			SUM(cached_input_tokens) AS cached_input_tokens,
			SUM(output_tokens) AS output_tokens,
			SUM(estimated_cost_usd) AS total_cost,
			SUM(CASE WHEN created_at >= %s THEN estimated_cost_usd ELSE 0 END) AS month_cost,
			SUM(CASE WHEN created_at >= %s THEN estimated_cost_usd ELSE 0 END) AS today_cost
			FROM {$table}",
			$month_start,
			$today_start
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$row = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		if ( null === $row ) {
			return self::empty_report();
		}

		$api_requests = Database::integer( $row['api_requests'] ?? 0 );
		$total_cost   = (float) Database::text( $row['total_cost'] ?? 0 );
		$model_sql    = 'SELECT model,COUNT(*) AS submissions,SUM(CASE WHEN cache_hit=0 THEN 1 ELSE 0 END) AS api_requests,'
			. 'SUM(input_tokens) AS input_tokens,SUM(output_tokens) AS output_tokens,SUM(estimated_cost_usd) AS total_cost '
			. "FROM {$table} GROUP BY model ORDER BY total_cost DESC,model";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$model_rows = Database::associative_rows( $wpdb->get_results( $model_sql, ARRAY_A ) );

		return array(
			'submissions'         => Database::integer( $row['submissions'] ?? 0 ),
			'api_requests'        => $api_requests,
			'cache_hits'          => Database::integer( $row['cache_hits'] ?? 0 ),
			'failures'            => Database::integer( $row['failures'] ?? 0 ),
			'input_tokens'        => Database::integer( $row['input_tokens'] ?? 0 ),
			'cached_input_tokens' => Database::integer( $row['cached_input_tokens'] ?? 0 ),
			'output_tokens'       => Database::integer( $row['output_tokens'] ?? 0 ),
			'total_cost'          => $total_cost,
			'month_cost'          => (float) Database::text( $row['month_cost'] ?? 0 ),
			'today_cost'          => (float) Database::text( $row['today_cost'] ?? 0 ),
			'average_cost'        => $api_requests > 0 ? $total_cost / $api_requests : 0.0,
			'models'              => $model_rows,
		);
	}

	/**
	 * Estimates the API cost from reported token usage.
	 *
	 * @param int $input_tokens  Total input tokens.
	 * @param int $cached_tokens Cached input tokens.
	 * @param int $output_tokens Output tokens.
	 */
	private static function estimate_cost( int $input_tokens, int $cached_tokens, int $output_tokens ): float {
		$uncached_tokens = max( 0, $input_tokens - $cached_tokens );
		return ( ( $uncached_tokens * self::INPUT_RATE ) + ( $cached_tokens * self::CACHED_RATE ) + ( $output_tokens * self::OUTPUT_RATE ) ) / 1000000;
	}

	/**
	 * Inserts one privacy-conscious usage event.
	 *
	 * @param string $model         Model identifier.
	 * @param int    $input_tokens  Total input tokens.
	 * @param int    $cached_tokens Cached input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param float  $cost          Estimated US-dollar cost.
	 * @param bool   $cache_hit      Whether WordPress served the interpretation from cache.
	 * @param bool   $succeeded      Whether the interpretation succeeded.
	 * @param string $error_code     Stable local error code.
	 * @param string $request_id     Correlation identifier.
	 */
	private static function insert( string $model, int $input_tokens, int $cached_tokens, int $output_tokens, float $cost, bool $cache_hit, bool $succeeded, string $error_code, string $request_id ): void {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_usage'];
		$wpdb->insert(
			$table,
			array(
				'created_at'          => current_time( 'mysql', true ),
				'model'               => sanitize_text_field( $model ),
				'input_tokens'        => $input_tokens,
				'cached_input_tokens' => $cached_tokens,
				'output_tokens'       => $output_tokens,
				'estimated_cost_usd'  => number_format( $cost, 8, '.', '' ),
				'cache_hit'           => $cache_hit ? 1 : 0,
				'request_succeeded'   => $succeeded ? 1 : 0,
				'error_code'          => sanitize_key( $error_code ),
				'pricing_version'     => self::PRICING_VERSION,
				'request_id'          => sanitize_text_field( $request_id ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Converts a numeric value to a nonnegative integer.
	 *
	 * @param mixed $value Candidate numeric value.
	 */
	private static function nonnegative_integer( $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Returns an empty report with the complete reporting shape.
	 *
	 * @return array{
	 *   submissions:int,api_requests:int,cache_hits:int,failures:int,
	 *   input_tokens:int,cached_input_tokens:int,output_tokens:int,
	 *   total_cost:float,month_cost:float,today_cost:float,average_cost:float,
	 *   models:list<array<string,mixed>>
	 * } Empty aggregate report.
	 */
	private static function empty_report(): array {
		return array(
			'submissions'         => 0,
			'api_requests'        => 0,
			'cache_hits'          => 0,
			'failures'            => 0,
			'input_tokens'        => 0,
			'cached_input_tokens' => 0,
			'output_tokens'       => 0,
			'total_cost'          => 0.0,
			'month_cost'          => 0.0,
			'today_cost'          => 0.0,
			'average_cost'        => 0.0,
			'models'              => array(),
		);
	}
}
