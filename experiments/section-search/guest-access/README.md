# Ask AI 3 Guest Access

This local-only addon searches **3,986 eligible posts / 19,951 sections** without administrator sign-in. It combines the preserved 500-post pilot with the 3,486-post expansion. Ask AI 1 and Ask AI 2 are unchanged. The original pilot, benchmark files, and vectors remain intact.

- Top navigation: Ask AI 3 follows Ask AI 2.
- Search configuration: 300-word sections, 512-dimension embeddings, title/summary plus matching passage in the candidate review, using the tested controlled-review prompt.
- Public page: `/ask-ai-3/`; shortcode: `[ehrman_section_public]`.
- Separate guest endpoint: `POST /wp-json/ehrman-section-public/v1/search`.
- The original experiment files and administrator-only benchmark routes are preserved.
- Guest search responses contain post links and descriptions, not full passages, private fixtures, raw API replies, or keys.
- Requests use a REST nonce, same-origin checking, input limits, an atomic request lock, and rolling request budgets (20 per five minutes per connected IP, 60 globally).

The API calls are paid. A guest nonce is not a secret or a substitute for authentication; rate limits reduce accidental or scripted overuse. This is restricted to the `local` WordPress environment and a loopback site URL. It is not a public-Internet deployment design. Search attempts use a separate expanded-search private audit log, not production AI analytics or the preserved pilot log.

## Local Installation

Requires the existing, enabled section-search pilot and its private runtime index. It never recreates the database or imports production data.

First run `package.py` using Python. It validates and combines the existing vectors; it does not call the API or generate new embeddings. The separate private runtime lives under `/opt/ehrman-section-public` in the local container.

`Index.php` and `Service.php` are isolated copies of the preserved pilot implementations with a new namespace, private directory, 3,986-post scope, and 300-word-only configuration. They reuse the original baseline ranking adapter and controlled passage-review prompt. This keeps historical benchmark hashes stable; changes to those shared algorithms require explicit revalidation.

```powershell
./experiments/section-search/guest-access/install-local.ps1
```

The installer preserves the page's prior shortcode in a WordPress option. Restore it and hide the guest link with:

```powershell
./experiments/section-search/guest-access/install-local.ps1 -Disable
```

The theme hook has no effect when this addon is absent or disabled. The entire section-search experiment directory remains excluded from Render images. No Render deployment is performed.

## Verification

`plugin/tests.php` runs inside local WordPress with provider requests mocked. `ui-test.cjs` uses an anonymous browser context and mocked paid responses. Neither test requires an administrator login or makes a paid API call.

Verified locally on October 18, 2026:

- Package validation: 3,986 eligible posts, 19,951 sections, zero new embedding calls.
- Guest regression suite: 728 assertions passed, 13 mocked HTTP calls, zero paid calls.
- Fingerprints of all production-plugin tables remained unchanged during regression testing.
- All 3,382 files preserved by the expansion manifest remained byte-for-byte unchanged.
- Anonymous browser checks passed at 1440, 1024, and 390 pixels, including menu order, no page overflow, search/error states, safe text rendering, live nonce validation, and private-route denial.
- PHPCS passed the existing experiment WordPress-Core ruleset; PHPStan passed the experiment's level 5 analysis. This was not a rerun of the entire production acceptance suite or a new relevance benchmark.

```powershell
docker compose -f wordpress-production/compose.local.yaml exec -T --user www-data wordpress wp eval-file /var/www/html/wp-content/plugins/ehrman-section-public/tests.php --path=/var/www/html
node experiments/section-search/guest-access/ui-test.cjs
```

The regression test requires no active guest searches and an expired request-limit window. Browser screenshots are saved under the ignored `experiments/section-search/private/guest-access-ui/` directory.
