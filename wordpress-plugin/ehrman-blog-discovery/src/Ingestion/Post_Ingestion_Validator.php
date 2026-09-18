<?php
/**
 * Post-ingestion input and proposal validation.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates submitted post data and canonicalizes proposed search metadata.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 */
final class Post_Ingestion_Validator {
	private const MAX_POST_LENGTH           = 120000;
	private const MAX_TITLE_LENGTH          = 1000;
	private const STATUS_HELD               = 'held';
	private const STATUS_READY              = 'ready';
	private const HOUSE_STYLE_OPENING_VERBS = array(
		'addresses',
		'analyzes',
		'announces',
		'answers',
		'applies',
		'argues',
		'asks',
		'assesses',
		'begins',
		'catalogs',
		'celebrates',
		'challenges',
		'clarifies',
		'collects',
		'compares',
		'concludes',
		'connects',
		'considers',
		'continues',
		'contrasts',
		'corrects',
		'criticizes',
		'critiques',
		'defines',
		'defends',
		'demonstrates',
		'describes',
		'develops',
		'discusses',
		'distinguishes',
		'evaluates',
		'examines',
		'explains',
		'explores',
		'frames',
		'gives',
		'highlights',
		'identifies',
		'interprets',
		'introduces',
		'investigates',
		'invites',
		'lists',
		'marks',
		'offers',
		'outlines',
		'places',
		'presents',
		'previews',
		'profiles',
		'proposes',
		'provides',
		'questions',
		'raises',
		'reassesses',
		'recalls',
		'recommends',
		'reconsiders',
		'reconstructs',
		'recounts',
		'rejects',
		'reflects',
		'remembers',
		'reminds',
		'reports',
		'reposts',
		'reproduces',
		'responds',
		'retells',
		'returns',
		'reviews',
		'revisits',
		'seeks',
		'shares',
		'shows',
		'sketches',
		'summarizes',
		'surveys',
		'traces',
		'uses',
	);

	/**
	 * Validates required post metadata and full text.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @phpstan-return ValidatedPost|WP_Error
	 */
	public function validate_submission( array $input ) {
		$source_wp_id = Database::integer( $input['source_wp_id'] ?? null );
		$title        = sanitize_text_field( Database::text( $input['title'] ?? null ) );
		$url          = esc_url_raw( Database::text( $input['url'] ?? null ), array( 'http', 'https' ) );
		$author       = sanitize_text_field( Database::text( $input['author'] ?? null ) );
		$date         = $this->parse_date( Database::text( $input['date'] ?? null ) );
		$post_text    = trim( wp_strip_all_tags( Database::text( $input['post_text'] ?? null ) ) );
		$post_text    = preg_replace( "/\r\n?|\n/", "\n", $post_text );
		$post_text    = is_string( $post_text ) ? $post_text : '';
		$errors       = array();
		if ( $source_wp_id < 1 ) {
			$errors[] = __( 'Enter a positive WordPress post ID.', 'ehrman-blog-discovery' );
		}
		if ( '' === $title ) {
			$errors[] = __( 'Enter the post title.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $title ) > self::MAX_TITLE_LENGTH ) {
			$errors[] = __( 'The post title is too long.', 'ehrman-blog-discovery' );
		}
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'Enter a valid post URL.', 'ehrman-blog-discovery' );
		}
		if ( '' === $author || $this->length( $author ) > 191 ) {
			$errors[] = __( 'Enter an author no longer than 191 characters.', 'ehrman-blog-discovery' );
		}
		if ( null === $date ) {
			$errors[] = __( 'Enter a valid publication date.', 'ehrman-blog-discovery' );
		}
		if ( '' === $post_text ) {
			$errors[] = __( 'Paste the complete post text.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $post_text ) > self::MAX_POST_LENGTH ) {
			$errors[] = __( 'The post text exceeds the 120,000-character limit.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $errors ) || null === $date ) {
			return new WP_Error( 'ehrman_ingestion_invalid_post', implode( ' ', $errors ) );
		}
		return array(
			'source_wp_id' => $source_wp_id,
			'title'        => $title,
			'url'          => $url,
			'author'       => $author,
			'date_text'    => $date['display'],
			'published_at' => $date['published'],
			'post_text'    => $post_text,
		);
	}

	/**
	 * Validates and canonicalizes an AI or administrator proposal.
	 *
	 * @param array<string,mixed> $proposal           Candidate proposal.
	 * @param array<string,mixed> $vocabulary         Approved topic and keyword vocabulary.
	 * @param bool                $allow_held         Whether held proposals are valid.
	 * @param bool                $require_rationales Whether every selected topic requires AI review evidence.
	 * @param string              $post_title         Post title used to prioritize possible missing-topic warnings.
	 * @phpstan-param Vocabulary $vocabulary
	 * @phpstan-return Proposal|WP_Error
	 */
	public function validate_proposal( array $proposal, array $vocabulary, bool $allow_held, bool $require_rationales = false, string $post_title = '' ) {
		$description = $this->one_line( Database::text( $proposal['description'] ?? null ) );
		$summary     = $this->one_line( Database::text( $proposal['searchSummary'] ?? null ) );
		$status      = sanitize_key( Database::text( $proposal['status'] ?? null ) );
		$errors      = array();
		$style_notes = array();
		if ( '' === $description ) {
			$errors[] = __( 'Description is required.', 'ehrman-blog-discovery' );
		} elseif ( $this->word_count( $description ) >= 30 ) {
			$errors[] = __( 'Description must contain fewer than 30 words.', 'ehrman-blog-discovery' );
		} elseif ( ! $this->has_house_style_opening( $description ) ) {
			$errors[] = __( 'Description must begin with an approved active, present-tense verb.', 'ehrman-blog-discovery' );
		}
		$description_words = $this->word_count( $description );
		if ( '' !== $description && ( $description_words < 18 || $description_words > 23 ) ) {
			$style_notes[] = sprintf(
				/* translators: %d: description word count. */
				__( 'Style warning: Description has %d words; house style is normally 18-23 words.', 'ehrman-blog-discovery' ),
				$description_words
			);
		}
		if ( '' === $summary ) {
			$errors[] = __( 'Search summary is required.', 'ehrman-blog-discovery' );
		} elseif ( $this->length( $summary ) > 1200 ) {
			$errors[] = __( 'Search summary must not exceed 1,200 characters.', 'ehrman-blog-discovery' );
		} elseif ( ! $this->has_house_style_opening( $summary ) ) {
			$errors[] = __( 'Search summary must begin with an approved active, present-tense verb.', 'ehrman-blog-discovery' );
		}
		if ( '' !== $summary && $this->first_sentence_is_question( $summary ) ) {
			$errors[] = __( 'Search summary must open with a declarative statement, not a question.', 'ehrman-blog-discovery' );
		}
		$summary_words = $this->word_count( $summary );
		if ( '' !== $summary && ( $summary_words < 60 || $summary_words > 80 ) ) {
			$style_notes[] = sprintf(
				/* translators: %d: search-summary word count. */
				__( 'Style warning: Search summary has %d words; house style is normally 60-80 words.', 'ehrman-blog-discovery' ),
				$summary_words
			);
		}
		$summary_sentences = $this->sentence_count( $summary );
		if ( '' !== $summary && 3 !== $summary_sentences ) {
			$style_notes[] = sprintf(
				/* translators: %d: search-summary sentence count. */
				__( 'Style warning: Search summary has %d sentences; house style normally uses three.', 'ehrman-blog-discovery' ),
				$summary_sentences
			);
		}
		if ( ! in_array( $status, array( self::STATUS_READY, self::STATUS_HELD ), true ) ) {
			$errors[] = __( 'Proposal status must be ready or held.', 'ehrman-blog-discovery' );
		}
		if ( ! $allow_held && self::STATUS_HELD === $status ) {
			$errors[] = __( 'A held proposal cannot be approved.', 'ehrman-blog-discovery' );
		}

		$topic_map       = array();
		$topic_alias_map = array();
		$topic_match_map = array();
		$keyword_map     = array();
		foreach ( $vocabulary['topics'] as $topic ) {
			$topic_name                     = $topic['name'];
			$normalized_topic               = Search_Service::normalize( $topic_name );
			$topic_map[ $normalized_topic ] = $topic_name;
			$topic_match_map[ $normalized_topic ][ $topic_name ] = true;
			foreach ( $topic['aliases'] as $alias ) {
				$normalized_alias = Search_Service::normalize( $alias );
				if ( '' !== $normalized_alias ) {
					$topic_alias_map[ $topic_name ][ $normalized_alias ] = true;
					$topic_match_map[ $normalized_alias ][ $topic_name ] = true;
				}
			}
		}
		foreach ( $vocabulary['keywords'] as $keyword ) {
			$keyword_map[ $keyword['normalized'] ] = $keyword['label'];
		}
		$topics           = $this->canonical_labels( $proposal['topics'] ?? array(), $topic_map, $errors, 'topic' );
		$topic_rationales = $this->topic_rationales( $proposal['topicRationales'] ?? array(), $topic_map, $topics, $errors, $require_rationales );
		if ( self::STATUS_READY === $status && empty( $topics ) ) {
			$errors[] = __( 'A ready proposal requires at least one existing topic.', 'ehrman-blog-discovery' );
		}
		if ( in_array( 'Ignore', $topics, true ) && count( $topics ) > 1 ) {
			$errors[] = __( 'Ignore cannot be combined with another topic.', 'ehrman-blog-discovery' );
		}

		$keywords               = $this->canonical_labels( $proposal['secondaryKeywords'] ?? array(), $keyword_map, $errors, 'secondary keyword' );
		$new_keywords           = $this->new_keyword_labels( $proposal['newSecondaryKeywords'] ?? array(), $keyword_map, $errors );
		$topic_norms            = array_fill_keys( array_map( array( Search_Service::class, 'normalize' ), $topics ), true );
		$assigned_topic_names   = array_fill_keys( $topics, true );
		$assigned_topic_aliases = array();
		$central_text           = Search_Service::normalize( $post_title . ' ' . $description . ' ' . $this->first_sentence( $summary ) );
		foreach ( $topics as $topic ) {
			foreach ( array_keys( $topic_alias_map[ $topic ] ?? array() ) as $normalized_alias ) {
				$assigned_topic_aliases[ $normalized_alias ] = $topic;
			}
		}
		$keyword_seen          = array();
		$missing_topic_matches = array();
		foreach ( array_merge( $keywords, $new_keywords ) as $keyword ) {
			$normalized = Search_Service::normalize( $keyword );
			if ( isset( $topic_norms[ $normalized ] ) ) {
				/* translators: %s: secondary keyword label. */
				$errors[] = sprintf( __( 'Secondary keyword "%s" duplicates an assigned topic.', 'ehrman-blog-discovery' ), $keyword );
			}
			if ( isset( $assigned_topic_aliases[ $normalized ] ) ) {
				$style_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: assigned topic label. */
					__( 'Review warning: Secondary keyword "%1$s" overlaps an alias of assigned topic "%2$s".', 'ehrman-blog-discovery' ),
					$keyword,
					$assigned_topic_aliases[ $normalized ]
				);
			}
			foreach ( array_keys( $topic_match_map[ $normalized ] ?? array() ) as $matching_topic ) {
				if ( isset( $assigned_topic_names[ $matching_topic ] ) ) {
					continue;
				}
				$topic_normalized = Search_Service::normalize( $matching_topic );
				$is_strong        = $this->contains_normalized_phrase( $central_text, $normalized )
					|| $this->contains_normalized_phrase( $central_text, $topic_normalized );
				$current          = $missing_topic_matches[ $matching_topic ] ?? null;
				if ( ! is_array( $current ) || ( $is_strong && empty( $current['strong'] ) ) ) {
					$missing_topic_matches[ $matching_topic ] = array(
						'keyword' => $keyword,
						'strong'  => $is_strong,
					);
				}
			}
			if ( isset( $keyword_seen[ $normalized ] ) ) {
				/* translators: %s: secondary keyword label. */
				$errors[] = sprintf( __( 'Secondary keyword "%s" is duplicated.', 'ehrman-blog-discovery' ), $keyword );
			}
			$keyword_seen[ $normalized ] = true;
		}
		ksort( $missing_topic_matches, SORT_NATURAL | SORT_FLAG_CASE );
		$strong_topic_notes   = array();
		$advisory_topic_notes = array();
		foreach ( $missing_topic_matches as $matching_topic => $match ) {
			if ( ! empty( $match['strong'] ) ) {
				$strong_topic_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: unassigned topic label. */
					__( 'Strong review warning: Possible missing topic "%2$s": matching keyword "%1$s" also appears in the title, description, or opening summary sentence.', 'ehrman-blog-discovery' ),
					Database::text( $match['keyword'] ),
					$matching_topic
				);
			} else {
				$advisory_topic_notes[] = sprintf(
					/* translators: 1: secondary keyword label, 2: unassigned topic label. */
					__( 'Advisory review warning: Secondary keyword "%1$s" matches unassigned topic "%2$s"; confirm that it is supporting rather than central.', 'ehrman-blog-discovery' ),
					Database::text( $match['keyword'] ),
					$matching_topic
				);
			}
		}

		$proposal_notes = array();
		foreach ( is_array( $proposal['reviewNotes'] ?? null ) ? $proposal['reviewNotes'] : array() as $note ) {
			$clean = sanitize_text_field( is_scalar( $note ) ? (string) $note : '' );
			if ( '' !== $clean && ! in_array( $clean, $proposal_notes, true ) ) {
				$proposal_notes[] = $clean;
			}
		}
		$notes = array_values( array_unique( array_merge( $strong_topic_notes, $advisory_topic_notes, $style_notes, $proposal_notes ) ) );
		if ( self::STATUS_HELD === $status && empty( $proposal_notes ) ) {
			$errors[] = __( 'A held proposal requires a review note explaining why no topic fits.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $new_keywords ) && empty( $proposal_notes ) ) {
			$errors[] = __( 'A new secondary keyword requires a review note.', 'ehrman-blog-discovery' );
		}
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'ehrman_ingestion_invalid_proposal', implode( ' ', array_values( array_unique( $errors ) ) ) );
		}
		return array(
			'description'          => $description,
			'searchSummary'        => $summary,
			'topics'               => $topics,
			'topicRationales'      => $topic_rationales,
			'secondaryKeywords'    => $keywords,
			'newSecondaryKeywords' => $new_keywords,
			'status'               => $status,
			'reviewNotes'          => $notes,
		);
	}

	/**
	 * Converts form fields into a proposal record.
	 *
	 * @param array<string,mixed> $input Submitted proposal fields.
	 * @return array<string,mixed> Proposal record.
	 */
	public function proposal_from_input( array $input ): array {
		$topic_rationales = json_decode( Database::text( $input['topic_rationales_json'] ?? null ), true );
		return array(
			'description'          => Database::text( $input['description'] ?? null ),
			'searchSummary'        => Database::text( $input['search_summary'] ?? null ),
			'topics'               => is_array( $input['topics'] ?? null ) ? $input['topics'] : array(),
			'topicRationales'      => is_array( $topic_rationales ) ? $topic_rationales : array(),
			'secondaryKeywords'    => $this->lines( Database::text( $input['secondary_keywords'] ?? null ) ),
			'newSecondaryKeywords' => $this->lines( Database::text( $input['new_secondary_keywords'] ?? null ) ),
			'status'               => Database::text( $input['status'] ?? null ),
			'reviewNotes'          => $this->lines( Database::text( $input['review_notes'] ?? null ) ),
		);
	}

	/**
	 * Decodes a stored proposal.
	 *
	 * @param string $json Stored JSON.
	 * @return array<string,mixed> Decoded proposal.
	 */
	public function decode_proposal( string $json ): array {
		$decoded = Database::associative_row( json_decode( $json, true ) );
		return null === $decoded ? array() : $decoded;
	}

	/**
	 * Maps submitted labels to exact vocabulary labels.
	 *
	 * @param mixed                $raw     Submitted labels.
	 * @param array<string,string> $map     Normalized-to-canonical labels.
	 * @param array<int,string>    $errors  Validation errors, updated by reference.
	 * @param string               $kind    Human-readable label kind.
	 * @return list<string> Canonical labels.
	 */
	private function canonical_labels( $raw, array $map, array &$errors, string $kind ): array {
		$labels = array();
		$seen   = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$label      = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			$normalized = Search_Service::normalize( $label );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( ! isset( $map[ $normalized ] ) ) {
				/* translators: 1: vocabulary kind, 2: submitted label. */
				$errors[] = sprintf( __( 'Unknown %1$s "%2$s".', 'ehrman-blog-discovery' ), $kind, $label );
				continue;
			}
			$labels[]            = $map[ $normalized ];
			$seen[ $normalized ] = true;
		}
		return $labels;
	}

	/**
	 * Validates topic-specific review evidence and orders it with selected topics.
	 *
	 * @param mixed                $raw         Submitted rationale records.
	 * @param array<string,string> $topic_map   Normalized-to-canonical topic labels.
	 * @param array                $topics      Selected canonical topics.
	 * @param array<int,string>    $errors      Validation errors, updated by reference.
	 * @param bool                 $require_all Whether each topic requires a rationale.
	 * @return list<array{topic:string,rationale:string}> Canonical rationale records.
	 * @phpstan-param list<string> $topics
	 */
	private function topic_rationales( $raw, array $topic_map, array $topics, array &$errors, bool $require_all ): array {
		$assigned = array_fill_keys( $topics, true );
		$by_topic = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$row = Database::associative_row( $value );
			if ( null === $row ) {
				$errors[] = __( 'Each topic rationale must contain a topic and rationale.', 'ehrman-blog-discovery' );
				continue;
			}
			$submitted_topic = sanitize_text_field( Database::text( $row['topic'] ?? null ) );
			$normalized      = Search_Service::normalize( $submitted_topic );
			if ( '' === $normalized || ! isset( $topic_map[ $normalized ] ) ) {
				/* translators: %s: submitted topic label. */
				$errors[] = sprintf( __( 'Unknown topic rationale label "%s".', 'ehrman-blog-discovery' ), $submitted_topic );
				continue;
			}
			$topic     = $topic_map[ $normalized ];
			$rationale = $this->one_line( Database::text( $row['rationale'] ?? null ) );
			if ( '' === $rationale ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is required.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			if ( $this->length( $rationale ) > 500 ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" must not exceed 500 characters.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			if ( ! isset( $assigned[ $topic ] ) ) {
				if ( $require_all ) {
					/* translators: %s: canonical topic label. */
					$errors[] = sprintf( __( 'Topic rationale "%s" does not correspond to an assigned topic.', 'ehrman-blog-discovery' ), $topic );
				}
				continue;
			}
			if ( isset( $by_topic[ $topic ] ) ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is duplicated.', 'ehrman-blog-discovery' ), $topic );
				continue;
			}
			$by_topic[ $topic ] = $rationale;
		}
		$rationales = array();
		foreach ( $topics as $topic ) {
			if ( isset( $by_topic[ $topic ] ) ) {
				$rationales[] = array(
					'topic'     => $topic,
					'rationale' => $by_topic[ $topic ],
				);
			} elseif ( $require_all ) {
				/* translators: %s: canonical topic label. */
				$errors[] = sprintf( __( 'Topic rationale for "%s" is required.', 'ehrman-blog-discovery' ), $topic );
			}
		}
		return $rationales;
	}

	/**
	 * Validates genuinely new keyword labels.
	 *
	 * @param mixed                $raw      Submitted labels.
	 * @param array<string,string> $existing Existing normalized labels.
	 * @param array<int,string>    $errors   Validation errors, updated by reference.
	 * @return list<string> New labels.
	 */
	private function new_keyword_labels( $raw, array $existing, array &$errors ): array {
		$labels = array();
		$seen   = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			$label      = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			$normalized = Search_Service::normalize( $label );
			if ( '' === $normalized || isset( $seen[ $normalized ] ) ) {
				continue;
			}
			if ( isset( $existing[ $normalized ] ) ) {
				/* translators: %s: submitted secondary keyword label. */
				$errors[] = sprintf( __( '"%s" already exists and must be listed as an existing secondary keyword.', 'ehrman-blog-discovery' ), $label );
				continue;
			}
			if ( $this->length( $label ) > 191 || $this->length( $normalized ) > 191 ) {
				/* translators: %s: submitted secondary keyword label. */
				$errors[] = sprintf( __( 'New secondary keyword "%s" is too long.', 'ehrman-blog-discovery' ), $label );
				continue;
			}
			$labels[]            = $label;
			$seen[ $normalized ] = true;
		}
		return $labels;
	}

	/**
	 * Returns trimmed, nonempty textarea lines.
	 *
	 * @param string $text Textarea value.
	 * @return list<string> Nonempty lines.
	 */
	private function lines( string $text ): array {
		$lines = preg_split( '/\R+/', $text );
		return array_values(
			array_filter(
				array_map( 'trim', is_array( $lines ) ? $lines : array() ),
				static fn( string $line ): bool => '' !== $line
			)
		);
	}

	/**
	 * Collapses line breaks and repeated whitespace.
	 *
	 * @param string $text Text to normalize.
	 */
	private function one_line( string $text ): string {
		$clean = sanitize_text_field( $text );
		$clean = preg_replace( '/\s+/', ' ', $clean );
		return is_string( $clean ) ? trim( $clean ) : '';
	}

	/**
	 * Returns whether text begins with an established active-verb opener.
	 *
	 * @param string $text Text to inspect.
	 */
	private function has_house_style_opening( string $text ): bool {
		$matched = preg_match( '/^([A-Za-z]+)\b/', trim( $text ), $matches );
		if ( 1 !== $matched ) {
			return false;
		}
		return in_array( strtolower( $matches[1] ), self::HOUSE_STYLE_OPENING_VERBS, true );
	}

	/**
	 * Returns whether the opening sentence is phrased as a question.
	 *
	 * @param string $text Text to inspect.
	 */
	private function first_sentence_is_question( string $text ): bool {
		return 1 === preg_match( '/^[^.!?]*\?/', trim( $text ) );
	}

	/**
	 * Returns the opening sentence or the complete text when no terminator exists.
	 *
	 * @param string $text Text to inspect.
	 */
	private function first_sentence( string $text ): string {
		$parts = preg_split( '/(?<=[.!?])(?:["”’\']+)?\s+/u', trim( $text ), 2 );
		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : trim( $text );
	}

	/**
	 * Returns whether normalized text contains a complete normalized phrase.
	 *
	 * @param string $text   Normalized text to inspect.
	 * @param string $phrase Complete normalized phrase.
	 */
	private function contains_normalized_phrase( string $text, string $phrase ): bool {
		return '' !== $phrase && str_contains( ' ' . $text . ' ', ' ' . $phrase . ' ' );
	}

	/**
	 * Counts sentences using terminal punctuation and a following sentence start.
	 *
	 * @param string $text Text to count.
	 */
	private function sentence_count( string $text ): int {
		$clean = trim( $text );
		if ( '' === $clean ) {
			return 0;
		}
		$sentences = preg_split( '/[.!?](?:["”’\']+)?\s+(?=[A-Z0-9“"‘\'])/u', $clean );
		return count( array_filter( is_array( $sentences ) ? $sentences : array(), static fn( string $sentence ): bool => '' !== $sentence ) );
	}

	/**
	 * Counts whitespace-delimited words.
	 *
	 * @param string $text Text to count.
	 */
	private function word_count( string $text ): int {
		$words = preg_split( '/\s+/', trim( $text ) );
		return '' === trim( $text ) ? 0 : count( is_array( $words ) ? $words : array() );
	}

	/**
	 * Returns a multibyte-safe character count.
	 *
	 * @param string $text Text to count.
	 */
	private function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Parses an ISO publication date.
	 *
	 * @param string $value Date value.
	 * @return array{display:string,published:string}|null Parsed date.
	 */
	private function parse_date( string $value ): ?array {
		$value = trim( $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return array(
			'display'   => $date->format( 'F j, Y' ),
			'published' => $date->format( 'Y-m-d 00:00:00' ),
		);
	}
}
