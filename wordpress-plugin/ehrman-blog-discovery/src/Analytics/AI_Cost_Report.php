<?php
/**
 * Cost reporting across reader searches and publishing workflows.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Aggregates each usage event once, separately from mutable post metadata. */
final class AI_Cost_Report {
	/**
	 * Reads metadata-generation calls within an Eastern-time date range.
	 *
	 * @param array<string,string> $filters Validated date/title filters.
	 * @return list<array<string,mixed>> Usage rows.
	 */
	public function metadata( array $filters ): array {
		return $this->events( $filters, false );
	}

	/**
	 * Reads separately billed bulk/index embedding calls.
	 *
	 * @param array<string,string> $filters Validated date filters.
	 * @return list<array<string,mixed>> Usage rows.
	 */
	public function index( array $filters ): array {
		return $this->events( $filters, true );
	}

	/**
	 * Summarizes known costs without treating missing usage as free service.
	 *
	 * @param list<array<string,mixed>> $events Usage events.
	 * @return array{total:float,metadata:float,vectors:float,runs:int,calls:int,unknown:int,historical:int,average:float}
	 */
	public function summary( array $events ): array {
		$summary = array(
			'total'      => 0.0,
			'metadata'   => 0.0,
			'vectors'    => 0.0,
			'runs'       => 0,
			'calls'      => count( $events ),
			'unknown'    => 0,
			'historical' => 0,
			'average'    => 0.0,
		);
		$runs    = array();
		foreach ( $events as $event ) {
			$known             = 1 === Database::integer( $event['cost_known'] ?? 0 ) || ( '' === Database::text( $event['purpose'] ?? '' ) && (float) Database::text( $event['estimated_cost_usd'] ?? 0 ) > 0 );
			$cost              = $known ? (float) Database::text( $event['estimated_cost_usd'] ?? 0 ) : 0.0;
			$summary['total'] += $cost;
			$purpose           = Database::text( $event['purpose'] ?? '' );
			$summary[ 'metadata_generation' === $purpose ? 'metadata' : 'vectors' ] += $cost;
			$summary['unknown']    += $known ? 0 : 1;
			$summary['historical'] += Database::integer( $event['history_import'] ?? 0 );
			$run                    = Database::text( $event['run_id'] ?? '' );
			if ( '' !== $run ) {
				$runs[ $run ] = true;
			}
		}
		$summary['runs']    = count( $runs );
		$summary['average'] = $summary['runs'] > 0 ? $summary['total'] / $summary['runs'] : 0.0;
		return $summary;
	}

	/**
	 * Groups attempts and post vectors by the generation run that incurred them.
	 *
	 * @param list<array<string,mixed>> $events Usage events, newest first.
	 * @return list<array<string,mixed>> Generation runs.
	 */
	public function runs( array $events ): array {
		$groups = array();
		foreach ( $events as $event ) {
			$id              = Database::text( $event['run_id'] );
			$groups[ $id ][] = $event;
		}
		$rows = array();
		foreach ( $groups as $id => $calls ) {
			$rows[] = array(
				'run_id'     => $id,
				'post_title' => Database::text( $calls[0]['post_title'] ),
				'post_id'    => Database::integer( $calls[0]['post_id'] ),
				'created_at' => Database::text( $calls[0]['created_at'] ),
				'summary'    => $this->summary( $calls ),
				'calls'      => $calls,
			);
		}
		return $rows;
	}

	/**
	 * Builds the combined table using the same Ask AI report as its detail tab.
	 *
	 * @param array<string,string> $filters Validated date filters.
	 * @return array<string,mixed> Combined totals and separate activity summaries.
	 */
	public function overview( array $filters ): array {
		$filters['search']       = '';
		$filters['zero_results'] = '';
		$builder                 = new AI_Analytics_Report();
		$requests                = AI_Requests::analytics( $filters, 1, 0 )['rows'];
		$ids                     = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ), $requests ), true );
		$search                  = $builder->summary( $requests, $builder->filtered_refinements( AI_Refinements::recent( 0 ), $ids ) );
		$metadata                = $this->summary( $this->metadata( $filters ) );
		$index                   = $this->summary( $this->index( $filters ) );
		return array(
			'search'   => $search,
			'metadata' => $metadata,
			'index'    => $index,
			'total'    => $search['total_cost'] + $metadata['total'] + $index['total'],
		);
	}

	/**
	 * Queries only the requested purpose, with bound dates and escaped title matching.
	 *
	 * @param array<string,string> $filters Validated filters.
	 * @param bool                 $index   Whether to select bulk embeddings.
	 * @return list<array<string,mixed>> Usage events.
	 */
	private function events( array $filters, bool $index ): array {
		$wpdb  = Database::client();
		$table = Database::tables()['ai_usage'];
		$where = $index ? "request_id='' AND (purpose='index_embedding' OR (purpose='' AND model LIKE 'text-embedding-%'))" : "purpose IN ('metadata_generation','post_embedding')";
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$value = $filters[ $key ] ?? '';
			if ( '' !== $value ) {
				$date = new \DateTimeImmutable( $value . ' 00:00:00', new \DateTimeZone( 'America/New_York' ) );
				if ( 'date_to' === $key ) {
					$date = $date->modify( '+1 day' );
				}
				$utc    = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$where .= 'date_from' === $key ? $wpdb->prepare( ' AND created_at >= %s', $utc ) : $wpdb->prepare( ' AND created_at < %s', $utc );
			}
		}
		if ( ! $index && '' !== ( $filters['search'] ?? '' ) ) {
			$where .= $wpdb->prepare( ' AND post_title LIKE %s', '%' . $wpdb->esc_like( $filters['search'] ) . '%' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table and fixed predicate; all filter values are prepared above.
		return Database::associative_rows( $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC,id DESC", ARRAY_A ) );
	}
}
