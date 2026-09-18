<?php
/**
 * Ask AI analytics CSV exports.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Streams protected question and refinement analytics exports. */
final class AI_Analytics_Exporter {
	private const DISPLAY_TIMEZONE = 'America/New_York';

	/**
	 * Streams the selected analytics dataset.
	 *
	 * @param array<string,string> $filters Sanitized analytics filters.
	 * @param string               $dataset Dataset identifier.
	 */
	public function export( array $filters, string $dataset ): void {
		if ( 'refinements' === $dataset ) {
			$this->export_refinements( $filters );
			return;
		}

		$report = AI_Requests::analytics( $filters, 1, 0 );
		$output = $this->open_output( 'ask-ai-analytics-' . gmdate( 'Y-m-d' ) . '.csv' );
		fputcsv( $output, array( 'Date (Eastern)', 'Method', 'Question', 'Topics and keywords', 'Results', 'Feedback', 'Response ID', 'Model', 'Service tier', 'Prompt version', 'WordPress cache hit', 'Input tokens', 'Cached input tokens', 'Cache write tokens', 'Output tokens', 'Reasoning tokens', 'Total tokens', 'Estimated cost USD', 'Pricing version', 'Status', 'Error code' ) );
		foreach ( $report['rows'] as $row ) {
			fputcsv(
				$output,
				array(
					$this->csv_datetime( $row ),
					$this->request_type_label( $row ),
					Database::text( $row['question'] ?? '' ),
					$this->term_text( $row ),
					Database::integer( $row['result_count'] ?? 0 ),
					$this->feedback_label( $row ),
					Database::text( $row['response_id'] ?? '' ),
					$this->source_model( $row ),
					Database::text( $row['service_tier'] ?? '' ),
					Database::text( $row['prompt_version'] ?? '' ),
					Database::integer( $row['cache_hit'] ?? 0 ),
					Database::integer( $row['input_tokens'] ?? 0 ),
					Database::integer( $row['cached_input_tokens'] ?? 0 ),
					Database::integer( $row['cache_write_tokens'] ?? 0 ),
					Database::integer( $row['output_tokens'] ?? 0 ),
					Database::integer( $row['reasoning_tokens'] ?? 0 ),
					$this->total_tokens( $row ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					Database::text( $row['pricing_version'] ?? '' ),
					1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? 'Success' : 'Failed',
					Database::text( $row['error_code'] ?? '' ),
				)
			);
		}
		$this->close_output( $output );
	}

	/**
	 * Streams refinement rows linked to the filtered questions.
	 *
	 * @param array<string,string> $filters Sanitized analytics filters.
	 */
	private function export_refinements( array $filters ): void {
		$report      = AI_Requests::analytics( $filters, 1, 0 );
		$request_ids = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $report['rows'] ), true );
		$rows        = array_values(
			array_filter(
				AI_Refinements::recent( 5000 ),
				static fn( array $row ): bool => isset( $request_ids[ Database::text( $row['request_id'] ?? '' ) ] )
			)
		);
		$output      = $this->open_output( 'ask-ai-refinements-' . gmdate( 'Y-m-d' ) . '.csv' );
		fputcsv( $output, array( 'Date (Eastern)', 'Parent request ID', 'Refinement ID', 'Question', 'Original results', 'Candidates reviewed', 'Refined results', 'Retained posts', 'Response ID', 'Model', 'Service tier', 'Prompt version', 'WordPress cache hit', 'Input tokens', 'Cached input tokens', 'Cache write tokens', 'Output tokens', 'Reasoning tokens', 'Total tokens', 'Estimated cost USD', 'Pricing version', 'Status', 'Error code' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					$this->csv_datetime( $row ),
					Database::text( $row['request_id'] ?? '' ),
					Database::text( $row['refinement_id'] ?? '' ),
					Database::text( $row['question'] ?? '' ),
					Database::integer( $row['original_count'] ?? 0 ),
					Database::integer( $row['candidate_count'] ?? 0 ),
					Database::integer( $row['refined_count'] ?? 0 ),
					$this->retained_post_text( $row ),
					Database::text( $row['response_id'] ?? '' ),
					Database::text( $row['model'] ?? '' ),
					Database::text( $row['service_tier'] ?? '' ),
					Database::text( $row['prompt_version'] ?? '' ),
					Database::integer( $row['cache_hit'] ?? 0 ),
					Database::integer( $row['input_tokens'] ?? 0 ),
					Database::integer( $row['cached_input_tokens'] ?? 0 ),
					Database::integer( $row['cache_write_tokens'] ?? 0 ),
					Database::integer( $row['output_tokens'] ?? 0 ),
					Database::integer( $row['reasoning_tokens'] ?? 0 ),
					$this->total_tokens( $row ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					Database::text( $row['pricing_version'] ?? '' ),
					1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? 'Success' : 'Failed',
					Database::text( $row['error_code'] ?? '' ),
				)
			);
		}
		$this->close_output( $output );
	}

	/**
	 * Opens the protected response stream.
	 *
	 * @param string $filename Download filename.
	 * @return resource Writable output stream.
	 */
	private function open_output( string $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV export could not be created.', 'ehrman-blog-discovery' ) );
		}
		return $output;
	}

	/**
	 * Closes the output stream and completes the request.
	 *
	 * @param resource $output Writable output stream.
	 */
	private function close_output( $output ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streamed CSV output.
		fclose( $output );
		exit;
	}

	/**
	 * Formats a stored UTC timestamp for the CSV export.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private function csv_datetime( array $row ): string {
		$value = Database::text( $row['created_at'] ?? '' );
		if ( '' === $value ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			return $date->setTimezone( new \DateTimeZone( self::DISPLAY_TIMEZONE ) )->format( 'Y-m-d H:i:s P T' );
		} catch ( \Exception $exception ) {
			return $value;
		}
	}

	/**
	 * Returns retained post titles as a readable list.
	 *
	 * @param array<string,mixed> $row Refinement row.
	 */
	private function retained_post_text( array $row ): string {
		$posts  = json_decode( Database::text( $row['selected_posts'] ?? '' ), true );
		$titles = array();
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				if ( is_array( $post ) && is_scalar( $post['title'] ?? null ) ) {
					$titles[] = sanitize_text_field( (string) $post['title'] );
				}
			}
		}
		return implode( ' | ', $titles );
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
	 * Returns readable interpreted terms with their types.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private function term_text( array $row ): string {
		if ( 'semantic' === Database::text( $row['request_type'] ?? '' ) ) {
			return __( 'Not used', 'ehrman-blog-discovery' );
		}
		$decoded = json_decode( Database::text( $row['selected_terms'] ?? '' ), true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$labels = array();
		foreach ( $decoded as $term ) {
			if ( is_array( $term ) && is_scalar( $term['label'] ?? null ) ) {
				$labels[] = ucfirst( 'topic' === ( $term['mode'] ?? '' ) ? 'topic' : 'keyword' ) . ': ' . sanitize_text_field( (string) $term['label'] );
			}
		}
		return implode( ' | ', $labels );
	}

	/**
	 * Returns a readable search-pipeline name.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private function request_type_label( array $row ): string {
		return 'semantic' === Database::text( $row['request_type'] ?? '' ) ? __( 'Ask AI 2', 'ehrman-blog-discovery' ) : __( 'Ask AI 1', 'ehrman-blog-discovery' );
	}

	/**
	 * Returns the recorded model with the request model as fallback.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private function source_model( array $row ): string {
		$model = Database::text( $row['usage_model'] ?? '' );
		return '' !== $model ? $model : Database::text( $row['model'] ?? '' );
	}

	/**
	 * Returns the three-state feedback label.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private function feedback_label( array $row ): string {
		$value = Database::text( $row['feedback'] ?? null );
		return '1' === $value ? 'Yes' : ( '0' === $value ? 'No' : 'Not provided' );
	}
}
