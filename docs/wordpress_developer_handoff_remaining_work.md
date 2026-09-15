# WordPress Developer Handoff: Remaining Work

This checklist tracks the work required before providing the Ehrman Blog Discovery plugin and data package to the development company.

## Existing Foundation

The plugin already provides:

- Versioned custom MySQL tables created through WordPress activation and upgrade routines.
- Authoritative JSON validation and import.
- Browse Topics, Keyword Search, Ask AI 1, and Ask AI 2 implementations.
- AI request analytics, feedback tracking, rate limiting, and cost estimates.
- Title-and-summary vector generation and semantic retrieval.
- An administrator-only Post Ingestion workflow with AI analysis, review, approval, audit evidence, and vector generation.
- A production-equivalent WordPress/MySQL Docker environment and acceptance suite.

## Remaining Work

### 1. Finalize Production Search Options

- Select one Browse Topics structure.
- Use the selected Ask AI implementation.
- Rename the public options to Browse Topics, Keyword Search, and Ask AI.
- Remove evaluation numbering, alternative public routes, comparisons, and Reviewer Tools from the production package.
- Preserve experimental implementations and results in repository history or an evaluation archive.

### 2. Add Portable Vector Export And Import

- Export the existing title-and-summary vectors to a portable compressed artifact.
- Add a WP-CLI vector importer that matches records by WordPress post ID.
- Validate the embedding model, dimensions, content hash, vector norm, and associated post before import.
- Reject stale, malformed, duplicate, or incompatible vectors.
- Make the process idempotent and report imported, skipped, missing, and rejected counts.

Current Render deployments do not import precomputed vectors. They preserve existing vectors in persistent MySQL and run `wp ehrman-discovery embeddings` to generate missing or stale vectors through the Embeddings API. The portable importer will eliminate the need to regenerate the initial supplied index.

### 3. Provide One Installation Command

- Create or upgrade all required tables.
- Import the selected authoritative JSON files.
- Import the supplied vector artifact.
- Verify relationships, expected counts, and vector coverage.
- Support repeatable execution without duplicate data.
- Stop or roll back when critical validation fails.

Suggested interface:

```bash
wp ehrman-discovery install-data --data-dir=/secure/ehrman-data
```

### 4. Standardize Secure API Configuration

- Add dedicated `EHRMAN_DISCOVERY_OPENAI_API_KEY` environment-variable support for reader search and embeddings.
- Retain `OPENAI_API_KEY` temporarily as a backward-compatible fallback.
- Continue using `EHRMAN_INGESTION_OPENAI_API_KEY` for administrator ingestion.
- Add configuration status that reports only configured or not configured.
- Include empty secret placeholders in deployment templates and instructions.
- Never store or expose API keys in Git, JSON, browser code, logs, exports, or the plugin package.

### 5. Integrate Ingestion Into The WordPress Post Editor

- Add a Search Metadata panel to the normal WordPress post editor.
- Obtain the post ID, title, URL, author, publication date, and complete text directly from WordPress.
- Provide Analyze for Search, review, edit, approve, and retry actions.
- Run AI analysis asynchronously so it does not delay or prevent normal publication.
- Display analysis and vector status clearly.
- Keep the existing Post Ingestion page as an administrative fallback and audit view.

### 6. Handle The WordPress Post Lifecycle

- Mark search metadata for review when important post content changes.
- Mark the semantic vector stale when the title or search summary changes.
- Rebuild stale vectors after approval.
- Exclude unpublished, trashed, or deleted posts from discovery results.
- Restore or reanalyze posts when their publication status changes appropriately.
- Provide retry handling for failed AI analysis and vector generation.

### 7. Complete The MySQL Source-Of-Truth Transition

- Use JSON and the supplied vectors for the initial production import.
- Set `EHRMAN_DISCOVERY_POST_SOURCE=mysql` after the approved handoff.
- Enable post-editor approval to update the live discovery index.
- Prevent later JSON imports from accidentally overwriting MySQL-authoritative changes.
- Document backup, restore, rollback, and source-of-truth recovery procedures.

### 8. Prepare The Final Handoff Package

- Installable, versioned plugin ZIP and corresponding source release.
- The selected authoritative JSON files.
- The portable vector export.
- Installation, upgrade, rollback, and API-configuration instructions.
- Database schema and technical architecture reference.
- Expected counts and acceptance checklist.
- Working demonstration and representative screenshots.

### 9. Run Final Production Testing

- Test a fresh WordPress installation and an upgrade from the current plugin version.
- Test JSON and vector import, repeat import, rollback, and duplicate handling.
- Test editor capabilities, nonces, authorization, and audit records.
- Test API failures, timeouts, malformed responses, and vector retry behavior.
- Test caching, CDN behavior, permalinks, and result links.
- Test responsive layout, keyboard navigation, accessibility, and supported browsers.
- Run representative Browse Topics, Keyword Search, and Ask AI acceptance cases.

## Principal Missing Features

The two largest development items are:

1. Portable export and import of the existing vector index.
2. Integration of the ingestion workflow into the normal WordPress post editor.

The remaining items primarily consolidate the selected production experience, complete the MySQL transition, and package and validate the handoff.
