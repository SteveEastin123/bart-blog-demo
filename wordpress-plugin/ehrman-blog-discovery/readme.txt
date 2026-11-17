=== Ehrman Blog Discovery ===
Contributors: steveeastin
Tags: search, topics, discovery
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.12.0
License: GPLv2 or later

Browse Topics, Keyword Search, Ask AI, and post metadata publishing for the Ehrman Blog.

== Description ==

This plugin uses the existing WordPress database connection and imports four
discovery JSON files and a supplied title-and-summary vector package into
dedicated database tables. It provides Browse Topics, Keyword Search with
autocomplete, and Ask AI using semantic retrieval and AI candidate review.
Only the selected Browse Topics structure and Ask AI method are active.

The standard WordPress post editor's Search Metadata panel uses the saved,
published post as the source for its ID, title, URL, author, date, and text.
It uses the shared server-side OPENAI_API_KEY to generate a Description,
Summary, Topics, and Keywords. Search and ingestion retain separate model
settings and usage records.

Manual mode is the default. Create Metadata shows progress and waits for
generation in the request, then displays the proposal for approval. Publishers
can edit Description and Summary and remove or restore AI-selected terms.
Approve and Add to Search saves the metadata, then generates its vector.
Approved, WordPress-linked records support explicit editing or regeneration
through a separate revision; existing live data remains until approval succeeds.

Opt-in Automatic mode queues generation, validation, approval, and vector
creation after a new post is first published. Publication does not wait for AI.
Automatic mode requires reliable WordPress cron; errors remain available for
manual recovery. Later post edits do not automatically regenerate metadata.
Tools > Ehrman Discovery provides Manual/Automatic radio buttons that save
immediately. Each post can override the default before first publication.

Database-managed approval is enabled by default. Explicit
EHRMAN_DISCOVERY_POST_SOURCE=json disables approval for environments rebuilt
from JSON. Data ownership and Manual/Automatic publishing are separate settings.

MariaDB is the current build and regression-test target. The plugin does not
bundle or start a database server. The existing EHRMAN_DISCOVERY_POST_SOURCE=mysql
setting means database-authoritative ingestion on MariaDB as well; it is not a
database product selector.

Tools > Search Metadata provides the worklist, review, revisions, and recovery.
It defaults to All records; Clear filters resets only the filters. Imported
records without native WordPress ownership remain read-only. Linking existing
imported records to production posts requires a validated integration step;
matching source IDs alone does not establish ownership.

Tools > AI Analytics provides Overview, Ask AI, and Post Metadata tabs for usage
and estimated costs. The production plugin excludes the demo-only Reviewer Tools
and experimental search methods. Developer regression tests are delivered separately.

== Installation ==

1. Back up the WordPress database and pause editorial writes and scheduled ingestion.
2. Copy the plugin directory to `wp-content/plugins/ehrman-blog-discovery`, then
   activate Ehrman Blog Discovery in WordPress.
3. Place the four discovery JSON files and `ehrman_post_embeddings.jsonl.gz` in
   a private directory outside WordPress.
4. Run `wp ehrman-discovery install-data --data-dir=/secure/ehrman-data --validate-only`,
   then the same command without `--validate-only` to install the approved package.
   Supplied vectors are imported without API calls. Existing editorial data is protected.
5. Configure the shared server-side `OPENAI_API_KEY` for paid Ask AI and metadata
   requests. Never put it in page content, client code, or the delivery package.
6. Use `[ehrman_browse_topics]`, `[ehrman_keyword_search]`, and `[ehrman_ask_ai]`
   on WordPress pages. Canonical managed URLs are `/browse-topics/`,
   `/keyword-search/`, and `/ask-ai/`.
7. Check Tools > Ehrman Discovery for database status and publishing settings.
   Leave Manual selected unless Automatic is intended and cron is configured.

Use the accompanying developer handoff and post-ingestion guides for installation
prerequisites, data-linking boundaries, upgrades, rollback, and acceptance testing.

== Development ==

Install the development dependencies with `composer install`. Run
`composer lint` to check the plugin against the configured WordPress Coding
Standards ruleset, or `composer lint:fix` to apply safe automatic formatting.
Run `composer analyse` for maximum-level, WordPress-aware PHPStan analysis, or
`composer check` to run both standards and static-analysis checks.

== Changelog ==

= 0.12.0 =
* Add an explicit WP-CLI link-posts preview/apply command for imported metadata.
* Verify source IDs, titles and permalink paths before assigning native ownership.
* Preserve imported baselines for editing, regeneration and native-post lifecycle cleanup.
* Keep ordinary imports, external demos, supplied vectors and AI charges unchanged.

= 0.11.0 =
* Add opt-in automatic metadata generation, approval, and vectors after first publication.
* Keep Manual as the default, with foreground generation, progress, and explicit approval.
* Support safe revisions of approved metadata and removal/restoration of AI-selected terms.
* Add autosaving Manual/Automatic site settings and a prepublication per-post override.
* Default Search Metadata to All, capitalize vector statuses, and clarify Clear filters.
* Default Browse Topics sorting to Most posts, while retaining the Alphabetical option.
* Keep analytics on its dedicated Tools page and update the developer handoff guidance.

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
