# Browse Topics Retirement Verification

Verified locally November 12, 2026, against MariaDB 13.0.2. No deployment or paid AI calls were made.

## Upgrade Result

- One Browse Topics hierarchy at `/browse-topics/`, retaining the first structure's 10 Subject Areas and 41 category links.
- Both numbered URLs return permanent redirects. Supported category/topic selections, search filters and pagination survive the redirect; obsolete Subject Areas are discarded.
- The first managed page is reused; a superseded managed page becomes a recoverable draft. An unrelated page at the destination is not overwritten.
- Menus, landing-page diagrams, Reviewer Tools, imports and handoff packaging use the single structure.
- Historical reports and presentations remain unchanged. Git retains the retired source's history.

## Data Integrity

The normal local demo was upgraded without reimporting its posts. Before-and-after row hashes matched for every discovery table except the three intentionally changed hierarchy tables (`browse_paths`, `subject_areas`, `subject_area_categories`). Preserved data includes:

| Data | Records |
| --- | ---: |
| Posts | 4,469 |
| Categories | 41 |
| Topics, including Ignore | 296 |
| Secondary Keywords | 909 |
| Search-term relationships | 24,416 |
| Title-and-summary vectors | 4,037 |

Analytics and ingestion-history tables also retained identical row hashes. All 22 saved pre-change comparison cases retained their search results, counts and ordering; the first hierarchy matched exactly after removing the obsolete second-structure fields.

## Verification Performed

- Production PHP regression suite, including migration rollback, repeatability, page reuse, collision protection and preservation of surrounding page content.
- Fresh installation and repeat installation, protected imports, vector validation and developer-test package extraction and execution. The delivered comparison bundle passed all 22 cases.
- PHPCS and PHPStan for the production plugin, Reviewer Tools and developer-test companion.
- Canonical-data validation, landing counts, frontend asset checks, Python tests and developer-test unit tests.
- Browser checks for frontend assets, navigation, keyword search, mocked Ask AI, Reviewer Tools, legacy browsing redirects and alphabetical/post-count sorting at each hierarchy level, including mobile layouts.
- The new browse-retirement browser test is included in `wordpress-production/verify-all.ps1`; the PHP upgrade fixtures are included in the main regression suite and developer-test package.

This verification did not rerun the separate backup/restore exercise. Render was not updated.

## Installation Receipt Follow-Up

The November 12 code-review follow-up reproduced the stale-receipt failure on
the previous code using a disposable two-hierarchy installation fixture. The fix
advances the database fingerprint only when the receipt matched before migration,
and writes it in the same transaction as the hierarchy changes. Package checksums
are preserved; editorial changes and database-managed replacements remain protected.

Added installation fixtures cover verified upgrades, unchanged shared data,
repeatability, explicit JSON-managed replacement, pre-existing editorial changes,
already-mismatched receipts, receipt-write rollback and installations without receipts.
Updated the setup, CLI help and handoff documentation to list one Browse Topics
hierarchy, its canonical URL and four JSON source files.

The complete `verify-all.ps1` suite passed on isolated MariaDB/WordPress stacks:

- PHPCS and PHPStan passed for all three packages, along with unit and frontend checks.
- All 22 saved pre-change search cases retained identical fingerprints, counts,
  results and ordering, including through the extracted developer-test ZIP.
- Fresh/repeat installation, the new receipt fixtures, production regressions and
  desktop/mobile browser workflows passed.
- Before/after schema, indexes, row counts and binary-safe content hashes matched
  across all 17 discovery tables. All 4,037 eligible vectors remained current.
- Backup restoration matched all 29 tables by row counts and extended content checksums.

Evidence: `.tmp/full-regression/7f8f815ddd8f4e12b1e5039da59d6a3c/report.txt`.
The comparison used the saved November 11 capture at
`.tmp/developer-test-verification/c300e4b37b934242a38b8f1aa483ecc6/actual.json`,
not a newly generated baseline. Test containers were stopped; the normal local
demo and Render were not changed, and no paid AI calls were made.
