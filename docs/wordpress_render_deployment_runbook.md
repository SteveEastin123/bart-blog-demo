# Fresh MariaDB Demo on Render

## Scope and Status

Replace the old Render demo database with a fresh MariaDB database populated from
the current repository. Deploy the current WordPress plugin, demo-only Reviewer
Tools, theme, five canonical JSON files, and supplied title-and-summary vectors.
This is a clean rebuild, not a transfer of the old database's live state.

**Keep the existing web service and its URL.** Replace only its database service
and database disk. Do not delete/recreate the web service, change its public URL,
or remove its uploads disk. No full post bodies or section vectors are imported.
Analytics, feedback, ingestion history, extra users, and database-only WordPress
customizations will not be copied. Retain a private precautionary backup first.

The repository describes the target configuration, not proof that it is live.
Record the actual Render service IDs, public URL, deployed commit, backup checksum,
and acceptance results privately when the rebuild is performed. Never put
credentials, database dumps, or API keys in Git or public web storage.

## Confirm the Existing Service Before Applying Anything

1. Open the currently used demo URL and identify its exact web service in Render.
2. Record its ID, name, URL, region, uploads disk, repository, branch and Blueprint
   association. Preserve existing API and administrator settings securely.
3. The checked-in Blueprint uses `bart-blog-demo` as its web service
   name, Standard plan, and Oregon region, matching the dashboard inspected on
   November 5, 2026. **If the actual service has a different name, stop and update that name
   and the self-reference for `WORDPRESS_SITE_URL` before syncing.** Do not create
   a second web service and assume its URL will match.
4. Turn **Blueprint Auto Sync off** in the Render dashboard. Service-level
   `autoDeployTrigger: off` does not disable Blueprint syncing. Commit/push and
   deployment are separate steps; review the proposed resource changes first.
5. Confirm the old database and its disk IDs. Do not select the web uploads disk
   or another project's database for replacement or deletion.

## Target Resources

| Resource | Target | Keep or replace |
| --- | --- | --- |
| Existing public web service | Current `wordpress-production/Dockerfile` | Keep identity and URL; deploy new image |
| Existing WordPress uploads disk | Existing mount and capacity | Keep |
| New private database | MariaDB 13.0.2, same pinned digest as local tests | Create separately |
| New database disk | 5 GB at `/var/lib/mysql` | New, never reuse MySQL files |
| Old MySQL service and disk | Retired after verification and approval | Explicitly delete at the end |

The current local MariaDB data directory measured about 290 MB on November 5,
2026. The 5 GB initial allocation leaves working/growth space; confirm the actual
fresh Render usage after import. Disks can grow, not shrink. Both services must
be in the same Render workspace and region. The database is private, on port
10000. Render's Docker Command replaces the image entrypoint, so the database
command must be `docker-entrypoint.sh mariadbd --port=10000`; invoking `mariadbd`
alone skips initialization and privilege handling. The web entrypoint listens on
Render's `PORT`, normally 10000.

## Local Release Gate

Run the current full regression and the Render-specific rehearsal:

```powershell
.\wordpress-production\verify-all.ps1 -PythonExecutable '<python>' -NodeExecutable '<node>'
.\wordpress-production\verify-render-rebuild.ps1 -PythonExecutable '<python>'
```

The full suite builds the current image and verifies canonical data, diagram
counts, PHP standards/static analysis, PHP/REST behavior, approved search cases,
desktop/mobile UI, developer-test delivery, installation and backup/restore.
It requires the approved private baseline; never replace it to make tests pass.

The Render rehearsal uses that built image in a unique Compose project. It starts
an empty MariaDB database, exercises port 10000 for both services, initializes
WordPress from the packaged JSON/vectors, verifies pages and vector coverage,
then recreates containers without reimporting and compares database fingerprints
and the site URL. External WordPress HTTP and cron are disabled; no real API key
is used. Test containers are stopped and volumes retained, not deleted. The
normal local demo and Render are untouched. Private evidence is under `.tmp/`.

## Backup Before Replacement

Pause public testing and all writes to the old site during backup and cutover.
Record its connection settings securely for rollback. Keep its existing
WordPress uploads disk; archive any irreplaceable uploads separately.

From the **old MySQL service's shell**, export the application database with the
MySQL client. Confirm its configured database/user environment names first. For
the old checked-in Blueprint they are `MYSQL_DATABASE`, `MYSQL_USER`, and
`MYSQL_PASSWORD`:

```sh
umask 077
set -o noclobber
MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --host=127.0.0.1 --port=10000 \
  --user="$MYSQL_USER" --single-transaction --hex-blob --no-tablespaces \
  --set-gtid-purged=OFF "$MYSQL_DATABASE" > /tmp/ehrman-before-mariadb.sql
test -s /tmp/ehrman-before-mariadb.sql
sha256sum /tmp/ehrman-before-mariadb.sql
```

Transfer the dump using an authenticated private download/SSH mechanism to an
ignored local backup directory. Verify its checksum and test restoration into an
isolated compatible database. Do not place it under WordPress's document root,
rely on an ephemeral `/tmp` file as the only copy, or use a disk snapshot as a
database backup. Keep the old service/disk until the replacement is accepted.

## Fresh Deployment

1. Review the committed release and the Blueprint preview. It must preserve the
   existing public web service and uploads disk and create a distinct MariaDB
   service/disk. No resources should be deleted during this stage. Confirm the
   new service's plan and disk allocation before creation.
2. Start MariaDB and wait for initialization. Keep the old MySQL disk separate.
   New database credentials are generated by Render; never copy local test keys.
3. Point the existing web service's `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`,
   `WORDPRESS_DB_USER`, and `WORDPRESS_DB_PASSWORD` to the new MariaDB service.
   `render-wordpress.yaml` contains those references. Preserve the old settings
   privately until acceptance. Brief demo downtime is expected and approved.
4. Deploy the current repository commit using `wordpress-production/Dockerfile`
   and repository-root build context. Keep `WORDPRESS_SITE_URL` equal to the
   **existing** public URL. Preserve the admin identity or deliberately configure
   the intended replacement admin using Render's secret settings.
5. Retain/configure `OPENAI_API_KEY` for reader search and the separate
   `EHRMAN_INGESTION_OPENAI_API_KEY` for ingestion. `sync: false` does not populate
   an existing service automatically; verify those settings without exposing
   their values. Keep `EHRMAN_DISCOVERY_POST_SOURCE=json` for this demo.
6. In the **existing web service's shell**, confirm the connection targets
   MariaDB and the new application database contains no tables:

   ```sh
   php -r '$p = explode(":", getenv("WORDPRESS_DB_HOST"), 2); $d = new mysqli($p[0], getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), (int)($p[1] ?? 3306)); if (stripos($d->server_info, "MariaDB") === false || $d->query("SHOW TABLES")->num_rows !== 0) { throw new RuntimeException("Not an empty MariaDB target; stop and investigate."); } echo "Empty MariaDB target confirmed.\n";'
   ```

7. Run `initialize-ehrman-wordpress` explicitly in that shell. The Blueprint's
   `initialDeployHook` runs only for a service's initial deployment; replacing
   the database of an existing web service does **not** rerun that hook.
   Initialization installs WordPress, activates the current plugin/Reviewer
   Tools/theme, creates pages, imports JSON, and imports precomputed vectors.
   No embeddings need generating and no paid API calls are needed for import.
8. If initialization fails partway, do not delete anything or blindly restart
   these steps. Inspect the failure and target state first. Normal initialization
   can force-replace JSON-authoritative discovery data; it is not a production
   database migration command. `/healthz` alone is not evidence of completion.

## Acceptance at the Existing URL

- Confirm the exact public URL and WordPress `home`/`siteurl` are unchanged.
- Check `/wp-json/ehrman-discovery/v1/status`: database connected and import complete.
- Compare counts with `scripts/wordpress_expected_counts.py` for this release.
- Run `wp ehrman-discovery status --allow-root --path=/var/www/html`; all eligible
  title-and-summary vectors must be current, with no missing/stale/obsolete ones.
- Check landing-page dates/counts, both Browse Topics structures, Keyword Search,
  Ask AI, Reviewer Tools and external article links at desktop/mobile widths.
- Verify admin login, empty new analytics/feedback, and intended settings.
- Run deterministic acceptance checks with outbound API calls disabled. Separately
  confirm a live Ask AI request only with approval for its small API charge.
- Confirm the plugin/theme deployed commit, API configuration without displaying
  keys, and MariaDB version. Verify persistence after a restart/redeploy.
- Measure the new disk usage and capture a fresh logical MariaDB backup.

## Delete Only the Retired Resources

After acceptance and **explicit deletion approval**:

1. Ensure the old MySQL resource is absent from the synced Blueprint. Removing a
   definition does not delete its resource; retaining it can recreate the resource
   at a future sync.
2. Recheck the old service and disk IDs against the private preflight record and
   verify the current web service points exclusively to MariaDB.
3. Delete only the old MySQL service and its attached database disk. Confirm both
   are gone and no old database storage remains billable. Do not delete the public
   web service, uploads disk, new MariaDB service/disk or unrelated legacy services.
4. Retain the verified local backup privately. Record deletion and successful
   final smoke checks. This repository update alone never performs deletion.

Before deletion, rollback means restoring the old database connection and, if
needed, the previous application image on the **same** web service. After deletion,
rollback requires restoring the local backup into a compatible new database.
New MariaDB activity is not in the old MySQL backup and must be preserved separately.

## Ongoing Updates and Handoff

Keep using canonical JSON and the portable vector package for this demo. Validate,
rebuild/test, commit/push when approved, deploy manually, then run the normal
initialization/import and verify coverage. Do not recreate MariaDB on every update.

Render hosting and this runbook are internal demo infrastructure, not requirements
for Bart's production developers. Deliver the production plugin, initial JSON and
vectors, integration documentation, and the separate regression-test ZIP described
in `wordpress_developer_handoff_package.md`. Keep Reviewer Tools demo-only.

## Provider References

- [Render Blueprint syncing and deletion](https://render.com/docs/infrastructure-as-code)
- [Render disks, backups, and size restrictions](https://render.com/docs/disks)
- [Render initial-deployment hook](https://render.com/docs/blueprint-spec#initialdeployhook)
