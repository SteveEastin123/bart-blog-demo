=== Ehrman Blog Discovery ===
Contributors: steveeastin
Tags: search, topics, discovery
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.9.0
License: GPLv2 or later

WordPress foundation for browsing and searching the Ehrman Blog index.

== Description ==

This plugin verifies the WordPress/MySQL runtime and imports the authoritative
post, topic, category, subject-area, and secondary-keyword indexes into
dedicated MySQL tables. It provides scoped keyword search, autocomplete, and
two alternative topic-browsing paths.

Administrators can also prepare full posts in a protected ingestion workflow.
It uses a dedicated OpenAI project to draft descriptions, search summaries,
topics, and secondary keywords for review. Approval remains disabled while JSON
is authoritative and can be enabled after the planned MySQL source-of-truth
handoff.

The block editor includes a Search Metadata panel for published posts. It uses
the saved WordPress post as the trusted source, exposes the current proposal and
vector status, and provides protected analysis, review, approval, reanalysis,
and vector-retry controls. AI analysis runs as a recoverable background job, so
the editor remains responsive while results are generated. The Tools > Post
Ingestion page remains available for detailed review and as an administrative
fallback.

== Installation ==

1. Copy the plugin directory to `wp-content/plugins/ehrman-blog-discovery`.
2. Activate Ehrman Blog Discovery in WordPress.
3. Add `[ehrman_discovery_status]` to a page to verify the environment.
4. Use `[ehrman_keyword_search]` and `[ehrman_browse_topics path="1"]` or
   `[ehrman_browse_topics path="2"]` on WordPress pages.

== Development ==

Install the development dependencies with `composer install`. Run
`composer lint` to check the plugin against the configured WordPress Coding
Standards ruleset, or `composer lint:fix` to apply safe automatic formatting.
Run `composer analyse` for maximum-level, WordPress-aware PHPStan analysis, or
`composer check` to run both standards and static-analysis checks.

== Changelog ==

= 0.9.0 =
* Added an administrator-only full-post ingestion, editorial review, approval,
  audit, and title-and-summary vector workflow for the future
  MySQL-authoritative deployment.
* Added an administrator-only Search Metadata panel to the WordPress post editor
  with nonce-protected workflow actions and links to the full review screen.
* Added queued background analysis with editor polling, atomic worker claims,
  stale-job recovery, and attempt fencing that prevents late results from
  replacing a newer analysis.
* Added separate ingestion API configuration and preserved JSON-authoritative
  approval locking for the current deployment phase.

= 0.4.0 =
* Added a disabled-by-default, token-protected parity endpoint, bounded search
  inputs, portable slug generation, and Phase 5 validation support.

= 0.3.0 =
* Added MySQL-backed search, ranked results, scoped autocomplete, category and
  topic filtering, both browse paths, external post lists, and WordPress pages.

= 0.2.0 =
* Added versioned MySQL tables, transactional JSON importing, validation,
  import status, a secured administrator action, and WP-CLI commands.

= 0.1.0 =
* Added the Phase 2 plugin foundation, status page, and REST health endpoint.
