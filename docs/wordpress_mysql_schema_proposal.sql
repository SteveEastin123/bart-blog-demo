-- Phase 1 proposal for the Ehrman Blog Discovery WordPress plugin.
--
-- `wp_` is illustrative. Runtime installation must use `$wpdb->prefix`.
-- Table creation will use `$wpdb->get_charset_collate()` and WordPress dbDelta().
-- Application code manages relationship cleanup for WordPress compatibility;
-- foreign-key constraints are intentionally omitted.

CREATE TABLE wp_ehrman_browse_paths (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_browse_paths_slug (slug),
    KEY idx_browse_paths_position (position, id)
);

CREATE TABLE wp_ehrman_subject_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    browse_path_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subject_areas_path_slug (browse_path_id, slug),
    KEY idx_subject_areas_path_position (browse_path_id, position, id)
);

CREATE TABLE wp_ehrman_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug)
);

CREATE TABLE wp_ehrman_topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    display_in_browser TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_topics_name (name),
    UNIQUE KEY uq_topics_slug (slug),
    KEY idx_topics_browser_name (display_in_browser, name)
);

CREATE TABLE wp_ehrman_external_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_wp_id BIGINT UNSIGNED NULL,
    title TEXT NOT NULL,
    url TEXT NOT NULL,
    url_hash BINARY(32) NOT NULL,
    author VARCHAR(191) NOT NULL DEFAULT '',
    date_text VARCHAR(64) NOT NULL DEFAULT '',
    published_at DATETIME NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_external_posts_source_wp_id (source_wp_id),
    UNIQUE KEY uq_external_posts_url_hash (url_hash),
    KEY idx_external_posts_published (published_at, id),
    KEY idx_external_posts_author (author)
);

CREATE TABLE wp_ehrman_keywords (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(191) NOT NULL,
    normalized VARCHAR(191) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_keywords_normalized (normalized),
    KEY idx_keywords_label (label)
);

CREATE TABLE wp_ehrman_subject_area_categories (
    subject_area_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (subject_area_id, category_id),
    KEY idx_subject_area_categories_category (category_id, subject_area_id),
    KEY idx_subject_area_categories_order (subject_area_id, position, category_id)
);

CREATE TABLE wp_ehrman_topic_categories (
    topic_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (topic_id, category_id),
    KEY idx_topic_categories_category (category_id, topic_id),
    KEY idx_topic_categories_order (category_id, position, topic_id)
);

CREATE TABLE wp_ehrman_post_topics (
    post_id BIGINT UNSIGNED NOT NULL,
    topic_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, topic_id),
    KEY idx_post_topics_topic (topic_id, post_id)
);

CREATE TABLE wp_ehrman_post_keywords (
    post_id BIGINT UNSIGNED NOT NULL,
    keyword_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, keyword_id),
    KEY idx_post_keywords_keyword (keyword_id, post_id)
);

CREATE TABLE wp_ehrman_post_search_terms (
    post_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(191) NOT NULL,
    normalized VARCHAR(191) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    weight SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, normalized, kind),
    KEY idx_search_terms_normalized_post (normalized, post_id),
    KEY idx_search_terms_label (label),
    KEY idx_search_terms_kind_normalized (kind, normalized, post_id)
);

-- Plugin schema and import versions are stored in wp_options rather than a
-- dedicated table:
--
-- ehrman_discovery_schema_version
-- ehrman_discovery_import_version
-- ehrman_discovery_import_checksum
-- ehrman_discovery_last_import_summary
