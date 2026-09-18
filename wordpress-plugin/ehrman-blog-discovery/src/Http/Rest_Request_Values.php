<?php
/**
 * REST request value normalization.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Normalizes the scalar and list values accepted by public REST endpoints. */
final class Rest_Request_Values {
	/**
	 * Returns a bounded natural-language question.
	 *
	 * @param mixed $value Raw request value.
	 */
	public static function question( mixed $value ): string {
		$text = sanitize_text_field( self::scalar_text( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 800 ) : substr( $text, 0, 800 );
	}

	/** Returns a non-identifying request-address value for short-lived rate limiting. */
	public static function request_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		return '' === $address ? 'unknown' : $address;
	}

	/**
	 * Converts a request value into a unique search-term list.
	 *
	 * @param mixed $value Raw request value.
	 * @return array<int,string> Sanitized search terms.
	 */
	public static function terms( mixed $value ): array {
		$terms = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
		return Search_Service::unique_terms( array_values( $terms ) );
	}

	/**
	 * Converts a request value into a bounded selected-term mode list.
	 *
	 * @param mixed $value Raw request value.
	 * @return array<int,string> Sanitized modes.
	 */
	public static function modes( mixed $value ): array {
		$modes = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
		return array_slice(
			array_values(
				array_map(
					static fn( $mode ): string => sanitize_key( is_scalar( $mode ) ? (string) $mode : '' ),
					$modes
				)
			),
			0,
			Search_Service::MAX_TERMS
		);
	}

	/**
	 * Sanitizes and bounds a single search value.
	 *
	 * @param mixed $value Raw request value.
	 */
	public static function bounded_text( mixed $value ): string {
		$text = sanitize_text_field( self::scalar_text( $value ) );
		return function_exists( 'mb_substr' )
			? mb_substr( $text, 0, Search_Service::MAX_TERM_LENGTH )
			: substr( $text, 0, Search_Service::MAX_TERM_LENGTH );
	}

	/**
	 * Converts scalar input to text and rejects compound values.
	 *
	 * @param mixed $value Raw request value.
	 */
	public static function scalar_text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
