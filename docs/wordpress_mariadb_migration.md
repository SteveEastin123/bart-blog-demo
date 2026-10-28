# Local MariaDB Migration

## Current Status: October 28, 2026

Local builds and CI now target MariaDB only. The Oracle MySQL compatibility job
and `wordpress-production/compose.mysql.yaml` have been retired. No database
containers, volumes, or backups were deleted by that removal, and the Render
blueprint remains unchanged. The migration evidence and rollback commands below
are historical: recover the old Compose file from Git before attempting a
separately approved MySQL rollback. They are not part of the current build.

## Scope

The local demo moves from Oracle MySQL 8.4.11 to MariaDB 13.0.2. WordPress,
PHP, plugin behavior, prompts, ranking, JSON authority, and embeddings are
unchanged. MariaDB is pinned to:

`mariadb:13.0.2@sha256:f1bba652ba57bea3099ca2fe1af692af537c27d96e0bcde39dce29e2ba1ec4f3`

13.0.2 is a stable rolling release, not an LTS release. Plan regular version
updates and repeat the acceptance checks before upgrading. Official references:
[release announcement](https://mariadb.org/mariadb-13-0-is-now-stable/) and
[logical migration guidance](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/migrating-to-mariadb/moving-from-mysql/mysql-to-mariadb-migration-the-master-guide).

Render configuration and deployment are intentionally unchanged. Local MariaDB
validation does not constitute a Render migration. Never attach the existing
MySQL data directory to MariaDB, including on Render.

## Environments

| Purpose | Compose file/project | Storage |
| --- | --- | --- |
| Active local demo | `compose.local.yaml`, `ehrman-wordpress-mariadb-test` | New MariaDB and uploads volumes |
| Retained rollback | `compose.mysql.yaml`, `ehrman-wordpress-production-test` | Original MySQL and uploads volumes |
| Clean-install check | Project `ehrman-mariadb-clean-install-20261016` | Separate test volumes; stopped after verification |

The active demo uses port 8086. The restored copy was tested on 8087 before
cutover; the separate clean installation uses 8088. All Compose commands run
from the repository root. Keep `COMPOSE_PROJECT_NAME` unset for the active demo.

The existing `MYSQL_*` settings remain the input names in the ignored local
`.env` file; Compose maps them to MariaDB's `MARIADB_*` initialization variables.
Credentials must not be changed on an existing volume by changing environment
variables alone. The separate `EHRMAN_DISCOVERY_POST_SOURCE=mysql` value means
database-authoritative ingestion and is not a database product selector. Keep
its current value `json` until the planned source-of-truth handoff.

## Backup And Verification

The migration backup is in the ignored directory:

`wordpress-production/backups/mariadb-migration-20261016/`

It contains the logical application database dump, uploads archive, private
runtime configuration, and database/search fingerprints. Treat this folder
and `.env` as private. Both are excluded from Git and Docker build context.
The database dump uses a single transaction and hex-encoded binary values;
MySQL system databases and accounts are not imported into MariaDB.

The source website is stopped before backup. The clone blocks WordPress HTTP
requests and automatic WP-Cron during testing to avoid duplicate jobs/API use.
Its tables are compared before WordPress is bootstrapped or URLs are adjusted.

`snapshot-database.ps1` captures every table without loading WordPress. It
records schema, indexes, collation, auto-increment values, row counts, and
binary-safe SHA-256 content hashes. `compare-database-snapshots.ps1` compares
them and fails on a mismatch. Integer display widths are normalized because
the database products present those differently; values are not normalized.

`capture-search-parity.ps1` exercises both Browse Topics trees, category
listings, every Topic in both matching modes and all three sort orders, every
Secondary Keyword, representative combined searches, and 20 semantic searches.
The semantic cases use fixed existing vectors as query fixtures, not newly
generated embeddings. They test retrieval/ranking parity, not relevance quality
or live LLM behavior. External HTTP calls are blocked, test database writes
are rolled back, and only case hashes are saved. InnoDB may still advance
auto-increment counters for rolled-back test inserts.

Example capture:

```powershell
.\wordpress-production\capture-search-parity.ps1 `
  -ComposeFile wordpress-production/compose.mysql.yaml `
  -OutputPath wordpress-production/backups/search-mysql.json
.\wordpress-production\capture-search-parity.ps1 `
  -OutputPath wordpress-production/backups/search-mariadb.json
```

Acceptance checks cover syntax, ingestion, analytics, vector transfer and
coverage, shortcodes, public pages, protected routes, search modes, and paging.
The backup-restore test validates all tables in a new scratch database on the
same engine. At migration, the CI matrix was configured for clean installation,
regressions, and restore verification on both MariaDB and MySQL. Current CI
targets MariaDB only, as noted above.

## Rollback

Do not reimport JSON, regenerate embeddings, delete volumes, or downgrade a
MariaDB data directory. The retained MySQL database represents the migration
snapshot. After new writes on MariaDB, rollback would lose those writes unless
they are explicitly reconciled first.

1. Stop new writes and make a fresh MariaDB backup with `backup-database.ps1`.
2. Stop the MariaDB stack without removing its volumes:

   ```powershell
   docker compose -f wordpress-production/compose.local.yaml stop
   ```

3. Resume the original MySQL containers, including their original images and
   volumes, on port 8086:

   ```powershell
   docker compose -f wordpress-production/compose.mysql.yaml start
   ```

4. Verify the demo. To continue using MySQL, explicitly pass its Compose file
   to tooling and restore it as the default only as a separate reviewed change.

Do not run both websites on port 8086. Keep the rollback environment stopped
while MariaDB is active to avoid duplicate background work. `start` intentionally
uses existing containers rather than recreating them from a newer image tag.

## Verified Result: October 16, 2026

Cutover completed at `http://localhost:8086/`. The running versions are
MariaDB `13.0.2-MariaDB-ubu2604`, WordPress `7.0.2`, and PHP `8.3.33`.

| Verification | Result |
| --- | --- |
| MySQL baseline acceptance suite | Passed |
| Logical migration, all 31 existing tables | Identical schema/indexes, counts, and binary-safe content hashes before test writes |
| Posts / Topics / Categories / Secondary Keywords | 4,446 / 296 / 41 / 901 |
| Current title-and-summary vectors | 4,016 of 4,016 eligible; no missing, stale, or obsolete vectors |
| Vector payload | 8,224,768 bytes preserved exactly |
| Analytics preserved | 521 requests, 511 refinements, 1,169 usage records |
| Cross-engine search comparison | 2,806 matching cases, including 20 semantic retrieval/ranking fixtures |
| Restored-site acceptance suite | Passed on 8087 and again on 8086 |
| Clean MariaDB install | Imported 4,446 posts and 4,016 packaged vectors; full acceptance suite passed |
| Backup and restore | Passed on both engines; all 31 tables matched |
| Uploads | Restored files match the original archive |
| PHPCS / PHPStan | Passed / no errors |
| Syntax | 77 packaged PHP files and 8 PowerShell scripts passed |
| Corruption-detection negative test | Deliberately changed vector fingerprint rejected |
| API/ingestion configuration | Preserved; normal cron and external HTTP enabled after cutover |

The landing page reports April 3, 2012 through October 11, 2026 (4,446 posts).
The Topic count includes the intentionally excluded `Ignore` metadata entry.
The clean import emits the existing warning that `Ignore` has no category;
this is intentional and did not prevent verification.

No paid OpenAI calls were needed. Live LLM responses were not regraded; this
was a database compatibility/parity test. No plugin search/ingestion logic or
canonical data changed. The vector package was imported unchanged, not rebuilt.

Backup checksums (SHA-256):

- `source-mysql.sql`: `25DD5992898388DF0228C8958743DB924B920C9E90E92455BDD13E5877373F42`
- `uploads.tar.gz`: `DF52CC933666953E1B77DFECD9DF3640B6C6B0F6589E5D24347D89D21395F60E`

The original MySQL containers and the clean-install test containers are stopped;
their volumes have not been deleted. The CI matrix was updated but has not run
on GitHub yet. No commit, push, Render deployment, or Render database change
was performed as part of this local migration.
