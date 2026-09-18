<?php
/**
 * Public REST API route registration.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers focused controllers for the plugin's REST endpoints. */
final class Rest_Controller {
	private const REST_NAMESPACE = 'ehrman-discovery/v1';

	/**
	 * Public status endpoint.
	 *
	 * @var Status_REST_Controller
	 */
	private Status_REST_Controller $status;

	/**
	 * Topic-and-keyword search endpoints.
	 *
	 * @var Search_REST_Controller
	 */
	private Search_REST_Controller $search;

	/**
	 * Topic-and-keyword Ask AI endpoints.
	 *
	 * @var Taxonomy_Ask_AI_REST_Controller
	 */
	private Taxonomy_Ask_AI_REST_Controller $taxonomy_ask_ai;

	/**
	 * Semantic Ask AI endpoint.
	 *
	 * @var Semantic_Ask_AI_REST_Controller
	 */
	private Semantic_Ask_AI_REST_Controller $semantic_ask_ai;

	/**
	 * Search feedback endpoint.
	 *
	 * @var Feedback_REST_Controller
	 */
	private Feedback_REST_Controller $feedback;

	/**
	 * Protected parity endpoint.
	 *
	 * @var Parity_REST_Controller
	 */
	private Parity_REST_Controller $parity;

	/** Creates the endpoint controllers and their shared services. */
	public function __construct() {
		$search_service = new Search_Service();
		$interpreter    = new AI_Interpreter();

		$this->status          = new Status_REST_Controller();
		$this->search          = new Search_REST_Controller( $search_service );
		$this->taxonomy_ask_ai = new Taxonomy_Ask_AI_REST_Controller( $search_service, $interpreter );
		$this->semantic_ask_ai = new Semantic_Ask_AI_REST_Controller( $interpreter, new Semantic_Search_Service() );
		$this->feedback        = new Feedback_REST_Controller();
		$this->parity          = new Parity_REST_Controller();
	}

	/** Registers all public and test-only REST routes. */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->status, 'status' ),
				'permission_callback' => '__return_true',
			)
		);
		if ( $this->parity->is_enabled() ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/parity/batch',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this->parity, 'batch' ),
					'permission_callback' => array( $this->parity, 'permission' ),
				)
			);
		}
		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->search, 'suggestions' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->search, 'search' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/interpret',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->taxonomy_ask_ai, 'interpret' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/refine',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->taxonomy_ask_ai, 'refine' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/semantic-search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->semantic_ask_ai, 'semantic_search' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->feedback, 'feedback' ),
				'permission_callback' => '__return_true',
			)
		);
	}
}
