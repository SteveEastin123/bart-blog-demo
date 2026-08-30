<?php
/**
 * Database schema and metadata services.
 *
 * @package EhrmanBlogDiscovery
 */

namespace EhrmanBlogDiscovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manages the plugin's custom MySQL tables and schema version. */
final class Database {

	/**
	 * Returns every custom table name keyed by its logical identifier.
	 *
	 * @return array<string,string> Custom table names.
	 */
	public static function tables(): array {
		$wpdb = self::client();

		$base = $wpdb->prefix . 'ehrman_';

		return array(
			'browse_paths'             => $base . 'browse_paths',
			'subject_areas'            => $base . 'subject_areas',
			'categories'               => $base . 'categories',
			'topics'                   => $base . 'topics',
			'external_posts'           => $base . 'external_posts',
			'keywords'                 => $base . 'keywords',
			'subject_area_categories'  => $base . 'subject_area_categories',
			'topic_categories'         => $base . 'topic_categories',
			'post_topics'              => $base . 'post_topics',
			'post_keywords'            => $base . 'post_keywords',
			'post_search_terms'        => $base . 'post_search_terms',
			'post_embeddings'          => $base . 'post_embeddings',
			'post_metadata_embeddings' => $base . 'post_metadata_embeddings',
			'ai_usage'                 => $base . 'ai_usage',
			'ai_requests'              => $base . 'ai_requests',
			'ai_refinements'           => $base . 'ai_refinements',
			'ai_feedback'              => $base . 'ai_feedback',
		);
	}

	/** Creates or updates the custom database schema. */
	public static function install(): void {
		$wpdb = self::client();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables  = self::tables();
		$collate = $wpdb->get_charset_collate();
		$sql     = "
CREATE TABLE {$tables['browse_paths']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  description text NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_browse_paths_slug (slug),
  KEY idx_browse_paths_position (position,id)
) {$collate};

CREATE TABLE {$tables['subject_areas']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  browse_path_id bigint(20) unsigned NOT NULL,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  description text NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_subject_areas_path_slug (browse_path_id,slug),
  KEY idx_subject_areas_path_position (browse_path_id,position,id)
) {$collate};

CREATE TABLE {$tables['categories']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  description text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_categories_name (name),
  UNIQUE KEY uq_categories_slug (slug)
) {$collate};

CREATE TABLE {$tables['topics']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  description text NOT NULL,
  display_in_browser tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_topics_name (name),
  UNIQUE KEY uq_topics_slug (slug),
  KEY idx_topics_browser_name (display_in_browser,name)
) {$collate};

CREATE TABLE {$tables['external_posts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_wp_id bigint(20) unsigned DEFAULT NULL,
  title text NOT NULL,
  url text NOT NULL,
  url_hash binary(32) NOT NULL,
  author varchar(191) NOT NULL DEFAULT '',
  date_text varchar(64) NOT NULL DEFAULT '',
  published_at datetime DEFAULT NULL,
  description text NOT NULL,
  search_summary text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_external_posts_source_wp_id (source_wp_id),
  UNIQUE KEY uq_external_posts_url_hash (url_hash),
  KEY idx_external_posts_published (published_at,id),
  KEY idx_external_posts_author (author)
) {$collate};

CREATE TABLE {$tables['keywords']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(191) NOT NULL,
  normalized varchar(191) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_keywords_normalized (normalized),
  KEY idx_keywords_label (label)
) {$collate};

CREATE TABLE {$tables['subject_area_categories']} (
  subject_area_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (subject_area_id,category_id),
  KEY idx_subject_area_categories_category (category_id,subject_area_id),
  KEY idx_subject_area_categories_order (subject_area_id,position,category_id)
) {$collate};

CREATE TABLE {$tables['topic_categories']} (
  topic_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (topic_id,category_id),
  KEY idx_topic_categories_category (category_id,topic_id),
  KEY idx_topic_categories_order (category_id,position,topic_id)
) {$collate};

CREATE TABLE {$tables['post_topics']} (
  post_id bigint(20) unsigned NOT NULL,
  topic_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (post_id,topic_id),
  KEY idx_post_topics_topic (topic_id,post_id)
) {$collate};

CREATE TABLE {$tables['post_keywords']} (
  post_id bigint(20) unsigned NOT NULL,
  keyword_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (post_id,keyword_id),
  KEY idx_post_keywords_keyword (keyword_id,post_id)
) {$collate};

CREATE TABLE {$tables['post_search_terms']} (
  post_id bigint(20) unsigned NOT NULL,
  label varchar(191) NOT NULL,
  normalized varchar(191) NOT NULL,
  kind varchar(16) NOT NULL,
  weight smallint(5) unsigned NOT NULL,
  PRIMARY KEY  (post_id,normalized,kind),
  KEY idx_search_terms_normalized_post (normalized,post_id),
  KEY idx_search_terms_label (label),
  KEY idx_search_terms_kind_normalized (kind,normalized,post_id)
) {$collate};

CREATE TABLE {$tables['post_embeddings']} (
  source_wp_id bigint(20) unsigned NOT NULL,
  content_hash char(64) NOT NULL,
  model varchar(100) NOT NULL,
  dimensions smallint(5) unsigned NOT NULL,
  embedding longblob NOT NULL,
  embedding_norm double unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (source_wp_id),
  KEY idx_post_embeddings_model (model,dimensions)
) {$collate};

CREATE TABLE {$tables['post_metadata_embeddings']} (
  source_wp_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  content_hash char(64) NOT NULL,
  model varchar(100) NOT NULL,
  dimensions smallint(5) unsigned NOT NULL,
  embedding longblob NOT NULL,
  embedding_norm double unsigned NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (source_wp_id,kind),
  KEY idx_post_metadata_embeddings_kind_model (kind,model,dimensions)
) {$collate};

CREATE TABLE {$tables['ai_usage']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  response_id varchar(191) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL,
  service_tier varchar(32) NOT NULL DEFAULT '',
  input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cached_input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cache_write_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  reasoning_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  total_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  estimated_cost_usd decimal(14,8) unsigned NOT NULL DEFAULT 0,
  cache_hit tinyint(1) unsigned NOT NULL DEFAULT 0,
  request_succeeded tinyint(1) unsigned NOT NULL DEFAULT 0,
  error_code varchar(100) NOT NULL DEFAULT '',
  pricing_version varchar(32) NOT NULL DEFAULT '',
  request_id char(36) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY idx_ai_usage_created (created_at),
  KEY idx_ai_usage_response (response_id),
  KEY idx_ai_usage_model (model),
  KEY idx_ai_usage_cache_hit (cache_hit),
  KEY idx_ai_usage_succeeded (request_succeeded),
  KEY idx_ai_usage_request (request_id)
) {$collate};

CREATE TABLE {$tables['ai_requests']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id char(36) NOT NULL,
  created_at datetime NOT NULL,
  request_type varchar(32) NOT NULL DEFAULT 'taxonomy',
  question text NOT NULL,
  selected_terms text NOT NULL,
  result_count int(10) unsigned NOT NULL DEFAULT 0,
  result_recorded tinyint(1) unsigned NOT NULL DEFAULT 0,
  model varchar(100) NOT NULL,
  prompt_version varchar(32) NOT NULL,
  cache_hit tinyint(1) unsigned NOT NULL DEFAULT 0,
  request_succeeded tinyint(1) unsigned NOT NULL DEFAULT 0,
  error_code varchar(100) NOT NULL DEFAULT '',
  feedback tinyint(1) unsigned DEFAULT NULL,
  feedback_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_ai_requests_request (request_id),
  KEY idx_ai_requests_created (created_at),
  KEY idx_ai_requests_type (request_type,created_at),
  KEY idx_ai_requests_feedback (feedback,created_at),
  KEY idx_ai_requests_succeeded (request_succeeded,created_at)
) {$collate};

CREATE TABLE {$tables['ai_refinements']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  refinement_id char(36) NOT NULL,
  request_id char(36) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  question text NOT NULL,
  original_count int(10) unsigned NOT NULL DEFAULT 0,
  candidate_count int(10) unsigned NOT NULL DEFAULT 0,
  refined_count int(10) unsigned NOT NULL DEFAULT 0,
  selected_posts longtext NOT NULL,
  model varchar(100) NOT NULL,
  prompt_version varchar(32) NOT NULL,
  cache_hit tinyint(1) unsigned NOT NULL DEFAULT 0,
  request_succeeded tinyint(1) unsigned NOT NULL DEFAULT 0,
  error_code varchar(100) NOT NULL DEFAULT '',
  input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cached_input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  estimated_cost_usd decimal(14,8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_ai_refinements_id (refinement_id),
  KEY idx_ai_refinements_request (request_id),
  KEY idx_ai_refinements_created (created_at),
  KEY idx_ai_refinements_succeeded (request_succeeded,created_at)
) {$collate};

CREATE TABLE {$tables['ai_feedback']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  question text NOT NULL,
  selected_terms text NOT NULL,
  helpful tinyint(1) unsigned NOT NULL,
  result_count int(10) unsigned NOT NULL DEFAULT 0,
  model varchar(100) NOT NULL,
  prompt_version varchar(32) NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_ai_feedback_created (created_at),
  KEY idx_ai_feedback_helpful (helpful,created_at),
  KEY idx_ai_feedback_model (model)
) {$collate};
";

		dbDelta( $sql );
		update_option( 'ehrman_discovery_schema_version', EHRMAN_DISCOVERY_SCHEMA_VERSION, false );
	}

	/** Applies a schema upgrade when the installed version is out of date. */
	public static function maybe_upgrade(): void {
		$installed_option = get_option( 'ehrman_discovery_schema_version', '0.0.0' );
		$installed        = is_scalar( $installed_option ) ? (string) $installed_option : '0.0.0';
		if ( version_compare( $installed, EHRMAN_DISCOVERY_SCHEMA_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Counts the records in each available custom table.
	 *
	 * @return array<string,int> Record counts keyed by table identifier.
	 */
	public static function counts(): array {
		$wpdb = self::client();

		$counts = array();
		foreach ( self::tables() as $key => $table ) {
			if ( ! self::table_exists( $table ) ) {
				$counts[ $key ] = 0;
				continue;
			}

			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return $counts;
	}

	/**
	 * Determines whether a custom table exists.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool Whether the table exists.
	 */
	public static function table_exists( string $table ): bool {
		$wpdb = self::client();

		$like = $wpdb->esc_like( $table );
		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	/**
	 * Returns the initialized WordPress database client.
	 *
	 * @return \wpdb WordPress database client.
	 * @throws \RuntimeException When WordPress has not initialized the client.
	 */
	public static function client(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database client is unavailable.' );
		}

		return $wpdb;
	}

	/**
	 * Validates one associative database row.
	 *
	 * @param mixed $value Database result.
	 * @return array<string,mixed>|null Associative row, or null.
	 */
	public static function associative_row( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return null;
			}
		}

		/**
		 * Validated associative row.
		 *
		 * @var array<string,mixed> $value
		 */
		return $value;
	}

	/**
	 * Validates a list of associative database rows.
	 *
	 * @param mixed $value Database result.
	 * @return list<array<string,mixed>> Associative rows.
	 */
	public static function associative_rows( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $value_row ) {
			$row = self::associative_row( $value_row );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Converts a numeric database value to an integer.
	 *
	 * @param mixed $value Database result.
	 * @return int Numeric value or zero.
	 */
	public static function integer( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Converts a scalar database value to text.
	 *
	 * @param mixed $value Database result.
	 * @return string Scalar text or an empty string.
	 */
	public static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Converts a database column result to text values.
	 *
	 * @param mixed $value Database result.
	 * @return list<string> Scalar text values.
	 */
	public static function strings( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$strings[] = (string) $item;
			}
		}

		return $strings;
	}
}
