<?php
/**
 * Plugin Name: Ehrman Ask AI 3 Guest Access (Local Only)
 * Description: Opt-in local guest access to 3,986 eligible posts using section search.
 * Version: 0.1.0
 * Text Domain: ehrman-section-public
 *
 * @package EhrmanSectionPublic
 */

namespace EhrmanSectionPublic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Index.php';
require_once __DIR__ . '/Service.php';

const LOCK_OPTION    = 'ehrman_section_public_lock';
const GLOBAL_RATE    = 'ehrman_section_public_global';
const WINDOW         = 300;
const EXPECTED_POSTS = 3986;

/**
 * Check the independent opt-in, expanded service, and frozen helper dependencies.
 *
 * @return bool Whether local guest access is enabled.
 * @phpstan-impure Reads mutable WordPress options.
 */
function enabled(): bool {
	if ( '1' !== (string) get_option( 'ehrman_section_public_enabled', '0' )
		|| ! function_exists( '\EhrmanSectionPilot\local_enabled' )
		|| ! \EhrmanSectionPilot\local_enabled() ) {
		return false;
	}
	foreach ( array( Service::class, Index::class, '\EhrmanSectionPilot\Baseline', '\EhrmanSectionPilot\Evidence_Review' ) as $class ) {
		if ( ! class_exists( $class ) ) {
			return false;
		}
	}
	return class_exists( '\EhrmanBlogDiscovery\AI_Candidate_Reviewer' )
		&& class_exists( '\EhrmanBlogDiscovery\AI_Usage' )
		&& class_exists( '\EhrmanBlogDiscovery\Database' );
}

/**
 * Compare an optional browser Origin to the site's exact origin.
 *
 * @param string|null $origin Origin header.
 * @return bool Whether the origin is absent or same-origin.
 */
function same_origin( ?string $origin ): bool {
	if ( null === $origin || '' === $origin ) {
		return true;
	}
	$parts = wp_parse_url( $origin );
	$home  = wp_parse_url( home_url() );
	if ( ! is_array( $parts ) || ! is_array( $home )
		|| array_diff( array_keys( $parts ), array( 'scheme', 'host', 'port' ) )
		|| ! isset( $parts['scheme'], $parts['host'], $home['scheme'], $home['host'] ) ) {
		return false;
	}
	$scheme      = strtolower( $parts['scheme'] );
	$home_scheme = strtolower( $home['scheme'] );
	$port        = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
	$home_port   = $home['port'] ?? ( 'https' === $home_scheme ? 443 : 80 );
	return in_array( $scheme, array( 'http', 'https' ), true )
		&& $home_scheme === $scheme
		&& strtolower( $parts['host'] ) === strtolower( $home['host'] )
		&& $home_port === $port;
}

/**
 * Authenticate the local public route without requiring a logged-in user.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return true|\WP_Error Permission result.
 */
function permission( \WP_REST_Request $request ) {
	if ( ! enabled() || ! same_origin( $request->get_header( 'origin' ) ) ) {
		return new \WP_Error( 'section_public_disabled', __( 'This local search is unavailable.', 'ehrman-section-public' ), array( 'status' => 403 ) );
	}
	$nonce = $request->get_header( 'x-wp-nonce' );
	if ( null === $nonce || '' === $nonce ) {
		$nonce = $request->get_param( '_wpnonce' );
	}
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new \WP_Error( 'section_public_nonce', __( 'Refresh the page and try again.', 'ehrman-section-public' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Release only the exact lease owned by this request, even after expiration.
 *
 * @param array $lease Lock token and expiration.
 * @return bool Whether this lease was removed.
 */
function release_lock( array $lease ): bool {
	global $wpdb;
	// Compare-and-delete prevents a late request from removing a replacement lease.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic ownership check; the option cache is invalidated below.
	$deleted = $wpdb->delete(
		$wpdb->options,
		array(
			'option_name'  => LOCK_OPTION,
			'option_value' => maybe_serialize( $lease ),
		),
		array( '%s', '%s' )
	);
	if ( 1 === $deleted ) {
		wp_cache_delete( LOCK_OPTION, 'options' );
		return true;
	}
	return false;
}

/**
 * Insert a lease using WordPress's atomic option-name uniqueness constraint.
 *
 * @param array $lease Lock token and expiration.
 * @return bool Whether this call acquired the option.
 * @phpstan-impure
 */
function add_lock_option( array $lease ): bool {
	return add_option( LOCK_OPTION, $lease, '', false );
}

/**
 * Atomically acquire the global five-minute lease; never remove an active lease.
 *
 * @return array|\WP_Error Owned lease or busy error.
 */
function acquire_lock() {
	$lease = array(
		'token'   => wp_generate_uuid4(),
		'expires' => time() + WINDOW,
	);
	if ( add_lock_option( $lease ) ) {
		return $lease;
	}
	$previous = get_option( LOCK_OPTION );
	if ( is_array( $previous ) && isset( $previous['token'], $previous['expires'] )
		&& is_string( $previous['token'] ) && is_int( $previous['expires'] )
		&& $previous['expires'] <= time() && release_lock( $previous )
		&& add_lock_option( $lease ) ) {
		return $lease;
	}
	return new \WP_Error( 'section_public_busy', __( 'Another search is running. Please try again shortly.', 'ehrman-section-public' ), array( 'status' => 503 ) );
}

/**
 * Hash the directly connected IP; forwarded headers are deliberately ignored.
 *
 * @return string Transient name.
 */
function ip_rate_key(): string {
	$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
		throw new \RuntimeException( 'Missing or invalid client address.' );
	}
	return 'ehrman_section_public_ip_' . hash_hmac( 'sha256', inet_pton( $address ), wp_salt( 'auth' ) );
}

/**
 * Read recent reservations from a rolling five-minute window.
 *
 * @param string $key Transient name.
 * @param int    $now Current epoch second.
 * @return array Recent request timestamps.
 */
function recent_requests( string $key, int $now ): array {
	$times = get_transient( $key );
	if ( false === $times ) {
		return array();
	}
	if ( ! is_array( $times ) || count( array_filter( $times, 'is_int' ) ) !== count( $times ) ) {
		throw new \RuntimeException( 'Invalid local rate-limit state.' );
	}
	return array_values( array_filter( $times, static fn( $timestamp ) => $timestamp > $now - WINDOW ) );
}

/**
 * Reserve both budgets while the caller holds the global lock.
 *
 * @return true|\WP_Error Reservation result.
 */
function reserve_request() {
	$now    = time();
	$key    = ip_rate_key();
	$local  = recent_requests( $key, $now );
	$global = recent_requests( GLOBAL_RATE, $now );
	if ( count( $local ) >= 20 || count( $global ) >= 60 ) {
		return new \WP_Error( 'section_public_rate_limit', __( 'Search limit reached. Please try again in five minutes.', 'ehrman-section-public' ), array( 'status' => 429 ) );
	}
	$local[]  = $now;
	$global[] = $now;
	// Count attempted paid requests too: a failed response may still have incurred cost.
	if ( ! set_transient( GLOBAL_RATE, $global, WINDOW ) || ! set_transient( $key, $local, WINDOW ) ) {
		throw new \RuntimeException( 'Could not persist local request limits.' );
	}
	return true;
}

/**
 * Search only the isolated 3,986-post, 300-word passage-evidence configuration.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return \WP_REST_Response|\WP_Error Public response.
 */
function search( \WP_REST_Request $request ) {
	$allowed = permission( $request );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	$question = $request->get_param( 'question' );
	if ( ! is_string( $question ) || '' === trim( $question ) || mb_strlen( $question ) > 800
		|| array_intersect( array_keys( $request->get_params() ), array( 'mode', 'arm', 'evidence', 'controlled' ) ) ) {
		return new \WP_Error( 'section_public_question', __( 'Enter a question of 1 to 800 characters without configuration overrides.', 'ehrman-section-public' ), array( 'status' => 400 ) );
	}
	$question = trim( sanitize_text_field( $question ) );
	if ( '' === $question || mb_strlen( $question ) > 800 ) {
		return new \WP_Error( 'section_public_question', __( 'Enter a question of 1 to 800 characters.', 'ehrman-section-public' ), array( 'status' => 400 ) );
	}
	$lease = acquire_lock();
	if ( is_wp_error( $lease ) ) {
		return $lease;
	}
	try {
		$limit = reserve_request();
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$result = ( new Service() )->search( $question, '300', true, true );
		if ( EXPECTED_POSTS !== $result['sampleCount'] ) {
			throw new \RuntimeException( 'Unexpected search scope.' );
		}
		$posts    = array_map(
			static fn( $post ) => array_intersect_key( $post, array_flip( array( 'id', 'wpId', 'title', 'url', 'description' ) ) ),
			$result['posts']
		);
		$tiers    = array_intersect_key( $result['tiers'], array_fill_keys( array_column( $posts, 'id' ), true ) );
		$tiers    = array_filter( $tiers, static fn( $tier ) => in_array( $tier, array( 'direct', 'related', 'background' ), true ) );
		$response = rest_ensure_response(
			array(
				'requestId'   => $result['requestId'],
				'question'    => $result['question'],
				'posts'       => $posts,
				'tiers'       => $tiers,
				'costUsd'     => $result['costUsd'],
				'seconds'     => $result['seconds'],
				'notice'      => $result['notice'],
				'sampleCount' => EXPECTED_POSTS,
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'section_public_failed', __( 'Search could not be completed. Please try again later.', 'ehrman-section-public' ), array( 'status' => 502 ) );
	} finally {
		release_lock( $lease );
	}
}

/**
 * Render the separately maintained guest template.
 *
 * @return string Shortcode markup.
 */
function render_page(): string {
	if ( ! enabled() || ! is_readable( __DIR__ . '/page.php' ) ) {
		return '<p>' . esc_html__( 'Ask AI 3 is not enabled on this site.', 'ehrman-section-public' ) . '</p>';
	}
	ob_start();
	require __DIR__ . '/page.php';
	return (string) ob_get_clean();
}

/** Enqueue enabled-site styles and search-page scripts before the theme head. */
function enqueue_assets(): void {
	if ( ! enabled() ) {
		return;
	}
	wp_enqueue_style( 'ehrman-section-public', plugins_url( 'public.css', __FILE__ ), array(), '0.1.0' );
	if ( ! is_page( 'ask-ai-3' ) ) {
		return;
	}
	wp_enqueue_script( 'ehrman-section-public', plugins_url( 'public.js', __FILE__ ), array(), '0.1.0', true );
	wp_localize_script(
		'ehrman-section-public',
		'ehrmanSectionPublic',
		array(
			'url'   => rest_url( 'ehrman-section-public/v1/search' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
}

/**
 * Scope the optional navigation styles without changing existing body classes.
 *
 * @param string[] $classes Existing body classes.
 * @return string[] Body classes with the enabled marker when appropriate.
 */
function body_classes( array $classes ): array {
	if ( enabled() && ! in_array( 'ehrman-section-public-enabled', $classes, true ) ) {
		$classes[] = 'ehrman-section-public-enabled';
	}
	return $classes;
}

/** Send nonce-page cache headers before any template output. */
function prevent_cache(): void {
	if ( enabled() && is_page( 'ask-ai-3' ) ) {
		nocache_headers();
	}
}

/** Print the optional theme navigation link without changing the frozen theme. */
function navigation_link(): void {
	if ( enabled() ) {
		$url = function_exists( 'ehrman_demo_page_url' ) ? ehrman_demo_page_url( 'ask-ai-3' ) : home_url( '/ask-ai-3/' );
		echo '<a href="' . esc_url( $url ) . '"' . ( is_page( 'ask-ai-3' ) ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Ask AI 3', 'ehrman-section-public' ) . '</a>';
	}
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'ehrman-section-public/v1',
			'/search',
			array(
				'methods'             => 'POST',
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'callback'            => __NAMESPACE__ . '\\search',
			)
		);
	}
);
add_shortcode( 'ehrman_section_public', __NAMESPACE__ . '\\render_page' );
add_action( 'ehrman_demo_extra_search_links', __NAMESPACE__ . '\\navigation_link' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
add_action( 'template_redirect', __NAMESPACE__ . '\\prevent_cache' );
add_filter( 'body_class', __NAMESPACE__ . '\\body_classes' );
