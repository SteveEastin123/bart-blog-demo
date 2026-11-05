<?php
/** Fixed-input semantic ranking contracts; no database writes or API calls. */

use EhrmanBlogDiscovery\Semantic_Index_Service;
use EhrmanBlogDiscovery\Semantic_Post_Ranker;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

( static function () use ( $assert, $assert_same ): void {
	$ranker = new Semantic_Post_Ranker();
	$rows = array();
	foreach ( array( 1, 2, 3 ) as $id ) {
		$row = array(
			'id' => $id,
			'title' => 1 === $id ? 'Luke' : 'Study ' . $id,
			'search_summary' => 'Explains textual evidence.',
			'published_at' => '2026-01-0' . $id,
			'embedding' => pack( 'g*', 3 === $id ? 0.0 : 1.0, 3 === $id ? 1.0 : 0.0 ),
			'embedding_norm' => '1',
		);
		$row['content_hash'] = Semantic_Index_Service::content_hash( $row );
		$rows[] = $row;
	}
	$stale = $rows[0];
	$stale['content_hash'] = str_repeat( '0', 64 );
	$wrong_dimensions = $rows[0];
	$wrong_dimensions['embedding'] = pack( 'g*', 1.0 );
	$zero_norm = $rows[0];
	$zero_norm['embedding_norm'] = '0';
	$matches = $ranker->semantic_matches( array( 1.0, 0.0 ), 1.0, array_merge( $rows, array( $stale, $wrong_dimensions, $zero_norm ) ) );
	$assert_same( 3, $matches['stale'], 'Stale, malformed and zero-norm vectors were not counted.' );
	$assert_same( array( 2, 1, 3 ), array_column( $matches['posts'], 'id' ), 'Cosine similarity or recency tie-breaking changed.' );
	$assert_same( array( 1.0, 1.0, 0.0 ), array_column( $matches['posts'], 'semantic_score' ), 'Semantic score calculation changed.' );
	$assert_same( 5.0, Semantic_Post_Ranker::vector_norm( array( 3.0, 4.0 ) ), 'Vector norm changed.' );
	$assert_same( 0.0, Semantic_Post_Ranker::vector_norm( array( 0.0, 0.0 ) ), 'Zero-vector norm changed.' );
	$assert_same( array( 'posts' => array(), 'stale' => 0 ), $ranker->semantic_matches( array( 1.0, 0.0 ), 1.0, array() ), 'Empty candidates changed.' );
	foreach ( $matches['posts'] as $post ) {
		$assert_same( array(), array_intersect( array( 'embedding', 'embedding_norm', 'content_hash' ), array_keys( $post ) ), 'Internal vector fields leaked to results.' );
	}
	$terms = array(
		array( 'post_id' => 3, 'normalized' => 'gospel of luke', 'kind' => 'topic' ),
		array( 'post_id' => 3, 'normalized' => 'luke', 'kind' => 'alias' ),
		array( 'post_id' => 3, 'normalized' => 'luke', 'kind' => 'secondary' ),
		array( 'post_id' => 2, 'normalized' => 'gospel of luke', 'kind' => 'secondary' ),
	);
	$hybrid = $ranker->hybrid_rank( 'Luke', $matches['posts'], $terms );
	$assert_same( array( 1, 3, 2 ), array_column( $hybrid, 'id' ), 'Lexical/assigned-term rank fusion changed.' );
	$assert( abs( $hybrid[0]['retrieval_score'] - ( 1.0 / 62 + 0.8 / 61 ) ) < 1e-12, 'Lexical weight or rank constant changed.' );
	$assert( abs( $hybrid[1]['retrieval_score'] - ( 1.0 / 63 + 0.6 / 61 ) ) < 1e-12, 'Assigned-term weight or rank constant changed.' );
	$assert( abs( $hybrid[2]['retrieval_score'] - 1.0 / 61 ) < 1e-12, 'Secondary keywords incorrectly used topic anchor matching.' );
	$unmatched = $ranker->hybrid_rank( 'the and of', $matches['posts'], $terms );
	$assert_same( array( 2, 1, 3 ), array_column( $unmatched, 'id' ), 'Stopword-only queries no longer preserve semantic order.' );
	echo "Semantic ranker: vector validation, cosine scores, ties, fusion weights and term matching: OK\n";
} )();
