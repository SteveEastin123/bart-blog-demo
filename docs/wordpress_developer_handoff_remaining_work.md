# WordPress Developer Handoff: Remaining Work

This checklist tracks the work required before providing the Ehrman Blog Discovery plugin and data package to the development company.

Reviewed against plugin **0.11.0**, schema **1.16.0**, on November 17, 2026.
Implemented capabilities below are distinct from final packaging and acceptance
tasks. This is not confirmation of the version currently deployed on Render.
The owner confirmed that publishers have the required permissions; no role or
capability changes are requested.

## Existing Foundation

The plugin already provides:

- Versioned custom database tables created through WordPress activation and upgrade routines.
- Authoritative JSON validation and import.
- Browse Topics, Keyword Search, and the selected Ask AI implementation.
- AI request analytics, rate limiting, and cost estimates. Helpfulness voting has
  been retired; no feedback endpoint, controls, filters, or CSV column are delivered.
- Title-and-summary vector generation and semantic retrieval.
- Versioned, compressed vector export and transactional, idempotent import by WordPress post ID.
- An administrator-only metadata workflow with analysis, review, approval,
  safe revisions of approved records, audit evidence, and vector generation.
- A Search Metadata panel in the normal WordPress post editor with protected
  endpoints, foreground metadata generation, one approval for AI-selected topics
  and keywords, term removal/undo, duplicate protection, stalled-attempt recovery,
  and a Tools > Search Metadata worklist. Manual is the default.
- Opt-in Automatic processing after first publication through WordPress cron,
  with the same validation/approval rules and manual failure recovery.
- Autosaving Manual/Automatic site settings and per-post overrides before publication.
- Native-post visibility, restoration, permanent-deletion cleanup, and retry handling
  for explicitly linked records.
- Database-managed approval by default, with an explicit JSON-managed preparation mode.
- A production-equivalent WordPress/MariaDB Docker environment and acceptance suite.

## Capability Status

### 1. Verify The Selected Production Search Options

**Implemented; final package verification remains.**

- Browse Topics is selected: the first hierarchy is retained at `/browse-topics/`
  with `[ehrman_browse_topics]`. The second hierarchy and its JSON file are retired.
- Ask AI is selected. Ask AI 1 is retired from the public plugin; the local
  section-based Ask AI 3 is parked. See [recovery instructions](ask_ai_retirement.md)
  for the GitHub checkpoint and required private vector backups.
- Ask AI is now the public name and `/ask-ai/` the canonical URL.
- Numbered browsing URLs remain only as compatibility redirects. Historical
  implementations and evaluation reports are retained outside the production plugin.
- Reviewer Tools and comparison tooling are separate from the production plugin.
  Verify the final package excludes the demo-only reviewer companion and includes
  the required separate developer-test ZIP.

### 2. Provide One Installation Command

**Implemented; final target-environment acceptance remains.**

Implemented in plugin 0.10.6 as `wp ehrman-discovery install-data`. It validates
the combined package before installation, coordinates metadata/vector writes in
one transaction, verifies coverage, and preserves identical reruns. Replacement
requires explicit consent; later database edits and database-authoritative mode
are protected. See the installation guide in `wordpress_developer_handoff_package.md`.
Final acceptance still requires running it against the approved production environment.

Historical verification on October 25, 2026 passed on a fresh isolated MariaDB site:
4,446 metadata records and 4,016 supplied vectors installed, identical reruns
preserved data, injected write failures rolled back, and all 22 approved search
cases retained their results and ordering. The extracted developer-test ZIP and
the plugin regression suite passed. No API calls were made. CI has targeted
MariaDB only since October 28; the Oracle MySQL job has been retired.
Those counts describe that test snapshot, not the final delivery package.

The implemented command validates relationships and vector coverage, creates or
upgrades tables, imports metadata and supplied vectors transactionally, supports
verified identical reruns, and stops or rolls back on critical data-load failures.
Schema changes are outside the data transaction; keep a backup for schema rollback.

Available interface:

```bash
wp ehrman-discovery install-data --data-dir=/secure/ehrman-data
```

### 3. Standardize Secure API Configuration

**Shared credentials implemented; production configuration and acceptance remain.**

- One server-side `OPENAI_API_KEY` serves search, metadata generation, and embeddings.
- Search and ingestion retain separate model settings and usage records.
- Before installation, the receiving team must configure its key and migrate any
  retired per-feature variables. Never include real keys in delivery files.
- Verify missing-key messages and secret redaction on staging; any shared
  configuration examples must contain placeholders only.

### 4. Handle The WordPress Post Lifecycle

**Implemented for explicitly linked posts; imported-post integration remains.**

- Pending approval detects source changes and rejects stale proposals. Later
  article edits require explicit metadata editing or regeneration; Automatic mode
  runs only after first publication, not on every update.
- Approved metadata revisions preserve live data until replacement succeeds.
  Changed title/summary input requires a replacement vector; unchanged input can
  reuse a valid vector. Failed generation and vector writes have recovery controls.
- Native-post ownership drives result visibility, restoration, and permanent-delete
  cleanup. Autocomplete uses retained index metadata without publication checks;
  a suggestion never bypasses the actual post-result visibility checks.
- Imported `source_wp_id` values do not establish native `wordpress_post_id`
  ownership. Unlinked imported metadata remains read-only, and native-post cleanup
  does not cover it. A validated production linking process and associated
  maintenance workflow remain to be implemented and accepted separately.
- Production mapping must not blindly claim matching IDs from an unrelated demo
  or staging database. No linking change is included in this documentation update.

### 5. Complete The Database Source-Of-Truth Transition

**Implemented; final production import and recovery rehearsal remain.**

- Production defaults to database-managed approval without a source-mode setting.
  The legacy value `mysql` also covers MariaDB; it is not a database selector.
- The four JSON files and supplied vectors seed an empty production index.
  Later publishing updates the database. Verified identical installation reruns
  do not replace data, and database-managed replacements are blocked.
- Local handoff preparation retains explicit `EHRMAN_DISCOVERY_POST_SOURCE=json`.
  Data ownership is independent of the Manual/Automatic publishing selection.
- Render is a separate demo environment. Verify its deployed version and settings
  when testing; a Render deployment is not the production installation procedure.
- Build handoff data only from approved local JSON and validated vectors, never
  from a Render dump. Render test posts do not need to be deleted.
- Confirm the receiving team's backup/restore process and documented recovery
  steps. Later JSON refreshes are not routine production maintenance and must not
  bypass protections on editorial data.

### 6. Prepare The Final Handoff Package

**Pending final release.** A developer-test ZIP builder exists; a repeatable
production-plugin and overall delivery build remains a packaging task.

- Installable, versioned plugin ZIP and corresponding source release.
- The four authoritative JSON files (posts, topics, categories and the selected subject areas).
- The portable vector export.
- **Required separate `ehrman-discovery-developer-tests.zip`**, including cases,
  approved baseline, standalone capture/comparison commands, production regression
  tests, instructions and checksum manifest. The builder and CI checks now exist
  in `developer-tests/` and `scripts/package_wordpress_tests.py`; regenerate and
  verify the extracted ZIP against the final approved data before delivery.
- Installation, upgrade, rollback, and API-configuration instructions.
- Database schema and technical architecture reference.
- Expected counts and acceptance checklist.
- Working demonstration and representative screenshots.

### 7. Run Final Production Testing

**Pending final release.** Focused tests and previous full runs are not a
substitute for acceptance of the exact archives and data delivered.

- Verify the receiving team's WordPress, PHP, MariaDB, theme, REST access,
  foreground-request timeouts, and cron configuration. Test declared minimum
  versions or explicitly revise the supported-version statement.
- Test a fresh WordPress installation and an upgrade from the current plugin version.
- Test JSON and vector import, repeat import, rollback, and duplicate handling.
- Test editor capabilities, nonces, authorization, and audit records.
- Test API failures, timeouts, malformed responses, and vector retry behavior.
- Test caching, CDN behavior, permalinks, and result links.
- Test responsive layout, keyboard navigation, accessibility, and supported browsers.
- Run representative Browse Topics, Keyword Search, and Ask AI acceptance cases.
- Run the exact delivered test ZIP from a clean private directory on staging;
  confirm both comparison and PHP regression commands pass without repository
  dependencies. Record the archive checksum with the final handoff results.

## Remaining Handoff Work

1. Resolve and validate native ownership and metadata maintenance for imported posts.
2. Build versioned production and data packages with explicit file inclusion,
   checksums, expected counts, and the required separate developer-test ZIP.
3. Verify compatibility and operation on the receiving team's staging environment.
4. Run final regression, standards/static analysis, installation, upgrade/rollback,
   browser/accessibility, and extracted-package checks; retain release-specific results.

The README and handoff guides now describe the current Manual/Automatic workflow,
UI labels, release/schema versions, and integration limits. Keep dated historical
test reports as evidence, but do not present them as current release acceptance.
No new search algorithm, permissions change, or broad module refactor is required
by this checklist update.
