<?php
/**
 * Combined and post-metadata analytics presentation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prepares cost views without changing the existing reader-search report. */
final class AI_Cost_Renderer {
	/**
	 * Builds the shared analytics navigation.
	 *
	 * @param string               $active  Selected tab.
	 * @param array<string,string> $filters Date filters preserved between tabs.
	 */
	public static function navigation( string $active, array $filters ): string {
		$tabs = array();
		foreach ( array(
			'overview'      => __( 'Overview', 'ehrman-blog-discovery' ),
			'ask-ai'        => __( 'Ask AI', 'ehrman-blog-discovery' ),
			'post-metadata' => __( 'Post Metadata', 'ehrman-blog-discovery' ),
		) as $key => $label ) {
			$tabs[] = array(
				'label'  => $label,
				'active' => $active === $key,
				'url'    => self::tab_url( $key, $filters ),
			);
		}
		return Template::render( 'admin/analytics/navigation.php', array( 'tabs' => $tabs ) );
	}

	/**
	 * Links to an activity while preserving only the common date filters.
	 *
	 * @param string               $tab     Analytics tab.
	 * @param array<string,string> $filters Validated date filters.
	 */
	private static function tab_url( string $tab, array $filters ): string {
		return add_query_arg(
			array(
				'page'      => 'ehrman-ai-analytics',
				'tab'       => $tab,
				'date_from' => $filters['date_from'] ?? '',
				'date_to'   => $filters['date_to'] ?? '',
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Renders a read-only cost dashboard.
	 *
	 * @param string               $tab     Overview or metadata.
	 * @param array<string,string> $filters Sanitized dates and post-title search.
	 * @param int                  $page    Metadata page number.
	 */
	public function render( string $tab, array $filters, int $page ): void {
		$builder  = new AI_Cost_Report();
		$overview = 'overview' === $tab ? $builder->overview( $filters ) : array();
		$events   = 'post-metadata' === $tab ? $builder->metadata( $filters ) : array();
		$summary  = $builder->summary( $events );
		$runs     = $builder->runs( $events );
		$pages    = max( 1, (int) ceil( count( $runs ) / 50 ) );
		$base     = array_merge(
			$filters,
			array(
				'page' => 'ehrman-ai-analytics',
				'tab'  => $tab,
			)
		);
		$now      = new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/New_York' ) );
		$periods  = array();
		foreach ( array(
			__( 'Today', 'ehrman-blog-discovery' )        => $now->format( 'Y-m-d' ),
			__( 'This month', 'ehrman-blog-discovery' )   => $now->format( 'Y-m-01' ),
			__( 'All retained', 'ehrman-blog-discovery' ) => '',
		) as $label => $date ) {
			$periods[] = array(
				'label' => $label,
				'url'   => add_query_arg(
					array(
						'page'      => 'ehrman-ai-analytics',
						'tab'       => $tab,
						'date_from' => $date,
						'date_to'   => '' === $date ? '' : $now->format( 'Y-m-d' ),
					),
					admin_url( 'tools.php' )
				),
			);
		}
		$html = Template::render(
			'admin/analytics/costs.php',
			array(
				'tab'        => $tab,
				'navigation' => self::navigation( $tab, $filters ),
				'filters'    => $filters,
				'periods'    => $periods,
				'overview'   => $overview,
				'summary'    => $summary,
				'activities' => array(
					'ask-ai'        => self::tab_url( 'ask-ai', $filters ),
					'post-metadata' => self::tab_url( 'post-metadata', $filters ),
				),
				'metrics'    => ! empty( $overview ) ? array(
					array( __( 'Total estimated cost', 'ehrman-blog-discovery' ), self::money( $overview['total'] ) ),
					array( __( 'Ask AI cost', 'ehrman-blog-discovery' ), self::money( $overview['search']['total_cost'] ) ),
					array( __( 'Post Metadata cost', 'ehrman-blog-discovery' ), self::money( $overview['metadata']['total'] ) ),
					array( __( 'Questions', 'ehrman-blog-discovery' ), number_format_i18n( $overview['search']['questions'] ) ),
					array( __( 'Metadata generation runs', 'ehrman-blog-discovery' ), number_format_i18n( $overview['metadata']['runs'] ) ),
				) : array(
					array( __( 'Total estimated cost', 'ehrman-blog-discovery' ), self::money( $summary['total'] ) ),
					array( __( 'Average cost per generation run', 'ehrman-blog-discovery' ), AI_Analytics_Renderer::cents( $summary['average'] ) ),
					array( __( 'Generation runs', 'ehrman-blog-discovery' ), number_format_i18n( $summary['runs'] ) ),
				),
				'tokens'     => AI_Analytics_Renderer::token_details( $builder->tokens( $events ) ),
				'runs'       => array_slice( $runs, ( $page - 1 ) * 50, 50 ),
				'pagination' => $pages > 1 ? paginate_links(
					array(
						'base'    => add_query_arg( array_merge( $base, array( 'paged' => '%#%' ) ), admin_url( 'tools.php' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				) : '',
				'clear_url'  => add_query_arg(
					array(
						'page' => 'ehrman-ai-analytics',
						'tab'  => $tab,
					),
					admin_url( 'tools.php' )
				),
				'export_url' => wp_nonce_url(
					add_query_arg(
						array_merge(
							$filters,
							array(
								'action'  => 'ehrman_ai_analytics_csv',
								'dataset' => 'post-metadata',
							)
						),
						admin_url( 'admin-post.php' )
					),
					'ehrman_ai_analytics_csv'
				),
				'since'      => self::date( Database::text( get_option( 'ehrman_discovery_cost_tracking_started', '' ) ) ),
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted template escapes all display values.
		echo $html;
	}

	/**
	 * Formats dollars without rounding very small embedding charges to zero.
	 *
	 * @param float $value Estimated US dollars.
	 */
	public static function money( float $value ): string {
		return '$' . number_format_i18n( $value, $value > 0 && $value < 0.0001 ? 8 : 4 );
	}

	/**
	 * Formats a stored UTC date in the reporting timezone.
	 *
	 * @param string $value Stored timestamp.
	 */
	public static function date( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'America/New_York' ) )->format( 'M j, Y g:i A T' );
	}
}
