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
	/** Returns the selected tab, preserving old Ask AI bookmarks. */
	public function tab(): string {
		$tab = $this->query_value( 'tab' );
		if ( in_array( $tab, array( 'overview', 'ask-ai', 'post-metadata' ), true ) ) {
			return $tab;
		}
		return '' !== $this->query_value( 'view' ) || '' !== $this->query_value( 'analytics_reset' ) ? 'ask-ai' : 'overview';
	}

	/**
	 * Returns sanitized filters from the current request.
	 *
	 * @return array{date_from:string,date_to:string,search:string,zero_results:string} Sanitized filters.
	 */
	public function filters(): array {
		return array(
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

	/**
	 * Returns a valid ISO date or an empty value.
	 *
	 * @param string $value Candidate date.
	 */
	private function date( string $value ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return '';
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
	}
}
