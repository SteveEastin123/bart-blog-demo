<?php
/**
 * Plugin Name: Ehrman Summary Comparison (Local Only)
 * Description: Frozen summary experiment; no production index writes.
 * Version: 0.1.0
 */
namespace EhrmanSummaryPilot;

use EhrmanBlogDiscovery\Database;
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Index;
use EhrmanSectionPilot\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Summary_Run {
	public const RUN = 'agent-summaries-2026-10-17';
	private array $manifest;
	private array $questions;
	private array $improved;
	private array $original;

	public function __construct() {
		$directory      = Index::DIRECTORY . '/' . self::RUN;
		$this->manifest = json_decode( file_get_contents( $directory . '/manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$files          = array(
			$directory . '/questions.json'   => 'questionsHash',
			$directory . '/improved.json'    => 'improvedHash',
			Index::DIRECTORY . '/index.json' => 'indexHash',
			__FILE__                         => 'runnerHash',
		);
		foreach ( $files as $file => $key ) {
			if ( hash_file( 'sha256', $file ) !== $this->manifest[ $key ] ) {
				throw new \RuntimeException( 'Frozen summary experiment changed.' );
			}
		}
		// Enforce the original production source guards before any paid calls.
		new Index();
		foreach ( $this->manifest['pilotSources'] as $name => $hash ) {
			if ( hash_file( 'sha256', WP_PLUGIN_DIR . '/ehrman-section-pilot/' . $name ) !== $hash ) {
				throw new \RuntimeException( 'A shared experiment helper changed.' );
			}
		}
		$this->questions = json_decode( file_get_contents( $directory . '/questions.json' ), true, 512, JSON_THROW_ON_ERROR )['questions'];
		$this->improved  = json_decode( file_get_contents( $directory . '/improved.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->original  = json_decode( file_get_contents( Index::DIRECTORY . '/index.json' ), true, 512, JSON_THROW_ON_ERROR );
		if ( 50 !== count( $this->questions ) || 500 !== count( $this->improved['posts'] ) || $this->improved['sampleHash'] !== $this->original['sampleHash'] ) {
			throw new \RuntimeException( 'Invalid summary sample.' );
		}
		if ( self::RUN !== $this->manifest['run'] || self::RUN !== $this->improved['run']
			|| 512 !== $this->improved['dimensions'] || 'text-embedding-3-small' !== $this->improved['model']
			|| array_column( $this->improved['posts'], 'wpId' ) !== array_column( $this->original['posts'], 'wpId' ) ) {
			throw new \RuntimeException( 'Wrong vector model or post identities.' );
		}
	}

	public function rank( string $question, array $query, string $arm ): array {
		if ( 'ask-ai-2' === $arm ) {
			return ( new Index() )->rank( $question, $query, 'baseline' );
		}
		if ( 'improved-summary' !== $arm ) {
			throw new \InvalidArgumentException( 'Unknown summary arm.' );
		}
		$table = Database::tables()['external_posts'];
		$ids   = array_map( static fn( $p ) => (int) $p['wpId'], $this->original['posts'] );
		$rows  = Database::client()->get_results( "SELECT * FROM {$table} WHERE source_wp_id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		$live  = array_column( $rows, null, 'source_wp_id' );
		$new   = array_column( $this->improved['posts'], null, 'wpId' );
		$posts = array();
		foreach ( $this->original['posts'] as $frozen ) {
			$id   = $frozen['wpId'];
			$post = $live[ $id ] ?? null;
			if ( ! $post || hash( 'sha256', 'Title: ' . $post['title'] . "\nSummary: " . $post['search_summary'] ) !== $frozen['vector']['contentHash'] ) {
				throw new \RuntimeException( 'Production metadata differs from the frozen sample.' );
			}
			$replacement = $new[ $id ];
			if ( hash( 'sha256', 'Title: ' . $post['title'] . "\nSummary: " . $replacement['summary'] ) !== $replacement['vector']['contentHash'] ) {
				throw new \RuntimeException( 'Summary and embedding do not match.' );
			}
			// Replace only request-local data; no UPDATE, import, option or cache write.
			$post['search_summary'] = $replacement['summary'];
			$post['semantic_score'] = Index::cosine( $query, Index::vector( $replacement['vector']['embedding'] ) );
			$posts[]                = $post;
		}
		return array_slice( Baseline::rank( $question, $posts ), 0, 100 );
	}

	public function run( int $number, string $arm ): array {
		if ( $number < 1 || $number > 50 || ! in_array( $arm, array( 'ask-ai-2', 'improved-summary' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown frozen question or arm.' );
		}
		$model = (string) getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
		if ( '' !== $model && 'gpt-5.4-mini' !== $model ) {
			throw new \RuntimeException( 'Reviewer model changed.' );
		}
		$question   = sanitize_text_field( $this->questions[ $number - 1 ]['question'] );
		$start      = microtime( true );
		$request_id = wp_generate_uuid4();
		$service    = new Service();
		$audit      = new \ReflectionProperty( $service, 'audit_id' );
		$audit->setValue( $service, $request_id );
		Service::log(
			array(
				'type'       => 'summary-comparison-start',
				'run'        => self::RUN,
				'requestId'  => $request_id,
				'questionId' => $number,
				'arm'        => $arm,
				'test'       => defined( 'EHRMAN_SUMMARY_TEST' ),
			)
		);
		$embedding  = Baseline::invoke(
			$service,
			'call',
			array(
				'embeddings',
				array(
					'model'      => 'text-embedding-3-small',
					'dimensions' => 512,
					'input'      => array( $question ),
				),
			)
		);
		$candidates = $this->rank( $question, $embedding['data'][0]['embedding'], $arm );
		if ( 100 !== count( $candidates ) || 100 !== count( array_unique( array_column( $candidates, 'id' ) ) ) ) {
			throw new \RuntimeException( 'Expected exactly 100 unique candidates.' );
		}
		$reviewer         = Baseline::reviewer( 'gpt-5.4-mini' );
		$metadata         = Baseline::invoke( $reviewer, 'candidates', array( $candidates ) );
		$payload          = Baseline::invoke( $reviewer, 'request_payload', array( $question, $metadata ) );
		$payload['store'] = false;
		$settings         = $payload;
		unset( $settings['input'], $settings['instructions'] );
		if ( hash( 'sha256', $payload['instructions'] ) !== $this->manifest['promptHash']
			|| hash( 'sha256', wp_json_encode( $settings ) ) !== $this->manifest['settingsHash'] ) {
			throw new \RuntimeException( 'Production reviewer prompt or settings changed.' );
		}
		$body = Baseline::invoke( $service, 'call', array( 'responses', $payload ) );
		if ( 'completed' !== ( $body['status'] ?? '' ) ) {
			throw new \RuntimeException( 'Incomplete review is a failed run, not an empty result.' );
		}
		$text    = Baseline::invoke( $reviewer, 'output_text', array( $body ) );
		$decoded = json_decode( $text, true );
		$parsed  = is_array( $decoded ) ? Baseline::invoke( $reviewer, 'parse_reviewed_posts', array( $decoded, $metadata ) ) : null;
		if ( null === $parsed ) {
			throw new \RuntimeException( 'Invalid reviewer output.' );
		}
		$by_id    = array_column( $candidates, null, 'id' );
		$selected = array_map( static fn( $id ) => $by_id[ $id ], $parsed['post_ids'] );
		$fallback = empty( $selected );
		if ( $fallback ) {
			$selected = array_slice( $candidates, 0, 25 );
		}
		$public         = static fn( $p ) => array(
			'id'      => (string) $p['id'],
			'wpId'    => (string) $p['source_wp_id'],
			'title'   => $p['title'],
			'url'     => $p['url'],
			'summary' => $p['search_summary'],
		);
		$usage_property = new \ReflectionProperty( $service, 'usage' );
		$usage          = $usage_property->getValue( $service );
		$result         = array(
			'run'             => self::RUN,
			'questionId'      => $number,
			'question'        => $question,
			'arm'             => $arm,
			'group'           => $this->questions[ $number - 1 ]['group'],
			'requestId'       => $request_id,
			'sampleHash'      => $this->original['sampleHash'],
			'sampleCount'     => 500,
			'promptHash'      => hash( 'sha256', $payload['instructions'] ),
			'settingsHash'    => hash( 'sha256', wp_json_encode( $settings ) ),
			'candidateHash'   => hash( 'sha256', wp_json_encode( $metadata ) ),
			'cacheHit'        => false,
			'broaderFallback' => $fallback,
			'posts'           => array_map( $public, $selected ),
			'candidates'      => array_map( $public, $candidates ),
			'tiers'           => $parsed['post_tiers'],
			'usage'           => $usage,
			'costUsd'         => array_sum( array_column( $usage, 'estimated_cost_usd' ) ),
			'seconds'         => microtime( true ) - $start,
			'createdAt'       => gmdate( 'c' ),
		);
		Service::log( $result );
		return $result;
	}
}

add_action(
	'rest_api_init',
	static function () {
		if ( ! function_exists( '\EhrmanSectionPilot\local_enabled' ) || ! \EhrmanSectionPilot\local_enabled() ) {
			return;
		}
		register_rest_route(
			'ehrman-section-pilot/v1',
			'/compare-summaries',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => \EhrmanSectionPilot\local_enabled() && current_user_can( 'manage_options' ),
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						return rest_ensure_response( ( new Summary_Run() )->run( (int) $request->get_param( 'questionId' ), (string) $request->get_param( 'arm' ) ) );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'summary_pilot_error', $error->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
	}
);
