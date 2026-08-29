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
	private const PAGE_SIZE        = 50;
	private const DISPLAY_TIMEZONE = 'America/New_York';

	/** Registers administrator hooks. */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_ehrman_ai_analytics_csv', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_ehrman_ai_analytics_reset', array( self::class, 'reset_test_analytics' ) );
	}

	/** Adds the analytics page under Tools. */
	public static function add_page(): void {
		add_management_page(
			__( 'AI Search Analytics', 'ehrman-blog-discovery' ),
			__( 'AI Search Analytics', 'ehrman-blog-discovery' ),
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
		$refinement_costs      = self::refinement_costs_by_request( $refinements );
		$summary               = self::summary( $all_report['rows'], $refinements );
		$usage                 = AI_Usage::report();
		$semantic_index_usage  = AI_Usage::semantic_index_report();
		$periods               = self::periods( $filters['interface'] );
		$comparison            = self::comparison( $filters );
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
			<h1><?php echo esc_html__( 'AI Search Analytics', 'ehrman-blog-discovery' ); ?></h1>
			<?php if ( '1' === self::query_value( 'analytics_reset' ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Test analytics and AI search caches were reset. The semantic post index and its preparation history were preserved.', 'ehrman-blog-discovery' ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Detailed questions are retained for 90 days. Dates are displayed in Eastern time; timestamps remain stored in UTC. No account, IP address, or browser identifier is stored with a request.', 'ehrman-blog-discovery' ); ?></p>
			<?php self::view_tabs( $filters ); ?>
			<?php if ( 'comparison' === $filters['view'] ) : ?>
				<?php self::comparison_table( $comparison ); ?>
				<?php self::semantic_index_note( $semantic_index_usage ); ?>
				<?php self::comparison_period_table( self::comparison_periods() ); ?>
				<?php self::filter_form( $filters ); ?>
				<p><?php echo esc_html__( 'The comparison respects the active date, question, feedback, and zero-result filters. Initial cost covers topic interpretation for Ask AI 1 and semantic retrieval for Ask AI 2; refinement cost covers title-and-summary evaluation.', 'ehrman-blog-discovery' ); ?></p>
			<?php else : ?>
			<div style="display:flex;gap:12px;flex-wrap:wrap;max-width:1200px;margin:18px 0">
				<?php self::metric( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $summary['questions'] ) ); ?>
				<?php self::metric( __( 'Refinements', 'ehrman-blog-discovery' ), number_format_i18n( $summary['refinements'] ) ); ?>
				<?php self::metric( __( 'Estimated cost', 'ehrman-blog-discovery' ), self::usd( $summary['total_cost'] ) ); ?>
				<?php self::metric( __( 'Average per question', 'ehrman-blog-discovery' ), self::cents( $summary['average_question'] ) ); ?>
				<?php self::metric( __( 'Average initial step', 'ehrman-blog-discovery' ), self::cents( $summary['average_interpretation'] ) ); ?>
				<?php self::metric( __( 'Average refinement', 'ehrman-blog-discovery' ), self::cents( $summary['average_refinement'] ) ); ?>
				<?php self::metric( __( 'Refinement rate', 'ehrman-blog-discovery' ), number_format_i18n( $summary['refinement_rate'], 1 ) . '%' ); ?>
				<?php self::metric( __( 'Helpful rate', 'ehrman-blog-discovery' ), number_format_i18n( $report['helpful_rate'], 1 ) . '%' ); ?>
			</div>
			<p><?php echo esc_html__( 'Summary cards reflect the active filters. OpenAI reports the token, cache, model, and service-tier details; this plugin converts those values to estimated dollars using the pricing version shown. Confirm billed amounts in the OpenAI usage dashboard.', 'ehrman-blog-discovery' ); ?></p>
				<?php self::period_table( $periods ); ?>
				<?php if ( 'combined' === $filters['view'] ) : ?>
					<?php self::usage_details( $usage ); ?>
			<?php endif; ?>
				<?php self::filter_form( $filters ); ?>
			<p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $refinement_export_url ); ?>"><?php echo esc_html__( 'Export refinements CSV', 'ehrman-blog-discovery' ); ?></a></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Method', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Topics and keywords', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Feedback', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $report['rows'] ) ) : ?>
					<tr><td colspan="9"><?php echo esc_html__( 'No requests match these filters.', 'ehrman-blog-discovery' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<?php self::row( $row, $refinement_costs[ Database::text( $row['request_id'] ?? '' ) ] ?? 0.0 ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
				<?php self::pagination( $page, $total_pages, $filters ); ?>
			<h2 style="margin-top:32px"><?php echo esc_html__( 'Recent refinement requests', 'ehrman-blog-discovery' ); ?></h2>
			<p><?php echo esc_html__( 'Refinement events are linked to their original question but report their own token usage and estimated cost.', 'ehrman-blog-discovery' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Date (ET)', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Question', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Results', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Retained posts', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Source', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Refinement cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Status', 'ehrman-blog-discovery' ); ?></th></tr></thead>
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
			<?php endif; ?>
			<?php self::reset_controls( $export_url, $refinement_export_url, $filters['view'] ); ?>
		</div>
		<?php
	}

	/**
	 * Clears test questions and costs while preserving the reusable semantic index.
	 *
	 * @throws \RuntimeException When a database transaction operation fails.
	 */
	public static function reset_test_analytics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to reset AI search analytics.', 'ehrman-blog-discovery' ) );
		}
		check_admin_referer( 'ehrman_ai_analytics_reset' );

		$view = self::posted_view();
		$wpdb = Database::client();
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'The analytics reset transaction could not be started.' );
			}
			self::delete_test_analytics();
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'The analytics reset transaction could not be committed.' );
			}
		} catch ( \Throwable ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The test analytics could not be reset. No analytics records were intentionally removed.', 'ehrman-blog-discovery' ) );
		}

		AI_Usage::invalidate_search_caches();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'ehrman-ai-analytics',
					'view'            => $view,
					'analytics_reset' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/** Exports all filtered rows as a protected CSV download. */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export AI search analytics.', 'ehrman-blog-discovery' ) );
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
		fputcsv( $output, array( 'Date (Eastern)', 'Method', 'Question', 'Topics and keywords', 'Results', 'Feedback', 'Response ID', 'Model', 'Service tier', 'Prompt version', 'WordPress cache hit', 'Input tokens', 'Cached input tokens', 'Cache write tokens', 'Output tokens', 'Reasoning tokens', 'Total tokens', 'Estimated cost USD', 'Pricing version', 'Status', 'Error code' ) );
		foreach ( $report['rows'] as $row ) {
			fputcsv(
				$output,
				array(
					self::csv_datetime( $row ),
					self::request_type_label( $row ),
					Database::text( $row['question'] ?? '' ),
					self::term_text( $row ),
					Database::integer( $row['result_count'] ?? 0 ),
					self::feedback_label( $row ),
					Database::text( $row['response_id'] ?? '' ),
					self::source_model( $row ),
					Database::text( $row['service_tier'] ?? '' ),
					Database::text( $row['prompt_version'] ?? '' ),
					Database::integer( $row['cache_hit'] ?? 0 ),
					Database::integer( $row['input_tokens'] ?? 0 ),
					Database::integer( $row['cached_input_tokens'] ?? 0 ),
					Database::integer( $row['cache_write_tokens'] ?? 0 ),
					Database::integer( $row['output_tokens'] ?? 0 ),
					Database::integer( $row['reasoning_tokens'] ?? 0 ),
					self::total_tokens( $row ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					Database::text( $row['pricing_version'] ?? '' ),
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
		fputcsv( $output, array( 'Date (Eastern)', 'Parent request ID', 'Refinement ID', 'Question', 'Original results', 'Candidates reviewed', 'Refined results', 'Retained posts', 'Response ID', 'Model', 'Service tier', 'Prompt version', 'WordPress cache hit', 'Input tokens', 'Cached input tokens', 'Cache write tokens', 'Output tokens', 'Reasoning tokens', 'Total tokens', 'Estimated cost USD', 'Pricing version', 'Status', 'Error code' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					self::csv_datetime( $row ),
					Database::text( $row['request_id'] ?? '' ),
					Database::text( $row['refinement_id'] ?? '' ),
					Database::text( $row['question'] ?? '' ),
					Database::integer( $row['original_count'] ?? 0 ),
					Database::integer( $row['candidate_count'] ?? 0 ),
					Database::integer( $row['refined_count'] ?? 0 ),
					self::retained_post_text( $row ),
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
					self::total_tokens( $row ),
					Database::text( $row['estimated_cost_usd'] ?? 0 ),
					Database::text( $row['pricing_version'] ?? '' ),
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
		$initial_cost      = self::row_cost( $requests );
		$refinement_cost   = self::row_cost( $refinements );
		$total_cost        = $initial_cost + $refinement_cost;
		$events            = array_merge( $requests, $refinements );
		$cache_hits        = count( array_filter( $events, static fn( array $row ): bool => 1 === Database::integer( $row['cache_hit'] ?? 0 ) ) );
		$api_calls         = max( 0, count( $events ) - $cache_hits );
		$questions         = count( $requests );
		$refinement_count  = count( $refinements );
		$refined_questions = array_filter( array_unique( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $refinements ) ) );
		$result_total      = self::row_integer_sum( $requests, 'result_count' );
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
			'input_tokens'           => self::row_integer_sum( $events, 'input_tokens' ),
			'cached_input_tokens'    => self::row_integer_sum( $events, 'cached_input_tokens' ),
			'cache_write_tokens'     => self::row_integer_sum( $events, 'cache_write_tokens' ),
			'output_tokens'          => self::row_integer_sum( $events, 'output_tokens' ),
			'reasoning_tokens'       => self::row_integer_sum( $events, 'reasoning_tokens' ),
			'total_tokens'           => array_reduce( $events, static fn( int $sum, array $row ): int => $sum + self::total_tokens( $row ), 0 ),
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
	 * @param string $request_interface Request source to include.
	 * @return array<string,array<string,int|float>> Cost summaries by period.
	 */
	private static function periods( string $request_interface = 'all' ): array {
		$filters     = array(
			'interface'    => $request_interface,
			'feedback'     => 'all',
			'date_from'    => '',
			'date_to'      => '',
			'search'       => '',
			'zero_results' => '',
		);
		$requests    = AI_Requests::analytics( $filters, 1, 0 )['rows'];
		$refinements = AI_Refinements::recent( 5000 );
		$now         = new \DateTimeImmutable( 'now', self::display_timezone() );
		$periods     = array(
			__( 'Today', 'ehrman-blog-discovery' )        => self::utc_datetime( $now->setTime( 0, 0 ) ),
			__( 'This month', 'ehrman-blog-discovery' )   => self::utc_datetime( $now->modify( 'first day of this month' )->setTime( 0, 0 ) ),
			__( 'All retained', 'ehrman-blog-discovery' ) => '',
		);
		$rows        = array();
		foreach ( $periods as $label => $start ) {
			$request_period = array_values( array_filter( $requests, static fn( array $row ): bool => '' === $start || Database::text( $row['created_at'] ?? '' ) >= $start ) );
			$request_ids    = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $request_period ), true );
			$refine_period  = self::filtered_refinements( $refinements, $request_ids );
			$refine_period  = array_values( array_filter( $refine_period, static fn( array $row ): bool => '' === $start || Database::text( $row['created_at'] ?? '' ) >= $start ) );
			$rows[ $label ] = self::summary( $request_period, $refine_period );
		}
		return $rows;
	}

	/**
	 * Returns comparable Ask AI and Ask AI 2 datasets for the active filters.
	 *
	 * @param array<string,string> $filters Active administrator filters.
	 * @return array<string,array<string,mixed>> Datasets keyed by interface.
	 */
	private static function comparison( array $filters ): array {
		$datasets = array();
		foreach ( array( 'taxonomy', 'semantic' ) as $interface ) {
			$interface_filters              = $filters;
			$interface_filters['interface'] = $interface;
			$report                         = AI_Requests::analytics( $interface_filters, 1, 0 );
			$request_ids                    = array_fill_keys( array_map( static fn( array $row ): string => Database::text( $row['request_id'] ?? '' ), $report['rows'] ), true );
			$refinements                    = self::filtered_refinements( AI_Refinements::recent( 5000 ), $request_ids );
			$summary                        = self::summary( $report['rows'], $refinements );
			$summary['yes']                 = $report['yes'];
			$summary['no']                  = $report['no'];
			$summary['unanswered']          = $report['unanswered'];
			$summary['response_rate']       = $report['response_rate'];
			$summary['helpful_rate']        = $report['helpful_rate'];
			$datasets[ $interface ]         = $summary;
		}
		return $datasets;
	}

	/**
	 * Returns standard-period comparisons for both Ask AI interfaces.
	 *
	 * @return array<string,array<string,array<string,int|float>>> Period rows by interface.
	 */
	private static function comparison_periods(): array {
		$taxonomy = self::periods( 'taxonomy' );
		$semantic = self::periods( 'semantic' );
		$rows     = array();
		foreach ( $taxonomy as $label => $summary ) {
			$rows[ $label ] = array(
				'taxonomy' => $summary,
				'semantic' => $semantic[ $label ] ?? self::summary( array(), array() ),
			);
		}
		return $rows;
	}

	/**
	 * Renders the Combined, Ask AI, Ask AI 2, and Comparison tabs.
	 *
	 * @param array<string,string> $filters Active administrator filters.
	 */
	private static function view_tabs( array $filters ): void {
		$tabs = array(
			'combined'   => __( 'Combined', 'ehrman-blog-discovery' ),
			'ask-ai'     => __( 'Ask AI 1', 'ehrman-blog-discovery' ),
			'ask-ai-2'   => __( 'Ask AI 2', 'ehrman-blog-discovery' ),
			'comparison' => __( 'Comparison', 'ehrman-blog-discovery' ),
		);
		$args = array_filter(
			array(
				'feedback'     => $filters['feedback'],
				'date_from'    => $filters['date_from'],
				'date_to'      => $filters['date_to'],
				'search'       => $filters['search'],
				'zero_results' => $filters['zero_results'],
			),
			static fn( string $value ): bool => '' !== $value && 'all' !== $value
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Analytics view', 'ehrman-blog-discovery' ); ?>">
			<?php foreach ( $tabs as $view => $label ) : ?>
				<?php
				$url = add_query_arg(
					array_merge(
						array(
							'page' => 'ehrman-ai-analytics',
							'view' => $view,
						),
						$args
					),
					admin_url( 'tools.php' )
				);
				?>
				<a class="nav-tab <?php echo esc_attr( $filters['view'] === $view ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders the side-by-side interface comparison.
	 *
	 * @param array<string,array<string,mixed>> $comparison Interface summary values.
	 */
	private static function comparison_table( array $comparison ): void {
		$taxonomy = $comparison['taxonomy'];
		$semantic = $comparison['semantic'];
		$rows     = array(
			__( 'Questions', 'ehrman-blog-discovery' )    => array( number_format_i18n( (int) $taxonomy['questions'] ), number_format_i18n( (int) $semantic['questions'] ) ),
			__( 'OpenAI calls per question', 'ehrman-blog-discovery' ) => array( number_format_i18n( (float) $taxonomy['average_calls'], 2 ), number_format_i18n( (float) $semantic['average_calls'], 2 ) ),
			__( 'Total estimated cost', 'ehrman-blog-discovery' ) => array( self::usd( (float) $taxonomy['total_cost'] ), self::usd( (float) $semantic['total_cost'] ) ),
			__( 'Average cost per question', 'ehrman-blog-discovery' ) => array( self::cents( (float) $taxonomy['average_question'] ), self::cents( (float) $semantic['average_question'] ) ),
			__( 'Initial-stage cost', 'ehrman-blog-discovery' ) => array( self::usd( (float) $taxonomy['initial_cost'], 6 ), self::usd( (float) $semantic['initial_cost'], 6 ) ),
			__( 'Refinement cost', 'ehrman-blog-discovery' ) => array( self::usd( (float) $taxonomy['refinement_cost'] ), self::usd( (float) $semantic['refinement_cost'] ) ),
			__( 'Average posts returned', 'ehrman-blog-discovery' ) => array( number_format_i18n( (float) $taxonomy['average_results'], 1 ), number_format_i18n( (float) $semantic['average_results'], 1 ) ),
			__( 'Zero-result questions', 'ehrman-blog-discovery' ) => array( number_format_i18n( (int) $taxonomy['zero_results'] ), number_format_i18n( (int) $semantic['zero_results'] ) ),
			__( 'Helpful / Not helpful', 'ehrman-blog-discovery' ) => array( number_format_i18n( (int) $taxonomy['yes'] ) . ' / ' . number_format_i18n( (int) $taxonomy['no'] ), number_format_i18n( (int) $semantic['yes'] ) . ' / ' . number_format_i18n( (int) $semantic['no'] ) ),
			__( 'Feedback not provided', 'ehrman-blog-discovery' ) => array( number_format_i18n( (int) $taxonomy['unanswered'] ), number_format_i18n( (int) $semantic['unanswered'] ) ),
			__( 'Feedback response rate', 'ehrman-blog-discovery' ) => array( self::percentage( (float) $taxonomy['response_rate'], (int) $taxonomy['yes'] + (int) $taxonomy['no'] ), self::percentage( (float) $semantic['response_rate'], (int) $semantic['yes'] + (int) $semantic['no'] ) ),
			__( 'Helpful rate', 'ehrman-blog-discovery' ) => array( self::percentage( (float) $taxonomy['helpful_rate'], (int) $taxonomy['yes'] + (int) $taxonomy['no'] ), self::percentage( (float) $semantic['helpful_rate'], (int) $semantic['yes'] + (int) $semantic['no'] ) ),
		);
		?>
		<h2><?php echo esc_html__( 'Side-by-side comparison', 'ehrman-blog-discovery' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php echo esc_html__( 'Metric', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 1', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 2', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $label => $values ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $values[0] ); ?></td><td><?php echo esc_html( $values[1] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Identifies the one-time semantic index cost excluded from question costs.
	 *
	 * @param array{calls:int,input_tokens:int,total_cost:float} $usage Semantic index usage.
	 */
	private static function semantic_index_note( array $usage ): void {
		?>
		<p><strong><?php echo esc_html__( 'Ask AI 2 index preparation:', 'ehrman-blog-discovery' ); ?></strong>
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
	 * Renders cost comparisons for standard reporting periods.
	 *
	 * @param array<string,array<string,array<string,int|float>>> $periods Period rows by interface.
	 */
	private static function comparison_period_table( array $periods ): void {
		?>
		<h2><?php echo esc_html__( 'Cost by period', 'ehrman-blog-discovery' ); ?></h2>
		<table class="widefat striped" style="max-width:1050px">
			<thead><tr><th><?php echo esc_html__( 'Period', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 1 questions', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 1 cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 1 average', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 2 questions', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 2 cost', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Ask AI 2 average', 'ehrman-blog-discovery' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $periods as $label => $interfaces ) : ?>
				<?php $taxonomy = $interfaces['taxonomy']; ?>
				<?php $semantic = $interfaces['semantic']; ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $taxonomy['questions'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $taxonomy['total_cost'] ) ); ?></td><td><?php echo esc_html( self::cents( (float) $taxonomy['average_question'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $semantic['questions'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $semantic['total_cost'] ) ); ?></td><td><?php echo esc_html( self::cents( (float) $semantic['average_question'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( number_format_i18n( (int) $period['questions'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['refinements'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $period['api_calls'] ) ); ?></td><td><?php echo esc_html( self::usd( (float) $period['total_cost'] ) ); ?></td><td><?php echo esc_html( self::cents( (float) $period['average_question'] ) ); ?></td></tr>
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
			<p><?php echo esc_html( sprintf( /* translators: 1: input tokens, 2: cached input tokens, 3: cache-write tokens, 4: output tokens, 5: reasoning tokens, 6: total tokens. */ __( '%1$s input tokens (%2$s cache reads and %3$s cache writes), %4$s output tokens (%5$s reasoning), and %6$s total tokens across retained usage.', 'ehrman-blog-discovery' ), number_format_i18n( Database::integer( $usage['input_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['cached_input_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['cache_write_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['output_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['reasoning_tokens'] ?? 0 ) ), number_format_i18n( Database::integer( $usage['total_tokens'] ?? 0 ) ) ) ); ?></p>
			<p><?php echo esc_html( sprintf( /* translators: 1: WordPress response-cache hits, 2: cache-hit percentage, 3: average paid OpenAI call cost. */ __( 'WordPress response-cache hits: %1$s (%2$s%%). Average paid OpenAI call: %3$s.', 'ehrman-blog-discovery' ), number_format_i18n( $cache_hits ), number_format_i18n( $cache_rate, 1 ), self::cents( (float) Database::text( $usage['average_cost'] ?? 0 ) ) ) ); ?></p>
			<?php if ( ! empty( $usage['models'] ) ) : ?>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Model and tier', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Pricing version', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'OpenAI calls', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Input tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Cache reads / writes', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Output / reasoning', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Total tokens', 'ehrman-blog-discovery' ); ?></th><th><?php echo esc_html__( 'Estimated cost', 'ehrman-blog-discovery' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $usage['models'] as $raw_row ) : ?>
					<?php $row = Database::associative_row( $raw_row ) ?? array(); ?>
					<tr><td><?php echo esc_html( self::model_and_tier( $row ) ); ?></td><td><?php echo esc_html( Database::text( $row['pricing_version'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['api_requests'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['input_tokens'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['cached_input_tokens'] ?? 0 ) ) . ' / ' . number_format_i18n( Database::integer( $row['cache_write_tokens'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['output_tokens'] ?? 0 ) ) . ' / ' . number_format_i18n( Database::integer( $row['reasoning_tokens'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( self::total_tokens( $row ) ) ); ?></td><td><?php echo esc_html( self::usd( (float) Database::text( $row['total_cost'] ?? 0 ) ) ); ?></td></tr>
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
		$clear_url = add_query_arg(
			array(
				'page' => 'ehrman-ai-analytics',
				'view' => $filters['view'],
			),
			admin_url( 'tools.php' )
		);
		?>
		<form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:18px 0">
			<input type="hidden" name="page" value="ehrman-ai-analytics">
			<input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>">
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
			<a class="button" href="<?php echo esc_url( $clear_url ); ?>"><?php echo esc_html__( 'Clear', 'ehrman-blog-discovery' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Renders the administrator-only test-data reset controls.
	 *
	 * @param string $questions_export_url  Filtered question export URL.
	 * @param string $refinements_export_url Filtered refinement export URL.
	 * @param string $view                  Active analytics view.
	 */
	private static function reset_controls( string $questions_export_url, string $refinements_export_url, string $view ): void {
		$confirmation = __( 'Permanently clear recorded test questions, refinements, feedback, question costs, and AI search caches? The semantic post index and its preparation history will be preserved.', 'ehrman-blog-discovery' );
		?>
		<hr style="margin:32px 0 24px">
		<h2><?php echo esc_html__( 'Reset test analytics', 'ehrman-blog-discovery' ); ?></h2>
		<p><?php echo esc_html__( 'Export any records you want to retain before resetting. This action does not remove post embeddings or discovery search data.', 'ehrman-blog-discovery' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $questions_export_url ); ?>"><?php echo esc_html__( 'Export questions CSV', 'ehrman-blog-discovery' ); ?></a> <a class="button" href="<?php echo esc_url( $refinements_export_url ); ?>"><?php echo esc_html__( 'Export refinements CSV', 'ehrman-blog-discovery' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( $confirmation ); ?>');">
			<input type="hidden" name="action" value="ehrman_ai_analytics_reset">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
			<?php wp_nonce_field( 'ehrman_ai_analytics_reset' ); ?>
			<?php submit_button( __( 'Reset Test Analytics', 'ehrman-blog-discovery' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Deletes test analytics while preserving semantic-index preparation usage.
	 *
	 * @throws \RuntimeException When an analytics table cannot be cleared.
	 */
	private static function delete_test_analytics(): void {
		$tables = Database::tables();
		self::delete_all_rows( $tables['ai_feedback'] );
		self::delete_all_rows( $tables['ai_refinements'] );
		self::delete_all_rows( $tables['ai_requests'] );

		$sql = "DELETE FROM {$tables['ai_usage']} WHERE request_id<>''";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally and no external values are used.
		if ( false === Database::client()->query( $sql ) ) {
			throw new \RuntimeException( 'Question usage records could not be deleted.' );
		}
	}

	/**
	 * Deletes every row from one internally named analytics table.
	 *
	 * @param string $table Fully qualified table name.
	 * @throws \RuntimeException When the analytics table cannot be cleared.
	 */
	private static function delete_all_rows( string $table ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		if ( false === Database::client()->query( "DELETE FROM {$table}" ) ) {
			throw new \RuntimeException( 'An analytics table could not be cleared.' );
		}
	}

	/** Returns a valid analytics view posted by the reset form. */
	private static function posted_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the reset nonce before this value is used.
		$value = isset( $_POST['view'] ) && is_scalar( $_POST['view'] ) ? sanitize_key( wp_unslash( (string) $_POST['view'] ) ) : 'combined';
		return in_array( $value, array( 'combined', 'ask-ai', 'ask-ai-2', 'comparison' ), true ) ? $value : 'combined';
	}

	/**
	 * Renders one request row.
	 *
	 * @param array<string,mixed> $row             Request row.
	 * @param float               $refinement_cost Cost of AI review calls linked to the request.
	 */
	private static function row( array $row, float $refinement_cost ): void {
		?>
		<tr><td><?php echo esc_html( self::display_datetime( $row ) ); ?></td><td><?php echo esc_html( self::request_type_label( $row ) ); ?></td><td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?></td><td><?php echo esc_html( self::term_text( $row ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['result_count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( self::feedback_label( $row ) ); ?></td><td><?php self::source_details( $row ); ?></td><td><?php self::token_details( $row ); ?></td><td><?php self::request_cost_details( $row, $refinement_cost ); ?></td></tr>
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
	 * Totals AI review costs by their parent question identifier.
	 *
	 * @param list<array<string,mixed>> $rows Refinement rows.
	 * @return array<string,float> Refinement costs keyed by parent request identifier.
	 */
	private static function refinement_costs_by_request( array $rows ): array {
		$costs = array();
		foreach ( $rows as $row ) {
			$request_id = Database::text( $row['request_id'] ?? '' );
			if ( '' === $request_id ) {
				continue;
			}
			$costs[ $request_id ] = ( $costs[ $request_id ] ?? 0.0 ) + (float) Database::text( $row['estimated_cost_usd'] ?? 0 );
		}
		return $costs;
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
		<br><small><?php echo esc_html( sprintf( /* translators: 1: initial-call cost, 2: AI-review cost. */ __( 'Initial: %1$s; AI review: %2$s', 'ehrman-blog-discovery' ), self::cents( $initial_cost ), self::cents( $refinement_cost ) ) ); ?></small>
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
	 * Returns a stored UTC timestamp formatted for CSV in Eastern time.
	 *
	 * @param array<string,mixed> $row Analytics row.
	 */
	private static function csv_datetime( array $row ): string {
		return self::format_datetime( Database::text( $row['created_at'] ?? '' ), 'Y-m-d H:i:s P T' );
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
	 * Converts an Eastern timestamp to the stored UTC timestamp format.
	 *
	 * @param \DateTimeImmutable $value Eastern timestamp.
	 */
	private static function utc_datetime( \DateTimeImmutable $value ): string {
		return $value->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
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
	 * Returns sanitized filters from the current request.
	 *
	 * @return array{view:string,interface:string,feedback:string,date_from:string,date_to:string,search:string,zero_results:string} Sanitized filters.
	 */
	private static function filters(): array {
		$feedback = sanitize_key( self::query_value( 'feedback', 'all' ) );
		$view     = sanitize_key( self::query_value( 'view', 'combined' ) );
		if ( ! in_array( $view, array( 'combined', 'ask-ai', 'ask-ai-2', 'comparison' ), true ) ) {
			$view = 'combined';
		}
		$interface = 'all';
		if ( 'ask-ai' === $view ) {
			$interface = 'taxonomy';
		} elseif ( 'ask-ai-2' === $view ) {
			$interface = 'semantic';
		}
		return array(
			'view'         => $view,
			'interface'    => $interface,
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
	private static function request_type_label( array $row ): string {
		return 'semantic' === Database::text( $row['request_type'] ?? '' )
			? __( 'Ask AI 2', 'ehrman-blog-discovery' )
			: __( 'Ask AI 1', 'ehrman-blog-discovery' );
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
