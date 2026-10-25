# WordPress Developer Handoff Package

This document defines the materials to provide to the development company for integrating the Ehrman Blog Discovery functionality into Bart's production WordPress site.

## 1. Installable WordPress Plugin

- `ehrman-blog-discovery.zip`
- Access to the corresponding source-code repository or tagged release

The plugin contains Keyword Search, Browse Topics, the selected title-and-summary semantic Ask AI search, autocomplete, result ranking, category filtering, database integration, data importing, REST endpoints, and administrative tools.

Reviewer Tools is now an optional **demo-only companion plugin**, stored at
`wordpress-demo-plugins/ehrman-reviewer-tools`. It is excluded from the production
plugin and handoff package, including its renderer, frontend controls and export
code. Our demos retain the outlines, category/topic indexes, CSV/PDF downloads,
menu link and evaluation note. The production plugin neither creates the review
page nor registers its shortcode. Ingestion approval/review and Ask AI relevance
review are unrelated features and remain in the production plugin.

The retired topic/keyword Ask AI interpreter and experimental section-search
plugins are not part of the production plugin. Package the active plugin directory,
not the repository root. Both Browse Topics structures remain until the final
structure is selected. A single Ask AI Analytics dashboard reports on the active
semantic method; retired-method records are retained locally but excluded from
the dashboard and its exports. Historical test data is not part of the discovery
data package.

### Ask AI Naming and Compatibility

The public page is **Ask AI** at `/ask-ai/`, using `[ehrman_ask_ai]` and the
`ehrman_discovery_page_ask_ai` page option. Version 0.10.2 reconnects the existing
canonical page, or reuses the former numbered page when it is the only one.
It preserves surrounding page content and retains a superseded managed page as
a draft rather than deleting it. The migration is repeatable and requires no
discovery-table or analytics-data migration.

Old `/ask-ai-2/` links redirect to `/ask-ai/`, preserving the reader's question
but discarding stale request IDs and method-specific filters. Other retired
Ask AI URLs retain the same redirect behavior. The `[ehrman_ask_ai_2]` and
`[ehrman_ask_question]` shortcodes remain compatibility aliases for the same
semantic form. The REST endpoint, stored request types, vector model, retrieval
algorithm, reviewer prompt and caches are unchanged. Historical comparison
reports keep their original names.

## 2. Discovery Data

Provide the five authoritative JSON files:

- `ehrman_post_search_index.json`
- `ehrman_post_topics.json`
- `ehrman_post_categories.json`
- `ehrman_post_subject_areas.json`
- `ehrman_post_subject_areas_2.json`

The data includes post metadata, concise descriptions, optional AI-refinement summaries, secondary keywords, organizational structures, and relationships among posts, topics, categories, and subject areas. It does not include the full text of Bart's posts.

Also provide `ehrman_post_embeddings.jsonl.gz`, the versioned, compressed
title-and-summary vector index. The plugin imports it by WordPress post ID and
rejects stale, malformed, duplicate, or incompatible records before changing
the database. The vector package does not contain full post text.

## 3. Integration and Maintenance Guide

Provide a consolidated guide explaining how to:

- Install and configure the plugin
- Import the JSON data and portable vector package
- Match imported records to existing WordPress posts using WordPress IDs or URLs
- Add the search and browsing pages to the production site
- Integrate the plugin with the existing WordPress theme
- Use the plugin's shortcodes, REST endpoints, administrative tools, and WP-CLI commands
- Update the discovery data when new posts are added
- Back up, validate, and roll back an import

Include the database schema and technical architecture as appendices.

### Initial Data Installation

Activate the plugin, put the five JSON files and `ehrman_post_embeddings.jsonl.gz`
in a private directory outside WordPress, and keep
`EHRMAN_DISCOVERY_POST_SOURCE=json` until the initial import is accepted. Back up
the database and pause editors, scheduled ingestion and other imports during installation.
The database user needs table/index creation and upgrade permissions, and named
lock support. All plugin tables and the WordPress options table must use InnoDB.

```sh
wp ehrman-discovery install-data --data-dir=/secure/ehrman-data --validate-only
wp ehrman-discovery install-data --data-dir=/secure/ehrman-data
```

The first command validates the entire package without installation writes.
Normal WordPress bootstrap/activation can independently create or upgrade schema.
Validation checks JSON relationships, vector
model/dimensions, content hashes, duplicates, binary values and complete coverage.
Coverage follows the existing search rules: Ignore posts and posts without a search
summary are excluded. Missing summaries on non-Ignore posts produce a warning.
The command imports supplied vectors; it never generates them or calls OpenAI.

Installation completes schema setup first, then writes metadata, relationships,
vectors, import status and a receipt in one transaction. A failed data load rolls
all those writes back. Schema changes cannot be rolled back by that transaction;
restore the backup if a schema upgrade itself must be undone. No WordPress articles,
users or analytics records are imported/replaced. Auto-increment counters can advance
after a rolled-back attempt even though the rows remain unchanged.

An identical rerun verifies the receipt's data fingerprint and performs no data
replacement, preserving IDs. A nonempty initial target or a different package needs
explicit `--replace-existing` consent after backup. This flag does NOT override
database edits detected since the prior installation, post-ingestion history, or
`EHRMAN_DISCOVERY_POST_SOURCE=mysql`. The legacy `import --force` command also
refuses database-authoritative mode. Do not switch back to JSON to bypass protection.
After the initial import and acceptance tests, the agreed handoff can switch the
source setting to `mysql`; this installation command does not change that setting.

Maintainers can run `wordpress-production/verify-installation.ps1` after building
the image. It creates a uniquely named, isolated WordPress/database stack, exercises
failure and rerun cases, installs the full package and runs regression checks with
API access disabled. It stops that test stack afterward and preserves its volumes
as evidence; it never resets the normal demo. CI runs it for MariaDB and MySQL.

### Analytics Presentation

`src/Analytics/AI_Analytics_Renderer.php` loads reports and prepares display values.
The dashboard HTML lives in `templates/admin/analytics/`: `dashboard.php` composes
the filters, question and review tables, period totals, and reset controls.
The source and token partials are reused in the summary and detail displays.
Templates receive explicit context through `Template::render()` and escape values
where they are output; they do not query or modify the database.

Keep authorization in `AI_Analytics_Page`, calculations in `AI_Analytics_Report`,
and CSV/reset operations in their existing handlers. Presentation edits should
not change those behaviors. The analytics PHP and browser regression tests in
`wordpress-production/tests/` cover these boundaries, including escaping,
pagination, empty states, cached/failed requests, exports, and reset protection.

### Post Ingestion Presentation

`src/Ingestion/Post_Ingestion_Page.php` retains page authorization, form handlers,
notice consumption, and display-data preparation. Its remaining page, draft,
review, approved-result, history, and action markup lives in
`templates/admin/post-ingestion/`; the existing new-submission template remains
at `templates/admin/post-ingestion-new.php`. Templates escape display values and
render nonce-protected forms; they do not query the database or run ingestion.

Analysis, review, approval, background processing, and vector generation remain
in the existing ingestion services. The production regression suite exercises
the page's workflow states, form nonces, JSON-mode approval lock, escaping,
history, and notices with rolled-back fixtures and no API calls.

### Public Search Presentation

`src/KeywordSearch/Search_Page_Renderer.php` prepares the Keyword Search page
and the controls and post results reused by Browse Topics. Its HTML lives in
`templates/public/search/`: the page, search panel, selected terms, category
selector, results summary, post list, and pagination each have a template.
Templates receive explicit display values and escape them when output; they do
not query the database or change search state. Search selection, ranking,
pagination URLs, and control IDs remain in their existing PHP services and
renderer. The existing JavaScript hooks, field names, and accessibility
attributes must be preserved when updating these templates.

`wordpress-production/tests/search-page-tests.php` is included in the production
acceptance suite. It covers term limits and modes, category selection, sorting,
escaping, empty states, result ordering, pagination, and repeated control IDs.
`search-page-ui.cjs` checks the live Keyword Search and both Browse Topics paths
at desktop and mobile sizes, including keyboard controls and no-JavaScript
pagination. Run it with Node from the repository root while the local demo is
running. Optional `--capture-before` and `--compare-before` modes save and compare
local DOM/screenshot baselines under `.tmp/search-page-ui/`; capture before a
code refresh, never overwrite the baseline with the changed page. These checks
are read-only and block AI requests.

## 4. Testing and Acceptance Package

### Required Separate Test ZIP

**Do not omit `ehrman-discovery-developer-tests.zip` from the handoff.** It is a
separate deliverable alongside the installable plugin, not code to install on
the live blog. Its maintained instructions are in
[developer-tests/README.md](../developer-tests/README.md).

The bundle includes a standalone WordPress capture command, deterministic cases,
the approved baseline, a Python-standard-library comparison command, production
PHP regression tests and their required partials, and a checksum/version manifest.
It needs neither the retired Python/SQLite app nor a paid API call. Developers
run it on a staging copy with matching imported JSON and vectors. An optional
token-protected HTTP compatibility add-on is supplied for staging only.

`Parity_Service` and `Parity_REST_Controller` now live in `developer-tests/test-tools`,
not the production plugin. The production plugin never registers the comparison
route, even if a legacy parity token is configured. Reviewer Tools remains a
separate demo feature and is not bundled with these developer tests.

Build with `scripts/package_wordpress_tests.py --baseline <approved-capture.json>
--output <new-zip-path>`. CI checks the explicit delivery manifest and runs the
extracted ZIP against clean WordPress/database setups. Final release acceptance
must regenerate a reference against the approved final data/search options,
verify the extracted bundle, and record the ZIP checksum and successful results.
The final handoff is incomplete without this test ZIP and its instructions.

### Frontend Source and Build

The public JavaScript and CSS are organized by functionality under the plugin's
`assets/src/`. See [the frontend source map and build instructions](../wordpress-plugin/ehrman-blog-discovery/assets/README.md).
Include those sources and the checked-in generated `assets/js/discovery.js` and
`assets/css/discovery.css` in the source release. Include the root package files,
`scripts/build_wordpress_assets.cjs`, and frontend tests with developer tooling.
The pinned build dependency is not required on the WordPress server. CI checks
that generated files match their sources before running WordPress regressions.
The build detects the optional demo companion only when it is present; the
`--production-only` flag explicitly limits builds/checks to production assets.
Keep reviewer-specific test fixtures with internal demo tests, not the installable
production plugin. Deliver the production search and ingestion regression tests.

### Acceptance Materials

- JSON and relationship validation scripts
- Expected database-count checks
- Search-parity test cases and results
- WordPress coding-standard and static-analysis configuration
- Security-scan results
- Accessibility, responsive-layout, and browser-testing checklist
- Final production acceptance checklist

These materials allow the developers to verify that their implementation behaves like the approved demo.

## 5. Working Reference Materials

- Live demonstration website
- Companion demo theme as an optional visual reference
- Search and browsing diagrams
- Presentation deck
- Screenshots and examples of expected behavior

The production site should continue using Bart's existing WordPress theme. The demo theme is only a reference for the intended layout and interactions.

## Materials Not Required

Do not include:

- Render deployment documentation
- Bart's complete post contents
- Passwords or hosting credentials
- Internal auditing files and temporary working scripts
- The old Python, PHP, or standalone HTML implementations
- `wordpress-demo-plugins/`, including Reviewer Tools and its internal tests
- `experiments/`, including Ask AI 3 and other experimental plugins, section
  vectors, benchmark inputs/results, private full-text data and runtime bundles
- Local recovery archives and database backups

The demo Docker build excludes all of `experiments/` through `.dockerignore` and
copies the production plugin, optional Reviewer Tools companion, demo theme,
explicit canonical data files and regression checks. This demo image is not the
production plugin ZIP. The repository retains experimental source for recovery; that
does not make it part of the installable plugin or the handoff package.

## Summary

The focused handoff package consists of the plugin, authoritative discovery data, integration instructions, validation materials, and a working reference implementation.
