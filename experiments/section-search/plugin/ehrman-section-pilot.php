<?php
/**
 * Plugin Name: Ehrman Ask AI 3 Section Pilot (Local Only)
 * Description: Isolated 500-post section-search evaluation. Disabled by default.
 * Version: 0.1.0
 */
namespace EhrmanSectionPilot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/Baseline.php';
require_once __DIR__ . '/Index.php';
require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/Evidence_Review.php';
require_once __DIR__ . '/End_To_End_Run.php';

/** @phpstan-impure Reads mutable WordPress options and environment. */
function local_enabled(): bool {
	return 'local' === wp_get_environment_type()
		&& in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '::1' ), true )
		&& '1' === (string) get_option( 'ehrman_section_pilot_enabled', '0' )
		&& class_exists( '\EhrmanBlogDiscovery\Semantic_Search_Service' );
}

add_action(
	'rest_api_init',
	static function () {
		if ( ! local_enabled() ) {
			return;
		}
		$permission = static fn() => local_enabled() && current_user_can( 'manage_options' );
		register_rest_route(
			'ehrman-section-pilot/v1',
			'/compare-300',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						return rest_ensure_response( ( new End_To_End_Run() )->run( (int) $request->get_param( 'questionId' ), (string) $request->get_param( 'arm' ) ) );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'comparison_error', $error->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
		register_rest_route(
			'ehrman-section-pilot/v1',
			'/review-evidence',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						return rest_ensure_response( ( new Evidence_Review() )->run( (int) $request->get_param( 'questionId' ), true === $request->get_param( 'evidence' ) ) );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'evidence_review_error', $error->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
		register_rest_route(
			'ehrman-section-pilot/v1',
			'/search',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						$result = ( new Service() )->search( (string) $request->get_param( 'question' ), (string) $request->get_param( 'mode' ), true === $request->get_param( 'evidence' ) );
						unset( $result['candidates'] );
						return rest_ensure_response( $result );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'section_pilot_error', $error->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
		register_rest_route(
			'ehrman-section-pilot/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static function ( \WP_REST_Request $request ) {
					$winner = (string) $request->get_param( 'winner' );
					$ids = $request->get_param( 'requestIds' );
					if ( ! in_array( $winner, array( 'baseline', 'sections', 'tie' ), true ) || ! is_array( $ids ) || count( $ids ) !== 2 ) {
						return new \WP_Error( 'invalid_feedback', 'Invalid comparison feedback.', array( 'status' => 400 ) );
					}
					foreach ( $ids as $id ) {
						if ( ! is_string( $id ) || ! wp_is_uuid( $id ) ) {
							return new \WP_Error( 'invalid_feedback', 'Invalid request identifier.', array( 'status' => 400 ) );
						}
					}
					Service::log(
						array(
							'type'       => 'feedback',
							'winner'     => $winner,
							'requestIds' => $ids,
							'createdAt'  => gmdate( 'c' ),
						)
					);
					return rest_ensure_response( array( 'saved' => true ) );
				},
			)
		);
	}
);

add_shortcode(
	'ehrman_section_pilot',
	static function () {
		if ( ! local_enabled() ) {
			return '<p>Ask AI 3 is not enabled on this site.</p>';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Sign in as an administrator</a> to access this local experiment.</p>';
		}
		nocache_headers();
		wp_enqueue_style( 'ehrman-section-pilot', plugins_url( 'pilot.css', __FILE__ ), array(), '0.1.0' );
		wp_enqueue_script( 'ehrman-section-pilot', plugins_url( 'pilot.js', __FILE__ ), array(), '0.1.0', true );
		wp_localize_script(
			'ehrman-section-pilot',
			'ehrmanSectionPilot',
			array(
				'url'   => rest_url( 'ehrman-section-pilot/v1/' ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		ob_start();
		?>
	<section class="section-pilot" id="section-pilot">
		<p class="pilot-scope">Local experiment &middot; 500 frozen posts &middot; Separate from Ask AI 2</p>
		<form id="pilot-form">
			<label for="pilot-question">Question or request</label>
			<textarea id="pilot-question" rows="3" maxlength="800" required></textarea>
			<div class="pilot-controls">
				<fieldset><legend>Section size</legend>
					<?php foreach ( array( 300, 400, 500 ) as $size ) : ?>
					<label><input type="radio" name="size" value="<?php echo esc_attr( (string) $size ); ?>" <?php checked( 400, $size ); ?>><?php echo esc_html( (string) $size ); ?> words</label>
					<?php endforeach; ?>
				</fieldset>
				<label for="pilot-evidence">AI review evidence<select id="pilot-evidence"><option value="summary">Titles and summaries</option><option value="passages">Titles, summaries, and matching passages</option></select></label>
			</div>
			<div class="pilot-actions"><button type="submit" name="action" value="search">Search</button><button type="submit" name="action" value="compare">Compare with baseline</button></div>
		</form>
		<p id="pilot-status" role="status" aria-live="polite"></p>
		<div id="pilot-results"></div>
		<fieldset id="pilot-feedback" hidden><legend>Which returned better posts?</legend><button type="button" data-winner="baseline">Baseline</button><button type="button" data-winner="sections">Section search</button><button type="button" data-winner="tie">Tie</button><span id="pilot-feedback-status" role="status"></span></fieldset>
	</section>
		<?php
		return ob_get_clean();
	}
);
