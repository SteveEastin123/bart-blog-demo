<?php
/**
 * Ask AI analytics dashboard rendering.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prepares display values for the protected analytics dashboard templates. */
final class AI_Analytics_Renderer {
	private const PAGE_SIZE        = 50;
	private const DISPLAY_TIMEZONE = 'America/New_York';

	/**
	 * Renders the analytics dashboard and request table.
	 *
	 * @param array<string,string> $filters   Sanitized administrator filters.
	 * @param int                  $page      Current results page.
	 * @param bool                 $was_reset Whether analytics were just reset.
	 */
	public function render( array $filters, int $page, bool $was_reset ): void {
		$report_builder        = new AI_Analytics_Report();
		$report                = AI_Requests::analytics( $filters, $page, self::PAGE_SIZE );
		$all_report            = AI_Requests::analytics( $filters, 1, 0 );
		$request_ids           = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $all_report['rows'] ), true );
		$refinements           = $report_builder->filtered_refinements( AI_Refinements::recent( 0 ), $request_ids );
		$rows                  = $report_builder->question_rows( $report['rows'], $refinements );
		$summary               = $report_builder->summary( $all_report['rows'], $refinements );
		$semantic_index_usage  = AI_Usage::semantic_index_report();
		$periods               = $report_builder->periods();
		$total_pages           = max( 1, (int) ceil( $report['total'] / self::PAGE_SIZE ) );
		$export_url            = wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => 'ehrman_ai_analytics_csv' ), $filters ), admin_url( 'admin-post.php' ) ),
			'ehrman_ai_analytics_csv'
		);
		$refinement_export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'action'  => 'ehrman_ai_analytics_csv',
						'dataset' => 'refinements',
					),
					$filters
				),
				admin_url( 'admin-post.php' )
			),
			'ehrman_ai_analytics_csv'
		);
		$html                  = Template::render(
			'admin/analytics/dashboard.php',
			array(
				'was_reset'             => $was_reset,
				'filters'               => $filters,
				'clear_url'             => add_query_arg( array( 'page' => 'ehrman-ai-analytics' ), admin_url( 'tools.php' ) ),
				'export_url'            => $export_url,
				'refinement_export_url' => $refinement_export_url,
				'metrics'               => array(
					array( __( 'Total estimated cost', 'ehrman-blog-discovery' ), self::usd( $summary['total_cost'] ) ),
					array( __( 'Average cost per question', 'ehrman-blog-discovery' ), self::cents( $summary['average_question'] ) ),
					array( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['questions'] ) ),
					array( __( 'Average posts returned', 'ehrman-blog-discovery' ), number_format_i18n( $summary['average_results'], 1 ) ),
					array( __( 'Helpful rate', 'ehrman-blog-discovery' ), self::percentage( $report['helpful_rate'], $report['yes'] + $report['no'] ) ),
					array( __( 'Zero-result questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['zero_results'] ) ),
				),
				'cost_details'          => sprintf(
					/* translators: 1: embedding cost, 2: review cost. */
					__( 'Query embeddings: %1$s; AI review: %2$s. Totals and averages include both stages and respect the active filters, including cached questions.', 'ehrman-blog-discovery' ),
					self::usd( $summary['initial_cost'], 6 ),
					self::usd( $summary['refinement_cost'], 6 )
				),
				'tokens'                => self::token_details( $summary ),
				'cache_details'         => sprintf(
					/* translators: 1: cached stages, 2: stage cache-hit percentage. */
					__( 'WordPress-cached stages: %1$s (%2$s%% of embedding and review stages). These stages make no new API call. Cached input tokens are provider-side prompt-cache reads, not WordPress cache hits.', 'ehrman-blog-discovery' ),
					number_format_i18n( (int) $summary['cache_hits'] ),
					number_format_i18n( $summary['cache_rate'], 1 )
				),
				'questions'             => array_map( self::row( ... ), $rows ),
				'reviews'               => array_map( self::refinement_row( ... ), array_slice( $refinements, 0, 100 ) ),
				'periods'               => self::period_rows( $periods ),
				'pagination'            => self::pagination( $page, $total_pages, $filters ),
				'index_note'            => sprintf(
					/* translators: 1: embedding calls, 2: input tokens, 3: estimated cost. */
					__( '%1$s embedding calls, %2$s input tokens, and %3$s estimated cost. This preparation cost is reported separately and is not included in per-question costs.', 'ehrman-blog-discovery' ),
					number_format_i18n( $semantic_index_usage['calls'] ),
					number_format_i18n( $semantic_index_usage['input_tokens'] ),
					self::usd( $semantic_index_usage['total_cost'], 6 )
				),
				'reset_confirmation'    => __( 'Permanently clear ALL active Ask AI questions, reviews, feedback and question costs, regardless of the filters above? Retired-method history, post vectors and index-preparation history will be preserved.', 'ehrman-blog-discovery' ),
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The trusted templates escape values at their output boundaries.
		echo $html;
	}

	/**
	 * Prepares cost totals for standard reporting periods.
	 *
	 * @param array<string,array<string,int|float>> $periods Period summaries.
	 * @return list<array<string,string>> Formatted period rows.
	 */
	private static function period_rows( array $periods ): array {
		$rows = array();
		foreach ( $periods as $label => $period ) {
			$rows[] = array(
				'label'           => $label,
				'questions'       => number_format_i18n( (int) $period['questions'] ),
				'reviews'         => number_format_i18n( (int) $period['refinements'] ),
				'uncached_stages' => number_format_i18n( (int) $period['uncached_stages'] ),
				'total_cost'      => self::usd( (float) $period['total_cost'] ),
				'average_cost'    => self::cents( (float) $period['average_question'] ),
			);
		}
		return $rows;
	}

	/**
	 * Prepares one question with total costs and tokens across both stages.
	 *
	 * @param array<string,mixed> $row Enriched question row.
	 * @return array<string,mixed> Question display values, escaped by the template.
	 */
	private static function row( array $row ): array {
		$usage           = Database::associative_row( $row['question_usage'] ?? null ) ?? array();
		$reviews         = Database::associative_rows( $row['reviews'] ?? array() );
		$initial_cost    = (float) Database::text( $row['estimated_cost_usd'] ?? 0 );
		$refinement_cost = (float) Database::text( $usage['refinement_cost'] ?? 0 );
		return array(
			'date'         => self::display_datetime( $row ),
			'question'     => Database::text( $row['question'] ?? '' ),
			'failed'       => 1 !== Database::integer( $row['request_succeeded'] ?? 0 ),
			'error'        => Database::text( $row['error_code'] ?? '' ),
			'total_cost'   => self::cents( $initial_cost + $refinement_cost ),
			'cost_details' => sprintf(
				/* translators: 1: initial-call cost, 2: AI-review cost. */
				__( 'Embedding: %1$s; AI review: %2$s', 'ehrman-blog-discovery' ),
				self::cents( $initial_cost ),
				self::cents( $refinement_cost )
			),
			'results'      => 1 === Database::integer( $row['result_recorded'] ?? 0 ) ? number_format_i18n( Database::integer( $row['result_count'] ?? 0 ) ) : __( 'Not recorded', 'ehrman-blog-discovery' ),
			'feedback'     => self::feedback_label( $row ),
			'source'       => self::source_details( $row ),
			'reviews'      => array_map( self::source_details( ... ), $reviews ),
			'tokens'       => self::token_details( $usage ),
		);
	}

	/**
	 * Prepares one post-result refinement event.
	 *
	 * @param array<string,mixed> $row Refinement event row.
	 * @return array<string,mixed> Review display values, escaped by the template.
	 */
	private static function refinement_row( array $row ): array {
		$status = 1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? __( 'Success', 'ehrman-blog-discovery' ) : __( 'Failed', 'ehrman-blog-discovery' );
		if ( 'Failed' === $status && '' !== Database::text( $row['error_code'] ?? '' ) ) {
			$status .= ': ' . Database::text( $row['error_code'] );
		}
		return array(
			'date'     => self::display_datetime( $row ),
			'question' => Database::text( $row['question'] ?? '' ),
			'results'  => number_format_i18n( Database::integer( $row['original_count'] ?? 0 ) ) . ' → ' . number_format_i18n( Database::integer( $row['refined_count'] ?? 0 ) ),
			'posts'    => self::retained_post_text( $row ),
			'source'   => self::source_details( $row ),
			'tokens'   => self::token_details( $row ),
			'cost'     => self::cents( (float) Database::text( $row['estimated_cost_usd'] ?? 0 ) ),
			'status'   => $status,
		);
	}

	/**
	 * Returns retained post titles as a readable list.
	 *
	 * @param array<string,mixed> $row Refinement row.
	 */
	private static function retained_post_text( array $row ): string {
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
	 * Returns a stored UTC timestamp formatted for the dashboard in Eastern time.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function display_datetime( array $row ): string {
		return self::format_datetime( Database::text( $row['created_at'] ?? '' ), 'M j, Y g:i A T' );
	}

	/**
	 * Converts a stored UTC timestamp to Eastern time.
	 *
	 * @param string $value  Stored UTC timestamp.
	 * @param string $format PHP date format.
	 */
	private static function format_datetime( string $value, string $format ): string {
		if ( '' === $value ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			return $date->setTimezone( self::display_timezone() )->format( $format );
		} catch ( \Exception $exception ) {
			return $value;
		}
	}

	/** Returns the named timezone used for analytics display and reporting. */
	private static function display_timezone(): \DateTimeZone {
		return new \DateTimeZone( self::DISPLAY_TIMEZONE );
	}

	/**
	 * Prepares the API source fields retained for one call.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 * @return array{cached:bool,model:string,pricing:string,response_id:string,short_id:string,failed:bool} Source display values.
	 */
	private static function source_details( array $row ): array {
		$response_id = Database::text( $row['response_id'] ?? '' );
		return array(
			'cached'      => 1 === Database::integer( $row['cache_hit'] ?? 0 ),
			'model'       => self::model_and_tier( $row ),
			'pricing'     => Database::text( $row['pricing_version'] ?? '' ),
			'response_id' => $response_id,
			'short_id'    => self::short_identifier( $response_id ),
			'failed'      => 1 !== Database::integer( $row['request_succeeded'] ?? 0 ),
		);
	}

	/**
	 * Prepares the API-reported token breakdown for one call.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 * @return array{total:string,details:string} Token display values.
	 */
	private static function token_details( array $row ): array {
		return array(
			'total'   => number_format_i18n( self::total_tokens( $row ) ),
			'details' => sprintf(
				/* translators: 1: input, 2: cached input, 3: cache writes, 4: output, 5: reasoning tokens. */
				__( '%1$s in; %2$s cached; %3$s written; %4$s out; %5$s reasoning', 'ehrman-blog-discovery' ),
				number_format_i18n( Database::integer( $row['input_tokens'] ?? 0 ) ),
				number_format_i18n( Database::integer( $row['cached_input_tokens'] ?? 0 ) ),
				number_format_i18n( Database::integer( $row['cache_write_tokens'] ?? 0 ) ),
				number_format_i18n( Database::integer( $row['output_tokens'] ?? 0 ) ),
				number_format_i18n( Database::integer( $row['reasoning_tokens'] ?? 0 ) )
			),
		);
	}

	/**
	 * Returns the API-reported total token count with a legacy-row fallback.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function total_tokens( array $row ): int {
		$total = Database::integer( $row['total_tokens'] ?? 0 );
		return 0 < $total ? $total : Database::integer( $row['input_tokens'] ?? 0 ) + Database::integer( $row['output_tokens'] ?? 0 );
	}

	/**
	 * Returns a readable model and service-tier label.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function model_and_tier( array $row ): string {
		$model = self::source_model( $row );
		$tier  = Database::text( $row['service_tier'] ?? '' );
		return '' !== $tier ? $model . ' (' . $tier . ')' : $model;
	}

	/**
	 * Shortens an opaque API identifier while preserving both ends.
	 *
	 * @param string $identifier API response identifier.
	 */
	private static function short_identifier( string $identifier ): string {
		return strlen( $identifier ) > 24 ? substr( $identifier, 0, 14 ) . '…' . substr( $identifier, -7 ) : $identifier;
	}

	/**
	 * Formats an estimated US-dollar value.
	 *
	 * @param float $value    Cost value.
	 * @param int   $decimals Decimal places.
	 */
	private static function usd( float $value, int $decimals = 4 ): string {
		return '$' . number_format( $value, $decimals );
	}

	/**
	 * Formats a per-request dollar value as cents.
	 *
	 * @param float $value    Cost value in dollars.
	 * @param int   $decimals Decimal places in cents.
	 */
	private static function cents( float $value, int $decimals = 3 ): string {
		return number_format( $value * 100, $decimals ) . '¢';
	}

	/**
	 * Formats a percentage or an unavailable marker when no responses exist.
	 *
	 * @param float $value Percentage value.
	 * @param int   $count Number of observations behind the percentage.
	 */
	private static function percentage( float $value, int $count ): string {
		return 0 < $count ? number_format_i18n( $value, 1 ) . '%' : '—';
	}

	/**
	 * Builds pagination links for the template.
	 *
	 * @param int                  $page        Current page.
	 * @param int                  $total_pages Total page count.
	 * @param array<string,string> $filters     Current filters.
	 */
	private static function pagination( int $page, int $total_pages, array $filters ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg(
					array_merge(
						array(
							'page'  => 'ehrman-ai-analytics',
							'paged' => '%#%',
						),
						$filters
					),
					admin_url( 'tools.php' )
				),
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'ehrman-blog-discovery' ),
				'next_text' => __( 'Next', 'ehrman-blog-discovery' ),
			)
		);
		return $links;
	}

	/**
	 * Returns the model recorded by usage tracking, with the request model as fallback.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function source_model( array $row ): string {
		$model = Database::text( $row['usage_model'] ?? '' );
		return '' !== $model ? $model : Database::text( $row['model'] ?? '' );
	}

	/**
	 * Returns the three-state feedback label.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function feedback_label( array $row ): string {
		$value = Database::text( $row['feedback'] ?? null );
		return '1' === $value ? 'Yes' : ( '0' === $value ? 'No' : 'Not provided' );
	}
}
