<?php
/** Expanded local index; ranking derived unchanged from the preserved section pilot. */
namespace EhrmanSectionPublic;

use EhrmanSectionPilot\Baseline;
use EhrmanSectionPilot\Evidence_Review;

use EhrmanBlogDiscovery\Database;

final class Index {
	public const DIRECTORY = '/opt/ehrman-section-public';
	private array $data;

	public function __construct() {
		$file = self::DIRECTORY . '/index.json';
		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException( 'The section pilot index has not been installed.' );
		}
		$this->data = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		if ( 512 !== $this->data['dimensions'] || 3986 !== count( $this->data['posts'] ) ) {
			throw new \RuntimeException( 'Invalid pilot index dimensions or post count.' );
		}
		foreach ( $this->data['sourceHashes'] as $relative => $hash ) {
			if ( ! hash_equals( $hash, hash_file( 'sha256', WP_PLUGIN_DIR . '/ehrman-blog-discovery/' . $relative ) ) ) {
				throw new \RuntimeException( 'Production search code changed. Revalidate the experiment before running it.' );
			}
		}
	}

	public function identity(): string {
		return $this->data['sampleHash'];
	}

	private function sections( string $mode ): \Generator {
		$file     = self::DIRECTORY . '/sections-' . $mode . '.jsonl.gz';
		$expected = $this->data['sectionFiles'][ $mode ];
		if ( ! is_readable( $file ) || ! hash_equals( $expected['sha256'], hash_file( 'sha256', $file ) ) ) {
			throw new \RuntimeException( 'Missing or changed section-vector file.' );
		}
		$handle = gzopen( $file, 'rb' );
		$count  = 0;
		try {
			$line = gzgets( $handle );
			while ( false !== $line ) {
				++$count;
				yield json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
				$line = gzgets( $handle );
			}
			if ( $count !== $expected['count'] ) {
				throw new \RuntimeException( 'Incomplete section-vector file.' );
			}
		} finally {
			gzclose( $handle );
		}
	}

	public static function vector( string $encoded ): array {
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || strlen( $bytes ) !== 2048 ) {
			throw new \RuntimeException( 'Invalid 512-dimensional float32 vector.' );
		}
		$values = array_values( unpack( 'g*', $bytes ) );
		foreach ( $values as $value ) {
			if ( ! is_finite( $value ) ) {
				throw new \RuntimeException( 'Non-finite vector component.' );
			}
		}
		return $values;
	}

	public static function cosine( array $query, array $vector ): float {
		if ( 512 !== count( $query ) || 512 !== count( $vector ) ) {
			throw new \RuntimeException( 'Wrong vector dimensions.' );
		}
		$dot   = 0.0;
		$left  = 0.0;
		$right = 0.0;
		foreach ( $query as $i => $number ) {
			$dot   += $number * $vector[ $i ];
			$left  += $number * $number;
			$right += $vector[ $i ] * $vector[ $i ];
		}
		if ( $left <= 0 || $right <= 0 ) {
			throw new \RuntimeException( 'Zero vector.' );
		}
		return $dot / sqrt( $left * $right );
	}

	public function rank( string $question, array $query, string $mode ): array {
		if ( ! in_array( $mode, array( '300' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown pilot configuration.' );
		}
		$table = Database::tables()['external_posts'];
		$ids   = array_map( static fn( $p ) => (int) $p['wpId'], $this->data['posts'] );
		$rows  = Database::client()->get_results( "SELECT * FROM {$table} WHERE source_wp_id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		$live  = array_column( $rows, null, 'source_wp_id' );
		$posts = array();
		foreach ( $this->data['posts'] as $frozen ) {
			$id   = $frozen['wpId'];
			$post = $live[ $id ] ?? null;
			if ( ! $post || ! hash_equals( $frozen['vector']['contentHash'], hash( 'sha256', 'Title: ' . $post['title'] . "\nSummary: " . $post['search_summary'] ) ) ) {
				throw new \RuntimeException( 'The frozen sample no longer matches the live index. Rebuild the experiment.' );
			}
			$post['semantic_score'] = self::cosine( $query, self::vector( $frozen['vector']['embedding'] ) );
			$posts[]                = $post;
		}
		$ranked = Baseline::rank( $question, $posts );
		$best   = array();
		foreach ( $this->sections( $mode ) as $section ) {
			if ( (int) $mode !== $section['size'] ) {
				continue;
			}
			$id    = $section['wpId'];
			$score = self::cosine( $query, self::vector( $section['embedding'] ) );
			if ( ! isset( $best[ $id ] ) || $score > $best[ $id ]['score'] ) {
				$best[ $id ] = array(
					'score'   => $score,
					'text'    => $section['text'],
					'section' => $section['section'],
				);
			}
		}
		if ( count( $best ) !== 3986 ) {
			throw new \RuntimeException( 'Section index is incomplete; refusing a partial comparison.' );
		}
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
}
