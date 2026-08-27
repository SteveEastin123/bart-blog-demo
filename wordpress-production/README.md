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

Ask AI 2 combines semantic similarity with exact title, summary, topic, and
keyword signals. Set `EHRMAN_DISCOVERY_SEMANTIC_RETRIEVAL=semantic` to restore
semantic-only candidate ranking without changing the stored embeddings.

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

## Render Blueprint

`render-wordpress.yaml` is intentionally separate from the active root
`render.yaml`. It defines a new staging WordPress web service and private
MySQL service with persistent disks and generated credentials. Do not activate
it or replace the current PHP service until deployment is explicitly approved.
