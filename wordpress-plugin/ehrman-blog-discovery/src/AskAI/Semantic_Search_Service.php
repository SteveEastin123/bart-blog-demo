<?php
/**
 * Semantic post indexing and retrieval.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Retrieves posts by comparing precomputed post vectors with a question vector. */
final class Semantic_Search_Service {
	public const CANDIDATE_LIMIT       = 100;
	public const PIPELINE_VERSION      = 'hybrid-1';
	private const RETRIEVAL_METHOD     = 'hybrid';
	private const CACHE_SECONDS        = DAY_IN_SECONDS;
	private const RANK_CONSTANT        = 60.0;
	private const SEMANTIC_WEIGHT      = 1.0;
	private const LEXICAL_WEIGHT       = 0.8;
	private const ASSIGNED_TERM_WEIGHT = 0.6;

	/**
	 * Embedding API client.
	 *
	 * @var Embedding_Service
	 */
	private Embedding_Service $embeddings;

	/**
	 * Creates the semantic retrieval service.
	 *
	 * @param Embedding_Service|null $embeddings Optional workflow-specific embedding client.
	 */
	public function __construct( ?Embedding_Service $embeddings = null ) {
		$this->embeddings = $embeddings ?? new Embedding_Service();
	}

	/**
	 * Generates or refreshes the title-and-summary vector for one approved post.
	 *
	 * @param int  $source_wp_id Source WordPress post identifier.
	 * @param bool $force        Regenerate even when the stored fingerprint matches.
	 * @return array{generated:bool,metrics:array<string,int|float|string>}|WP_Error Result or error.
	 */
	public function build_post_embedding( int $source_wp_id, bool $force = false ) {
		if ( $source_wp_id < 1 ) {
			return new WP_Error( 'ehrman_embedding_invalid_post', __( 'The post identifier is invalid.', 'ehrman-blog-discovery' ) );
		}
		if ( ! $this->embeddings->configured() ) {
			return new WP_Error( 'ehrman_embedding_not_configured', __( 'An OpenAI API key is required to build the post vector.', 'ehrman-blog-discovery' ) );
		}

		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = $wpdb->prepare(
			"SELECT p.* FROM {$tables['external_posts']} p WHERE p.source_wp_id=%d AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')",
			$source_wp_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; table identifiers are internal.
		$post = Database::associative_row( $wpdb->get_row( $sql, ARRAY_A ) );
		if ( null === $post ) {
			return new WP_Error( 'ehrman_embedding_ineligible_post', __( 'The approved post is not eligible for semantic indexing.', 'ehrman-blog-discovery' ) );
		}

		$existing = $this->existing_rows()[ $source_wp_id ] ?? null;
		if ( ! $force && is_array( $existing )
			&& hash_equals( self::content_hash( $post ), Database::text( $existing['content_hash'] ?? null ) )
			&& Embedding_Service::model_id() === Database::text( $existing['model'] ?? null )
			&& Embedding_Service::dimensions() === Database::integer( $existing['dimensions'] ?? null ) ) {
			return array(
				'generated' => false,
				'metrics'   => array(),
			);
		}

		$vectors = $this->embeddings->embed( array( self::content_text( $post ) ) );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}
		$this->store_vector( $post, $vectors[0] );
		return array(
			'generated' => true,
			'metrics'   => $this->embeddings->last_metrics(),
		);
	}

	/**
	 * Returns the eligible and current embedding counts.
	 *
	 * @return array{
	 *     eligible:int,
	 *     indexed:int,
	 *     stored:int,
	 *     current:int,
	 *     missing:int,
	 *     stale:int,
	 *     obsolete:int,
	 *     ready:bool,
	 *     model:string,
	 *     dimensions:int,
	 *     strategy:string
	 * }
	 */
	public function status(): array {
		$wpdb         = Database::client();
		$tables       = Database::tables();
		$eligibility  = "p.source_wp_id IS NOT NULL AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')";
		$eligible_sql = "SELECT COUNT(*) FROM {$tables['external_posts']} p WHERE {$eligibility}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		$eligible = Database::integer( $wpdb->get_var( $eligible_sql ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$stored      = Database::integer( $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['post_embeddings']}" ) );
		$indexed_sql = "SELECT COUNT(*) FROM {$tables['post_embeddings']} e "
			. "JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id WHERE {$eligibility}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		$indexed     = Database::integer( $wpdb->get_var( $indexed_sql ) );
		$current_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tables['post_embeddings']} e "
			. "JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id WHERE {$eligibility} "
			. 'AND e.model=%s AND e.dimensions=%d AND e.embedding_norm>0 '
			. 'AND OCTET_LENGTH(e.embedding)=e.dimensions*4 '
			. "AND e.content_hash=SHA2(CONCAT('Title: ',COALESCE(p.title,''),CHAR(10),'Summary: ',COALESCE(p.search_summary,'')),256)",
			Embedding_Service::model_id(),
			Embedding_Service::dimensions()
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally and values are prepared.
		$current       = Database::integer( $wpdb->get_var( $current_sql ) );
		$missing       = max( 0, $eligible - $indexed );
		$stale         = max( 0, $indexed - $current );
		$obsolete      = max( 0, $stored - $indexed );
		$content_ready = $eligible > 0 && $current === $eligible;
		return array(
			'eligible'   => $eligible,
			'indexed'    => $indexed,
			'stored'     => $stored,
			'current'    => $current,
			'missing'    => $missing,
			'stale'      => $stale,
			'obsolete'   => $obsolete,
			'ready'      => $content_ready,
			'model'      => Embedding_Service::model_id(),
			'dimensions' => Embedding_Service::dimensions(),
			'strategy'   => self::RETRIEVAL_METHOD,
		);
	}

	/**
	 * Generates missing or stale post embeddings.
	 *
	 * @param bool          $force      Regenerate every eligible post.
	 * @param int           $batch_size Number of posts embedded per API call.
	 * @param callable|null $progress   Optional progress callback receiving processed and total counts.
	 * @return array{eligible:int,generated:int,unchanged:int,removed:int}|WP_Error Build summary or error.
	 */
	public function build_index( bool $force = false, int $batch_size = 50, ?callable $progress = null ) {
		if ( ! Embedding_Service::is_configured() ) {
			return new WP_Error( 'ehrman_embedding_not_configured', __( 'OPENAI_API_KEY is required to build the semantic index.', 'ehrman-blog-discovery' ) );
		}
		$batch_size = max( 1, min( 100, $batch_size ) );
		$posts      = $this->eligible_posts();
		$existing   = $this->existing_rows();
		/**
		 * Posts requiring new embeddings.
		 *
		 * @var list<array<string,mixed>> $pending
		 */
		$pending   = array();
		$unchanged = 0;
		foreach ( $posts as $post ) {
			$wp_id = Database::integer( $post['source_wp_id'] ?? null );
			$hash  = self::content_hash( $post );
			$row   = $existing[ $wp_id ] ?? null;
			if ( ! $force && is_array( $row )
				&& hash_equals( $hash, Database::text( $row['content_hash'] ?? null ) )
				&& Embedding_Service::model_id() === Database::text( $row['model'] ?? null )
				&& Embedding_Service::dimensions() === Database::integer( $row['dimensions'] ?? null ) ) {
				++$unchanged;
				continue;
			}
			$pending[] = $post;
		}

		$generated = 0;
		$total     = count( $pending );
		foreach ( array_chunk( $pending, $batch_size ) as $batch ) {
			$texts   = array_map( array( self::class, 'content_text' ), $batch );
			$vectors = $this->embeddings->embed( $texts );
			if ( is_wp_error( $vectors ) ) {
				return $vectors;
			}
			foreach ( $batch as $index => $post ) {
				$this->store_vector( $post, $vectors[ $index ] );
				++$generated;
			}
			if ( null !== $progress ) {
				$progress( $generated, $total );
			}
		}

		$eligible_ids = array();
		foreach ( $posts as $post ) {
			$eligible_ids[ Database::integer( $post['source_wp_id'] ?? null ) ] = true;
		}
		$removed = 0;
		foreach ( array_keys( $existing ) as $wp_id ) {
			if ( isset( $eligible_ids[ $wp_id ] ) ) {
				continue;
			}
			Database::client()->delete( Database::tables()['post_embeddings'], array( 'source_wp_id' => $wp_id ), array( '%d' ) );
			++$removed;
		}
		return array(
			'eligible'  => count( $posts ),
			'generated' => $generated,
			'unchanged' => $unchanged,
			'removed'   => $removed,
		);
	}

	/**
	 * Returns the strongest semantic title-and-summary matches.
	 *
	 * @param string $question   Reader question.
	 * @param string $request_id Correlation identifier for usage analytics.
	 * @param int    $limit      Maximum candidate count.
	 * @return array{posts:list<array<string,mixed>>,count:int,cache_hit:bool,stale:int,strategy:string}|WP_Error Candidates or error.
	 */
	public function search( string $question, string $request_id = '', int $limit = self::CANDIDATE_LIMIT ) {
		$question = sanitize_text_field( $question );
		if ( '' === trim( $question ) ) {
			return new WP_Error( 'ehrman_semantic_empty', __( 'Enter a question to search.', 'ehrman-blog-discovery' ), array( 'status' => 400 ) );
		}
		$limit     = max( 1, min( self::CANDIDATE_LIMIT, $limit ) );
		$cache_key = 'ebd_semantic_query_' . hash( 'sha256', Search_Service::normalize( $question ) . '|' . Embedding_Service::model_id() . '|' . Embedding_Service::dimensions() . '|' . AI_Usage::cache_version() );
		$cached    = get_transient( $cache_key );
		$cache_hit = is_array( $cached ) && count( $cached ) === Embedding_Service::dimensions();
		if ( $cache_hit ) {
			$query_vector = array_map( static fn( mixed $value ): float => is_scalar( $value ) ? (float) $value : 0.0, array_values( $cached ) );
			AI_Usage::record_cache_hit( Embedding_Service::model_id(), $request_id );
		} else {
			$vectors = $this->embeddings->embed( array( $question ), $request_id );
			if ( is_wp_error( $vectors ) ) {
				return $vectors;
			}
			$query_vector = $vectors[0];
			set_transient( $cache_key, $query_vector, self::CACHE_SECONDS );
		}

		$query_norm = self::vector_norm( $query_vector );
		if ( $query_norm <= 0.0 ) {
			return new WP_Error( 'ehrman_semantic_invalid_vector', __( 'Semantic search could not prepare this question.', 'ehrman-blog-discovery' ), array( 'status' => 502 ) );
		}
		/**
		 * Candidate post rows with semantic scores.
		 *
		 * @var list<array<string,mixed>> $ranked
		 */
		$ranked = array();
		$stale  = 0;
		foreach ( $this->candidate_rows() as $row ) {
			if ( ! hash_equals( self::content_hash( $row ), Database::text( $row['content_hash'] ?? null ) ) ) {
				++$stale;
				continue;
			}
			$vector = self::unpack_vector( Database::text( $row['embedding'] ?? null ) );
			$norm   = (float) Database::text( $row['embedding_norm'] ?? 0 );
			if ( count( $vector ) !== count( $query_vector ) || $norm <= 0.0 ) {
				++$stale;
				continue;
			}
			$content_score = self::dot_product( $query_vector, $vector ) / ( $query_norm * $norm );
			unset( $row['embedding'], $row['embedding_norm'], $row['content_hash'] );
			$row['semantic_score'] = $content_score;
			$ranked[]              = $row;
		}
		if ( empty( $ranked ) ) {
			return new WP_Error( 'ehrman_semantic_index_empty', __( 'The semantic search index has not been built yet.', 'ehrman-blog-discovery' ), array( 'status' => 503 ) );
		}
		usort( $ranked, array( self::class, 'compare_semantic_rows' ) );
		$ranked = $this->hybrid_rank( $question, $ranked );
		$posts  = array_slice( $ranked, 0, $limit );
		return array(
			'posts'     => $posts,
			'count'     => count( $posts ),
			'cache_hit' => $cache_hit,
			'stale'     => $stale,
			'strategy'  => self::RETRIEVAL_METHOD,
		);
	}

	/**
	 * Returns posts eligible for semantic indexing.
	 *
	 * @return list<array<string,mixed>> Eligible post rows.
	 */
	public function eligible_posts(): array {
		$tables = Database::tables();
		$sql    = "SELECT p.* FROM {$tables['external_posts']} p WHERE p.source_wp_id IS NOT NULL AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore') ORDER BY p.source_wp_id";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers and the fixed Ignore label are internal.
		return Database::associative_rows( Database::client()->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Returns stored embedding metadata keyed by source WordPress ID.
	 *
	 * @return array<int,array<string,mixed>> Stored embedding metadata.
	 */
	private function existing_rows(): array {
		$table = Database::tables()['post_embeddings'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier is generated internally.
		$rows = Database::associative_rows( Database::client()->get_results( "SELECT source_wp_id,content_hash,model,dimensions FROM {$table}", ARRAY_A ) );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ Database::integer( $row['source_wp_id'] ?? null ) ] = $row;
		}
		return $map;
	}

	/**
	 * Loads current post records with their vectors.
	 *
	 * @return list<array<string,mixed>> Current post and embedding rows.
	 */
	private function candidate_rows(): array {
		$wpdb   = Database::client();
		$tables = Database::tables();
		$sql    = $wpdb->prepare(
			"SELECT p.*,e.content_hash,e.embedding,e.embedding_norm FROM {$tables['post_embeddings']} e JOIN {$tables['external_posts']} p ON p.source_wp_id=e.source_wp_id "
			. "WHERE e.model=%s AND e.dimensions=%d AND p.search_summary IS NOT NULL AND TRIM(p.search_summary)<>'' "
			. "AND NOT EXISTS (SELECT 1 FROM {$tables['post_topics']} pt JOIN {$tables['topics']} t ON t.id=pt.topic_id WHERE pt.post_id=p.id AND t.name='Ignore')",
			Embedding_Service::model_id(),
			Embedding_Service::dimensions()
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are generated internally and values are prepared.
		return Database::associative_rows( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Combines semantic, title-and-summary text, and assigned-term ranks.
	 *
	 * Reciprocal-rank fusion keeps semantic similarity primary while allowing
	 * exact wording and curated assignments to promote otherwise strong posts.
	 *
	 * @param string                    $question Reader question.
	 * @param list<array<string,mixed>> $posts    Posts ordered by semantic similarity.
	 * @return list<array<string,mixed>> Hybrid-ranked posts.
	 */
	private function hybrid_rank( string $question, array $posts ): array {
		$tokens               = self::question_tokens( $question );
		$lexical_scores       = self::lexical_scores( $posts, $tokens );
		$assigned_term_scores = $this->assigned_term_scores( $question );
		$semantic_ranks       = array();
		foreach ( $posts as $index => $post ) {
			$semantic_ranks[ Database::integer( $post['id'] ?? null ) ] = $index + 1;
		}
		$lexical_ranks       = self::score_ranks( $lexical_scores );
		$assigned_term_ranks = self::score_ranks( $assigned_term_scores );

		foreach ( $posts as &$post ) {
			$post_id                 = Database::integer( $post['id'] ?? null );
			$score                   = self::rank_contribution( $semantic_ranks[ $post_id ] ?? 0, self::SEMANTIC_WEIGHT );
			$score                  += self::rank_contribution( $lexical_ranks[ $post_id ] ?? 0, self::LEXICAL_WEIGHT );
			$score                  += self::rank_contribution( $assigned_term_ranks[ $post_id ] ?? 0, self::ASSIGNED_TERM_WEIGHT );
			$post['retrieval_score'] = $score;
		}
		unset( $post );

		usort(
			$posts,
			static function ( array $left, array $right ): int {
				$comparison = (float) Database::text( $right['retrieval_score'] ?? 0 ) <=> (float) Database::text( $left['retrieval_score'] ?? 0 );
				return 0 !== $comparison ? $comparison : self::compare_semantic_rows( $left, $right );
			}
		);
		return $posts;
	}

	/**
	 * Calculates lexical relevance from meaningful question words.
	 *
	 * Title matches receive three times the summary weight. Inverse document
	 * frequency prevents common names and concepts from dominating the rank.
	 *
	 * @param list<array<string,mixed>> $posts  Candidate posts.
	 * @param array<int,string>         $tokens Meaningful normalized question tokens.
	 * @return array<int,float> Scores keyed by internal post ID.
	 */
	private static function lexical_scores( array $posts, array $tokens ): array {
		if ( empty( $tokens ) ) {
			return array();
		}
		/**
		 * Candidate title and summary word sets.
		 *
		 * @var array<int,array{title:array<string,bool>,summary:array<string,bool>}> $documents
		 */
		$documents   = array();
		$frequencies = array_fill_keys( $tokens, 0 );
		foreach ( $posts as $post ) {
			$post_id               = Database::integer( $post['id'] ?? null );
			$title_words           = self::word_set( Database::text( $post['title'] ?? null ) );
			$summary_words         = self::word_set( Database::text( $post['search_summary'] ?? null ) );
			$documents[ $post_id ] = array(
				'title'   => $title_words,
				'summary' => $summary_words,
			);
			foreach ( $tokens as $token ) {
				if ( isset( $title_words[ $token ] ) || isset( $summary_words[ $token ] ) ) {
					++$frequencies[ $token ];
				}
			}
		}

		$total  = max( 1, count( $posts ) );
		$scores = array();
		foreach ( $documents as $post_id => $document ) {
			$score   = 0.0;
			$matched = 0;
			foreach ( $tokens as $token ) {
				$in_title   = isset( $document['title'][ $token ] );
				$in_summary = isset( $document['summary'][ $token ] );
				if ( ! $in_title && ! $in_summary ) {
					continue;
				}
				++$matched;
				$idf    = log( ( $total + 1.0 ) / ( ( $frequencies[ $token ] ?? 0 ) + 1.0 ) ) + 1.0;
				$score += ( $in_title ? 3.0 : 0.0 ) * $idf;
				$score += ( $in_summary ? 1.0 : 0.0 ) * $idf;
			}
			if ( $matched > 0 ) {
				$scores[ $post_id ] = $score + ( 2.0 * $matched / count( $tokens ) );
			}
		}
		return $scores;
	}

	/**
	 * Calculates soft ranking signals from topic and keyword assignments.
	 *
	 * @param string $question Reader question.
	 * @return array<int,float> Scores keyed by internal post ID.
	 */
	private function assigned_term_scores( string $question ): array {
		$question = Search_Service::normalize( $question );
		if ( '' === $question ) {
			return array();
		}
		$tables = Database::tables();
		$sql    = "SELECT post_id,normalized,kind FROM {$tables['post_search_terms']} WHERE kind IN ('topic','alias','secondary')";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifier and allowed kinds are internal constants.
		$rows = Database::associative_rows( Database::client()->get_results( $sql, ARRAY_A ) );
		/**
		 * Strongest assigned-term weight for each matched concept on each post.
		 *
		 * @var array<int,array<string,float>> $matches
		 */
		$matches = array();
		foreach ( $rows as $row ) {
			$normalized = Database::text( $row['normalized'] ?? null );
			$kind       = Database::text( $row['kind'] ?? null );
			$match      = self::assigned_term_match( $question, $normalized, $kind );
			if ( null === $match ) {
				continue;
			}
			$post_id                              = Database::integer( $row['post_id'] ?? null );
			$weight                               = self::assigned_term_kind_weight( $kind ) * $match['strength'];
			$current                              = (float) ( $matches[ $post_id ][ $match['key'] ] ?? 0.0 );
			$matches[ $post_id ][ $match['key'] ] = max( $current, $weight );
		}

		$scores = array();
		foreach ( $matches as $post_id => $weights ) {
			$scores[ $post_id ] = array_sum( $weights ) + ( 0.25 * count( $weights ) );
		}
		return $scores;
	}

	/**
	 * Matches one assigned topic, alias, or keyword against the normalized question.
	 *
	 * @param string $question   Normalized reader question.
	 * @param string $normalized Normalized assigned term or alias.
	 * @param string $kind       Assigned term kind.
	 * @return array{key:string,strength:float}|null Match details.
	 */
	private static function assigned_term_match( string $question, string $normalized, string $kind ): ?array {
		if ( '' === $normalized ) {
			return null;
		}
		if ( self::contains_phrase( $question, $normalized ) ) {
			return array(
				'key'      => $normalized,
				'strength' => 1.0,
			);
		}
		if ( ! in_array( $kind, array( 'topic', 'alias' ), true ) ) {
			return null;
		}
		$anchor = self::assigned_term_anchor( $normalized );
		if ( '' === $anchor || ! self::contains_phrase( $question, $anchor ) ) {
			return null;
		}
		return array(
			'key'      => $anchor,
			'strength' => 0.75,
		);
	}

	/**
	 * Returns meaningful words from a reader question.
	 *
	 * @param string $question Reader question.
	 * @return list<string> Distinct normalized content words.
	 */
	private static function question_tokens( string $question ): array {
		$stopwords = array_fill_keys(
			array(
				'a',
				'about',
				'an',
				'and',
				'are',
				'as',
				'at',
				'be',
				'been',
				'but',
				'by',
				'can',
				'could',
				'did',
				'do',
				'does',
				'explain',
				'for',
				'from',
				'had',
				'has',
				'have',
				'how',
				'i',
				'in',
				'into',
				'is',
				'it',
				'its',
				'may',
				'of',
				'on',
				'or',
				'say',
				'says',
				'summarize',
				'tell',
				'than',
				'that',
				'the',
				'their',
				'there',
				'these',
				'they',
				'this',
				'to',
				'was',
				'were',
				'what',
				'when',
				'where',
				'which',
				'who',
				'why',
				'with',
				'would',
			),
			true
		);
		$tokens    = array();
		foreach ( explode( ' ', Search_Service::normalize( $question ) ) as $token ) {
			if ( strlen( $token ) < 3 || isset( $stopwords[ $token ] ) ) {
				continue;
			}
			$tokens[ $token ] = true;
		}
		return array_keys( $tokens );
	}

	/**
	 * Returns a normalized word set for fast exact-token matching.
	 *
	 * @param string $text Source text.
	 * @return array<string,bool> Normalized words keyed to true.
	 */
	private static function word_set( string $text ): array {
		$words = array_filter( explode( ' ', Search_Service::normalize( $text ) ) );
		return array_fill_keys( $words, true );
	}

	/**
	 * Removes generic wrappers from topic labels before matching named texts.
	 *
	 * @param string $normalized Normalized topic label.
	 */
	private static function assigned_term_anchor( string $normalized ): string {
		$generic = array_fill_keys(
			array( 'and', 'book', 'books', 'epistle', 'epistles', 'general', 'gospel', 'gospels', 'in', 'letter', 'letters', 'of', 'on', 'the' ),
			true
		);
		$tokens  = array_values(
			array_filter(
				explode( ' ', $normalized ),
				static fn( string $token ): bool => ! isset( $generic[ $token ] )
			)
		);
		return implode( ' ', $tokens );
	}

	/**
	 * Returns whether a normalized phrase occurs at word boundaries.
	 *
	 * @param string $haystack Normalized text to search.
	 * @param string $needle   Normalized phrase to find.
	 */
	private static function contains_phrase( string $haystack, string $needle ): bool {
		return '' !== $needle && str_contains( " {$haystack} ", " {$needle} " );
	}

	/**
	 * Returns the relative value of an assigned term kind.
	 *
	 * @param string $kind Assigned term kind.
	 */
	private static function assigned_term_kind_weight( string $kind ): float {
		if ( 'topic' === $kind ) {
			return 1.2;
		}
		return 'alias' === $kind ? 1.0 : 0.8;
	}

	/**
	 * Converts positive scores into one-based ranks.
	 *
	 * @param array<int,float> $scores Scores keyed by internal post ID.
	 * @return array<int,int> One-based ranks keyed by internal post ID.
	 */
	private static function score_ranks( array $scores ): array {
		$scores = array_filter( $scores, static fn( $score ): bool => (float) $score > 0.0 );
		arsort( $scores, SORT_NUMERIC );
		$ranks = array();
		$rank  = 1;
		foreach ( array_keys( $scores ) as $post_id ) {
			$ranks[ (int) $post_id ] = $rank++;
		}
		return $ranks;
	}

	/**
	 * Calculates one reciprocal-rank contribution.
	 *
	 * @param int   $rank   One-based rank or zero when the signal did not match.
	 * @param float $weight Retrieval-signal weight.
	 */
	private static function rank_contribution( int $rank, float $weight ): float {
		return $rank > 0 ? $weight / ( self::RANK_CONSTANT + $rank ) : 0.0;
	}

	/**
	 * Orders candidate rows by semantic similarity and then recency.
	 *
	 * @param array<string,mixed> $left  Left-side post record.
	 * @param array<string,mixed> $right Right-side post record.
	 */
	private static function compare_semantic_rows( array $left, array $right ): int {
		$comparison = (float) Database::text( $right['semantic_score'] ?? 0 ) <=> (float) Database::text( $left['semantic_score'] ?? 0 );
		if ( 0 !== $comparison ) {
			return $comparison;
		}
		return strcmp( Database::text( $right['published_at'] ?? null ), Database::text( $left['published_at'] ?? null ) );
	}

	/** Returns the analytics version for the selected retrieval pipeline. */
	public static function pipeline_version(): string {
		return self::PIPELINE_VERSION;
	}

	/**
	 * Stores one float32 vector and its source-content fingerprint.
	 *
	 * @param array<string,mixed> $post   Source post record.
	 * @param array<int,float>    $vector Embedding vector.
	 */
	private function store_vector( array $post, array $vector ): void {
		$wpdb = Database::client();
		$wpdb->replace(
			Database::tables()['post_embeddings'],
			array(
				'source_wp_id'   => Database::integer( $post['source_wp_id'] ?? null ),
				'content_hash'   => self::content_hash( $post ),
				'model'          => Embedding_Service::model_id(),
				'dimensions'     => Embedding_Service::dimensions(),
				'embedding'      => pack( 'g*', ...$vector ),
				'embedding_norm' => number_format( self::vector_norm( $vector ), 12, '.', '' ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Builds the canonical title-and-summary text for one post.
	 *
	 * @param array<string,mixed> $post Source post record.
	 */
	public static function content_text( array $post ): string {
		return 'Title: ' . Database::text( $post['title'] ?? null ) . "\nSummary: " . Database::text( $post['search_summary'] ?? null );
	}

	/**
	 * Returns the canonical content fingerprint for one post.
	 *
	 * @param array<string,mixed> $post Source post record.
	 */
	public static function content_hash( array $post ): string {
		return hash( 'sha256', self::content_text( $post ) );
	}

	/**
	 * Decodes a little-endian float32 vector.
	 *
	 * @param string $packed Packed vector bytes.
	 * @return list<float> Decoded vector.
	 */
	private static function unpack_vector( string $packed ): array {
		if ( '' === $packed ) {
			return array();
		}
		$values = unpack( 'g*', $packed );
		return is_array( $values )
			? array_map( static fn( mixed $value ): float => is_scalar( $value ) ? (float) $value : 0.0, array_values( $values ) )
			: array();
	}

	/**
	 * Calculates a vector's Euclidean norm.
	 *
	 * @param array<int,float> $vector Embedding vector.
	 */
	private static function vector_norm( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $value ) {
			$value = (float) $value;
			$sum  += $value * $value;
		}
		return sqrt( $sum );
	}

	/**
	 * Calculates the dot product of equal-length vectors.
	 *
	 * @param array<int,float> $left  Left-side vector.
	 * @param array<int,float> $right Right-side vector.
	 */
	private static function dot_product( array $left, array $right ): float {
		$sum = 0.0;
		foreach ( $left as $index => $value ) {
			$sum += (float) $value * (float) ( $right[ $index ] ?? 0.0 );
		}
		return $sum;
	}
}
