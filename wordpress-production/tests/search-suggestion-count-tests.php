<?php
/** Focused, read-only autocomplete count checks; no database fixtures or AI calls. */

use EhrmanBlogDiscovery\Database;
use EhrmanBlogDiscovery\Search_Suggestion_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function () use ( $assert_same ): void {
	$count = new ReflectionMethod( Search_Suggestion_Service::class, 'count_suggestion_posts' );
	$legacy = static function ( array $rows, array $candidates ): array {
		$matching = array_fill_keys( array_keys( $candidates ), array() );
		$topics = $matching;
		foreach ( $rows as $row ) {
			$indexed = Database::text( $row['normalized'] ?? null );
			$post = Database::integer( $row['post_id'] ?? null );
			foreach ( $candidates as $candidate => $_unused ) {
				if ( $indexed === $candidate || str_contains( " {$indexed} ", " {$candidate} " ) ) {
					$matching[$candidate][$post] = true;
				}
				if ( $indexed === $candidate && in_array( Database::text( $row['kind'] ?? null ), array( 'topic', 'alias' ), true ) ) {
					$topics[$candidate][$post] = true;
				}
			}
		}
		$result = array();
		foreach ( $candidates as $candidate => $_unused ) {
			$result[$candidate] = array( 'posts' => count( $matching[$candidate] ), 'topics' => count( $topics[$candidate] ) );
		}
		return $result;
	};
	$rows = array();
	foreach ( array(
		array( 1, 'paul', 'topic' ), array( 1, 'paul', 'secondary' ),
		array( 1, 'paul', 'alias' ), array( 1, 'life of paul', 'secondary' ),
		array( 2, 'paul', 'alias' ), array( 3, 'paul', 'secondary' ),
		array( 4, 'pauline epistles', 'topic' ), array( 5, 'paul and jesus', 'topic' ),
		array( 6, 'life of paul', 'secondary' ), array( 6, 'paul and jesus', 'secondary' ),
		array( 7, 'mustard seed', 'topic' ), array( 7, 'parable of the mustard seed', 'secondary' ),
		array( 8, 'parable of the mustard seed', 'secondary' ), array( 9, 'mustard seedlings', 'secondary' ),
		array( 10, '666', 'secondary' ), array( 11, '666', 'topic' ),
	) as [$post, $term, $kind] ) {
		$rows[] = array( 'post_id' => $post, 'normalized' => $term, 'kind' => $kind );
	}
	$candidates = array_fill_keys( array( 'paul', 'pauline', 'mustard seed', 'seed', 'absent', '666' ), true );
	$actual = $count->invoke( null, $rows, $candidates );
	$assert_same( array( 'posts' => 5, 'topics' => 2 ), $actual['paul'], 'Overlapping terms/kinds must not double-count a post.' );
	$assert_same( array( 'posts' => 1, 'topics' => 0 ), $actual['pauline'], 'Combined whole-word matching differs from exact topic matching.' );
	$assert_same( array( 'posts' => 2, 'topics' => 1 ), $actual['mustard seed'], 'Whole-phrase matching included a partial word or lost a post.' );
	$assert_same( array( 'posts' => 2, 'topics' => 0 ), $actual['seed'], 'Word-boundary matching changed.' );
	$assert_same( array( 'posts' => 0, 'topics' => 0 ), $actual['absent'], 'Absent suggestion should have zero counts.' );
	$assert_same( $legacy( $rows, $candidates ), $actual, 'Optimized counts differ from the prior algorithm.' );
	$assert_same( $actual, $count->invoke( null, array_merge( $rows, array_reverse( $rows ) ), $candidates ), 'Duplicate rows changed counts.' );
	$assert_same( $actual, $count->invoke( null, array_reverse( $rows ), $candidates ), 'Row order changed counts.' );
	$assert_same( array(), $count->invoke( null, $rows, array() ), 'Empty candidate set changed.' );
	$assert_same( $legacy( array(), $candidates ), $count->invoke( null, array(), $candidates ), 'Empty eligible set changed.' );
	foreach ( array( 1, 2, 3, 5, 8 ) as $divisor ) {
		$scoped = array_values( array_filter( $rows, static fn( array $row ): bool => 0 === $row['post_id'] % $divisor ) );
		$assert_same( $legacy( $scoped, $candidates ), $count->invoke( null, $scoped, $candidates ), 'Eligible-post filtering changed counts.' );
	}

	// Deterministic generated fixtures exercise repeated terms, aliases, and numeric keys.
	$vocabulary = array( 'paul', 'pauline', 'life of paul', 'paul and jesus', 'jesus', 'mustard seed', 'mustard seedlings', 'seed', '666', '0', '007', 'cafe', 'cafe society', 'peter' );
	$kinds = array( 'topic', 'alias', 'secondary' );
	for ( $seed = 1; $seed <= 30; ++$seed ) {
		$generated = array();
		for ( $i = 0; $i < 250; ++$i ) {
			$generated[] = array(
				'post_id' => (string) ( ( $i * 7 + $seed ) % 41 + 1 ),
				'normalized' => $vocabulary[( $i * 11 + $seed * 3 ) % count( $vocabulary )],
				'kind' => $kinds[( $i + $seed ) % count( $kinds )],
			);
		}
		$terms = array_fill_keys( array_slice( $vocabulary, $seed % 5 ), true );
		$assert_same( $legacy( $generated, $terms ), $count->invoke( null, $generated, $terms ), 'Generated autocomplete count parity failed.' );
	}
} )();
echo "Autocomplete post counting: OK\n";
