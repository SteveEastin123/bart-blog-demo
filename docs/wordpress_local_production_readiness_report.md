# WordPress/MySQL Local Production Readiness Report

Date: August 6, 2026

## Result

The production-equivalent WordPress/PHP/MySQL package passed the local
acceptance gate. No Render service was created or modified.

## Clean Build And Initialization

- Built `wordpress-production/Dockerfile` from pinned WordPress and WP-CLI
  image digests.
- Started a separate MySQL 8.4 database and WordPress service on port 8086.
- Installed WordPress on a fresh database.
- Activated Ehrman Blog Discovery version 0.4.15 and the companion demo theme.
- Imported the authoritative JSON in approximately 3.3 seconds.
- Verified that import JSON remains outside Apache's document root and that
  image-managed plugin and theme files are refreshed on every container start.

## Imported Data

| Record | Count |
| --- | ---: |
| Browse paths | 2 |
| Subject areas | 19 |
| Categories | 41 |
| Topics | 272 |
| External posts | 4,390 |
| Secondary keywords | 1,051 |
| Subject-area/category links | 83 |
| Topic/category links | 313 |
| Post/topic links | 8,573 |
| Post/keyword links | 21,586 |
| Search-term links | 30,159 |

The expected counts are calculated dynamically from the five authoritative
JSON files by `scripts/wordpress_expected_counts.py`.

## Correctness

- Authoritative JSON validation: passed
- Packaged plugin and theme PHP syntax: 19 files passed
- Search parity unit tests: 21 passed
- Deterministic parity cases: 500 compared
- Unapproved differences: 0
- Approved variance: 1

The approved variance is the intentionally retired Featured Topics response
for a blank, unscoped suggestion request.

## Browser Validation

- Category selection displayed all 41 categories with right-aligned post counts.
- Category-scoped autocomplete returned topics and secondary keywords.
- Selecting a topic automatically narrowed the result set.
- Subsequent suggestions used the current category and selected-term scope.
- Best-match sorting and description display were active by default.
- Browse Topics 1 navigated from subject area to category to topic results.
- Browse Topics 2 is covered by parity and page checks.
- Result titles linked to external `ehrmanblog.org` URLs.
- Existing Phase 5 mobile and keyboard checks remain applicable because the
  plugin and theme assets in the production image are identical.

## Persistence

After recreating the WordPress container:

- The uploads-volume marker remained available.
- The MySQL import still contained 4,390 external posts.
- The runtime synchronizer restored the packaged plugin and theme files.
- The legacy public import directory remained absent.
- The parity route returned HTTP 404 after its temporary token was removed.

## Backup And Restore

`mysqldump` produced a logical backup. The backup was restored into an isolated
`ehrman_restore_test` database, and all 4,390 external post records were
verified before the test database and temporary backup were removed.

## Warm Local Performance

Each case used two warmups and 20 measured requests.

| Case | Median | P95 | Maximum |
| --- | ---: | ---: | ---: |
| Single-term search | 414.4 ms | 585.6 ms | 598.1 ms |
| Two-term search | 71.4 ms | 80.2 ms | 83.6 ms |
| Three-term search | 65.0 ms | 71.9 ms | 72.8 ms |
| Four-term search | 78.9 ms | 94.3 ms | 94.5 ms |
| Global autocomplete | 118.3 ms | 129.6 ms | 133.5 ms |
| Narrowed autocomplete | 68.2 ms | 77.2 ms | 82.4 ms |
| Category page | 90.6 ms | 100.2 ms | 109.7 ms |
| Topic page | 119.7 ms | 137.4 ms | 214.8 ms |

## Remaining Work

Only Render-specific work remains: create the staging services, enter the
administrator email, verify private networking and persistent disks, rerun the
acceptance suite against the public URL, and establish off-service backups.
