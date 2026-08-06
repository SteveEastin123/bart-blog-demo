<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Browse_Service
{
    public function subject_areas(int $path_number): array
    {
        global $wpdb;
        $tables = Database::tables();
        $path = $this->browse_path($path_number);
        if (null === $path) {
            return array();
        }
        $sql = "SELECT sa.id,sa.name,sa.slug,sa.description,sa.position, "
            . 'COUNT(DISTINCT sac.category_id) category_count,COUNT(DISTINCT tc.topic_id) topic_count,'
            . "COUNT(DISTINCT pt.post_id) post_count FROM {$tables['subject_areas']} sa "
            . "LEFT JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
            . "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
            . 'WHERE sa.browse_path_id=%d GROUP BY sa.id,sa.name,sa.slug,sa.description,sa.position '
            . 'ORDER BY sa.position,sa.name';
        return $wpdb->get_results($wpdb->prepare($sql, (int) $path['id']), ARRAY_A);
    }

    public function subject_area(int $path_number, string $slug): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $path = $this->browse_path($path_number);
        if (null === $path) {
            return null;
        }
        $sql = "SELECT * FROM {$tables['subject_areas']} WHERE browse_path_id=%d AND slug=%s LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, (int) $path['id'], sanitize_title($slug)), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function subject_area_categories(int $subject_area_id): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT c.id,c.name,c.slug,c.description,sac.position, "
            . 'COUNT(DISTINCT tc.topic_id) topic_count,COUNT(DISTINCT pt.post_id) post_count '
            . "FROM {$tables['subject_area_categories']} sac "
            . "JOIN {$tables['categories']} c ON c.id=sac.category_id "
            . "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
            . 'WHERE sac.subject_area_id=%d '
            . 'GROUP BY c.id,c.name,c.slug,c.description,sac.position ORDER BY sac.position,c.name';
        return $wpdb->get_results($wpdb->prepare($sql, $subject_area_id), ARRAY_A);
    }

    public function subject_area_counts(int $subject_area_id): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = 'SELECT COUNT(DISTINCT sac.category_id) category_count,'
            . 'COUNT(DISTINCT tc.topic_id) topic_count,COUNT(DISTINCT pt.post_id) post_count '
            . "FROM {$tables['subject_area_categories']} sac "
            . "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=sac.category_id "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
            . 'WHERE sac.subject_area_id=%d';
        $row = $wpdb->get_row($wpdb->prepare($sql, $subject_area_id), ARRAY_A);
        return is_array($row) ? $row : array('category_count' => 0, 'topic_count' => 0, 'post_count' => 0);
    }

    public function category(string $slug): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['categories']} WHERE slug=%s LIMIT 1", sanitize_title($slug)),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function category_topics(int $category_id): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT t.id,t.name,t.slug,t.description,tc.position,COUNT(DISTINCT pt.post_id) post_count "
            . "FROM {$tables['topics']} t JOIN {$tables['topic_categories']} tc ON tc.topic_id=t.id "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=t.id "
            . 'WHERE tc.category_id=%d AND t.display_in_browser=1 '
            . 'GROUP BY t.id,t.name,t.slug,t.description,tc.position '
            . 'ORDER BY CASE WHEN tc.position>0 THEN 0 ELSE 1 END,tc.position,t.name';
        return $wpdb->get_results($wpdb->prepare($sql, $category_id), ARRAY_A);
    }

    public function category_post_count(int $category_id): int
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT COUNT(DISTINCT pt.post_id) FROM {$tables['post_topics']} pt "
            . "JOIN {$tables['topic_categories']} tc ON tc.topic_id=pt.topic_id WHERE tc.category_id=%d";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $category_id));
    }

    public function topic(string $slug): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['topics']} WHERE slug=%s LIMIT 1", sanitize_title($slug)),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function topic_category(int $topic_id, string $requested_slug = ''): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        if ('' !== $requested_slug) {
            $sql = "SELECT c.* FROM {$tables['categories']} c "
                . "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
                . 'WHERE tc.topic_id=%d AND c.slug=%s LIMIT 1';
            $row = $wpdb->get_row(
                $wpdb->prepare($sql, $topic_id, sanitize_title($requested_slug)),
                ARRAY_A
            );
            if (is_array($row)) {
                return $row;
            }
        }
        $sql = "SELECT c.* FROM {$tables['categories']} c "
            . "JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
            . 'WHERE tc.topic_id=%d ORDER BY c.name LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $topic_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function primary_subject_area(int $path_number, int $category_id, string $requested_slug = ''): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $path = $this->browse_path($path_number);
        if (null === $path) {
            return null;
        }
        $requested_slug = sanitize_title($requested_slug);
        if ('' !== $requested_slug) {
            $sql = "SELECT sa.* FROM {$tables['subject_areas']} sa "
                . "JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
                . 'WHERE sa.browse_path_id=%d AND sac.category_id=%d AND sa.slug=%s LIMIT 1';
            $row = $wpdb->get_row(
                $wpdb->prepare($sql, (int) $path['id'], $category_id, $requested_slug),
                ARRAY_A
            );
            if (is_array($row)) {
                return $row;
            }
        }
        $sql = "SELECT sa.* FROM {$tables['subject_areas']} sa "
            . "JOIN {$tables['subject_area_categories']} sac ON sac.subject_area_id=sa.id "
            . 'WHERE sa.browse_path_id=%d AND sac.category_id=%d '
            . 'ORDER BY sac.position,sa.position,sa.name LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, (int) $path['id'], $category_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function category_options(): array
    {
        global $wpdb;
        $tables = Database::tables();
        $sql = "SELECT c.name,c.slug,COUNT(DISTINCT pt.post_id) post_count FROM {$tables['categories']} c "
            . "LEFT JOIN {$tables['topic_categories']} tc ON tc.category_id=c.id "
            . "LEFT JOIN {$tables['post_topics']} pt ON pt.topic_id=tc.topic_id "
            . 'GROUP BY c.id,c.name,c.slug ORDER BY c.name';
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function browse_path(int $path_number): ?array
    {
        global $wpdb;
        $tables = Database::tables();
        $slug = 'browse-topics-' . (2 === $path_number ? '2' : '1');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['browse_paths']} WHERE slug=%s LIMIT 1", $slug),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }
}
