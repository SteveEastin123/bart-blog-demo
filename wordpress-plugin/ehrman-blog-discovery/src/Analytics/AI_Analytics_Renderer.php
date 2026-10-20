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

/** Renders the protected analytics dashboard. */
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Ask AI Analytics', 'ehrman-blog-discovery' ); ?></h1>
			<?php if ( $was_reset ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Active Ask AI analytics and search caches were reset. Retired-method history, post vectors and index-preparation history were preserved.', 'ehrman-blog-discovery' ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Reports include the active title-and-summary search only. Retired methods and experiments are excluded. Dates use Eastern time; no account, IP address or browser identifier is stored with a request.', 'ehrman-blog-discovery' ); ?></p>
			<?php self::filter_form( $filters ); ?>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0" data-ebd-analytics-metrics>
				<?php self::metric( __( 'Total estimated cost', 'ehrman-blog-discovery' ), self::usd( $summary['total_cost'] ) ); ?>
				<?php self::metric( __( 'Average cost per question', 'ehrman-blog-discovery' ), self::cents( $summary['average_question'] ) ); ?>
				<?php self::metric( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['questions'] ) ); ?>
				<?php self::metric( __( 'Average posts returned', 'ehrman-blog-discovery' ), number_format_i18n( $summary['average_results'], 1 ) ); ?>
				<?php self::metric( __( 'Helpful rate', 'ehrman-blog-discovery' ), self::percentage( $report['helpful_rate'], $report['yes'] + $report['no'] ) ); ?>
				<?php self::metric( __( 'Zero-result questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['zero_results'] ) ); ?>
			</div>
			<p><?php echo esc_html( sprintf( /* translators: 1: embedding cost, 2: review cost. */ __( 'Query embeddings: %1$s; AI review: %2$s. Totals and averages include both stages and respect the active filters, including cached questions.', 'ehrman-blog-discovery' ), self::usd( $summary['initial_cost'], 6 ), self::usd( $summary['refinement_cost'], 6 ) ) ); ?></p>
			<p><?php echo esc_html__( 'Costs are estimates based on API-reported usage and recorded pricing. Confirm billed amounts in the OpenAI usage dashboard. Index preparation is reported separately.', 'ehrman-blog-discovery' ); ?></p>
			<?php self::usage_details( $summary ); ?>
			<h2><?php echo esc_html__( 'Questions', 'ehrman-blog-discovery' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $refinement_export_url ); ?>"><?php echo esc_html__( 'Export AI reviews CSV', 'ehrman-blog-discovery' ); ?></a></p>
			<div style="overflow-x:auto">
			<table class="widefat striped" data-ebd-analytics-questions>
				<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Feedback', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total tokens', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php echo esc_html__( 'No requests match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php self::row( $row ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
			<?php self::pagination( $page, $total_pages, $filters ); ?>
			<details style="margin:24px 0">
				<summary><strong><?php echo esc_html__( 'AI review details', 'ehrman-blog-discovery' ); ?></strong></summary>
				<p><?php echo esc_html__( 'The latest 100 reviews linked to the filtered questions. The CSV export includes all matching reviews.', 'ehrman-blog-discovery' ); ?></p>
				<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Retained posts', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'AI review cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $refinements ) ) : ?>
						<tr><td colspan="8"><?php echo esc_html__( 'No AI reviews match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( array_slice( $refinements, 0, 100 ) as $refinement ) : ?>
							<?php self::refinement_row( $refinement ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				</div>
			</details>
			<?php self::period_table( $periods ); ?>
			<?php self::semantic_index_note( $semantic_index_usage ); ?>
			<?php self::reset_controls( $export_url, $refinement_export_url ); ?>
		</div>
		<?php
	}

	/**
	 * Renders one metric card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Formatted metric value.
	 */
	private static function metric( string $label, string $value ): void {
		?>
		<div style="min-width:140px;padding:14px 18px;border:1px solid #c3c4c7;background:#fff"><strong style="display:block;font-size:22px"><?php echo esc_html( $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
		<?php
	}

	/**
	 * Identifies the one-time semantic index cost excluded from question costs.
	 *
	 * @param array{calls:int,input_tokens:int,total_cost:float} $usage Semantic index usage.
	 */
	private static function semantic_index_note( array $usage ): void {
		?>
		<p><strong><?php echo esc_html__( 'Index preparation:', 'ehrman-blog-discovery' ); ?></strong>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: embedding calls, 2: input tokens, 3: estimated cost. */
				__( '%1$s embedding calls, %2$s input tokens, and %3$s estimated cost. This preparation cost is reported separately and is not included in per-question costs.', 'ehrman-blog-discovery' ),
				number_format_i18n( $usage['calls'] ),
				number_format_i18n( $usage['input_tokens'] ),
				self::usd( $usage['total_cost'], 6 )
			)
		);
		?>
		</p>
		<?php
	}

	/**
	 * Renders cost totals for standard reporting periods.
	 *
	 * @param array<string,array<string,int|float>> $periods Period summaries.
	 */
	private static function period_table( array $periods ): void {
		?>
		<h2><?php echo esc_html__( 'Cost by period', 'ehrman-blog-discovery' ); ?></h2>
		<p><?php echo esc_html__( 'Active-method totals by question submission date, independent of the filters above.', 'ehrman-blog-discovery' ); ?></p>
		<div style="overflow-x:auto;max-width:1050px">
		<table class="widefat striped" style="max-width:1050px">
			<thead><tr><th><?php echo esc_html__( 'Period', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Questions', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'AI reviews', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Uncached stages', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Estimated cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Average per question', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $periods as $label => $period ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $period['questions'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['refinements'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['api_calls'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $period['total_cost'] ) ); ?></td><td><?php echo esc_html( self::cents( (float) $period['average_question'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Renders combined token and cache statistics for the filtered questions.
	 *
	 * @param array<string,int|float> $usage Filtered question and review totals.
	 */
	private static function usage_details( array $usage ): void {
		?>
		<details style="margin:18px 0">
			<summary><strong><?php echo esc_html__( 'Token and cache details', 'ehrman-blog-discovery' ); ?></strong></summary>
			<p><?php self::token_details( $usage ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: cached stages, 2: stage cache-hit percentage. */ __( 'WordPress-cached stages: %1$s (%2$s%% of embedding and review stages). These stages make no new API call. Cached input tokens are provider-side prompt-cache reads, not WordPress cache hits.', 'ehrman-blog-discovery' ), number_format_i18n( (int) $usage['cache_hits'] ), number_format_i18n( $usage['cache_rate'], 1 ) ) ); ?></p>
		</details>
		<?php
	}

	/**
	 * Renders the request filters.
	 *
	 * @param array<string,string> $filters Current filters.
	 */
	private static function filter_form( array $filters ): void {
		$clear_url = add_query_arg(
			array(
				'page' => 'ehrman-ai-analytics',
			),
			admin_url( 'tools.php' )
		);
		?>
		<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:18px 0">
			<input type="hidden" name="page" value="ehrman-ai-analytics">
			<label><?php echo esc_html__( 'Feedback', 'ehrman-blog-discovery' ); ?><br><select name="feedback">
			<?php
			foreach ( array(
				'all'        => 'All',
				'yes'        => 'Yes',
				'no'         => 'No',
				'unanswered' => 'Not provided',
			) as $value => $label ) :
				?>
	<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['feedback'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'From', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
			<label><?php echo esc_html__( 'To', 'ehrman-blog-discovery' ); ?><br><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
			<label><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?><br><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
			<label><input type="checkbox" name="zero_results" value="1" <?php checked( $filters['zero_results'], '1' ); ?>> <?php echo esc_html__( 'Zero results', 'ehrman-blog-discovery' ); ?></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Apply filters', 'ehrman-blog-discovery' ); ?></button>
			<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Renders the administrator-only test-data reset controls.
	 *
	 * @param string $questions_export_url  Filtered question export URL.
	 * @param string $refinements_export_url Filtered refinement export URL.
	 */
	private static function reset_controls( string $questions_export_url, string $refinements_export_url ): void {
		$confirmation = __( 'Permanently clear ALL active Ask AI questions, reviews, feedback and question costs, regardless of the filters above? Retired-method history, post vectors and index-preparation history will be preserved.', 'ehrman-blog-discovery' );
		?>
		<hr style="margin:32px 0 24px">
		<h2><?php echo esc_html__( 'Reset test analytics', 'ehrman-blog-discovery' ); ?></h2>
		<p><?php echo esc_html__( 'Reset affects all active-method analytics, not only the filtered rows. Export records before resetting; exports follow the current filters. Retired-method history, post vectors and discovery data are preserved.', 'ehrman-blog-discovery' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $questions_export_url ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $refinements_export_url ); ?>"><?php echo esc_html__( 'Export AI reviews CSV', 'ehrman-blog-discovery' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( $confirmation ); ?>');">
			<input type="hidden" name="action" value="ehrman_ai_analytics_reset">
			<?php wp_nonce_field( 'ehrman_ai_analytics_reset' ); ?>
			<?php submit_button( __( 'Reset Test Analytics', 'ehrman-blog-discovery' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders one question with total costs and tokens across both stages.
	 *
	 * @param array<string,mixed> $row Enriched question row.
	 */
	private static function row( array $row ): void {
		$usage   = Database::associative_row( $row['question_usage'] ?? null ) ?? array();
		$reviews = Database::associative_rows( $row['reviews'] ?? array() );
		?>
		<tr>
			<td><?php echo esc_html( self::display_datetime( $row ) ); ?></td>
			<td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?>
				<?php if ( 1 !== Database::integer( $row['request_succeeded'] ?? 0 ) ) : ?>
					<br><strong><?php echo esc_html__( 'Failed', 'ehrman-blog-discovery' ); ?></strong>: <?php echo esc_html( Database::text( $row['error_code'] ?? '' ) ); ?>
				<?php endif; ?>
			</td>
			<td><?php self::request_cost_details( $row, (float) Database::text( $usage['refinement_cost'] ?? 0 ) ); ?></td>
			<td><?php echo esc_html( 1 === Database::integer( $row['result_recorded'] ?? 0 ) ? number_format_i18n( Database::integer( $row['result_count'] ?? 0 ) ) : __( 'Not recorded', 'ehrman-blog-discovery' ) ); ?></td>
			<td><?php echo esc_html( self::feedback_label( $row ) ); ?></td>
			<td>
				<details><summary><?php echo esc_html__( 'Embedding and AI review', 'ehrman-blog-discovery' ); ?></summary>
				<p><?php echo esc_html__( 'Embedding:', 'ehrman-blog-discovery' ); ?><br><?php self::source_details( $row ); ?></p>
				<?php foreach ( $reviews as $review ) : ?>
					<p><?php echo esc_html__( 'AI review:', 'ehrman-blog-discovery' ); ?><br><?php self::source_details( $review ); ?>
					<?php if ( 1 !== Database::integer( $review['request_succeeded'] ?? 0 ) ) : ?>
						<br><?php echo esc_html__( 'Failed; broader results returned.', 'ehrman-blog-discovery' ); ?>
					<?php endif; ?>
					</p>
				<?php endforeach; ?>
				<?php
				if ( empty( $reviews ) ) :
					?>
					<p><?php echo esc_html__( 'No AI review recorded.', 'ehrman-blog-discovery' ); ?></p><?php endif; ?>
				</details>
			</td>
			<td><?php self::token_details( $usage ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Renders one post-result refinement event.
	 *
	 * @param array<string,mixed> $row Refinement event row.
	 */
	private static function refinement_row( array $row ): void {
		$status = 1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? __( 'Success', 'ehrman-blog-discovery' ) : __( 'Failed', 'ehrman-blog-discovery' );
		if ( 'Failed' === $status && '' !== Database::text( $row['error_code'] ?? '' ) ) {
			$status .= ': ' . Database::text( $row['error_code'] );
		}
		?>
		<tr><td><?php echo esc_html( self::display_datetime( $row ) ); ?></td><td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['original_count'] ?? 0 ) ) . ' → ' . number_format_i18n( Database::integer( $row['refined_count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( self::retained_post_text( $row ) ); ?></td><td><?php self::source_details( $row ); ?></td><td><?php self::token_details( $row ); ?></td><td><?php echo esc_html( self::cents( (float) Database::text( $row['estimated_cost_usd'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( $status ); ?></td></tr>
		<?php
	}

	/**
	 * Renders total question cost first, followed by its two components.
	 *
	 * @param array<string,mixed> $row             Request row.
	 * @param float               $refinement_cost Cost of linked AI review calls.
	 */
	private static function request_cost_details( array $row, float $refinement_cost ): void {
		$initial_cost = (float) Database::text( $row['estimated_cost_usd'] ?? 0 );
		$total_cost   = $initial_cost + $refinement_cost;
		?>
		<strong><?php echo esc_html( self::cents( $total_cost ) ); ?></strong>
		<br><small><?php echo esc_html( sprintf( /* translators: 1: initial-call cost, 2: AI-review cost. */ __( 'Embedding: %1$s; AI review: %2$s', 'ehrman-blog-discovery' ), self::cents( $initial_cost ), self::cents( $refinement_cost ) ) ); ?></small>
		<?php
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
	 * Renders the API source fields retained for one call.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function source_details( array $row ): void {
		if ( 1 === Database::integer( $row['cache_hit'] ?? 0 ) ) {
			echo esc_html__( 'WordPress cache', 'ehrman-blog-discovery' );
			return;
		}
		$response_id = Database::text( $row['response_id'] ?? '' );
		$pricing     = Database::text( $row['pricing_version'] ?? '' );
		?>
		<strong><?php echo esc_html( self::model_and_tier( $row ) ); ?></strong>
		<?php if ( '' !== $pricing ) : ?>
			<br><small><?php echo esc_html( sprintf( /* translators: %s: pricing version date. */ __( 'Pricing: %s', 'ehrman-blog-discovery' ), $pricing ) ); ?></small>
		<?php endif; ?>
		<?php if ( '' !== $response_id ) : ?>
			<br><code title="<?php echo esc_attr( $response_id ); ?>"><?php echo esc_html( self::short_identifier( $response_id ) ); ?></code>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the API-reported token breakdown for one call.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function token_details( array $row ): void {
		$input     = Database::integer( $row['input_tokens'] ?? 0 );
		$cached    = Database::integer( $row['cached_input_tokens'] ?? 0 );
		$writes    = Database::integer( $row['cache_write_tokens'] ?? 0 );
		$output    = Database::integer( $row['output_tokens'] ?? 0 );
		$reasoning = Database::integer( $row['reasoning_tokens'] ?? 0 );
		?>
		<strong><?php echo esc_html( number_format_i18n( self::total_tokens( $row ) ) ); ?></strong>
		<br><small><?php echo esc_html( sprintf( /* translators: 1: input, 2: cached input, 3: cache writes, 4: output, 5: reasoning tokens. */ __( '%1$s in; %2$s cached; %3$s written; %4$s out; %5$s reasoning', 'ehrman-blog-discovery' ), number_format_i18n( $input ), number_format_i18n( $cached ), number_format_i18n( $writes ), number_format_i18n( $output ), number_format_i18n( $reasoning ) ) ); ?></small>
		<?php
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
	 * Renders pagination links.
	 *
	 * @param int                  $page        Current page.
	 * @param int                  $total_pages Total page count.
	 * @param array<string,string> $filters     Current filters.
	 */
	private static function pagination( int $page, int $total_pages, array $filters ): void {
		if ( $total_pages <= 1 ) {
			return;
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
		if ( '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
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
