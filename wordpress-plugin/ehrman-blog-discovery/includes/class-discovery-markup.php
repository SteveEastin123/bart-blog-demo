<?php
/**
 * Shared discovery-page markup helpers.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds reusable page-shell, heading, and control markup. */
final class Discovery_Markup {

	/**
	 * Counter used to generate unique control identifiers.
	 *
	 * @var int
	 */
	private int $instance = 0;

	/**
	 * Builds a content heading with optional navigation and actions.
	 *
	 * @param string                              $title                Heading text.
	 * @param string                              $meta                 Count or context markup.
	 * @param array<int,array{0:string,1:string}> $breadcrumbs          Breadcrumb labels and URLs.
	 * @param string                              $actions              Optional action markup.
	 * @param bool                                $dynamic_result_count Whether JavaScript may update the count.
	 * @return string Heading markup.
	 */
	public function heading(
		string $title,
		string $meta,
		array $breadcrumbs = array(),
		string $actions = '',
		bool $dynamic_result_count = false
	): string {
		return '<header class="ebd-content-header">' . $this->breadcrumbs( $breadcrumbs ) . '<h2>'
			. esc_html( $title ) . '</h2><p class="ebd-count-line"'
			. ( $dynamic_result_count ? ' data-ebd-result-count' : '' ) . '>' . wp_kses_post( $meta ) . '</p>'
			. ( '' === $actions ? '' : '<div class="ebd-actions">' . $actions . '</div>' ) . '</header>';
	}

	/**
	 * Builds accessible breadcrumb navigation.
	 *
	 * @param array<int,array{0:string,1:string}> $items Breadcrumb labels and URLs.
	 * @return string Breadcrumb markup.
	 */
	public function breadcrumbs( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}
		$crumbs = array();
		foreach ( $items as [$label, $url] ) {
			$crumbs[] = '' === $url
				? '<li aria-current="page">' . esc_html( $label ) . '</li>'
				: '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return '<nav class="ebd-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'ehrman-blog-discovery' )
			. '"><ol>' . implode( '', $crumbs ) . '</ol></nav>';
	}

	/**
	 * Builds the description display-mode control.
	 *
	 * @param string $default_mode Initial display mode.
	 * @param string $scope        Description scope identifier.
	 * @return string Description-mode markup.
	 */
	public function description_control( string $default_mode, string $scope ): string {
		++$this->instance;
		$id           = 'ebd-descriptions-' . $this->instance;
		$default_mode = in_array( $default_mode, array( 'always', 'hover', 'hidden' ), true ) ? $default_mode : 'hover';
		$options      = array(
			'always' => __( 'Always', 'ehrman-blog-discovery' ),
			'hover'  => __( 'On hover', 'ehrman-blog-discovery' ),
			'hidden' => __( 'Hidden', 'ehrman-blog-discovery' ),
		);
		$choices      = array();
		foreach ( $options as $value => $label ) {
			$choice_class = 'ebd-description-choice' . ( 'hover' === $value ? ' ebd-description-choice-hover' : '' );
			$choices[]    = '<label class="' . esc_attr( $choice_class ) . '"><input type="radio" name="' . esc_attr( $id )
				. '" value="' . esc_attr( $value ) . '"' . checked( $default_mode, $value, false )
				. '><span>' . esc_html( $label ) . '</span></label>';
		}
		return '<div class="ebd-description-mode" role="radiogroup" aria-labelledby="' . esc_attr( $id )
			. '-label" data-ebd-description-mode data-scope="' . esc_attr( $scope ) . '" data-default-mode="'
			. esc_attr( $default_mode ) . '"><span id="' . esc_attr( $id ) . '-label" class="ebd-description-mode-label">'
			. esc_html__( 'Show descriptions:', 'ehrman-blog-discovery' ) . '</span>' . implode( '', $choices ) . '</div>';
	}

	/**
	 * Wraps rendered content in the discovery page shell.
	 *
	 * @param string $content Rendered page content.
	 * @param string $view    View-specific CSS identifier.
	 * @return string Wrapped markup.
	 */
	public function shell( string $content, string $view ): string {
		return '<section class="ebd-discovery ebd-view-' . esc_attr( $view ) . '">' . $content . '</section>';
	}

	/**
	 * Formats a localized count with the appropriate noun form.
	 *
	 * @param int    $count    Numeric count.
	 * @param string $singular Singular noun.
	 * @param string $plural   Optional irregular plural noun.
	 * @return string Formatted count and noun.
	 */
	public function plural( int $count, string $singular, string $plural = '' ): string {
		$word = 1 === $count ? $singular : ( '' !== $plural ? $plural : $singular . 's' );
		return number_format_i18n( $count ) . ' ' . $word;
	}
}
