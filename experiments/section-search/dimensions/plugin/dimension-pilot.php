<?php
/**
 * Plugin Name: Ehrman Dimension Comparison (Local Only)
 * Description: Frozen three-arm section comparison; no production index writes.
 * Version: 0.1.0
 */
namespace EhrmanDimensionPilot;

use EhrmanBlogDiscovery\Database;
use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Evidence_Review;
use EhrmanSectionPilot\Index;
use EhrmanSectionPilot\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** All runtime code is in this file: manifest.runnerHash is its SHA-256. */
final class Dimension_Run {
	public const RUN   = 'dimensions-300-2026-10-17';
	private const ARMS = array( 'ask-ai-2', 'sections-512', 'sections-1536' );
	private array $manifest;
	private array $questions;
	private array $original;
	private array $sections;

	public function __construct() {
		$directory      = Index::DIRECTORY . '/' . self::RUN;
		$this->manifest = self::json( $directory . '/manifest.json' );
		if ( self::RUN !== ( $this->manifest['run'] ?? null ) ) {
			throw new \RuntimeException( 'Wrong dimension experiment run.' );
		}
		foreach ( array(
			Index::DIRECTORY . '/index.json'       => 'indexHash',
			$directory . '/questions.json'         => 'questionsHash',
			$directory . '/sections-1536.jsonl.gz' => 'sectionHash',
			__FILE__                               => 'runnerHash',
		) as $file => $key ) {
			self::fingerprint( $file, $this->manifest[ $key ] ?? null );
		}
		$sources = $this->manifest['pilotSources'] ?? array();
		foreach ( array( 'Index.php', 'Baseline.php', 'Service.php', 'Evidence_Review.php' ) as $name ) {
			if ( ! isset( $sources[ $name ] ) ) {
				throw new \RuntimeException( 'Missing shared experiment source fingerprint.' );
			}
		}
		foreach ( $sources as $name => $hash ) {
			if ( basename( $name ) !== $name || str_contains( $name, '\\' ) ) {
				throw new \RuntimeException( 'Invalid shared source name.' );
			}
			self::fingerprint( WP_PLUGIN_DIR . '/ehrman-section-pilot/' . $name, $hash );
		}
		// Index checks the original production source hashes without changing its runtime.
		$index           = new Index();
		$this->original  = self::json( Index::DIRECTORY . '/index.json' );
		$this->questions = self::json( $directory . '/questions.json' )['questions'] ?? array();
		if ( 512 !== ( $this->original['dimensions'] ?? null )
			|| 'text-embedding-3-small' !== ( $this->original['model'] ?? null )
			|| $index->identity() !== ( $this->manifest['sampleHash'] ?? null )
			|| 500 !== count( $this->original['posts'] ) || 50 !== count( $this->questions ) ) {
			throw new \RuntimeException( 'Invalid frozen dimension sample.' );
		}
		$ids = array();
		foreach ( $this->original['posts'] as $post ) {
			$id = (string) ( $post['wpId'] ?? '' );
			if ( ! ctype_digit( $id ) || (int) $id <= 0 || isset( $ids[ (int) $id ] ) ) {
				throw new \RuntimeException( 'Invalid or duplicate frozen post identity.' );
			}
			$ids[ (int) $id ] = true;
			self::vector( $post['vector']['embedding'], 512 );
		}
		foreach ( $this->questions as $i => $row ) {
			$question = sanitize_text_field( $row['question'] ?? '' );
			if ( ( $row['id'] ?? null ) !== $i + 1 || ! is_string( $row['group'] ?? null )
				|| '' === trim( $question ) || mb_strlen( $question ) > 800 ) {
				throw new \RuntimeException( 'Invalid frozen question identity or text.' );
			}
		}
		$old_sections = Index::DIRECTORY . '/sections-300.jsonl.gz';
		self::fingerprint( $old_sections, $this->original['sectionFiles']['300']['sha256'] ?? null );
		if ( ! is_int( $this->manifest['sectionCount'] ?? null )
			|| ( $this->original['sectionFiles']['300']['count'] ?? null ) !== $this->manifest['sectionCount'] ) {
			throw new \RuntimeException( 'Frozen section count changed.' );
		}
		$this->sections = $this->validate_sections(
			self::read_sections( $directory . '/sections-1536.jsonl.gz' ),
			self::read_sections( $old_sections )
		);
		// Prompt/settings drift must fail before even the first query embedding is billed.
		foreach ( self::ARMS as $arm ) {
			$this->review_payload( 'Preflight', array(), $arm );
		}
	}

	private static function json( string $file ): array {
		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException( 'Missing frozen dimension fixture: ' . basename( $file ) );
		}
		$row = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'Invalid frozen JSON fixture.' );
		}
		return $row;
	}

	private static function fingerprint( string $file, $expected ): void {
		if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
			|| ! is_readable( $file ) || ! hash_equals( $expected, hash_file( 'sha256', $file ) ) ) {
			throw new \RuntimeException( 'Frozen dimension experiment changed: ' . basename( $file ) );
		}
	}

	private static function read_sections( string $file ): \Generator {
		$handle = gzopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not read frozen sections.' );
		}
		try {
			$line = gzgets( $handle );
			while ( false !== $line ) {
				$row = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
				if ( ! is_array( $row ) ) {
					throw new \RuntimeException( 'Invalid section record.' );
				}
				yield $row;
				$line = gzgets( $handle );
			}
			if ( ! gzeof( $handle ) ) {
				throw new \RuntimeException( 'Incomplete section stream.' );
			}
		} finally {
			gzclose( $handle );
		}
	}

	/** Preserve row order as well as identity: equal-scoring passages keep the first row. */
	private function validate_sections( iterable $sections, \Iterator $original ): array {
		$posts     = array_column( $this->original['posts'], null, 'wpId' );
		$seen      = array();
		$covered   = array();
		$validated = array();
		$original->rewind();
		foreach ( $sections as $section ) {
			if ( ! $original->valid() || ! is_array( $section ) ) {
				throw new \RuntimeException( 'Extra or invalid dimension section.' );
			}
			$source = $original->current();
			foreach ( array( 'wpId', 'size', 'section', 'startWord', 'endWord', 'text', 'hash' ) as $field ) {
				if ( ! array_key_exists( $field, $section ) || ! array_key_exists( $field, $source ) || $section[ $field ] !== $source[ $field ] ) {
					throw new \RuntimeException( 'Section identity, source text or order changed.' );
				}
			}
			$id  = $section['wpId'];
			$key = $id . ':' . $section['section'];
			if ( ! isset( $posts[ $id ] ) || 300 !== $section['size'] || ! is_int( $section['section'] ) || $section['section'] < 0
				|| ! is_int( $section['startWord'] ) || ! is_int( $section['endWord'] )
				|| $section['startWord'] < 0 || $section['endWord'] <= $section['startWord']
				|| ! is_string( $section['text'] ) || '' === trim( $section['text'] ) || isset( $seen[ $key ] )
				|| hash( 'sha256', 'Title: ' . $posts[ $id ]['title'] . "\nPassage: " . $section['text'] ) !== $section['hash']
				|| ( isset( $section['dimensions'] ) && 1536 !== $section['dimensions'] )
				|| ( isset( $section['model'] ) && 'text-embedding-3-small' !== $section['model'] ) ) {
				throw new \RuntimeException( 'Invalid, duplicate or foreign section source.' );
			}
			self::vector( $source['embedding'], 512 );
			self::vector( $section['embedding'], 1536 );
			$seen[ $key ]   = true;
			$covered[ $id ] = true;
			$validated[]    = $section;
			$original->next();
		}
		if ( $original->valid() || count( $validated ) !== $this->manifest['sectionCount'] || 500 !== count( $covered ) ) {
			throw new \RuntimeException( 'Incomplete section index; exact count and all 500 posts are required.' );
		}
		return $validated;
	}

	public static function vector( string $encoded, int $dimensions ): array {
		$bytes = base64_decode( $encoded, true );
		if ( ! in_array( $dimensions, array( 512, 1536 ), true ) || false === $bytes || strlen( $bytes ) !== 4 * $dimensions ) {
			throw new \RuntimeException( 'Invalid float32 vector dimensions.' );
		}
		$vector = array_values( unpack( 'g*', $bytes ) );
		self::validate_vector( $vector, $dimensions );
		return $vector;
	}

	private static function validate_vector( array $vector, int $dimensions ): void {
		if ( ! in_array( $dimensions, array( 512, 1536 ), true ) || count( $vector ) !== $dimensions || ! array_is_list( $vector ) ) {
			throw new \RuntimeException( 'Wrong vector dimensions.' );
		}
		$norm = 0.0;
		foreach ( $vector as $value ) {
			if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( $value ) ) {
				throw new \RuntimeException( 'Non-finite or nonnumeric vector component.' );
			}
			$norm += $value * $value;
		}
		if ( ! is_finite( $norm ) || sqrt( $norm ) < 0.99 || sqrt( $norm ) > 1.01 ) {
			throw new \RuntimeException( 'Expected a finite unit-norm embedding.' );
		}
	}

	private static function cosine1536( array $query, array $vector ): float {
		// The same accumulation order and denominator as Index::cosine; no renormalization.
		$dot   = 0.0;
		$left  = 0.0;
		$right = 0.0;
		foreach ( $query as $i => $number ) {
			$dot   += $number * $vector[ $i ];
			$left  += $number * $number;
			$right += $vector[ $i ] * $vector[ $i ];
		}
		return $dot / sqrt( $left * $right );
	}

	private static function arm( string $arm ): void {
		if ( ! in_array( $arm, self::ARMS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown dimension arm.' );
		}
	}

	/** Build all 500 rows, never the already-truncated Index::rank baseline output. */
	private function baseline_rows( string $question, array $query ): array {
		$table = Database::tables()['external_posts'];
		$ids   = array_map( static fn( $p ) => (int) $p['wpId'], $this->original['posts'] );
		$rows  = Database::client()->get_results( "SELECT * FROM {$table} WHERE source_wp_id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		if ( ! is_array( $rows ) || 500 !== count( $rows ) || 500 !== count( array_unique( array_column( $rows, 'source_wp_id' ) ) )
			|| 500 !== count( array_unique( array_column( $rows, 'id' ) ) ) ) {
			throw new \RuntimeException( 'Expected 500 unique live sample rows.' );
		}
		$live  = array_column( $rows, null, 'source_wp_id' );
		$posts = array();
		foreach ( $this->original['posts'] as $frozen ) {
			$post = $live[ $frozen['wpId'] ] ?? null;
			if ( ! $post || hash( 'sha256', 'Title: ' . $post['title'] . "\nSummary: " . $post['search_summary'] ) !== $frozen['vector']['contentHash'] ) {
				throw new \RuntimeException( 'Production metadata differs from the frozen sample.' );
			}
			$post['semantic_score'] = Index::cosine( $query, self::vector( $frozen['vector']['embedding'], 512 ) );
			$posts[]                = $post;
		}
		return Baseline::rank( $question, $posts );
	}

	private function fuse( array $ranked, array $query, iterable $sections ): array {
		self::validate_vector( $query, 1536 );
		if ( 500 !== count( $ranked ) ) {
			throw new \RuntimeException( 'Fusion requires all 500 baseline rows.' );
		}
		$best = array();
		foreach ( $sections as $section ) {
			$id    = $section['wpId'];
			$score = self::cosine1536( $query, self::vector( $section['embedding'], 1536 ) );
			if ( ! isset( $best[ $id ] ) || $score > $best[ $id ]['score'] ) {
				$best[ $id ] = array(
					'score'   => $score,
					'text'    => $section['text'],
					'section' => $section['section'],
				);
			}
		}
		if ( count( $best ) !== 500 || array_diff( array_column( $ranked, 'source_wp_id' ), array_keys( $best ) ) ) {
			throw new \RuntimeException( 'Section index is incomplete; refusing a partial comparison.' );
		}
		// Deliberately mirror Index::rank's fusion, tie rules and final 100-row slice.
		uksort(
			$best,
			static function ( $a, $b ) use ( $best ) {
				$order = $best[ $b ]['score'] <=> $best[ $a ]['score'];
				return 0 !== $order ? $order : ( (int) $a <=> (int) $b );
			}
		);
		$section_ranks = array_flip( array_keys( $best ) );
		foreach ( $ranked as &$post ) {
			$id                       = $post['source_wp_id'];
			$post['retrieval_score'] += 1.0 / ( 61.0 + $section_ranks[ $id ] );
			$post['matching_passage'] = $best[ $id ]['text'];
			$post['section_score']    = $best[ $id ]['score'];
		}
		unset( $post );
		usort(
			$ranked,
			static function ( $a, $b ) {
				$order = $b['retrieval_score'] <=> $a['retrieval_score'];
				if ( 0 === $order ) {
					$order = $b['semantic_score'] <=> $a['semantic_score'];
				}
				return 0 !== $order ? $order : strcmp( $b['published_at'], $a['published_at'] );
			}
		);
		return array_slice( $ranked, 0, 100 );
	}

	public function rank( string $question, array $query512, string $arm, ?array $query1536 = null ): array {
		self::arm( $arm );
		self::validate_vector( $query512, 512 );
		if ( 'sections-1536' !== $arm ) {
			if ( null !== $query1536 ) {
				throw new \InvalidArgumentException( 'Controls must not use a 1536-dimensional query.' );
			}
			return ( new Index() )->rank( $question, $query512, 'ask-ai-2' === $arm ? 'baseline' : '300' );
		}
		self::validate_vector( $query1536 ?? array(), 1536 );
		return $this->fuse( $this->baseline_rows( $question, $query512 ), $query1536, $this->sections );
	}

	private function review_payload( string $question, array $candidates, string $arm ): array {
		self::arm( $arm );
		$reviewer = Baseline::reviewer( 'gpt-5.4-mini' );
		$metadata = Baseline::invoke( $reviewer, 'candidates', array( $candidates ) );
		$payload  = Baseline::invoke( $reviewer, 'request_payload', array( $question, $metadata ) );
		if ( 'ask-ai-2' !== $arm ) {
			$payload = Evidence_Review::payload(
				array(
					'model'      => 'gpt-5.4-mini',
					'question'   => $question,
					'candidates' => array_map(
						static fn( $p ) => array(
							'id'      => $p['id'],
							'title'   => $p['title'],
							'summary' => $p['search_summary'],
							'passage' => $p['matching_passage'],
						),
						$candidates
					),
				),
				true
			);
		}
		$payload['store'] = false;
		$settings         = $payload;
		unset( $settings['input'], $settings['instructions'] );
		if ( hash( 'sha256', $payload['instructions'] ) !== ( $this->manifest['promptHashes'][ $arm ] ?? null )
			|| hash( 'sha256', wp_json_encode( $settings ) ) !== ( $this->manifest['settingsHash'] ?? null ) ) {
			throw new \RuntimeException( 'Frozen reviewer prompt or settings changed.' );
		}
		return $payload;
	}

	private static function query( Service $service, string $question, int $dimensions ): array {
		$body = Baseline::invoke(
			$service,
			'call',
			array(
				'embeddings',
				array(
					'model'      => 'text-embedding-3-small',
					'dimensions' => $dimensions,
					'input'      => array( $question ),
				),
			)
		);
		if ( 'text-embedding-3-small' !== ( $body['model'] ?? null ) || ! is_array( $body['data'] ?? null )
			|| 1 !== count( $body['data'] ) || 0 !== ( $body['data'][0]['index'] ?? null )
			|| ! is_array( $body['data'][0]['embedding'] ?? null ) ) {
			throw new \RuntimeException( 'Invalid query embedding response identity.' );
		}
		$query = $body['data'][0]['embedding'];
		self::validate_vector( $query, $dimensions );
		return $query;
	}

	public function run( int $number, string $arm ): array {
		self::arm( $arm );
		if ( $number < 1 || $number > 50 ) {
			throw new \InvalidArgumentException( 'Unknown frozen question.' );
		}
		if ( ! \EhrmanSectionPilot\local_enabled() ) {
			throw new \RuntimeException( 'The local section experiment is disabled.' );
		}
		$model = (string) getenv( 'EHRMAN_DISCOVERY_AI_MODEL' );
		if ( '' !== $model && 'gpt-5.4-mini' !== $model ) {
			throw new \RuntimeException( 'Reviewer model changed.' );
		}
		$question   = sanitize_text_field( $this->questions[ $number - 1 ]['question'] );
		$start      = microtime( true );
		$request_id = wp_generate_uuid4();
		$service    = new Service();
		( new \ReflectionProperty( $service, 'audit_id' ) )->setValue( $service, $request_id );
		$context = array(
			'run'        => self::RUN,
			'requestId'  => $request_id,
			'questionId' => $number,
			'arm'        => $arm,
			'test'       => defined( 'EHRMAN_DIMENSION_TEST' ),
		);
		Service::log( array( 'type' => 'dimension-comparison-start' ) + $context );
		try {
			$query512   = self::query( $service, $question, 512 );
			$query1536  = 'sections-1536' === $arm ? self::query( $service, $question, 1536 ) : null;
			$candidates = $this->rank( $question, $query512, $arm, $query1536 );
			if ( 100 !== count( $candidates ) || 100 !== count( array_unique( array_column( $candidates, 'id' ) ) )
				|| 100 !== count( array_unique( array_column( $candidates, 'source_wp_id' ) ) ) ) {
				throw new \RuntimeException( 'Expected exactly 100 unique candidates.' );
			}
			$reviewer = Baseline::reviewer( 'gpt-5.4-mini' );
			$metadata = Baseline::invoke( $reviewer, 'candidates', array( $candidates ) );
			$payload  = $this->review_payload( $question, $candidates, $arm );
			if ( 100 !== count( $metadata ) ) {
				throw new \RuntimeException( 'Reviewer must receive all 100 candidates.' );
			}
			// Hash the actual evidence sent, including passages in both section arms.
			$review_metadata = $metadata;
			if ( 'ask-ai-2' !== $arm ) {
				foreach ( $review_metadata as $i => &$post ) {
					$post['matching_passage'] = $candidates[ $i ]['matching_passage'];
				}
				unset( $post );
			}
			$settings = $payload;
			unset( $settings['input'], $settings['instructions'] );
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
			$public = static fn( $p ) => array(
				'id'      => (string) $p['id'],
				'wpId'    => (string) $p['source_wp_id'],
				'title'   => $p['title'],
				'url'     => $p['url'],
				'summary' => $p['search_summary'],
				'passage' => $p['matching_passage'] ?? '',
			);
			$usage  = ( new \ReflectionProperty( $service, 'usage' ) )->getValue( $service );
			$result = $context + array(
				'question'        => $question,
				'group'           => $this->questions[ $number - 1 ]['group'],
				'sampleHash'      => $this->original['sampleHash'],
				'sampleCount'     => 500,
				'promptHash'      => hash( 'sha256', $payload['instructions'] ),
				'settingsHash'    => hash( 'sha256', wp_json_encode( $settings ) ),
				'candidateHash'   => hash( 'sha256', wp_json_encode( $review_metadata ) ),
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
		} catch ( \Throwable $error ) {
			$usage = ( new \ReflectionProperty( $service, 'usage' ) )->getValue( $service );
			Service::log(
				$context + array(
					'failed'    => true,
					'error'     => $error->getMessage(),
					'usage'     => $usage,
					'costUsd'   => array_sum( array_column( $usage, 'estimated_cost_usd' ) ),
					'seconds'   => microtime( true ) - $start,
					'createdAt' => gmdate( 'c' ),
				)
			);
			throw $error;
		}
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
			'/compare-dimensions',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => \EhrmanSectionPilot\local_enabled() && current_user_can( 'manage_options' ),
				'callback'            => static function ( \WP_REST_Request $request ) {
					try {
						return rest_ensure_response( ( new Dimension_Run() )->run( (int) $request->get_param( 'questionId' ), (string) $request->get_param( 'arm' ) ) );
					} catch ( \Throwable $error ) {
						return new \WP_Error( 'dimension_pilot_error', $error->getMessage(), array( 'status' => 400 ) );
					}
				},
			)
		);
	}
);
