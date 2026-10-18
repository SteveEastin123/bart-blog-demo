<?php
/** Local CLI-only adapter to the unchanged production reviewer. No HTTP or database writes. */
namespace EhrmanMetadataReview;

use EhrmanBlogDiscovery\AI_Candidate_Reviewer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new \RuntimeException( 'Local WordPress CLI only.' );
}

/** Invoke only the production pure metadata, payload and parser helpers. */
function invoke( AI_Candidate_Reviewer $reviewer, string $method, array $arguments ) {
	return ( new \ReflectionMethod( $reviewer, $method ) )->invokeArgs( $reviewer, $arguments );
}

/** Persist once; never overwrite a different experimental artifact. */
function freeze( string $path, array $value ): void {
	if ( file_exists( $path ) ) {
		if ( json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) !== $value ) {
			throw new \RuntimeException( 'Frozen output differs.' );
		}
		return;
	}
	if ( false === file_put_contents( $path, wp_json_encode( $value, JSON_PRETTY_PRINT ) . "\n" ) ) {
		throw new \RuntimeException( 'Could not save experiment output.' );
	}
}

/** Build all three requests from the same ordered candidate records. */
function requests( array $input, AI_Candidate_Reviewer $reviewer ): array {
	$output = array();
	foreach ( $input['questions'] as $question ) {
		$rows = array_map(
			static fn( $p ) => array(
				'id'             => $p['id'],
				'title'          => $p['title'],
				'search_summary' => $p['summary'],
			),
			$question['candidates']
		);
		$base = invoke( $reviewer, 'candidates', array( $rows ) );
		if ( 100 !== count( $base ) || hash( 'sha256', wp_json_encode( $base ) ) !== $question['candidateHash'] ) {
			throw new \RuntimeException( 'Original candidate content or ordering changed.' );
		}
		$original          = invoke( $reviewer, 'request_payload', array( $question['question'], $base ) );
		$original['store'] = false;
		$settings          = $original;
		unset( $settings['input'], $settings['instructions'] );
		if ( hash( 'sha256', $original['instructions'] ) !== $input['promptHash']
			|| hash( 'sha256', wp_json_encode( $settings ) ) !== $input['settingsHash'] ) {
			throw new \RuntimeException( 'Original prompt or settings changed.' );
		}
		foreach ( array( 'baseline', 'topics', 'topics-keywords' ) as $arm ) {
			$metadata = $base;
			if ( 'baseline' !== $arm ) {
				foreach ( $metadata as $index => &$post ) {
					$post['topics'] = array_values( array_unique( array_map( 'sanitize_text_field', $question['candidates'][ $index ]['topics'] ) ) );
					if ( 'topics-keywords' === $arm ) {
						$post['secondary_keywords'] = array_values( array_unique( array_map( 'sanitize_text_field', $question['candidates'][ $index ]['secondaryKeywords'] ) ) );
					}
				}
				unset( $post );
			}
			$payload          = invoke( $reviewer, 'request_payload', array( $question['question'], $metadata ) );
			$payload['store'] = false;
			if ( 'baseline' !== $arm ) {
				$payload['instructions'] = str_replace(
					'Use only the supplied titles and summaries. ',
					'Use only the supplied titles and summaries, with assigned topics and secondary keywords when present as supplementary context. '
					. 'Topics identify central subjects; secondary keywords identify supporting people, texts, events or ideas. '
					. 'Treat these labels as fallible clues, not proof that a post answers the question; a matching label alone is insufficient. ',
					$payload['instructions'],
					$replacements
				);
				if ( 1 !== $replacements ) {
					throw new \RuntimeException( 'Expected exactly one metadata instruction replacement.' );
				}
			}
			$output[] = array(
				'key'           => sprintf( 'q%02d-%s', $question['id'], $arm ),
				'questionId'    => $question['id'],
				'arm'           => $arm,
				'candidateHash' => $question['candidateHash'],
				'promptHash'    => hash( 'sha256', $payload['instructions'] ),
				'payload'       => $payload,
				'metadata'      => $metadata,
			);
		}
	}
	return $output;
}

/** Parse through production code, preserving tier ordering and empty-selection fallback. */
function parse( array $request, array $response, AI_Candidate_Reviewer $reviewer ): array {
	if ( 'completed' !== ( $response['status'] ?? '' ) ) {
		throw new \RuntimeException( 'Incomplete review, not an empty selection.' );
	}
	$text    = invoke( $reviewer, 'output_text', array( $response ) );
	$decoded = json_decode( $text, true, 512, JSON_THROW_ON_ERROR );
	$result  = invoke( $reviewer, 'parse_reviewed_posts', array( $decoded, $request['metadata'] ) );
	if ( null === $result ) {
		throw new \RuntimeException( 'Invalid review response.' );
	}
	$result['broaderFallback'] = empty( $result['post_ids'] );
	if ( $result['broaderFallback'] ) {
		$result['post_ids'] = array_column( array_slice( $request['metadata'], 0, 25 ), 'id' );
	}
	return $result;
}

/** Mocked parser checks make no network calls. */
function tests( array $requests, AI_Candidate_Reviewer $reviewer ): int {
	$checks = 0;
	foreach ( $requests as $request ) {
		$ids    = array_column( $request['metadata'], 'id' );
		$body   = static fn( $rows ) => array(
			'status'      => 'completed',
			'output_text' => wp_json_encode( array( 'selected_posts' => $rows ) ),
		);
		$rows   = array(
			array(
				'id'             => $ids[0],
				'relevance_tier' => 'background',
			),
			array(
				'id'             => $ids[1],
				'relevance_tier' => 'direct',
			),
			array(
				'id'             => $ids[2],
				'relevance_tier' => 'related',
			),
		);
		$parsed = parse( $request, $body( $rows ), $reviewer );
		if ( array( $ids[1], $ids[2], $ids[0] ) !== $parsed['post_ids'] || $parsed['broaderFallback'] ) {
			throw new \RuntimeException( 'Tier ordering changed.' );
		}
		$empty = parse( $request, $body( array() ), $reviewer );
		if ( ! $empty['broaderFallback'] || array_slice( $ids, 0, 25 ) !== $empty['post_ids'] ) {
			throw new \RuntimeException( 'Fallback changed.' );
		}
		$checks += 2;
	}
	return $checks;
}

// WP-CLI eval-file arguments are paths chosen by the local orchestration script.
/** @var list<string> $args */
$operation = $args[0] ?? '';
$directory = $args[1] ?? '';
$input     = json_decode( file_get_contents( $directory . '/input.json' ), true, 512, JSON_THROW_ON_ERROR );
$reviewer  = new AI_Candidate_Reviewer( '', 'gpt-5.4-mini' );
$source    = ( new \ReflectionClass( $reviewer ) )->getFileName();
if ( ! $source || hash_file( 'sha256', $source ) !== $input['reviewerSourceHash'] ) {
	throw new \RuntimeException( 'Installed reviewer differs from the frozen source.' );
}
$requests = requests( $input, $reviewer );
if ( 'export' === $operation ) {
	freeze( $directory . '/requests.json', $requests );
	echo wp_json_encode(
		array(
			'requests'     => count( $requests ),
			'mockedChecks' => tests( $requests, $reviewer ),
			'apiCalls'     => 0,
		)
	) . "\n";
} elseif ( 'parse' === $operation ) {
	$receipts = json_decode( file_get_contents( $directory . '/receipts.json' ), true, 512, JSON_THROW_ON_ERROR );
	$parsed   = array();
	foreach ( $requests as $request ) {
		$parsed[ $request['key'] ] = parse( $request, $receipts[ $request['key'] ]['response'], $reviewer );
	}
	freeze( $directory . '/parsed.json', $parsed );
	echo wp_json_encode(
		array(
			'parsedReviews' => count( $parsed ),
			'apiCalls'      => 0,
		)
	) . "\n";
} else {
	throw new \InvalidArgumentException( 'Choose export or parse.' );
}
