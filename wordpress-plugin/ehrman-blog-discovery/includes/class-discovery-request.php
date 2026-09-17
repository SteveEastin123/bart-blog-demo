<?php
/**
 * Public discovery request parsing.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads and sanitizes public discovery query parameters. */
final class Discovery_Request {
	/**
	 * Raw query parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $query;

	/**
	 * Creates a request reader from the current query string or supplied values.
	 *
	 * @param array<string,mixed>|null $query Optional query values for isolated use and tests.
	 */
	public function __construct( ?array $query = null ) {
		if ( null === $query ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public read-only values are sanitized by each accessor.
			$query = $_GET;
		}
		$this->query = $query;
	}

	/**
	 * Reads one sanitized scalar query value.
	 *
	 * @param string $name     Query parameter name.
	 * @param string $fallback Fallback value.
	 * @return string Sanitized query value.
	 */
	public function value( string $name, string $fallback = '' ): string {
		if ( ! isset( $this->query[ $name ] ) || is_array( $this->query[ $name ] ) ) {
			return $fallback;
		}
		$raw_value = Database::text( $this->query[ $name ] );
		return sanitize_text_field( wp_unslash( $raw_value ) );
	}

	/**
	 * Reads one query value as a sanitized slug.
	 *
	 * @param string $name Query parameter name.
	 * @return string Sanitized slug.
	 */
	public function slug( string $name ): string {
		return sanitize_title( $this->value( $name ) );
	}

	/**
	 * Reads one query value as a sanitized key.
	 *
	 * @param string $name Query parameter name.
	 * @return string Sanitized key.
	 */
	public function key( string $name ): string {
		return sanitize_key( $this->value( $name ) );
	}

	/**
	 * Reads selected topics and keywords.
	 *
	 * @return array<int,string> Unique sanitized search terms.
	 */
	public function terms(): array {
		$raw = $this->array_value( 'ebd_keyword' );
		$raw = array_values( array_filter( $raw, 'is_scalar' ) );
		return Search_Service::unique_terms(
			array_map( static fn( $value ): string => sanitize_text_field( Database::text( $value ) ), $raw )
		);
	}

	/**
	 * Reads selected-term modes without performing database-backed inference.
	 *
	 * @return array<int,string> Sanitized requested modes.
	 */
	public function term_modes(): array {
		return array_values(
			array_map(
				static fn( $value ): string => sanitize_key( is_scalar( $value ) ? (string) $value : '' ),
				$this->array_value( 'ebd_term_mode' )
			)
		);
	}

	/**
	 * Reads the requested results page.
	 *
	 * @return int Positive results page number.
	 */
	public function page(): int {
		return max( 1, Database::integer( $this->value( 'ebd_page', '1' ) ) );
	}

	/**
	 * Reads an array-shaped query value and removes WordPress slashes.
	 *
	 * @param string $name Query parameter name.
	 * @return array<int,mixed> Query values.
	 */
	private function array_value( string $name ): array {
		$raw = isset( $this->query[ $name ] ) ? wp_unslash( $this->query[ $name ] ) : array();
		return array_values( is_array( $raw ) ? $raw : array( $raw ) );
	}
}
