# Production-Equivalent WordPress Package

This directory packages the Ehrman Blog Discovery plugin, companion demo
theme, and five authoritative JSON sources into a reproducible WordPress
Docker image. It exists beside the development Compose stack and does not
change the current Python or PHP demos.

## Local Acceptance Stack

The isolated production-equivalent stack runs on port `8086` by default:

```powershell
.\wordpress-production\setup-production-test.ps1
```

The reviewer-only hierarchy outline is available at
`http://localhost:8086/structure-review/`. It is linked from the landing page,
excluded from the primary navigation, and marked `noindex, nofollow`.

The script builds the exact WordPress image intended for staging, starts a
fresh MySQL service, installs WordPress when necessary, activates the plugin
and theme, configures permalinks, and imports the authoritative index.

Ask AI uses the focused two-term interpretation strategy by default. Set
`EHRMAN_DISCOVERY_AI_TERM_STRATEGY=legacy` to restore the previous four-term
interpreter; this switch does not affect regular Keyword Search.

AI-refined results are grouped into direct answers, strongly related posts, and
supporting background. Set `EHRMAN_DISCOVERY_AI_RESULT_GROUPING=ordered` to
restore the previous flat relevance-ordered result list.

Ask AI 2 uses the selected single-vector `hybrid` retrieval strategy, combining
title-and-summary similarity with lexical and exact metadata signals. The
experimental topic, alias, and secondary-keyword vectors are disabled unless
`EHRMAN_DISCOVERY_SEMANTIC_RETRIEVAL=hybrid-metadata` is explicitly selected.
Use `semantic` to test title-and-summary similarity alone.

Validate the running stack with the bundled Python runtime path when `python`
is not on `PATH`:

```powershell
.\wordpress-production\verify-production-test.ps1 `
  -PythonExecutable 'C:\path\to\python.exe'
```

Stop the stack without deleting its data:

```powershell
docker compose -f .\wordpress-production\compose.local.yaml down
```

Deleting the named volumes is destructive and is needed only when deliberately
repeating a clean-install test:

```powershell
docker compose -f .\wordpress-production\compose.local.yaml down --volumes
```

## Backup And Restore Test

Create a logical MySQL backup:

```powershell
.\wordpress-production\backup-database.ps1
```

Verify that a backup can be restored without touching the working database:

```powershell
.\wordpress-production\test-backup-restore.ps1 -BackupPath <backup.sql>
```

With no `BackupPath`, the restore test creates a temporary backup, restores it
into `ehrman_restore_test`, compares indexed-post counts, and removes both the
temporary database and backup.

## Production Characteristics

- The plugin, theme, and import sources are immutable image content.
- Import sources live outside Apache's document root at `/opt/ehrman-import`.
- A runtime synchronizer refreshes image-managed plugin and theme code and
  removes any legacy public import directory on every container start.
- MySQL stores WordPress and discovery-index data.
- Only `wp-content/uploads` requires a WordPress persistent disk.
- Result links open Bart's existing post URLs; full post bodies are not stored.
- `/healthz` does not depend on WordPress installation state.
- The protected parity route is disabled unless a test token is explicitly set.
- WordPress file editing is disabled so deployed code continues to come from Git.

## Ask AI 2 Index

Ask AI 2 uses one title-and-summary vector per eligible post. After deploying a
plugin or data update, refresh the semantic index from the WordPress service
shell. The command does not build optional metadata vectors while `hybrid` is
active:

```bash
wp ehrman-discovery embeddings --allow-root --path=/var/www/html
```

On a Render database that previously contained experimental topic, alias, or
secondary-keyword vectors, rebuild the content index and remove those rows with:

```bash
wp ehrman-discovery embeddings --purge-metadata --allow-root --path=/var/www/html
```

The empty metadata table remains part of the plugin schema so the experiment can
still be run locally, but no metadata vectors are stored or loaded in the
selected Render configuration.

## Render Blueprint

`render-wordpress.yaml` is intentionally separate from the active root
`render.yaml`. It defines a new staging WordPress web service and private
MySQL service with persistent disks and generated credentials. Do not activate
it or replace the current PHP service until deployment is explicitly approved.
