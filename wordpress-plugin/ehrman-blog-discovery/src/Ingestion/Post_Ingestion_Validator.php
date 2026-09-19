<?php
/**
 * Post-ingestion source input validation.
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
 * Validates submitted post data and delegates proposal validation.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 */
final class Post_Ingestion_Validator {
	private const MAX_POST_LENGTH  = 120000;
	private const MAX_TITLE_LENGTH = 1000;

	/**
	 * Proposal validator.
	 *
	 * @var Post_Ingestion_Proposal_Validator
	 */
	private Post_Ingestion_Proposal_Validator $proposal_validator;

	/**
	 * Creates the validator.
	 *
	 * @param Post_Ingestion_Proposal_Validator|null $proposal_validator Optional proposal validator.
	 */
	public function __construct( ?Post_Ingestion_Proposal_Validator $proposal_validator = null ) {
		$this->proposal_validator = $proposal_validator ?? new Post_Ingestion_Proposal_Validator();
	}

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
		return $this->proposal_validator->validate( $proposal, $vocabulary, $allow_held, $require_rationales, $post_title );
	}

	/**
	 * Converts form fields into a proposal record.
	 *
	 * @param array<string,mixed> $input Submitted proposal fields.
	 * @return array<string,mixed> Proposal record.
	 */
	public function proposal_from_input( array $input ): array {
		return $this->proposal_validator->from_input( $input );
	}

	/**
	 * Decodes a stored proposal.
	 *
	 * @param string $json Stored JSON.
	 * @return array<string,mixed> Decoded proposal.
	 */
	public function decode_proposal( string $json ): array {
		return $this->proposal_validator->decode( $json );
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
