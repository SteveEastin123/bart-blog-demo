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

/** Records privacy-conscious AI usage and reports index-preparation costs. */
final class AI_Usage {
	private const CACHE_VERSION_OPTION = 'ehrman_discovery_ai_cache_version';
	private const PRICING_VERSION      = '2026-08-21';
	private const INPUT_RATE           = 0.75;
	private const CACHED_RATE          = 0.075;
	private const OUTPUT_RATE          = 4.50;
	private const EMBEDDING_RATE       = 0.02;

	/** Returns the namespace version shared by all AI search caches. */
	public static function cache_version(): int {
		return max( 1, Database::integer( get_option( self::CACHE_VERSION_OPTION, 1 ) ) );
	}

	/** Invalidates cached candidate reviews and semantic query vectors. */
	public static function invalidate_search_caches(): void {
		update_option( self::CACHE_VERSION_OPTION, self::cache_version() + 1, false );
	}

	/**
	 * Records a candidate review or query vector served from the WordPress cache.
	 *
	 * @param string $model Configured model identifier.
	 * @param string $request_id Correlation identifier.
	 */
	public static function record_cache_hit( string $model, string $request_id = '' ): void {
		self::insert( self::empty_metrics( $model ), true, true, '', $request_id );
	}

	/**
	 * Records an OpenAI response, including token usage when available.
	 *
	 * @param array<string,mixed> $response_body Decoded Responses API body.
	 * @param bool                $succeeded     Whether the candidate-review response was usable.
	 * @param string              $error_code    Stable local error code.
	 * @param string              $request_id    Correlation identifier.
	 */
	public static function record_response( array $response_body, bool $succeeded, string $error_code = '', string $request_id = '' ): void {
		$metrics = self::response_metrics( $response_body );

		self::insert( $metrics, false, $succeeded, $error_code, $request_id );
	}

	/**
	 * Records an embeddings response and its input-token cost.
	 *
	 * @param array<string,mixed> $response_body Decoded Embeddings API body.
	 * @param bool                $succeeded     Whether usable vectors were returned.
	 * @param string              $error_code    Stable local error code.
	 * @param string              $request_id    Correlation identifier.
	 */
	public static function record_embedding_response( array $response_body, bool $succeeded, string $error_code = '', string $request_id = '' ): void {
		$usage        = is_array( $response_body['usage'] ?? null ) ? $response_body['usage'] : array();
		$input_tokens = self::nonnegative_integer( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 );
		$model        = self::response_text( $response_body, 'model', 100 );
		$metrics      = array(
			'response_id'         => self::response_text( $response_body, 'id', 191 ),
			'model'               => $model,
			'service_tier'        => sanitize_key( self::response_text( $response_body, 'service_tier', 32 ) ),
			'input_tokens'        => $input_tokens,
			'cached_input_tokens' => 0,
			'cache_write_tokens'  => 0,
			'output_tokens'       => 0,
			'reasoning_tokens'    => 0,
			'total_tokens'        => self::nonnegative_integer( $usage['total_tokens'] ?? $input_tokens ),
			'estimated_cost_usd'  => ( $input_tokens * self::EMBEDDING_RATE ) / 1000000,
		);

		self::insert( $metrics, false, $succeeded, $error_code, $request_id );
	}

	/**
	 * Extracts token and estimated-cost details from a Responses API body.
	 *
	 * @param array<string,mixed> $response_body Decoded Responses API body.
	 * @return array{
	 *   response_id:string,model:string,service_tier:string,input_tokens:int,
	 *   cached_input_tokens:int,cache_write_tokens:int,output_tokens:int,
	 *   reasoning_tokens:int,total_tokens:int,estimated_cost_usd:float
	 * }
	 */
	public static function response_metrics( array $response_body ): array {
		$usage          = is_array( $response_body['usage'] ?? null ) ? $response_body['usage'] : array();
		$input_details  = is_array( $usage['input_tokens_details'] ?? null ) ? $usage['input_tokens_details'] : array();
		$output_details = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : array();
		$input_tokens   = self::nonnegative_integer( $usage['input_tokens'] ?? 0 );
		$cached_tokens  = min( $input_tokens, self::nonnegative_integer( $input_details['cached_tokens'] ?? 0 ) );
		$write_tokens   = min( $input_tokens, self::nonnegative_integer( $input_details['cache_write_tokens'] ?? 0 ) );
		$output_tokens  = self::nonnegative_integer( $usage['output_tokens'] ?? 0 );
		$reasoning      = min( $output_tokens, self::nonnegative_integer( $output_details['reasoning_tokens'] ?? 0 ) );
		$total_tokens   = self::nonnegative_integer( $usage['total_tokens'] ?? ( $input_tokens + $output_tokens ) );
		$model          = self::response_text( $response_body, 'model', 100 );
		$estimated_cost = self::estimate_cost( $input_tokens, $cached_tokens, $output_tokens );

		return array(
			'response_id'         => self::response_text( $response_body, 'id', 191 ),
			'model'               => $model,
			'service_tier'        => sanitize_key( self::response_text( $response_body, 'service_tier', 32 ) ),
			'input_tokens'        => $input_tokens,
			'cached_input_tokens' => $cached_tokens,
			'cache_write_tokens'  => $write_tokens,
			'output_tokens'       => $output_tokens,
			'reasoning_tokens'    => $reasoning,
			'total_tokens'        => $total_tokens,
			'estimated_cost_usd'  => $estimated_cost,
		);
	}

	/**
	 * Records an API request that failed before a usable response was returned.
	 *
	 * @param string $model      Configured model identifier.
	 * @param string $error_code Stable local error code.
	 * @param string $request_id Correlation identifier.
	 */
	public static function record_failure( string $model, string $error_code, string $request_id = '' ): void {
		self::insert( self::empty_metrics( $model ), false, false, $error_code, $request_id );
	}

	/**
	 * Returns semantic-index embedding usage that is not tied to a question.
	 *
	 * @return array{calls:int,input_tokens:int,total_cost:float} Index-build usage values.
	 */
	public static function semantic_index_report(): array {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_usage'];
		if ( ! Database::table_exists( $table ) ) {
			return array(
				'calls'        => 0,
				'input_tokens' => 0,
				'total_cost'   => 0.0,
			);
		}
		$sql = $wpdb->prepare(
			'SELECT COUNT(*) calls,SUM(input_tokens) input_tokens,SUM(estimated_cost_usd) total_cost FROM %i WHERE request_id=\'\' AND model LIKE %s',
			$table,
			'text-embedding-%'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally and the value is prepared.
		$row = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		return array(
			'calls'        => Database::integer( $row['calls'] ?? 0 ),
			'input_tokens' => Database::integer( $row['input_tokens'] ?? 0 ),
			'total_cost'   => (float) Database::text( $row['total_cost'] ?? 0 ),
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
	 * @param array<string,int|float|string> $metrics    API response metrics.
	 * @param bool                           $cache_hit  Whether WordPress served the result from cache.
	 * @param bool                           $succeeded  Whether the review or embedding request succeeded.
	 * @param string                         $error_code Stable local error code.
	 * @param string                         $request_id Correlation identifier.
	 */
	private static function insert( array $metrics, bool $cache_hit, bool $succeeded, string $error_code, string $request_id ): void {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_usage'];
		$wpdb->insert(
			$table,
			array(
				'created_at'          => current_time( 'mysql', true ),
				'response_id'         => sanitize_text_field( Database::text( $metrics['response_id'] ?? '' ) ),
				'model'               => sanitize_text_field( Database::text( $metrics['model'] ?? '' ) ),
				'service_tier'        => sanitize_key( Database::text( $metrics['service_tier'] ?? '' ) ),
				'input_tokens'        => self::nonnegative_integer( $metrics['input_tokens'] ?? 0 ),
				'cached_input_tokens' => self::nonnegative_integer( $metrics['cached_input_tokens'] ?? 0 ),
				'cache_write_tokens'  => self::nonnegative_integer( $metrics['cache_write_tokens'] ?? 0 ),
				'output_tokens'       => self::nonnegative_integer( $metrics['output_tokens'] ?? 0 ),
				'reasoning_tokens'    => self::nonnegative_integer( $metrics['reasoning_tokens'] ?? 0 ),
				'total_tokens'        => self::nonnegative_integer( $metrics['total_tokens'] ?? 0 ),
				'estimated_cost_usd'  => number_format( (float) Database::text( $metrics['estimated_cost_usd'] ?? 0 ), 8, '.', '' ),
				'cache_hit'           => $cache_hit ? 1 : 0,
				'request_succeeded'   => $succeeded ? 1 : 0,
				'error_code'          => sanitize_key( $error_code ),
				'pricing_version'     => self::PRICING_VERSION,
				'request_id'          => sanitize_text_field( $request_id ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns a zero-token metric set for cache hits and local failures.
	 *
	 * @param string $model Configured model identifier.
	 * @return array{
	 *   response_id:string,model:string,service_tier:string,input_tokens:int,
	 *   cached_input_tokens:int,cache_write_tokens:int,output_tokens:int,
	 *   reasoning_tokens:int,total_tokens:int,estimated_cost_usd:float
	 * }
	 */
	private static function empty_metrics( string $model ): array {
		return array(
			'response_id'         => '',
			'model'               => $model,
			'service_tier'        => '',
			'input_tokens'        => 0,
			'cached_input_tokens' => 0,
			'cache_write_tokens'  => 0,
			'output_tokens'       => 0,
			'reasoning_tokens'    => 0,
			'total_tokens'        => 0,
			'estimated_cost_usd'  => 0.0,
		);
	}

	/**
	 * Returns one sanitized scalar field from an API response.
	 *
	 * @param array<string,mixed> $response_body Decoded API response.
	 * @param string              $key           Response field.
	 * @param int                 $length        Maximum stored length.
	 */
	private static function response_text( array $response_body, string $key, int $length ): string {
		$value = is_scalar( $response_body[ $key ] ?? null ) ? sanitize_text_field( (string) $response_body[ $key ] ) : '';
		return substr( $value, 0, $length );
	}

	/**
	 * Converts a numeric value to a nonnegative integer.
	 *
	 * @param mixed $value Candidate numeric value.
	 */
	private static function nonnegative_integer( $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}
}
