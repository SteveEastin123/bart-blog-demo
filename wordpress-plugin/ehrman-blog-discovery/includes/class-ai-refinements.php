<?php
/**
 * Ask AI refinement analytics storage.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores post-result refinement events separately from question interpretation. */
final class AI_Refinements {
	private const RETENTION_DAYS = 90;

	/**
	 * Stores one refinement event.
	 *
	 * @param array<string,mixed>       $data  Refinement metadata.
	 * @param list<array<string,mixed>> $posts Posts retained by refinement.
	 */
	public static function record( array $data, array $posts ): void {
		self::delete_expired();
		$usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		$kept  = array();
		foreach ( $posts as $post ) {
			$id    = Database::text( $post['id'] ?? null );
			$title = sanitize_text_field( Database::text( $post['title'] ?? null ) );
			if ( '' !== $id && '' !== $title ) {
				$kept[] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
		}
		Database::client()->insert(
			Database::tables()['ai_refinements'],
			array(
				'refinement_id'       => sanitize_text_field( Database::text( $data['refinement_id'] ?? null ) ),
				'request_id'          => sanitize_text_field( Database::text( $data['request_id'] ?? null ) ),
				'created_at'          => current_time( 'mysql', true ),
				'question'            => sanitize_text_field( Database::text( $data['question'] ?? null ) ),
				'original_count'      => max( 0, Database::integer( $data['original_count'] ?? 0 ) ),
				'candidate_count'     => max( 0, Database::integer( $data['candidate_count'] ?? 0 ) ),
				'refined_count'       => count( $kept ),
				'selected_posts'      => (string) wp_json_encode( $kept ),
				'model'               => sanitize_text_field( Database::text( $usage['model'] ?? AI_Interpreter::model_id() ) ),
				'prompt_version'      => AI_Interpreter::refine_prompt_version(),
				'cache_hit'           => ! empty( $data['cache_hit'] ) ? 1 : 0,
				'request_succeeded'   => ! empty( $data['succeeded'] ) ? 1 : 0,
				'error_code'          => sanitize_key( Database::text( $data['error_code'] ?? null ) ),
				'input_tokens'        => max( 0, Database::integer( $usage['input_tokens'] ?? 0 ) ),
				'cached_input_tokens' => max( 0, Database::integer( $usage['cached_input_tokens'] ?? 0 ) ),
				'output_tokens'       => max( 0, Database::integer( $usage['output_tokens'] ?? 0 ) ),
				'estimated_cost_usd'  => number_format( (float) Database::text( $usage['estimated_cost_usd'] ?? 0 ), 8, '.', '' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Returns recent refinement events with their recorded usage.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @return list<array<string,mixed>> Recent refinement events.
	 */
	public static function recent( int $limit = 100 ): array {
		self::delete_expired();
		$tables = Database::tables();
		$usage  = "SELECT request_id,SUM(input_tokens) input_tokens,SUM(cached_input_tokens) cached_input_tokens,SUM(output_tokens) output_tokens,SUM(estimated_cost_usd) estimated_cost_usd FROM {$tables['ai_usage']} WHERE request_id<>'' GROUP BY request_id";
		$sql    = Database::client()->prepare(
			"SELECT r.*,COALESCE(u.input_tokens,r.input_tokens) input_tokens,COALESCE(u.cached_input_tokens,r.cached_input_tokens) cached_input_tokens,COALESCE(u.output_tokens,r.output_tokens) output_tokens,COALESCE(u.estimated_cost_usd,r.estimated_cost_usd) estimated_cost_usd FROM {$tables['ai_refinements']} r LEFT JOIN ({$usage}) u ON u.request_id=r.refinement_id ORDER BY r.id DESC LIMIT %d",
			max( 1, min( 5000, $limit ) )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally and limit is prepared.
		return Database::associative_rows( Database::client()->get_results( $sql, ARRAY_A ) );
	}

	/** Returns the retained refinement count. */
	public static function count(): int {
		self::delete_expired();
		$table = Database::tables()['ai_refinements'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		return Database::integer( Database::client()->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	/** Deletes detailed refinement events after the retention period. */
	private static function delete_expired(): void {
		$table  = Database::tables()['ai_refinements'];
		$cutoff = esc_sql( gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier and escaped date are generated internally.
		Database::client()->query( "DELETE FROM {$table} WHERE created_at < '{$cutoff}'" );
	}
}
