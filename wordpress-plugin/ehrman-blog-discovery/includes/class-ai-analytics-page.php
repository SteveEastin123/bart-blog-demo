<?php
/**
 * Ask AI analytics administration page.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders protected request analytics and CSV exports. */
final class AI_Analytics_Page {
	private const PAGE_SIZE = 50;

	/** Registers administrator hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_ehrman_ai_analytics_csv', array( self::class, 'export_csv' ) );
	}

	/** Adds the analytics page under Tools. */
	public static function add_page(): void {
		add_management_page(
			__( 'Ask AI Analytics', 'ehrman-blog-discovery' ),
			__( 'Ask AI Analytics', 'ehrman-blog-discovery' ),
			'manage_options',
			'ehrman-ai-analytics',
			array( self::class, 'render' )
		);
	}

	/** Renders the analytics dashboard and request table. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$filters               = self::filters();
		$page                  = max( 1, absint( self::query_value( 'paged', '1' ) ) );
		$report                = AI_Requests::analytics( $filters, $page, self::PAGE_SIZE );
		$all_report            = AI_Requests::analytics( $filters, 1, 0 );
		$request_ids           = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $all_report['rows'] ), true );
		$refinements           = self::filtered_refinements( AI_Refinements::recent( 5000 ), $request_ids );
		$summary               = self::summary( $all_report['rows'], $refinements );
		$usage                 = AI_Usage::report();
		$periods               = self::periods();
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
			<p><?php echo esc_html__( 'Detailed questions are retained for 90 days. No account, IP address, or browser identifier is stored with a request.', 'ehrman-blog-discovery' ); ?></p>
			<div style="display:flex;gap:12px;flex-wrap:wrap;max-width:1200px;margin:18px 0">
				<?php self::metric( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['questions'] ) ); ?>
				<?php self::metric( __( 'Refinements', 'ehrman-blog-discovery' ), number_format_i18n( $summary['refinements'] ) ); ?>
				<?php self::metric( __( 'Estimated cost', 'ehrman-blog-discovery' ), self::usd( $summary['total_cost'] ) ); ?>
				<?php self::metric( __( 'Average per question', 'ehrman-blog-discovery' ), self::usd( $summary['average_question'], 5 ) ); ?>
				<?php self::metric( __( 'Average interpretation', 'ehrman-blog-discovery' ), self::usd( $summary['average_interpretation'], 5 ) ); ?>
				<?php self::metric( __( 'Average refinement', 'ehrman-blog-discovery' ), self::usd( $summary['average_refinement'], 5 ) ); ?>
				<?php self::metric( __( 'Refinement rate', 'ehrman-blog-discovery' ), number_format_i18n( $summary['refinement_rate'], 1 ) . '%' ); ?>
				<?php self::metric( __( 'Helpful rate', 'ehrman-blog-discovery' ), number_format_i18n( $report['helpful_rate'], 1 ) . '%' ); ?>
			</div>
			<p><?php echo esc_html__( 'Summary cards reflect the active filters. Costs are estimates; confirm billed amounts in the OpenAI usage dashboard.', 'ehrman-blog-discovery' ); ?></p>
			<?php self::period_table( $periods ); ?>
			<?php self::usage_details( $usage ); ?>
			<?php self::filter_form( $filters ); ?>
			<p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $refinement_export_url ); ?>"><?php echo esc_html__( 'Export refinements CSV', 'ehrman-blog-discovery' ); ?></a></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Date', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Topics and keywords', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Feedback', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $report['rows'] ) ) : ?>
					<tr><td colspan="8"><?php echo esc_html__( 'No requests match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<?php self::row( $row ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php self::pagination( $page, $total_pages, $filters ); ?>
			<h2 style="margin-top:32px"><?php echo esc_html__( 'Recent refinement requests', 'ehrman-blog-discovery' ); ?></h2>
			<p><?php echo esc_html__( 'Refinement events are linked to their original question but report their own token usage and estimated cost.', 'ehrman-blog-discovery' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Date', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Retained posts', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $refinements ) ) : ?>
					<tr><td colspan="8"><?php echo esc_html__( 'No refinement requests have been recorded.', 'ehrman-blog-discovery' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( array_slice( $refinements, 0, 100 ) as $refinement ) : ?>
						<?php self::refinement_row( $refinement ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Exports all filtered rows as a protected CSV download. */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export Ask AI analytics.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( 'ehrman_ai_analytics_csv' );
		if ( 'refinements' === self::query_value( 'dataset' ) ) {
			self::export_refinements_csv();
		}
		$report = AI_Requests::analytics( self::filters(), 1, 0 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ask-ai-analytics-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV export could not be created.', 'ehrman-blog-discovery' ) );
		}
		fputcsv( $output, array( 'Date', 'Question', 'Topics and keywords', 'Results', 'Feedback', 'Model', 'Prompt version', 'Cache hit', 'Input tokens', 'Cached input tokens', 'Output tokens', 'Estimated cost USD', 'Status', 'Error code' ) );
		foreach ( $report['rows'] as $row ) {
			fputcsv(
				$output,
				array(
					Database::text( $row['created_at'] ?? '' ),
					Database::text( $row['question'] ?? '' ),
					self::term_text( $row ),
					Database::integer( $row['result_count'] ?? 0 ),
					self::feedback_label( $row ),
					Database::text( $row['model'] ?? '' ),
					Database::text( $row['prompt_version'] ?? '' ),
					Database::integer( $row['cache_hit'] ?? 0 ),
					Database::integer( $row['input_tokens'] ?? 0 ),
					Database::integer( $row['cached_input_tokens'] ?? 0 ),
					Database::integer( $row['output_tokens'] ?? 0 ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? 'Success' : 'Failed',
					Database::text( $row['error_code'] ?? '' ),
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streamed CSV output.
		fclose( $output );
		exit;
	}

	/** Exports filtered refinement rows as a protected CSV download. */
	private static function export_refinements_csv(): void {
		$report      = AI_Requests::analytics( self::filters(), 1, 0 );
		$request_ids = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $report['rows'] ), true );
		$rows        = self::filtered_refinements( AI_Refinements::recent( 5000 ), $request_ids );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ask-ai-refinements-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV export could not be created.', 'ehrman-blog-discovery' ) );
		}
		fputcsv( $output, array( 'Date', 'Parent request ID', 'Refinement ID', 'Question', 'Original results', 'Candidates reviewed', 'Refined results', 'Retained posts', 'Model', 'Prompt version', 'Cache hit', 'Input tokens', 'Cached input tokens', 'Output tokens', 'Estimated cost USD', 'Status', 'Error code' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					Database::text( $row['created_at'] ?? '' ),
					Database::text( $row['request_id'] ?? '' ),
					Database::text( $row['refinement_id'] ?? '' ),
					Database::text( $row['question'] ?? '' ),
					Database::integer( $row['original_count'] ?? 0 ),
					Database::integer( $row['candidate_count'] ?? 0 ),
					Database::integer( $row['refined_count'] ?? 0 ),
					self::retained_post_text( $row ),
					Database::text( $row['model'] ?? '' ),
					Database::text( $row['prompt_version'] ?? '' ),
					Database::integer( $row['cache_hit'] ?? 0 ),
					Database::integer( $row['input_tokens'] ?? 0 ),
					Database::integer( $row['cached_input_tokens'] ?? 0 ),
					Database::integer( $row['output_tokens'] ?? 0 ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? 'Success' : 'Failed',
					Database::text( $row['error_code'] ?? '' ),
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streamed CSV output.
		fclose( $output );
		exit;
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
	 * Summarizes question and refinement rows.
	 *
	 * @param list<array<string,mixed>> $requests    Question interpretation rows.
	 * @param list<array<string,mixed>> $refinements Refinement rows.
	 * @return array<string,int|float> Summary values.
	 */
	private static function summary( array $requests, array $refinements ): array {
		$interpretation_cost = self::row_cost( $requests );
		$refinement_cost     = self::row_cost( $refinements );
		$total_cost          = $interpretation_cost + $refinement_cost;
		$events              = array_merge( $requests, $refinements );
		$cache_hits          = count( array_filter( $events, static fn( array $row ): bool => 1 === Database::integer( $row['cache_hit'] ?? 0 ) ) );
		$api_calls           = max( 0, count( $events ) - $cache_hits );
		$questions           = count( $requests );
		$refinement_count    = count( $refinements );
		$refined_questions   = array_filter( array_unique( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $refinements ) ) );

		return array(
			'questions'              => $questions,
			'refinements'            => $refinement_count,
			'api_calls'              => $api_calls,
			'cache_hits'             => $cache_hits,
			'input_tokens'           => self::row_integer_sum( $events, 'input_tokens' ),
			'cached_input_tokens'    => self::row_integer_sum( $events, 'cached_input_tokens' ),
			'output_tokens'          => self::row_integer_sum( $events, 'output_tokens' ),
			'total_cost'             => $total_cost,
			'average_question'       => $questions > 0 ? $total_cost / $questions : 0.0,
			'average_interpretation' => $questions > 0 ? $interpretation_cost / $questions : 0.0,
			'average_refinement'     => $refinement_count > 0 ? $refinement_cost / $refinement_count : 0.0,
			'average_api_call'       => $api_calls > 0 ? $total_cost / $api_calls : 0.0,
			'cache_rate'             => count( $events ) > 0 ? ( $cache_hits / count( $events ) ) * 100 : 0.0,
			'refinement_rate'        => $questions > 0 ? ( count( $refined_questions ) / $questions ) * 100 : 0.0,
		);
	}

	/**
	 * Returns cost summaries for today, this month, and all retained data.
	 *
	 * @return array<string,array<string,int|float>> Cost summaries by period.
	 */
	private static function periods(): array {
		$filters     = array(
			'feedback'     => 'all',
			'date_from'    => '',
			'date_to'      => '',
			'search'       => '',
			'zero_results' => '',
		);
		$requests    = AI_Requests::analytics( $filters, 1, 0 )['rows'];
		$refinements = AI_Refinements::recent( 5000 );
		$periods     = array(
			__( 'Today', 'ehrman-blog-discovery' )        => gmdate( 'Y-m-d 00:00:00' ),
			__( 'This month', 'ehrman-blog-discovery' )   => gmdate( 'Y-m-01 00:00:00' ),
			__( 'All retained', 'ehrman-blog-discovery' ) => '',
		);
		$rows        = array();
		foreach ( $periods as $label => $start ) {
			$request_period = array_values( array_filter( $requests, static fn( array $row ): bool => '' === $start || Database::text( $row['created_at'] ?? '' ) >= $start ) );
			$refine_period  = array_values( array_filter( $refinements, static fn( array $row ): bool => '' === $start || Database::text( $row['created_at'] ?? '' ) >= $start ) );
			$rows[ $label ] = self::summary( $request_period, $refine_period );
		}
		return $rows;
	}

	/**
	 * Renders cost totals for standard reporting periods.
	 *
	 * @param array<string,array<string,int|float>> $periods Period summaries.
	 */
	private static function period_table( array $periods ): void {
		?>
		<h2><?php echo esc_html__( 'Cost overview', 'ehrman-blog-discovery' ); ?></h2>
		<table class="widefat striped" style="max-width:1050px">
			<thead><tr><th><?php echo esc_html__( 'Period', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Questions', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Refinements', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'OpenAI calls', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Estimated cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Average per question', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $periods as $label => $period ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $period['questions'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['refinements'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['api_calls'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $period['total_cost'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $period['average_question'], 5 ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders token and model details from all retained usage events.
	 *
	 * @param array<string,mixed> $usage Aggregate usage report.
	 */
	private static function usage_details( array $usage ): void {
		$submissions = Database::integer( $usage['submissions'] ?? 0 );
		$cache_hits  = Database::integer( $usage['cache_hits'] ?? 0 );
		$cache_rate  = $submissions > 0 ? ( $cache_hits / $submissions ) * 100 : 0.0;
		?>
		<details style="margin:18px 0;max-width:1050px">
			<summary><strong><?php echo esc_html__( 'Token and model details', 'ehrman-blog-discovery' ); ?></strong></summary>
			<p><?php echo esc_html( sprintf( /* translators: 1: input tokens, 2: cached input tokens, 3: output tokens. */ __( '%1$s input tokens (%2$s cached) and %3$s output tokens across retained usage.', 'ehrman-blog-discovery' ), number_format_i18n( Database::integer( $usage['input_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['cached_input_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['output_tokens'] ?? 0 ) ) ) ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: WordPress response-cache hits, 2: cache-hit percentage, 3: average paid OpenAI call cost. */ __( 'WordPress response-cache hits: %1$s (%2$s%%). Average paid OpenAI call: %3$s.', 'ehrman-blog-discovery' ), number_format_i18n( $cache_hits ), number_format_i18n( $cache_rate, 1 ), self::usd( (float) Database::text( $usage['average_cost'] ?? 0 ), 5 ) ) ); ?></p>
			<?php if ( ! empty( $usage['models'] ) ) : ?>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Model', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'OpenAI calls', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Input tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Output tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Estimated cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $usage['models'] as $raw_row ) : ?>
					<?php $row = Database::associative_row( $raw_row ) ?? array(); ?>
					<tr><td><?php echo esc_html( Database::text( $row['model'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['api_requests'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['input_tokens'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['output_tokens'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( self::usd( (float) Database::text( $row['total_cost'] ?? 0 ) ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Renders the request filters.
	 *
	 * @param array<string,string> $filters Current filters.
	 */
	private static function filter_form( array $filters ): void {
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
			<label><?php echo esc_html__( 'Question or term', 'ehrman-blog-discovery' ); ?><br><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
			<label><input type="checkbox" name="zero_results" value="1" <?php checked( $filters['zero_results'], '1' ); ?>> <?php echo esc_html__( 'Zero results', 'ehrman-blog-discovery' ); ?></label>
			<button class="button button-primary" type="submit"><?php echo esc_html__( 'Apply filters', 'ehrman-blog-discovery' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=ehrman-ai-analytics' ) ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Renders one request row.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function row( array $row ): void {
		$tokens = Database::integer( $row['input_tokens'] ?? 0 ) + Database::integer( $row['output_tokens'] ?? 0 );
		?>
		<tr><td><?php echo esc_html( Database::text( $row['created_at'] ?? '' ) ); ?></td><td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?></td><td><?php echo esc_html( self::term_text( $row ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['result_count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( self::feedback_label( $row ) ); ?></td><td><?php echo esc_html( 1 === Database::integer( $row['cache_hit'] ?? 0 ) ? __( 'Cache', 'ehrman-blog-discovery' ) : Database::text( $row['model'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( $tokens ) ); ?></td><td><?php echo esc_html( '$' . number_format( (float) Database::text( $row['estimated_cost_usd'] ?? 0 ), 5 ) ); ?></td></tr>
		<?php
	}

	/**
	 * Renders one post-result refinement event.
	 *
	 * @param array<string,mixed> $row Refinement event row.
	 */
	private static function refinement_row( array $row ): void {
		$tokens = Database::integer( $row['input_tokens'] ?? 0 ) + Database::integer( $row['output_tokens'] ?? 0 );
		$source = 1 === Database::integer( $row['cache_hit'] ?? 0 ) ? __( 'Cache', 'ehrman-blog-discovery' ) : Database::text( $row['model'] ?? '' );
		$status = 1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? __( 'Success', 'ehrman-blog-discovery' ) : __( 'Failed', 'ehrman-blog-discovery' );
		if ( 'Failed' === $status && '' !== Database::text( $row['error_code'] ?? '' ) ) {
			$status .= ': ' . Database::text( $row['error_code'] );
		}
		?>
		<tr><td><?php echo esc_html( Database::text( $row['created_at'] ?? '' ) ); ?></td><td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['original_count'] ?? 0 ) ) . ' → ' . number_format_i18n( Database::integer( $row['refined_count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( self::retained_post_text( $row ) ); ?></td><td><?php echo esc_html( $source ); ?></td><td><?php echo esc_html( number_format_i18n( $tokens ) ); ?></td><td><?php echo esc_html( self::usd( (float) Database::text( $row['estimated_cost_usd'] ?? 0 ), 5 ) ); ?></td><td><?php echo esc_html( $status ); ?></td></tr>
		<?php
	}

	/**
	 * Keeps refinements linked to the currently filtered question rows.
	 *
	 * @param list<array<string,mixed>> $rows        Refinement rows.
	 * @param array<string,bool>        $request_ids Allowed parent request identifiers.
	 * @return list<array<string,mixed>> Filtered refinement rows.
	 */
	private static function filtered_refinements( array $rows, array $request_ids ): array {
		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => isset( $request_ids[ Database::text( $row['request_id'] ?? '' ) ] )
			)
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
	 * Returns the estimated-cost sum for analytics rows.
	 *
	 * @param list<array<string,mixed>> $rows Analytics rows.
	 */
	private static function row_cost( array $rows ): float {
		return array_reduce( $rows, static fn( float $sum, array $row ): float => $sum + (float) Database::text( $row['estimated_cost_usd'] ?? 0 ), 0.0 );
	}

	/**
	 * Returns an integer-field sum for analytics rows.
	 *
	 * @param list<array<string,mixed>> $rows  Analytics rows.
	 * @param string                    $field Integer field to total.
	 */
	private static function row_integer_sum( array $rows, string $field ): int {
		return array_reduce( $rows, static fn( int $sum, array $row ): int => $sum + Database::integer( $row[ $field ] ?? 0 ), 0 );
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
	 * Returns sanitized filters from the current request.
	 *
	 * @return array{feedback:string,date_from:string,date_to:string,search:string,zero_results:string} Sanitized filters.
	 */
	private static function filters(): array {
		$feedback = sanitize_key( self::query_value( 'feedback', 'all' ) );
		return array(
			'feedback'     => in_array( $feedback, array( 'all', 'yes', 'no', 'unanswered' ), true ) ? $feedback : 'all',
			'date_from'    => self::date( self::query_value( 'date_from' ) ),
			'date_to'      => self::date( self::query_value( 'date_to' ) ),
			'search'       => sanitize_text_field( self::query_value( 'search' ) ),
			'zero_results' => '1' === self::query_value( 'zero_results' ) ? '1' : '',
		);
	}

	/**
	 * Returns a sanitized scalar query value.
	 *
	 * @param string $key      Query key.
	 * @param string $fallback Default value.
	 */
	private static function query_value( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only administrator filter sanitized below.
		$value = $_GET[ $key ] ?? $fallback;
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : $fallback;
	}

	/**
	 * Returns a valid ISO date or an empty value.
	 *
	 * @param string $value Candidate date.
	 */
	private static function date( string $value ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Returns readable interpreted terms with their types.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function term_text( array $row ): string {
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
	 * Returns the three-state feedback label.
	 *
	 * @param array<string,mixed> $row Request row.
	 */
	private static function feedback_label( array $row ): string {
		$value = Database::text( $row['feedback'] ?? null );
		return '1' === $value ? 'Yes' : ( '0' === $value ? 'No' : 'Not provided' );
	}
}
