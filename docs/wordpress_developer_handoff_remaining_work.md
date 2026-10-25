# WordPress Developer Handoff: Remaining Work

This checklist tracks the work required before providing the Ehrman Blog Discovery plugin and data package to the development company.

## Existing Foundation

The plugin already provides:

- Versioned custom MySQL tables created through WordPress activation and upgrade routines.
- Authoritative JSON validation and import.
- Browse Topics, Keyword Search, and the selected Ask AI implementation.
- AI request analytics, feedback tracking, rate limiting, and cost estimates.
- Title-and-summary vector generation and semantic retrieval.
- Versioned, compressed vector export and transactional, idempotent import by WordPress post ID.
- An administrator-only Post Ingestion workflow with AI analysis, review, approval, audit evidence, and vector generation.
- A Search Metadata panel in the normal WordPress post editor with protected
  endpoints, queued background analysis, status polling, duplicate protection,
  stalled-job recovery, and a Tools-page fallback.
- A production-equivalent WordPress/MySQL Docker environment and acceptance suite.

## Remaining Work

### 1. Finalize Production Search Options

- Select one Browse Topics structure.
- Ask AI is selected. Ask AI 1 is retired from the public plugin; the local
  section-based Ask AI 3 is parked. See [recovery instructions](ask_ai_retirement.md)
  for the GitHub checkpoint and required private vector backups.
- Ask AI is now the public name and `/ask-ai/` the canonical URL. Rename the
  selected Browse Topics structure after that choice is finalized.
- Remove evaluation numbering, alternative public routes, comparisons, and Reviewer Tools from the production package.
- Preserve experimental implementations and results in repository history or an evaluation archive.

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
the plugin regression suite passed. No API calls were made. MySQL coverage is
configured in CI but was not rerun locally for this change.

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

- Add dedicated `EHRMAN_DISCOVERY_OPENAI_API_KEY` environment-variable support for reader search and embeddings.
- Retain `OPENAI_API_KEY` temporarily as a backward-compatible fallback.
- Continue using `EHRMAN_INGESTION_OPENAI_API_KEY` for administrator ingestion.
- Add configuration status that reports only configured or not configured.
- Include empty secret placeholders in deployment templates and instructions.
- Never store or expose API keys in Git, JSON, browser code, logs, exports, or the plugin package.

### 4. Handle The WordPress Post Lifecycle

- Mark search metadata for review when important post content changes.
- Mark the semantic vector stale when the title or search summary changes.
- Rebuild stale vectors after approval.
- Exclude unpublished, trashed, or deleted posts from discovery results.
- Restore or reanalyze posts when their publication status changes appropriately.
- Provide retry handling for failed AI analysis and vector generation.

### 5. Complete The MySQL Source-Of-Truth Transition

- Use JSON and the supplied vectors for the initial production import.
- Set `EHRMAN_DISCOVERY_POST_SOURCE=mysql` after the approved handoff.
- Enable post-editor approval to update the live discovery index.
- Prevent later JSON imports from accidentally overwriting MySQL-authoritative changes.
- Document backup, restore, rollback, and source-of-truth recovery procedures.

### 6. Prepare The Final Handoff Package

- Installable, versioned plugin ZIP and corresponding source release.
- The selected authoritative JSON files.
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

The post-editor ingestion integration and recoverable background analysis are
complete. The largest remaining development item is automatic handling of the
WordPress post lifecycle, followed by the MySQL source-of-truth transition.
The other items consolidate the selected production experience and package and
validate the handoff.
