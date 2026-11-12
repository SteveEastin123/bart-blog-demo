=== Ehrman Blog Discovery ===
Contributors: steveeastin
Tags: search, topics, discovery
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.10.15
License: GPLv2 or later

WordPress foundation for browsing and searching the Ehrman Blog index.

== Description ==

This plugin uses the WordPress database connection and imports the authoritative
post, topic, category, subject-area, and secondary-keyword indexes into
dedicated database tables. It provides scoped keyword search, autocomplete, and
hierarchical topic browsing through subject areas and categories.

Administrators can also prepare full posts in a protected ingestion workflow.
It uses the shared server-side OPENAI_API_KEY to draft descriptions, search
summaries, topics, and secondary keywords for review. Database-managed approval
is enabled by default. Explicit EHRMAN_DISCOVERY_POST_SOURCE=json disables
approval for environments rebuilt from JSON. Publishers still approve each proposal;
metadata is never automatically approved.

MariaDB is the current build and regression-test target. The plugin does not
bundle or start a database server. The existing EHRMAN_DISCOVERY_POST_SOURCE=mysql
setting means database-authoritative ingestion on MariaDB as well; it is not a
database product selector.

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
4. Use `[ehrman_keyword_search]`, `[ehrman_browse_topics]`, and `[ehrman_ask_ai]`
   on WordPress pages. The managed browsing page is `/browse-topics/`.

== Development ==

Install the development dependencies with `composer install`. Run
`composer lint` to check the plugin against the configured WordPress Coding
Standards ruleset, or `composer lint:fix` to apply safe automatic formatting.
Run `composer analyse` for maximum-level, WordPress-aware PHPStan analysis, or
`composer check` to run both standards and static-analysis checks.

== Changelog ==

= 0.10.15 =
* Retire Browse Topics 2 and rename the retained first structure to Browse Topics.
* Redirect both numbered browsing URLs to /browse-topics/ and preserve supported filters.
* Upgrade existing hierarchies without replacing shared posts, taxonomy, vectors or analytics.
* Update the demo, Reviewer Tools, four-file import and developer regression package.

= 0.10.14 =
* Add Overview, Ask AI and Post Metadata tabs under Tools > AI Analytics.
* Preserve metadata-generation and post-vector costs per attempt, including reported usage for failed outputs, retries and regenerated metadata.
* Retain usage when posts are deleted; label incomplete historical snapshots and unknown costs.
* Keep API keys, models, search and publishing behavior unchanged.

= 0.10.13 =
* Removed Ask AI helpfulness voting, its public endpoint, and feedback reporting.
* Preserved question, token, cost, cache, and failure analytics.
* New installations no longer create feedback storage. Upgrades leave historical
  feedback tables and columns untouched; the plugin no longer uses them.

= 0.10.12 =
* Enabled database-managed approval by default, with an explicit JSON-mode opt-out.
* Allowed initial installation and verified no-op reruns without a source-mode
  switch, while retaining protection against replacing editorial data.

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
