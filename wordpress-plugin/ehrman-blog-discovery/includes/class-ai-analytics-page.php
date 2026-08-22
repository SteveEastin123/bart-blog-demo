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
		$filters     = self::filters();
		$page        = max( 1, absint( self::query_value( 'paged', '1' ) ) );
		$report      = AI_Requests::analytics( $filters, $page, self::PAGE_SIZE );
		$refinements = AI_Refinements::recent();
		$total_pages = max( 1, (int) ceil( $report['total'] / self::PAGE_SIZE ) );
		$export_url  = wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => 'ehrman_ai_analytics_csv' ), $filters ), admin_url( 'admin-post.php' ) ),
			'ehrman_ai_analytics_csv'
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Ask AI Analytics', 'ehrman-blog-discovery' ); ?></h1>
			<p><?php echo esc_html__( 'Detailed questions are retained for 90 days. No account, IP address, or browser identifier is stored with a request.', 'ehrman-blog-discovery' ); ?></p>
			<div style="display:flex;gap:12px;flex-wrap:wrap;max-width:1100px;margin:18px 0">
				<?php self::metric( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $report['total'] ) ); ?>
				<?php self::metric( __( 'Yes', 'ehrman-blog-discovery' ), number_format_i18n( $report['yes'] ) ); ?>
				<?php self::metric( __( 'No', 'ehrman-blog-discovery' ), number_format_i18n( $report['no'] ) ); ?>
				<?php self::metric( __( 'Not provided', 'ehrman-blog-discovery' ), number_format_i18n( $report['unanswered'] ) ); ?>
				<?php self::metric( __( 'Response rate', 'ehrman-blog-discovery' ), number_format_i18n( $report['response_rate'], 1 ) . '%' ); ?>
				<?php self::metric( __( 'Helpful rate', 'ehrman-blog-discovery' ), number_format_i18n( $report['helpful_rate'], 1 ) . '%' ); ?>
				<?php self::metric( __( 'Refinements', 'ehrman-blog-discovery' ), number_format_i18n( AI_Refinements::count() ) ); ?>
			</div>
			<?php self::filter_form( $filters ); ?>
			<p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php echo esc_html__( 'Export filtered CSV', 'ehrman-blog-discovery' ); ?></a></p>
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
					<?php foreach ( $refinements as $refinement ) : ?>
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
		$posts  = json_decode( Database::text( $row['selected_posts'] ?? '' ), true );
		$titles = array();
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				if ( is_array( $post ) && is_scalar( $post['title'] ?? null ) ) {
					$titles[] = sanitize_text_field( (string) $post['title'] );
				}
			}
		}
		$source = 1 === Database::integer( $row['cache_hit'] ?? 0 ) ? __( 'Cache', 'ehrman-blog-discovery' ) : Database::text( $row['model'] ?? '' );
		$status = 1 === Database::integer( $row['request_succeeded'] ?? 0 ) ? __( 'Success', 'ehrman-blog-discovery' ) : __( 'Failed', 'ehrman-blog-discovery' );
		if ( 'Failed' === $status && '' !== Database::text( $row['error_code'] ?? '' ) ) {
			$status .= ': ' . Database::text( $row['error_code'] );
		}
		?>
		<tr><td><?php echo esc_html( Database::text( $row['created_at'] ?? '' ) ); ?></td><td><?php echo esc_html( Database::text( $row['question'] ?? '' ) ); ?></td><td><?php echo esc_html( number_format_i18n( Database::integer( $row['original_count'] ?? 0 ) ) . ' → ' . number_format_i18n( Database::integer( $row['refined_count'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( implode( ' | ', $titles ) ); ?></td><td><?php echo esc_html( $source ); ?></td><td><?php echo esc_html( number_format_i18n( $tokens ) ); ?></td><td><?php echo esc_html( '$' . number_format( (float) Database::text( $row['estimated_cost_usd'] ?? 0 ), 5 ) ); ?></td><td><?php echo esc_html( $status ); ?></td></tr>
		<?php
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
