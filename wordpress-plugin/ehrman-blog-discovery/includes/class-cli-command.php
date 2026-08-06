<?php

namespace EhrmanBlogDiscovery;

if (!defined('ABSPATH')) {
    exit;
}

final class Cli_Command
{
    /**
     * Import the authoritative Ehrman Blog discovery JSON files.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Import even when the source checksum has not changed.
     *
     * ## EXAMPLES
     *
     *     wp ehrman-discovery import
     *     wp ehrman-discovery import --force
     */
    public function import(array $args, array $assoc_args): void
    {
        unset($args);

        $importer = new Importer();
        try {
            $summary = $importer->import(isset($assoc_args['force']));
        }
        catch (\Throwable $error) {
            \WP_CLI::error($error->getMessage());
            return;
        }

        if (!empty($summary['skipped'])) {
            \WP_CLI::success('The authoritative JSON checksum is unchanged; no import was needed.');
            return;
        }

        \WP_CLI::success(
            sprintf(
                'Imported %d posts, %d topics, %d categories, and %d keywords in %d ms.',
                (int) $summary['counts']['external_posts'],
                (int) $summary['counts']['topics'],
                (int) $summary['counts']['categories'],
                (int) $summary['counts']['keywords'],
                (int) $summary['duration_ms']
            )
        );

        foreach ($summary['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }
    }

    /**
     * Display plugin schema, import status, and record counts.
     */
    public function status(): void
    {
        $status = Plugin::status_data();
        \WP_CLI::line('Plugin version: ' . $status['plugin_version']);
        \WP_CLI::line('Schema version: ' . $status['schema_version']);
        \WP_CLI::line('Import state: ' . $status['import_state']);
        \WP_CLI::line('Database connected: ' . ($status['database_connected'] ? 'yes' : 'no'));
        foreach ($status['counts'] as $name => $count) {
            \WP_CLI::line("{$name}: {$count}");
        }
    }
}
