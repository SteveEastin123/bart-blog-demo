<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Database
{
    public static function tables(): array
    {
        global $wpdb;

        $base = $wpdb->prefix . 'ehrman_';

        return array(
            'browse_paths' => $base . 'browse_paths',
            'subject_areas' => $base . 'subject_areas',
            'categories' => $base . 'categories',
            'topics' => $base . 'topics',
            'external_posts' => $base . 'external_posts',
            'keywords' => $base . 'keywords',
            'subject_area_categories' => $base . 'subject_area_categories',
            'topic_categories' => $base . 'topic_categories',
            'post_topics' => $base . 'post_topics',
            'post_keywords' => $base . 'post_keywords',
            'post_search_terms' => $base . 'post_search_terms',
        );
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = self::tables();
        $collate = $wpdb->get_charset_collate();
        $sql = "
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
";

        dbDelta($sql);
        update_option('ehrman_discovery_schema_version', EBD_SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        $installed = (string) get_option('ehrman_discovery_schema_version', '0.0.0');
        if (version_compare($installed, EBD_SCHEMA_VERSION, '<')) {
            self::install();
        }
    }

    public static function counts(): array
    {
        global $wpdb;

        $counts = array();
        foreach (self::tables() as $key => $table) {
            if (!self::table_exists($table)) {
                $counts[$key] = 0;
                continue;
            }

            $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        return $counts;
    }

    public static function table_exists(string $table): bool
    {
        global $wpdb;

        $like = $wpdb->esc_like($table);
        return $table === (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    }
}
