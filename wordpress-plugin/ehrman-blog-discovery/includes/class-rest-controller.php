<?php

namespace EhrmanBlogDiscovery;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class Rest_Controller
{
    private const NAMESPACE = 'ehrman-discovery/v1';

    private Search_Service $search;

    public function __construct()
    {
        $this->search = new Search_Service();
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/status',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'status'),
                'permission_callback' => '__return_true',
            )
        );
        if ('' !== Parity_Service::configured_token()) {
            register_rest_route(
                self::NAMESPACE,
                '/parity/batch',
                array(
                    'methods' => 'POST',
                    'callback' => array($this, 'parity_batch'),
                    'permission_callback' => array($this, 'parity_permission'),
                )
            );
        }
        register_rest_route(
            self::NAMESPACE,
            '/suggestions',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'suggestions'),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/search',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'search'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $status = Plugin::status_data();
        unset($status['database_version']);
        return new WP_REST_Response($status, 200);
    }

    public function suggestions(WP_REST_Request $request): WP_REST_Response
    {
        $response = new WP_REST_Response(
            $this->search->suggestions(
                $this->bounded_text($request->get_param('q')),
                $this->terms($request->get_param('selected')),
                sanitize_title($this->scalar_text($request->get_param('category'))),
                sanitize_title($this->scalar_text($request->get_param('topic')))
            ),
            200
        );
        $response->header('Cache-Control', 'public, max-age=30');
        return $response;
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->search->search(
            $this->terms($request->get_param('term')),
            sanitize_key($this->scalar_text($request->get_param('sort'))),
            sanitize_title($this->scalar_text($request->get_param('category'))),
            sanitize_title($this->scalar_text($request->get_param('topic')))
        );
        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'public, max-age=30');
        return $response;
    }

    public function parity_permission(WP_REST_Request $request)
    {
        $configured = Parity_Service::configured_token();
        $provided = (string) $request->get_header('X-Ehrman-Parity-Token');
        if ('' === $configured || '' === $provided || !hash_equals($configured, $provided)) {
            return new WP_Error(
                'ehrman_parity_forbidden',
                __('Forbidden', 'ehrman-blog-discovery'),
                array('status' => 403)
            );
        }
        return true;
    }

    public function parity_batch(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error(
                'ehrman_parity_invalid_json',
                __('Request body must be a JSON object.', 'ehrman-blog-discovery'),
                array('status' => 400)
            );
        }
        if (isset($payload['schemaVersion']) && Parity_Service::SCHEMA_VERSION !== (int) $payload['schemaVersion']) {
            return new WP_Error(
                'ehrman_parity_schema',
                __('Unsupported schemaVersion.', 'ehrman-blog-discovery'),
                array('status' => 400)
            );
        }

        try {
            $response = new WP_REST_Response(
                (new Parity_Service())->run_batch($payload['cases'] ?? null),
                200
            );
            $response->header('Cache-Control', 'no-store');
            return $response;
        }
        catch (\InvalidArgumentException | \RuntimeException $error) {
            return new WP_Error(
                'ehrman_parity_invalid_request',
                $error->getMessage(),
                array('status' => 400)
            );
        }
    }

    private function terms($value): array
    {
        $terms = is_array($value) ? $value : (null === $value || '' === $value ? array() : array($value));
        return Search_Service::unique_terms($terms);
    }

    private function bounded_text($value): string
    {
        $text = sanitize_text_field($this->scalar_text($value));
        return function_exists('mb_substr')
            ? mb_substr($text, 0, Search_Service::MAX_TERM_LENGTH)
            : substr($text, 0, Search_Service::MAX_TERM_LENGTH);
    }

    private function scalar_text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
