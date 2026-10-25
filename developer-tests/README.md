# Ehrman Discovery Developer Tests

This is a **required, separate handoff deliverable**, not part of the installable
production plugin. Supply `ehrman-discovery-developer-tests.zip` alongside the
plugin ZIP, source release, authoritative discovery JSON and title/summary vector
package. Keep this bundle outside the public web root. Never upload it as the
production plugin or install it on the live blog.

## What Is Tested

- Keyword Search results, counts and ordering, including combined search terms.
- Autocomplete suggestions and both Browse Topics structures and relationships.
- Source-file fingerprints and imported counts for like-for-like comparisons.
- The separate PHP regression suite covers search rendering, Ask AI contracts,
  analytics, ingestion approval and queues, vector import/export and error cases.

The comparison is a deterministic regression check, **not an AI relevance grade**.
It does not call OpenAI or generate embeddings. Ask AI and ingestion regression
responses are mocked. The comparison suite is representative, not exhaustive;
extend `cases/search.json` with approved cases as requirements change.

## Prerequisites

1. Use a disposable staging/local WordPress site with the production plugin active.
2. Import the matching discovery JSON and supplied vectors. Keep the five JSON
   files available through `EHRMAN_DISCOVERY_IMPORT_DIR` for fingerprint checks.
   They must be the same files used for the supplied baseline, not just similarly
   sized files. These tests currently cover both Browse Topics structures.
3. Set WordPress `WP_ENVIRONMENT_TYPE` to `staging`, `development` or `local`.
   The command entry points refuse production, including WordPress's default.
4. Have WP-CLI and PHP on the WordPress host. Python 3.10+ (standard library only)
   is needed for comparison and can run on a separate workstation.
5. Back up the staging database. Stop scheduled jobs and concurrent edits while
   capturing results. The comparison reads discovery data; the broader regression
   suite uses temporary fixtures and rollback, and can consume auto-increment IDs.
   Do not run the broader suite on production or a staging site with active editors.

No Docker, Node, retired Python web app, SQLite database, API key, or optional
test plugin is needed for the command-line comparison below. The PHP regression
suite requires the complete imported vector index but does not create vectors.

## Run the Delivered Tests

Unzip to a private directory such as `/secure/ehrman-discovery-developer-tests`.
Run WP-CLI as the site's normal maintenance user, with its WordPress path:

```sh
wp --path=/path/to/wordpress eval-file /secure/ehrman-discovery-developer-tests/capture.php /secure/ehrman-discovery-developer-tests/cases/search.json /secure/actual.json
python3 /secure/ehrman-discovery-developer-tests/compare.py /secure/ehrman-discovery-developer-tests/baseline.json /secure/actual.json
wp --path=/path/to/wordpress eval-file /secure/ehrman-discovery-developer-tests/run-regressions.php
```

The capture must use a **new output filename** outside the WordPress directory;
existing captures are never overwritten. Preserve the delivered `baseline.json`.
An exit status of 0 means the command passed; comparison returns 1 for differences
and 2 for malformed/incomplete captures. Differences report JSON paths rather
than dumping post content. Post order and suggestion order are significant.
Runtime versions and commit IDs are recorded but do not cause comparison failures.
Production post IDs, URLs, data and configuration must match the reference. A
different import or legitimate editorial change can produce differences; review
them before approving a new baseline. Equal source-file hashes alone do not prove
the entire live database is unchanged: counts and captured outputs are also checked.

For later upgrades, first capture a baseline on the accepted version, then capture
the candidate version using identical data and cases. Compare the two files.
Never create a replacement baseline from failing output just to make a test pass.
These tests complement, not replace, browser/accessibility and real-site integration
testing. The release acceptance checklist still requires those checks.

## Optional HTTP Compatibility

Only if a developer needs the historical `/wp-json/ehrman-discovery/v1/parity/batch`
contract: copy `test-tools/` to `wp-content/plugins/ehrman-discovery-test-tools/`
on **staging**, activate it, and supply a private `EHRMAN_DISCOVERY_PARITY_TOKEN`
through the server environment or `wp-config.php`. Requests must send the matching
`X-Ehrman-Parity-Token` header; never put tokens in URLs. Use HTTPS for remote tests.
The route is unavailable without the core plugin, without the token, or on production.
Deactivate and remove the optional add-on and token when finished. The normal
production plugin never registers this endpoint, even if an old token remains set.
The demos' Reviewer Tools add-on is independent and remains available as before.

## Build and Deliver (Repository Maintainers)

`developer-tests/package-manifest.json` is the explicit delivery allowlist.
`scripts/package_wordpress_tests.py --check` fails if required files or regression
partials are missing, or comparison code has returned to the production plugin.
CI runs that check and the comparator/packager unit tests.

Capture and review a reference from the approved demo using `capture.php`, then:

```sh
python scripts/package_wordpress_tests.py --baseline /private/approved-baseline.json --output .tmp/handoff/ehrman-discovery-developer-tests.zip
```

The builder validates the baseline and matching case list, includes production
regression files without Reviewer Tools tests, and writes version/data-fingerprint
metadata plus file sizes and SHA-256 hashes to `MANIFEST.json`. It refuses to replace
an existing archive. No credentials, full posts, vectors, experiments, demo plugin,
or database backups are included. The baseline contains discovery metadata and
result lists, so handle the ZIP as a private development-company deliverable.

Before final delivery, regenerate the bundle against the approved data and final
Browse Topics choice, extract it into a clean private directory and run **these
exact commands** there. Do not assume a successful repository test proves the ZIP
is self-contained. Record the archive checksum and test results in the handoff.
