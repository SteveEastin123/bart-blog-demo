# Local WordPress/MySQL Demo

This isolated Docker Compose environment runs the WordPress/MySQL version of
the Ehrman Blog Discovery demo. It runs alongside the
existing standalone HTML, PHP/SQLite, and Python/SQLite implementations.

## Services

- WordPress with Apache and PHP 8.3
- MySQL 8.4
- Optional WP-CLI utility container
- Bind-mounted `Ehrman Blog Discovery` plugin source
- Bind-mounted `Ehrman Discovery Demo` companion theme source
- Read-only access to the authoritative JSON index files for Phase 3 imports

Persistent WordPress and MySQL data live in named Docker volumes. The current
demo files and databases are not modified.

## Requirements

- Docker Desktop with Docker Compose v2
- PowerShell 7 or Windows PowerShell 5.1

## Start and Install

From the repository root:

```powershell
.\wordpress-demo\setup-wordpress.ps1
```

The default local URLs are:

- WordPress: `http://localhost:8085`
- Administrator: `http://localhost:8085/wp-admin/`
- Plugin status page: `http://localhost:8085/ehrman-discovery-status/`
- Keyword Search: `http://localhost:8085/keyword-search/`
- Browse Topics 1: `http://localhost:8085/browse-topics-1/`
- Browse Topics 2: `http://localhost:8085/browse-topics-2/`
- REST status: `http://localhost:8085/wp-json/ehrman-discovery/v1/status`

The bootstrap generates and prints a local administrator password on the first
installation. A specific local password can be supplied if desired:

```powershell
.\wordpress-demo\setup-wordpress.ps1 -AdminPassword "choose-a-local-password"
```

## Verify

After the environment starts, run:

```powershell
.\wordpress-demo\verify-wordpress.ps1
```

The verifier checks the Compose configuration, runs PHP syntax checks inside
the WordPress container, calls the plugin REST endpoint, and confirms that the
plugin can reach MySQL. With `-RequireImport`, it also verifies exact imported
counts, the three discovery pages, autocomplete, AND filtering, and a
representative ranked search.

## Import the Authoritative Index

Run the transactional Phase 3 importer from the repository root:

```powershell
docker compose -f .\wordpress-demo\compose.yaml run --rm wpcli ehrman-discovery import --force --path=/var/www/html
```

The importer validates all five JSON sources before replacing the dedicated
plugin tables. A failed import rolls back the transaction and preserves the
previous dataset. Repeating the command without `--force` skips the import when
the combined source checksum is unchanged:

```powershell
docker compose -f .\wordpress-demo\compose.yaml run --rm wpcli ehrman-discovery import --path=/var/www/html
```

Inspect the imported counts with:

```powershell
docker compose -f .\wordpress-demo\compose.yaml run --rm wpcli ehrman-discovery status --path=/var/www/html
```

After importing, run the stricter verification mode:

```powershell
.\wordpress-demo\verify-wordpress.ps1 -RequireImport
```

## Stop

```powershell
docker compose -f .\wordpress-demo\compose.yaml down
```

To remove the local WordPress and MySQL volumes as well, append `--volumes`.
That operation permanently deletes the local container data and is not needed
for ordinary stops or restarts.

## Configuration

Compose has usable local-only defaults. To override ports or database values,
copy `.env.example` to `.env` inside this directory. The `.env` file is ignored
by Git.

Do not place Bart Ehrman Blog credentials in this environment. The WordPress
proof of concept searches locally imported metadata and links readers to Bart's
existing post URLs.

## Phase 4 Features

- Public WordPress pages for Keyword Search and both Browse Topics paths
- Category, subject-area, topic, and external-post navigation
- Up to four combined topic/secondary-keyword terms with AND filtering
- Category-scoped search and autocomplete
- Best-match, newest-first, and oldest-first sorting
- Responsive controls, description toggles, and accessible keyboard interaction
- Public read-only REST endpoints for search and suggestions

The active WordPress theme owns the global header, navigation, and footer. The
plugin owns only the discovery content and can therefore be integrated into the
production site's theme without duplicating site chrome.

## Phase 5 Validation

Run the repeatable warm-response benchmark with:

```powershell
.\wordpress-demo\benchmark-wordpress.ps1
```

The parity route is unavailable unless `EHRMAN_DISCOVERY_PARITY_TOKEN` is set.
When enabled in a controlled local environment, send the same value in the
`X-Ehrman-Parity-Token` header. Do not enable this route in normal production
operation.

Capture the 500 deterministic WordPress/MySQL results with:

```powershell
$env:EHRMAN_PARITY_TEST_TOKEN = '<same local test token>'
python -B scripts\search_parity.py capture `
  --cases tests\parity\artifacts\wordpress-phase5-cases.jsonl `
  --output tests\parity\artifacts\wordpress-phase5-mysql.jsonl.gz `
  --digests tests\parity\artifacts\wordpress-phase5-mysql-digests.jsonl `
  --base-url http://localhost:8085 `
  --endpoint-path wp-json/ehrman-discovery/v1/parity/batch
```

The approved WordPress design intentionally omits the retired Featured Topics
starter list. Compare while allowing only that known case:

```powershell
python -B scripts\search_parity.py compare `
  tests\parity\artifacts\wordpress-phase5-python.jsonl.gz `
  tests\parity\artifacts\wordpress-phase5-mysql.jsonl.gz `
  --allow-case suggest-starter-000015 `
  --report tests\parity\artifacts\wordpress-phase5-comparison.html
```

See `docs/wordpress_phase5_validation_report.md` for the completed Phase 5
results and acceptance evidence.
