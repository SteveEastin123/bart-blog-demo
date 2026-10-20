<?php
/**
 * Ask AI analytics aggregation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds cost and usage summaries for the active Ask AI method. */
final class AI_Analytics_Report {
	private const DISPLAY_TIMEZONE = 'America/New_York';

	/**
	 * Summarizes question and refinement rows.
	 *
	 * @param list<array<string,mixed>> $requests    Question rows.
	 * @param list<array<string,mixed>> $refinements Refinement rows.
	 * @return array<string,int|float> Summary values.
	 */
	public function summary( array $requests, array $refinements ): array {
		$initial_cost      = $this->row_cost( $requests );
		$refinement_cost   = $this->row_cost( $refinements );
		$total_cost        = $initial_cost + $refinement_cost;
		$events            = array_merge( $requests, $refinements );
		$cache_hits        = count( array_filter( $events, static fn( array $row ): bool => 1 === Database::integer( $row['cache_hit'] ?? 0 ) ) );
		$api_calls         = max( 0, count( $events ) - $cache_hits );
		$questions         = count( $requests );
		$refinement_count  = count( $refinements );
		$refined_questions = array_filter( array_unique( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $refinements ) ) );
		$result_total      = $this->row_integer_sum( $requests, 'result_count' );
		$zero_results      = count(
			array_filter(
				$requests,
				static fn( array $row ): bool => 1 === Database::integer( $row['result_recorded'] ?? 0 )
					&& 0 === Database::integer( $row['result_count'] ?? 0 )
			)
		);

		return array(
			'questions'              => $questions,
			'refinements'            => $refinement_count,
			'api_calls'              => $api_calls,
			'cache_hits'             => $cache_hits,
			'input_tokens'           => $this->row_integer_sum( $events, 'input_tokens' ),
			'cached_input_tokens'    => $this->row_integer_sum( $events, 'cached_input_tokens' ),
			'cache_write_tokens'     => $this->row_integer_sum( $events, 'cache_write_tokens' ),
			'output_tokens'          => $this->row_integer_sum( $events, 'output_tokens' ),
			'reasoning_tokens'       => $this->row_integer_sum( $events, 'reasoning_tokens' ),
			'total_tokens'           => array_reduce( $events, fn( int $sum, array $row ): int => $sum + $this->total_tokens( $row ), 0 ),
			'initial_cost'           => $initial_cost,
			'refinement_cost'        => $refinement_cost,
			'total_cost'             => $total_cost,
			'average_question'       => $questions > 0 ? $total_cost / $questions : 0.0,
			'average_interpretation' => $questions > 0 ? $initial_cost / $questions : 0.0,
			'average_refinement'     => $refinement_count > 0 ? $refinement_cost / $refinement_count : 0.0,
			'average_api_call'       => $api_calls > 0 ? $total_cost / $api_calls : 0.0,
			'average_calls'          => $questions > 0 ? $api_calls / $questions : 0.0,
			'average_results'        => $questions > 0 ? $result_total / $questions : 0.0,
			'zero_results'           => $zero_results,
			'cache_rate'             => count( $events ) > 0 ? ( $cache_hits / count( $events ) ) * 100 : 0.0,
			'refinement_rate'        => $questions > 0 ? ( count( $refined_questions ) / $questions ) * 100 : 0.0,
		);
	}

	/**
	 * Returns cost summaries for today, this month, and all retained data.
	 *
	 * @return array<string,array<string,int|float>> Cost summaries by period.
	 */
	public function periods(): array {
		$filters     = array(
			'feedback'     => 'all',
			'date_from'    => '',
			'date_to'      => '',
			'search'       => '',
			'zero_results' => '',
		);
		$requests    = AI_Requests::analytics( $filters, 1, 0 )['rows'];
		$refinements = AI_Refinements::recent( 0 );
		$now         = new \DateTimeImmutable( 'now', new \DateTimeZone( self::DISPLAY_TIMEZONE ) );
		$periods     = array(
			__( 'Today', 'ehrman-blog-discovery' )        => $this->utc_datetime( $now->setTime( 0, 0 ) ),
			__( 'This month', 'ehrman-blog-discovery' )   => $this->utc_datetime( $now->modify( 'first day of this month' )->setTime( 0, 0 ) ),
			__( 'All retained', 'ehrman-blog-discovery' ) => '',
		);
		$rows        = array();
		foreach ( $periods as $label => $start ) {
			$request_period = array_values( array_filter( $requests, static fn( array $row ): bool => '' === $start || Database::text( $row['created_at'] ?? '' ) >= $start ) );
			$request_ids    = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $request_period ), true );
			$refine_period  = $this->filtered_refinements( $refinements, $request_ids );
			$rows[ $label ] = $this->summary( $request_period, $refine_period );
		}
		return $rows;
	}

	/**
	 * Keeps refinements linked to the filtered question rows.
	 *
	 * @param list<array<string,mixed>> $rows        Refinement rows.
	 * @param array<string,bool>        $request_ids Allowed parent request identifiers.
	 * @return list<array<string,mixed>> Filtered refinement rows.
	 */
	public function filtered_refinements( array $rows, array $request_ids ): array {
		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => isset( $request_ids[ Database::text( $row['request_id'] ?? '' ) ] )
			)
		);
	}

	/**
	 * Attaches complete question totals and linked review details for UI and CSV.
	 *
	 * @param list<array<string,mixed>> $requests    Filtered question rows.
	 * @param list<array<string,mixed>> $refinements Linked review rows.
	 * @return list<array<string,mixed>> Enriched question rows.
	 */
	public function question_rows( array $requests, array $refinements ): array {
		$reviews_by_request = array();
		foreach ( $refinements as $review ) {
			$reviews_by_request[ Database::text( $review['request_id'] ?? '' ) ][] = $review;
		}
		foreach ( $requests as &$row ) {
			$reviews               = $reviews_by_request[ Database::text( $row['request_id'] ?? '' ) ] ?? array();
			$row['reviews']        = $reviews;
			$row['question_usage'] = $this->summary( array( $row ), $reviews );
			$row['review_usage']   = $this->summary( array(), $reviews );
		}
		unset( $row );
		return $requests;
	}

	/**
	 * Returns the estimated-cost sum for analytics rows.
	 *
	 * @param list<array<string,mixed>> $rows Analytics rows.
	 */
	private function row_cost( array $rows ): float {
		return array_reduce( $rows, static fn( float $sum, array $row ): float => $sum + (float) Database::text( $row['estimated_cost_usd'] ?? 0 ), 0.0 );
	}

	/**
	 * Returns an integer-field sum for analytics rows.
	 *
	 * @param list<array<string,mixed>> $rows  Analytics rows.
	 * @param string                    $field Integer field to total.
	 */
	private function row_integer_sum( array $rows, string $field ): int {
		return array_reduce( $rows, static fn( int $sum, array $row ): int => $sum + Database::integer( $row[ $field ] ?? 0 ), 0 );
	}

	/**
	 * Returns the reported token total with a legacy fallback.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private function total_tokens( array $row ): int {
		$total = Database::integer( $row['total_tokens'] ?? 0 );
		return 0 < $total ? $total : Database::integer( $row['input_tokens'] ?? 0 ) + Database::integer( $row['output_tokens'] ?? 0 );
	}

	/**
	 * Converts an Eastern timestamp to stored UTC format.
	 *
	 * @param \DateTimeImmutable $value Eastern timestamp.
	 */
	private function utc_datetime( \DateTimeImmutable $value ): string {
		return $value->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
