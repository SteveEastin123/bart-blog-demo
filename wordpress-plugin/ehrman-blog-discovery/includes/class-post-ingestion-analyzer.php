<?php
/**
 * Post-ingestion AI analysis.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one post to the Responses API and returns validated search metadata.
 *
 * @phpstan-type ValidatedPost array{source_wp_id:int,title:string,url:string,author:string,date_text:string,published_at:string,post_text:string}
 * @phpstan-type TopicRecord array{id:int,name:string,description:string,aliases:list<string>}
 * @phpstan-type KeywordRecord array{id:int,label:string,normalized:string,count:int}
 * @phpstan-type Vocabulary array{topics:list<TopicRecord>,keywords:list<KeywordRecord>}
 * @phpstan-type TopicRationale array{topic:string,rationale:string}
 * @phpstan-type Proposal array{description:string,searchSummary:string,topics:list<string>,topicRationales:list<TopicRationale>,secondaryKeywords:list<string>,newSecondaryKeywords:list<string>,status:string,reviewNotes:list<string>}
 * @phpstan-type AnalysisMetrics array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
 * @phpstan-type AnalysisResult array{proposal:Proposal,metrics:AnalysisMetrics}
 */
final class Post_Ingestion_Analyzer {
	private const INITIAL_MAX_OUTPUT_TOKENS = 16000;
	private const RETRY_MAX_OUTPUT_TOKENS   = 32000;

	/**
	 * Proposal validator.
	 *
	 * @var Post_Ingestion_Validator
	 */
	private Post_Ingestion_Validator $validator;

	/**
	 * Creates the analyzer.
	 *
	 * @param Post_Ingestion_Validator|null $validator Optional proposal validator.
	 */
	public function __construct( ?Post_Ingestion_Validator $validator = null ) {
		$this->validator = $validator ?? new Post_Ingestion_Validator();
	}

	/**
	 * Builds and sends one structured editorial-analysis request.
	 *
	 * @param array<string,mixed> $post          Validated post.
	 * @param array<string,mixed> $taxonomy      Vocabulary.
	 * @param string              $taxonomy_hash Taxonomy fingerprint.
	 * @param int                 $user_id       Administrator identifier.
	 * @phpstan-param ValidatedPost $post
	 * @phpstan-param Vocabulary $taxonomy
	 * @phpstan-return AnalysisResult|WP_Error
	 */
	public function analyze( array $post, array $taxonomy, string $taxonomy_hash, int $user_id ) {
		$limits             = array( self::INITIAL_MAX_OUTPUT_TOKENS, self::RETRY_MAX_OUTPUT_TOKENS );
		$combined_metrics   = null;
		$last_invalid_error = null;
		foreach ( $limits as $attempt => $max_output_tokens ) {
			$payload = $this->request_payload( $post, $taxonomy, $taxonomy_hash, $user_id, $max_output_tokens );
			$encoded = wp_json_encode( $payload );
			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'ehrman_ingestion_request_error', __( 'The post could not be prepared for analysis.', 'ehrman-blog-discovery' ) );
			}
			$response = wp_remote_post(
				Post_Ingestion_Settings::api_url(),
				array(
					'timeout' => 180,
					'headers' => array(
						'Authorization' => 'Bearer ' . Post_Ingestion_Settings::api_key(),
						'Content-Type'  => 'application/json',
					),
					'body'    => $encoded,
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ehrman_ingestion_unavailable', __( 'The ingestion analysis service is temporarily unavailable.', 'ehrman-blog-discovery' ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			$body   = Database::associative_row( json_decode( wp_remote_retrieve_body( $response ), true ) );
			if ( null === $body || $status < 200 || $status >= 300 ) {
				return new WP_Error( 'ehrman_ingestion_response_error', __( 'The ingestion analysis service returned an error.', 'ehrman-blog-discovery' ) );
			}
			$metrics          = $this->response_metrics( $body );
			$combined_metrics = null === $combined_metrics ? $metrics : $this->combine_metrics( $combined_metrics, $metrics );
			$output           = Database::associative_row( json_decode( $this->output_text( $body ), true ) );
			$proposal         = null !== $output
				? $this->validator->validate_proposal( $output, $taxonomy, true, true, $post['title'] )
				: null;
			if ( is_array( $proposal ) ) {
				return array(
					'proposal' => $proposal,
					'metrics'  => $combined_metrics,
				);
			}

			$last_invalid_error = is_wp_error( $proposal ) ? $proposal : null;
			$incomplete         = Database::associative_row( $body['incomplete_details'] ?? null );
			$reason             = sanitize_key( Database::text( $incomplete['reason'] ?? null ) );
			if ( 'max_output_tokens' === $reason && 0 === $attempt ) {
				continue;
			}
			if ( 'max_output_tokens' === $reason ) {
				return new WP_Error(
					'ehrman_ingestion_output_limit',
					__( 'The ingestion analysis reached the expanded response limit before completing structured data.', 'ehrman-blog-discovery' )
				);
			}
			break;
		}

		$message = is_wp_error( $last_invalid_error )
			? $last_invalid_error->get_error_message()
			: __( 'The ingestion analysis service returned invalid structured data.', 'ehrman-blog-discovery' );
		return new WP_Error( 'ehrman_ingestion_invalid_output', $message );
	}

	/**
	 * Creates the Responses API payload.
	 *
	 * @param array<string,mixed> $post              Validated post.
	 * @param array<string,mixed> $taxonomy          Vocabulary.
	 * @param string              $taxonomy_hash     Taxonomy fingerprint.
	 * @param int                 $user_id           Administrator identifier.
	 * @param int                 $max_output_tokens Maximum generated-token allowance.
	 * @return array<string,mixed> API request payload.
	 * @phpstan-param ValidatedPost $post
	 * @phpstan-param Vocabulary $taxonomy
	 */
	private function request_payload( array $post, array $taxonomy, string $taxonomy_hash, int $user_id, int $max_output_tokens ): array {
		$instructions  = implode(
			"\n\n",
			array(
				'Analyze the complete Bart Ehrman Blog post for a curated search index. Use the full text, not the title, excerpt, source categories, or generated tags alone. Return only the requested structured fields.',
				'DESCRIPTION: Write one natural sentence, generally 18-23 words and always under 30. Begin with an active, present-tense verb and state the post’s central argument or purpose without merely repeating the title. Do not begin with Ehrman, Bart, This post, The post, an author name, a quotation, or a question word.',
				'SEARCH SUMMARY: Write normally three clear sentences and about 60-80 words. Begin with an active, present-tense verb and a declarative statement. State the central inquiry or argument first, the principal evidence or reasoning next, and the conclusion or implication last. Include concrete names, texts, events, or claims when they materially improve semantic retrieval. Do not begin with an author name or a question, and do not infer content absent from the supplied text.',
				'CLASSIFICATION GOAL: Preserve every distinct central subject while eliminating redundant and incidental labels. Do not aim for either many labels or few labels. Select the complete, nonredundant topic set plus every genuinely useful supporting keyword. Assign every existing topic that represents a primary, sustained subject or a substantial independent section. Do not omit a specific topic merely because a broader topic or keyword partially covers it.',
				'TOPIC DECISION PROCESS: First identify the distinct subjects developed across the complete post. Treat a multipart post’s substantial independent sections separately. For each subject, compare all plausible existing topic names and descriptions and choose the closest label. Add another topic only when it represents a separate central subject or substantial section that the selected topics do not already cover. Prefer a specific topic for a specific central subject. Keep a broader topic alongside it only when the broader topic covers other substantial material; never let an umbrella topic hide a distinct major section merely to reduce the count. Never invent or recommend a topic.',
				'TOPIC ELIGIBILITY TEST: Include a topic only when all three conditions hold: (1) it is discussed throughout the post or within a substantial independent section; (2) it is necessary to describe what that material is principally about; and (3) a reader browsing that topic would reasonably expect this post among the results. Mere mention, contextual background, or use as a source, example, or comparison does not pass this test.',
				'TOPIC COVERAGE CHECK: Before selecting keywords, identify internally every central subject and substantial independent section in the complete post. Verify that each is represented by its closest suitable existing topic. Reconsider any central subject represented only by a keyword, especially when that keyword matches or closely corresponds to an existing topic or topic alias. Do not output this internal checklist.',
				'TOPIC RATIONALES: For every selected topic, return exactly one topicRationales object using the canonical topic name and one short sentence explaining which primary, sustained material or substantial independent section passes the topic eligibility test. Return an empty topicRationales array only when no topic is selected. These rationales are review evidence, not additional topics or keyword justifications.',
				'SUBJECTS VERSUS EVIDENCE: A topic states what the post investigates, explains, or argues. A biblical book, author, person, text, event, or concept used chiefly as evidence, comparison, or example is not thereby a topic. It may be a secondary keyword when materially useful for retrieval. Assign a named biblical-book topic when the book’s composition, distinctive presentation, theology, interpretation, textual history, or reliability is itself a sustained inquiry, not simply because passages from it support another question.',
				'SECONDARY KEYWORDS: Select the smallest useful set of existing labels that makes the post discoverable beyond its assigned topics. Keep a label only if all three conditions hold: (1) the post discusses it beyond a passing reference; (2) it materially contributes to the argument, narrative, evidence, or a substantial section; and (3) a reader searching for that label alone would reasonably expect this post among useful results. Omit background and promotional details, incidental examples, and labels inferred only from the analytical method or genre. Do not add a person, text, place, event, or concept merely because it is named or quoted. A repeatedly used source may qualify when it carries the argument. Reject duplicates and labels equivalent to an assigned topic or any supplied topic alias. There is no fixed limit, but recheck an unusually long list term by term; more labels are not inherently better.',
				'NEW KEYWORDS: Check the entire existing vocabulary for the exact term, variants, and reasonable synonyms. Propose a new keyword only when the concept is central or materially supporting, likely to be reused for search, and lacks an adequate existing label. Do not create a new umbrella term that merely restates the title or central question when existing topics and keywords already retrieve it. An existing person or author keyword normally makes a new keyword for a cited work unnecessary unless the work is independently central and broadly reusable. Put new labels only in newSecondaryKeywords and explain each one in reviewNotes.',
				'EDGE CASES: Use Ignore only for genuinely administrative, promotional, or nonsubstantive posts. An interview, podcast, video, broadcast, or documentary introduction remains substantive for Media Interviews and Videos even when no transcript is supplied; advertised subjects can be keywords but should not become topics without substantive text. If a substantive post has no suitable existing topic, return held with no topics and explain why in reviewNotes.',
				'FINAL CHECK: Confirm that every distinct central subject has a topic; every topic adds coverage not supplied by a closer selected topic; sources and examples have not been promoted into topics; every keyword passes all three search-result expectation conditions and is nonredundant; every new keyword is unavoidable; and both prose fields follow the established opening and length style.',
			)
		);
		$taxonomy_json = wp_json_encode( $taxonomy );
		$metadata_json = wp_json_encode(
			array(
				'wpId'   => $post['source_wp_id'],
				'title'  => $post['title'],
				'url'    => $post['url'],
				'author' => $post['author'],
				'date'   => $post['date_text'],
			)
		);
		$input         = "Existing vocabulary (topic descriptions govern topic scope):\n"
			. ( is_string( $taxonomy_json ) ? $taxonomy_json : '{}' )
			. "\n\nPost metadata:\n"
			. ( is_string( $metadata_json ) ? $metadata_json : '{}' )
			. "\n\nComplete post text:\n"
			. $post['post_text'];
		return array(
			'model'             => Post_Ingestion_Settings::model_id(),
			'reasoning'         => array( 'effort' => Post_Ingestion_Settings::reasoning_effort() ),
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => $max_output_tokens,
			'store'             => false,
			'prompt_cache_key'  => 'ehrman-ingestion-v' . Post_Ingestion_Settings::prompt_version() . '-' . substr( $taxonomy_hash, 0, 32 ),
			'safety_identifier' => hash_hmac( 'sha256', (string) max( 0, $user_id ), wp_salt( 'auth' ) ),
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'ehrman_post_ingestion',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'description', 'searchSummary', 'topics', 'topicRationales', 'secondaryKeywords', 'newSecondaryKeywords', 'status', 'reviewNotes' ),
						'properties'           => array(
							'description'          => array( 'type' => 'string' ),
							'searchSummary'        => array( 'type' => 'string' ),
							'topics'               => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'topicRationales'      => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'topic', 'rationale' ),
									'properties'           => array(
										'topic'     => array( 'type' => 'string' ),
										'rationale' => array( 'type' => 'string' ),
									),
								),
							),
							'secondaryKeywords'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'newSecondaryKeywords' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'status'               => array(
								'type' => 'string',
								'enum' => array( 'ready', 'held' ),
							),
							'reviewNotes'          => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Extracts the structured text from a Responses API body.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 */
	private function output_text( array $body ): string {
		if ( is_string( $body['output_text'] ?? null ) ) {
			return $body['output_text'];
		}
		foreach ( is_array( $body['output'] ?? null ) ? $body['output'] : array() as $output ) {
			$output_row = Database::associative_row( $output );
			if ( null === $output_row ) {
				continue;
			}
			foreach ( is_array( $output_row['content'] ?? null ) ? $output_row['content'] : array() as $content ) {
				$content_row = Database::associative_row( $content );
				if ( null !== $content_row && is_string( $content_row['text'] ?? null ) ) {
					return $content_row['text'];
				}
			}
		}
		return '';
	}

	/**
	 * Extracts token metrics and estimates the supported model cost.
	 *
	 * @param array<string,mixed> $body Decoded response body.
	 * @return array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
	 */
	private function response_metrics( array $body ): array {
		$usage          = is_array( $body['usage'] ?? null ) ? $body['usage'] : array();
		$input_details  = is_array( $usage['input_tokens_details'] ?? null ) ? $usage['input_tokens_details'] : array();
		$output_details = is_array( $usage['output_tokens_details'] ?? null ) ? $usage['output_tokens_details'] : array();
		$input_tokens   = Database::integer( $usage['input_tokens'] ?? null );
		$cached_tokens  = min( $input_tokens, Database::integer( $input_details['cached_tokens'] ?? null ) );
		$output_tokens  = Database::integer( $usage['output_tokens'] ?? null );
		$reasoning      = min( $output_tokens, Database::integer( $output_details['reasoning_tokens'] ?? null ) );
		$uncached       = max( 0, $input_tokens - $cached_tokens );
		$cost           = 0.0;
		if ( str_starts_with( Post_Ingestion_Settings::model_id(), 'gpt-5.6-sol' ) || 'gpt-5.6' === Post_Ingestion_Settings::model_id() ) {
			$cost = ( ( $uncached * 4.0 ) + ( $cached_tokens * 0.4 ) + ( $output_tokens * 20.0 ) ) / 1000000;
		}
		return array(
			'response_id'         => sanitize_text_field( Database::text( $body['id'] ?? null ) ),
			'input_tokens'        => max( 0, $input_tokens ),
			'cached_input_tokens' => max( 0, $cached_tokens ),
			'output_tokens'       => max( 0, $output_tokens ),
			'reasoning_tokens'    => max( 0, $reasoning ),
			'estimated_cost_usd'  => $cost,
		);
	}

	/**
	 * Combines billable usage when an output-limit retry was required.
	 *
	 * @param array<string,mixed> $first  Earlier attempt metrics.
	 * @param array<string,mixed> $second Latest attempt metrics.
	 * @return array{response_id:string,input_tokens:int,cached_input_tokens:int,output_tokens:int,reasoning_tokens:int,estimated_cost_usd:float}
	 */
	private function combine_metrics( array $first, array $second ): array {
		return array(
			'response_id'         => Database::text( $second['response_id'] ?? null ),
			'input_tokens'        => Database::integer( $first['input_tokens'] ?? null ) + Database::integer( $second['input_tokens'] ?? null ),
			'cached_input_tokens' => Database::integer( $first['cached_input_tokens'] ?? null ) + Database::integer( $second['cached_input_tokens'] ?? null ),
			'output_tokens'       => Database::integer( $first['output_tokens'] ?? null ) + Database::integer( $second['output_tokens'] ?? null ),
			'reasoning_tokens'    => Database::integer( $first['reasoning_tokens'] ?? null ) + Database::integer( $second['reasoning_tokens'] ?? null ),
			'estimated_cost_usd'  => (float) Database::text( $first['estimated_cost_usd'] ?? 0 ) + (float) Database::text( $second['estimated_cost_usd'] ?? 0 ),
		);
	}
}
