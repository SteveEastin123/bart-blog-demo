# WordPress Developer Handoff: Remaining Work

This checklist tracks the work required before providing the Ehrman Blog Discovery plugin and data package to the development company.

## Existing Foundation

The plugin already provides:

- Versioned custom database tables created through WordPress activation and upgrade routines.
- Authoritative JSON validation and import.
- Browse Topics, Keyword Search, and the selected Ask AI implementation.
- AI request analytics, rate limiting, and cost estimates. Helpfulness voting has
  been retired; no feedback endpoint, controls, filters, or CSV column are delivered.
- Title-and-summary vector generation and semantic retrieval.
- Versioned, compressed vector export and transactional, idempotent import by WordPress post ID.
- An administrator-only Post Ingestion workflow with AI analysis, review, approval, audit evidence, and vector generation.
- A Search Metadata panel in the normal WordPress post editor with protected
  endpoints, foreground metadata generation, one approval for AI-selected topics
  and keywords, duplicate protection, stalled-attempt recovery, and a Tools-page fallback.
- A production-equivalent WordPress/MariaDB Docker environment and acceptance suite.

## Remaining Work

### 1. Verify The Selected Production Search Options

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

Implemented in plugin 0.10.6 as `wp ehrman-discovery install-data`. It validates
the combined package before installation, coordinates metadata/vector writes in
one transaction, verifies coverage, and preserves identical reruns. Replacement
requires explicit consent; later database edits and database-authoritative mode
are protected. See the installation guide in `wordpress_developer_handoff_package.md`.
Final acceptance still requires running it against the approved production environment.

Local verification on October 25, 2026 passed on a fresh isolated MariaDB site:
4,446 metadata records and 4,016 supplied vectors installed, identical reruns
preserved data, injected write failures rolled back, and all 22 approved search
cases retained their results and ordering. The extracted developer-test ZIP and
the plugin regression suite passed. No API calls were made. CI has targeted
MariaDB only since October 28; the Oracle MySQL job has been retired.

- Create or upgrade all required tables.
- Import the selected authoritative JSON files.
- Import the supplied vector artifact.
- Verify relationships, expected counts, and vector coverage.
- Support repeatable execution without duplicate data.
- Stop or roll back when critical validation fails.

Available interface:

```bash
wp ehrman-discovery install-data --data-dir=/secure/ehrman-data
```

### 3. Standardize Secure API Configuration

- Use one server-side `OPENAI_API_KEY` for reader search, administrator ingestion, and embeddings (implemented).
- Keep search and ingestion model settings and usage records separate.
- Migrate any old per-feature keys to `OPENAI_API_KEY` before updating those deployments.
- Add configuration status that reports only configured or not configured.
- Include empty secret placeholders in deployment templates and instructions.
- Never store or expose API keys in Git, JSON, browser code, logs, exports, or the plugin package.

### 4. Handle The WordPress Post Lifecycle

- Mark search metadata for review when important post content changes.
- Mark the semantic vector stale when the title or search summary changes.
- Rebuild stale vectors after approval.
- Editor-created records now carry explicit native-post ownership. Unpublished,
  private, trashed, and deleted posts are excluded from discovery results;
  republishing restores retained metadata/vectors without new AI calls.
- Permanent WordPress deletion removes only linked records; it is not a test-post
  purge. Existing imported/unlinked records are not automatically matched to
  native posts. Production mapping of those records remains an integration task.
- Provide retry handling for failed AI analysis and vector generation.

### 5. Complete The Database Source-Of-Truth Transition

- Use JSON and the supplied vectors for the initial production import.
- Keep the local preparation copy JSON-managed. Render is prepared for
  `EHRMAN_DISCOVERY_POST_SOURCE=mysql` publishing tests, but deployment and live
  enablement still need authorization. Production defaults to database-managed
  approval without a source-mode setting. The legacy `mysql` value also covers MariaDB.
- Enable post-editor approval to update the live discovery index.
- Database-managed replacements are blocked; initial installation can seed an
  empty index and verify identical reruns. Initialization preserves an existing
  index. Future JSON refreshes on Render
  require a backed-up, explicitly approved reconciliation procedure.
- Keep Render test posts out of the handoff by packaging approved local JSON and
  validated vectors, not by deleting test posts or exporting Render's database.
- Document backup, restore, rollback, and source-of-truth recovery procedures.

### 6. Prepare The Final Handoff Package

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

## Principal Missing Features

The post-editor ingestion integration and recoverable foreground analysis are
complete. The largest remaining development item is automatic handling of the
WordPress post lifecycle, followed by the MySQL source-of-truth transition.
The other items consolidate the selected production experience and package and
validate the handoff.
