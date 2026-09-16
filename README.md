# Bart Ehrman Blog Discovery

This repository contains the discovery system for browsing and searching Bart
Ehrman's blog. The current product and handoff target is the WordPress/MySQL
implementation. It provides Browse Topics, Keyword Search, Ask AI 1, Ask AI 2,
reviewer tools, AI analytics, and an administrator post-ingestion workflow.

The repository is private because its indexing sources include member-only post
content. Do not publish the repository or its raw data.

## Current Architecture

| Component | Location | Purpose |
| --- | --- | --- |
| WordPress plugin | `wordpress-plugin/ehrman-blog-discovery/` | Search, browsing, AI retrieval, analytics, ingestion, and custom MySQL tables |
| Demo theme | `wordpress-theme/ehrman-discovery-demo/` | Production-equivalent presentation and landing page |
| Local/Render image | `wordpress-production/` | Reproducible WordPress/PHP/MySQL build and acceptance tooling |
| Render blueprint | `render-wordpress.yaml` | WordPress and private MySQL services; applied manually |
| Canonical discovery data | Five selected files in `data/index/` | Current JSON source of truth for imported posts and taxonomy |
| Portable semantic index | `data/index/ehrman_post_embeddings.jsonl.gz` | Precomputed Ask AI 2 title-and-summary vectors |

The five imported JSON files are:

- `ehrman_post_search_index.json`
- `ehrman_post_topics.json`
- `ehrman_post_categories.json`
- `ehrman_post_subject_areas.json`
- `ehrman_post_subject_areas_2.json`

JSON remains authoritative during development. A rebuild imports those files
into MySQL, preserving the current workflow for downloaded posts. At the final
source-of-truth handoff, MySQL will become authoritative and the administrator
ingestion workflow will update it directly.

## Local WordPress Build

Docker Desktop must be running. Build or refresh the production-equivalent
local system on port `8086`:

```powershell
.\wordpress-production\setup-production-test.ps1
```

The main entry points are:

- `http://localhost:8086/`
- `http://localhost:8086/browse-topics-1/`
- `http://localhost:8086/browse-topics-2/`
- `http://localhost:8086/keyword-search/`
- `http://localhost:8086/ask-ai/`
- `http://localhost:8086/ask-ai-2/`
- `http://localhost:8086/structure-review/`

Run the complete local acceptance suite after plugin, theme, canonical-data, or
build changes:

```powershell
.\wordpress-production\verify-production-test.ps1 `
  -PythonExecutable 'C:\path\to\python.exe'
```

The suite validates the Docker configuration, packaged PHP syntax, imported
counts, private source placement, public pages, representative searches,
pagination, and focused regressions for semantic-vector coverage, post
ingestion, and AI analytics.

## Semantic Index

Ask AI 2 uses one title-and-summary vector for every eligible post. The vectors
are stored in MySQL and are not part of the canonical JSON files. A versioned,
compressed package at `data/index/ehrman_post_embeddings.jsonl.gz` supplies the
initial index without paid embedding calls. Fresh production-equivalent
installations import it automatically.

Export or restore the complete package through WP-CLI:

```bash
wp ehrman-discovery embeddings export --file=/secure/ehrman_post_embeddings.jsonl.gz --allow-root --path=/var/www/html
wp ehrman-discovery embeddings import --file=/secure/ehrman_post_embeddings.jsonl.gz --allow-root --path=/var/www/html
```

Build only vectors that are missing or stale after new posts are ingested with:

```bash
wp ehrman-discovery embeddings --allow-root --path=/var/www/html
```

The selected production pipeline uses the title-and-summary vector together
with lexical and exact topic, alias, and secondary-keyword ranking signals.
The retired two-vector experiment is preserved only in historical evaluation
reports under `docs/` and `data/evaluations/`.

Import is transactional and idempotent. It validates the package version,
embedding model, dimensions, post IDs, title-and-summary hashes, binary lengths,
and vector norms before changing MySQL. The semantic index is ready only when
every eligible post has a current vector. `wp ehrman-discovery status` reports
current, missing, stale, and obsolete vector counts. See
`docs/wordpress_portable_vector_index.md` for the package contract.

## Render

`render-wordpress.yaml` defines the current WordPress/MySQL target. Render
updates are intentionally manual: commit and push the repository, then apply or
deploy the WordPress service through Render and run the import/index verification
steps in `docs/wordpress_render_deployment_runbook.md`.

The root `render.yaml` belongs to the legacy Python demonstration and is not the
WordPress handoff or deployment definition.

## Development Checks

The plugin has WordPress Coding Standards and maximum-level PHPStan checks:

```powershell
cd wordpress-plugin\ehrman-blog-discovery
composer install
composer check
```

GitHub Actions runs those static checks and the production-equivalent
WordPress/MySQL regression suite on pushes and pull requests. The same complete
regression suite is run locally by `verify-production-test.ps1`.

## Data Maintenance

The established post-ingestion workflow downloads new posts, prepares their
descriptions and search summaries, assigns controlled topics and secondary
keywords, updates the canonical JSON, rebuilds the local WordPress/MySQL demo,
and creates missing title-and-summary vectors.

Temporary member credentials belong only in `.ehrman_credentials.env`, which is
ignored by Git. API keys and WordPress credentials must be supplied through
environment variables and must never be committed.

## Legacy Reference Implementations

The following directories remain for historical comparison and parity evidence;
they are not the current product or handoff target:

- `webapp/` and `app.py`: Python/SQLite demonstration
- `phpapp/`: independent PHP/SQLite comparison application
- `wordpress-demo/`: earlier WordPress development stack on port `8085`
- `ehrman_search_demo.html`: standalone HTML demonstration
- `render.yaml`: legacy Python Render service

Search-parity tooling and prior validation reports remain useful as historical
evidence, but new production work should be made and verified against
`wordpress-plugin/`, `wordpress-theme/`, and `wordpress-production/`.
