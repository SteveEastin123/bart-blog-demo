# WordPress/MySQL Render Deployment Runbook

## Purpose

Deploy a staging WordPress/MySQL version of the Ehrman Blog Discovery demo
without changing the existing PHP/SQLite service. The staging application
searches locally imported metadata and links to Bart's authoritative post URLs.
It does not store full post bodies.

## Services

| Service | Render type | Runtime | Persistence |
| --- | --- | --- | --- |
| `bart-blog-demo-wordpress` | Web service | Docker WordPress/PHP/Apache | Upload disk |
| `bart-blog-demo-wordpress-mysql` | Private service | MySQL 8.4 image | `/var/lib/mysql` disk |

The services must be in the same Render workspace and region so WordPress can
reach MySQL over Render's private network.

Both services use persistent disks. Render disables zero-downtime deploys for
services with disks, so schedule staging and production deploys with a brief
maintenance window in mind.

## Pre-Deployment Gate

Do not create Render services until all of these local checks pass:

1. `scripts/validate_ehrman_search_demo.py`
2. `wordpress-production/setup-production-test.ps1`
3. `wordpress-production/verify-production-test.ps1`
4. The 500-case parity comparison with no unapproved differences
5. Desktop and mobile browser checks for search and both browse paths
6. Container-recreation persistence check
7. `wordpress-production/test-backup-restore.ps1`
8. Repository credential scan and clean Git status

The completed evidence is recorded in
`docs/wordpress_local_production_readiness_report.md`.

## Blueprint Creation

1. In Render, create a new Blueprint from the existing private GitHub repo.
2. Select `render-wordpress.yaml` as the Blueprint path.
3. Confirm that it creates two new services and does not modify
   `bart-blog-demo` or `bart-blog-demo-php`.
4. Supply `WORDPRESS_ADMIN_EMAIL` when prompted.
5. Keep automatic deploys disabled during staging evaluation.
6. Review the generated database and WordPress administrator passwords in the
   service Environment pages and store them in an approved password manager.

No credential belongs in Git, a JSON source file, or this runbook.

## First Deployment

1. Wait for the private MySQL service and its `/var/lib/mysql` disk to become
   healthy.
2. Let Render build the WordPress image from
   `wordpress-production/Dockerfile`.
3. Confirm `/healthz` returns HTTP 200.
4. The one-time initialization hook installs WordPress, activates the plugin
   and theme, configures permalinks, and imports the authoritative JSON.
5. Open `/wp-json/ehrman-discovery/v1/status` and verify the import state is
   `complete`.
6. Confirm the expected counts using `scripts/wordpress_expected_counts.py`.

If the initialization hook fails, use the Render shell for the WordPress
service to run:

```text
initialize-ehrman-wordpress
```

The importer is transactional and can be rerun safely.

## Staging Acceptance

Test all of the following against the new `onrender.com` URL:

- Landing page and navigation
- Keyword Search with zero through four selected terms
- Category-scoped suggestions and searches
- Best-match, newest-first, and oldest-first ordering
- Browse Topics 1 and Browse Topics 2
- Subject-area, category, topic, and post counts
- Description display and hover behavior
- Keyboard navigation and mobile layout
- External post URLs
- WordPress administrator access
- Upload persistence across restart and redeploy
- MySQL persistence across restart
- HTTP health checks and logs

Temporarily enable the parity endpoint only for the controlled 500-case test.
Remove its token and confirm the route returns HTTP 404 afterward.

## Updating Discovery Data

1. Update and validate the five authoritative JSON files locally.
2. Rebuild the production image locally.
3. Repeat import, parity, browser, and backup checks.
4. Commit and push only after approval.
5. Trigger a manual Render deploy.
6. Run `initialize-ehrman-wordpress` in the Render shell to import the new
   checksum, or add a separately approved deployment job.
7. Verify counts and representative searches before announcing the update.

## Backups

Use `mysqldump` for logical database backups. Do not use a Render disk snapshot
as the primary MySQL restore mechanism. Keep backups outside the MySQL service
disk and periodically test restoration into an isolated database.

Recommended staging schedule:

- Logical backup before every data import or plugin upgrade
- Daily logical backup while testers are changing WordPress settings
- Monthly restore test

## Rollback

The current PHP/SQLite demo remains untouched during staging.

If WordPress fails acceptance:

1. Stop directing testers to the WordPress staging URL.
2. Continue using the existing PHP demo.
3. Preserve logs and a logical MySQL backup for diagnosis.
4. Fix and retest locally before another manual staging deployment.

Do not delete the PHP service or repoint a custom domain until WordPress has
passed acceptance and the cutover is explicitly approved.

## Handoff Package

Provide the development company with:

- `wordpress-plugin/ehrman-blog-discovery/`
- The five authoritative JSON files
- Plugin schema and JSON integration documentation
- This deployment runbook
- The local production Docker package
- The latest parity cases and acceptance report
- Instructions for mapping the plugin shortcodes into the production theme

The demonstration theme is visual reference material. The production site's
existing theme should own its global header, footer, typography, and account
controls.
