<?php
/**
 * Ask AI analytics administrator request parsing.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads and sanitizes analytics dashboard request values. */
final class AI_Analytics_Request {
	/**
	 * Returns sanitized filters from the current request.
	 *
	 * @return array{view:string,interface:string,feedback:string,date_from:string,date_to:string,search:string,zero_results:string} Sanitized filters.
	 */
	public function filters(): array {
		$feedback = sanitize_key( $this->query_value( 'feedback', 'all' ) );
		$view     = sanitize_key( $this->query_value( 'view', 'combined' ) );
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
			'date_from'    => $this->date( $this->query_value( 'date_from' ) ),
			'date_to'      => $this->date( $this->query_value( 'date_to' ) ),
			'search'       => sanitize_text_field( $this->query_value( 'search' ) ),
			'zero_results' => '1' === $this->query_value( 'zero_results' ) ? '1' : '',
		);
	}

	/**
	 * Returns a sanitized scalar query value.
	 *
	 * @param string $key      Query key.
	 * @param string $fallback Default value.
	 */
	public function query_value( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only administrator filter sanitized below.
		$value = $_GET[ $key ] ?? $fallback;
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : $fallback;
	}

	/** Returns a valid analytics view posted by the reset form. */
	public function posted_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The reset handler verifies the nonce before this value is used.
		$value = isset( $_POST['view'] ) && is_scalar( $_POST['view'] ) ? sanitize_key( wp_unslash( (string) $_POST['view'] ) ) : 'combined';
		return in_array( $value, array( 'combined', 'ask-ai', 'ask-ai-2', 'comparison' ), true ) ? $value : 'combined';
	}

	/**
	 * Returns a valid ISO date or an empty value.
	 *
	 * @param string $value Candidate date.
	 */
	private function date( string $value ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
