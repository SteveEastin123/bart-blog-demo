<?php
/**
 * Ask AI controlled-vocabulary term selection.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates, reconciles, and bounds model-selected search terms. */
final class AI_Term_Selector {
	/**
	 * Validates model-selected terms against exact database labels.
	 *
	 * @param mixed                                                                           $raw        Raw terms.
	 * @param array{topics:list<array{name:string,description:string}>,keywords:list<string>} $vocabulary Approved vocabulary.
	 * @param int                                                                             $max_terms  Maximum retained terms.
	 * @return list<array{label:string,mode:string}>
	 */
	public function validated_terms( mixed $raw, array $vocabulary, int $max_terms ): array {
		$topics = array();
		foreach ( $vocabulary['topics'] as $topic ) {
			$topics[ Search_Service::normalize( $topic['name'] ) ] = $topic['name'];
		}
		$keywords = array();
		foreach ( $vocabulary['keywords'] as $keyword ) {
			$keywords[ Search_Service::normalize( $keyword ) ] = $keyword;
		}
		$terms       = array();
		$seen        = array();
		$topic_count = 0;
		foreach ( is_array( $raw ) ? $raw : array() as $term ) {
			if ( ! is_array( $term ) || ! is_scalar( $term['label'] ?? null ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $term['label'] );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $topics[ $normalized ] ) ) {
				if ( $topic_count >= 2 ) {
					continue;
				}
				$terms[] = array(
					'label' => $topics[ $normalized ],
					'mode'  => Search_Service::TERM_MODE_TOPIC,
				);
				++$topic_count;
			} elseif ( isset( $keywords[ $normalized ] ) ) {
				$terms[] = array(
					'label' => $keywords[ $normalized ],
					'mode'  => Search_Service::TERM_MODE_KEYWORD,
				);
			} else {
				continue;
			}
			$seen[ $normalized ] = true;
			if ( count( $terms ) >= $max_terms ) {
				break;
			}
		}
		return $terms;
	}

	/**
	 * Validates explicitly named entities against exact keyword labels.
	 *
	 * @param mixed             $raw      Raw named entities.
	 * @param array<int,string> $keywords Approved keywords.
	 * @param int               $max_terms Maximum retained terms.
	 * @return list<array{label:string,mode:string}> Valid entities.
	 */
	public function validated_entities( mixed $raw, array $keywords, int $max_terms ): array {
		$approved = array();
		foreach ( $keywords as $keyword ) {
			$approved[ Search_Service::normalize( $keyword ) ] = (string) $keyword;
		}
		$entities = array();
		$seen     = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entity ) {
			if ( ! is_scalar( $entity ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( (string) $entity );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) || ! isset( $approved[ $normalized ] ) ) {
				continue;
			}
			$entities[]          = array(
				'label' => $approved[ $normalized ],
				'mode'  => Search_Service::TERM_MODE_KEYWORD,
			);
			$seen[ $normalized ] = true;
			if ( count( $entities ) >= $max_terms ) {
				break;
			}
		}
		return $entities;
	}

	/**
	 * Combines prioritized entity and interpreted terms without duplicates.
	 *
	 * @param list<array{label:string,mode:string}> $entities Explicit entities.
	 * @param list<array{label:string,mode:string}> $terms    Interpreted terms.
	 * @param int                                   $max_terms Maximum retained terms.
	 * @return list<array{label:string,mode:string}> Combined bounded terms.
	 */
	public function merge_terms( array $entities, array $terms, int $max_terms ): array {
		$merged = array();
		$seen   = array();
		foreach ( array_merge( $entities, $terms ) as $term ) {
			$normalized = Search_Service::normalize( $term['label'] );
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$merged[]            = $term;
			$seen[ $normalized ] = true;
			if ( count( $merged ) >= $max_terms ) {
				break;
			}
		}
		return $merged;
	}

	/**
	 * Promotes a selected keyword to topic mode when an identical topic exists.
	 *
	 * @param list<array{label:string,mode:string}>       $terms  Selected terms.
	 * @param list<array{name:string,description:string}> $topics Approved topics.
	 * @return list<array{label:string,mode:string}> Topic-preferred terms.
	 */
	public function prefer_topic_labels( array $terms, array $topics ): array {
		$topic_labels = array();
		foreach ( $topics as $topic ) {
			$topic_labels[ Search_Service::normalize( $topic['name'] ) ] = $topic['name'];
		}
		foreach ( $terms as &$term ) {
			$normalized = Search_Service::normalize( $term['label'] );
			if ( isset( $topic_labels[ $normalized ] ) ) {
				$term['label'] = $topic_labels[ $normalized ];
				$term['mode']  = Search_Service::TERM_MODE_TOPIC;
			}
		}
		unset( $term );
		return $terms;
	}

	/**
	 * Makes explicit literary comparisons between named Gospels deterministic.
	 *
	 * @param string                                      $question Reader question.
	 * @param list<array{label:string,mode:string}>       $terms    Interpreted terms.
	 * @param list<array{name:string,description:string}> $topics   Approved topics.
	 * @param int                                         $max_terms Maximum retained terms.
	 * @return list<array{label:string,mode:string}> Reconciled terms.
	 */
	public function reconcile_gospel_comparison( string $question, array $terms, array $topics, int $max_terms ): array {
		$relationship_pattern = '/\b(?:compare|compared|compares|comparing|differ|differed|differs|differing|different|differently|difference|differences|agree|agreed|agrees|agreeing|disagree|disagreed|disagrees|disagreeing|change|changed|changes|changing|alter|altered|alters|altering|edit|edited|edits|editing|rewrite|rewrites|rewriting|rewritten|copy|copied|copies|copying|use|used|uses|using|depend|depended|depends|depending|borrow|borrowed|borrows|borrowing|influence|influenced|influences|influencing|relationship|relationships|source|sources)\b/i';
		if ( ! preg_match( $relationship_pattern, $question ) ) {
			return $terms;
		}

		$topic_labels = array();
		foreach ( $topics as $topic ) {
			$topic_labels[ Search_Service::normalize( $topic['name'] ) ] = $topic['name'];
		}
		$gospels = array(
			'matthew' => 'Gospel of Matthew',
			'mark'    => 'Gospel of Mark',
			'luke'    => 'Gospel of Luke',
			'john'    => 'Gospel of John',
		);
		$named   = array();
		foreach ( $gospels as $short_name => $topic_name ) {
			if ( ! preg_match( '/\b(?:gospel\s+of\s+)?' . preg_quote( $short_name, '/' ) . '\b/i', $question, $match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$normalized = Search_Service::normalize( $topic_name );
			if ( isset( $topic_labels[ $normalized ] ) ) {
				$named[] = array(
					'offset' => (int) $match[0][1],
					'label'  => $topic_labels[ $normalized ],
				);
			}
		}
		if ( count( $named ) < 2 ) {
			return $terms;
		}

		usort( $named, static fn( array $left, array $right ): int => $left['offset'] <=> $right['offset'] );
		$reconciled = array();
		$seen       = array();
		foreach ( $named as $gospel ) {
			$normalized          = Search_Service::normalize( $gospel['label'] );
			$reconciled[]        = array(
				'label' => $gospel['label'],
				'mode'  => Search_Service::TERM_MODE_TOPIC,
			);
			$seen[ $normalized ] = true;
		}

		$method_topics       = array_map(
			array( Search_Service::class, 'normalize' ),
			array( 'Historical Methods (General)', 'Methods for Studying the Historical Jesus', 'Redaction Criticism', 'Source Criticism', 'Synoptic Problem' )
		);
		$normalized_question = Search_Service::normalize( $question );
		foreach ( $terms as $term ) {
			$normalized = Search_Service::normalize( $term['label'] );
			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( in_array( $normalized, $method_topics, true ) && ! str_contains( $normalized_question, $normalized ) ) {
				continue;
			}
			$reconciled[]        = $term;
			$seen[ $normalized ] = true;
			if ( count( $reconciled ) >= $max_terms ) {
				break;
			}
		}
		return $reconciled;
	}

	/**
	 * Keeps only selected terms that produce a useful AND search.
	 *
	 * @param list<array{label:string,mode:string}> $terms Validated terms.
	 * @return list<array{label:string,mode:string}> Compatible terms.
	 */
	public function compatible_terms( array $terms ): array {
		if ( count( $terms ) < 2 ) {
			return $terms;
		}
		$search   = new Search_Service();
		$accepted = array( $terms[0] );
		foreach ( array_slice( $terms, 1 ) as $term ) {
			$trial        = array_merge( $accepted, array( $term ) );
			$trial_result = $search->search( array_column( $trial, 'label' ), 'ranked', '', '', 1, 1, array_column( $trial, 'mode' ) );
			if ( $trial_result['count'] > 0 ) {
				$accepted = $trial;
			}
		}
		return $accepted;
	}

	/**
	 * Applies the configured interpretation limit.
	 *
	 * @param list<array{label:string,mode:string}> $terms Validated terms.
	 * @param int                                   $max_terms Maximum retained terms.
	 * @return list<array{label:string,mode:string}> Bounded terms.
	 */
	public function bounded_terms( array $terms, int $max_terms ): array {
		return array_slice( $terms, 0, $max_terms );
	}
}
