# Bart Ehrman Blog Indexing

This workspace is for building a local index of Ehrman Blog posts.

The raw full-text export is committed for private backup/indexing use. Keep
this repository private unless you remove membership-site content first.

## Workflow

1. Put the temporary member credentials in `.ehrman_credentials.env`.
2. Visit each post URL and save clean post metadata plus full text.
3. Produce summaries and candidate tags.
4. Review the candidate tags and normalize posts against a controlled tag set.

## Files

- `scripts/ehrman_http_indexer.py`: scraper/indexer for the logged-in site.
- `.ehrman_credentials.env.example`: template for the temporary login file.
- `data/raw/archive_months.json`: discovered monthly archive URLs and visible counts.
- `data/raw/post_urls.json`: discovered post URLs and archive-source metadata.
- `data/raw/posts.jsonl`: full extracted post records, one JSON object per line.
- `data/index/posts_index.json`: summary/tag index generated from raw posts.
- `data/index/posts_index.csv`: spreadsheet-friendly version of the same index.

## Run

Create `.ehrman_credentials.env`:

```ini
EHRMAN_USERNAME=...
EHRMAN_PASSWORD=...
```

Run a small authenticated pilot:

```powershell
python -B scripts\ehrman_http_indexer.py --reset --limit-months 1 --limit-posts 3
```

Run the full archive:

```powershell
python -B scripts\ehrman_http_indexer.py --reset
```

## Search Parity

Generate the routine 500-case Python/PHP comparison suite:

```powershell
python -B scripts\search_parity.py generate --profile standard
```

Use `--profile smoke` for a quick deployment check. The much larger
`--profile full` suite is retained only for optional stress testing.

Remote captures can use smaller batches plus retry and resume support:

```powershell
python -B scripts\search_parity.py capture --base-url https://example.onrender.com `
  --batch-size 25 --retries 5 --resume
```

## PHP comparison application

The independent PHP 8.4 implementation is in `phpapp/`. It uses the same
SQLite schema and browser assets as the Python demo while preserving the
existing Python Render service as the reference application. See
`phpapp/README.md` for local startup, Docker deployment, and the 500-case
Python-to-PHP comparison workflow.

## WordPress/MySQL proof of concept

The WordPress migration is being developed alongside the existing demos:

- Architecture and schema: `docs/wordpress_mysql_migration_architecture.md`
- Local Docker environment: `wordpress-demo/`
- WordPress plugin source: `wordpress-plugin/ehrman-blog-discovery/`

After Docker Desktop is installed and running, bootstrap the isolated local
environment with:

```powershell
.\wordpress-demo\setup-wordpress.ps1
```

Then verify WordPress, MySQL, the plugin, and its REST status endpoint with:

```powershell
.\wordpress-demo\verify-wordpress.ps1
```

After importing, Phase 4 is available at:

- `http://localhost:8085/keyword-search/`
- `http://localhost:8085/browse-topics-1/`
- `http://localhost:8085/browse-topics-2/`

Import and strictly verify the authoritative discovery index with:

```powershell
docker compose -f .\wordpress-demo\compose.yaml run --rm wpcli ehrman-discovery import --force --path=/var/www/html
.\wordpress-demo\verify-wordpress.ps1 -RequireImport
```

Phase 5 parity, security, accessibility, integrity, and MySQL performance
results are documented in `docs/wordpress_phase5_validation_report.md`.

The production-equivalent Docker package is in `wordpress-production/`. It
uses a separate port and separate volumes, bakes the plugin, theme, and import
sources into the image, and leaves all existing demos unchanged:

```powershell
.\wordpress-production\setup-production-test.ps1
.\wordpress-production\verify-production-test.ps1 -PythonExecutable <python.exe>
```

Local readiness evidence is in
`docs/wordpress_local_production_readiness_report.md`. The Render staging and
rollback procedure is in `docs/wordpress_render_deployment_runbook.md`. The
inactive staging Blueprint is `render-wordpress.yaml`; the active root
`render.yaml` has not been changed.
