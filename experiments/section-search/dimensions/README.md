# Isolated Section-Dimension Pilot

Compares the current Ask AI 2 reference with identical 300-word section indexes at 512 and 1,536 dimensions. This is local experimental code, not a production plugin feature. It does not create Ask AI 3 pages or change the production search index.

## Controls

- Same frozen 500 posts and 50 questions from the earlier section pilot.
- Same section boundaries, input text, embedding model (`text-embedding-3-small`), candidate limit, fusion, passage selection and reviewer for both section arms.
- The original 512-dimensional title/summary branch is retained in all arms. The 1,536-dimensional section arm therefore requests a second query vector.
- Current Ask AI 2 uses its original title/summary-only reviewer. Comparing it with a section arm changes both retrieval and review evidence; only the two section arms isolate the dimensionality setting.
- Old vectors, API receipts, grades and results are preserved. New embeddings and raw full-post evidence remain in the ignored `../private/dimensions-300-2026-10-17/` directory.
- Fresh searches bypass the WordPress result cache. Provider prompt caching is measured separately. No reruns are selected for a more favorable answer.

## Components

| File | Responsibility |
|---|---|
| `common.py` | Paths, source identities and preservation guards |
| `embed.py` | Receipt-checked, resumable 1,536-dimensional section embeddings |
| `compare.py` | Freeze inputs, run controlled searches and validate responses |
| `plugin/dimension-pilot.php` | Administrator-only local comparison endpoint |
| `plugin/tests.php` | Mocked WordPress contract, ranking and failure tests |
| `install-local.ps1` | Copy and activate the guarded local helper; run mocked tests |
| `score.py` | Blind full-post grades, receipt audit and common-pool metrics |
| `spot_check.py` | Reproducible selection of additional blind full-post checks |
| `report.py` | Render the report from validated results |
| `test_dimensions.py` | Offline embedding and response validation tests |

## Reproduction

Run from the repository root with the project's Python runtime and local WordPress environment. Review cost and authorization before any command marked `--approve-api`; those commands can incur real API charges. Existing valid receipts are reused. Never delete receipts or edit frozen manifests to force a fresh run. Use a new run identity for a new experiment.

```powershell
python experiments/section-search/dimensions/embed.py plan
python experiments/section-search/dimensions/embed.py embed --approve-api
python experiments/section-search/dimensions/embed.py verify
python experiments/section-search/dimensions/compare.py prepare
./wordpress-production/snapshot-database.ps1 -OutputPath experiments/section-search/private/dimensions-300-2026-10-17/database-before.json
./experiments/section-search/dimensions/install-local.ps1
python experiments/section-search/dimensions/compare.py run --approve-api
python experiments/section-search/dimensions/spot_check.py select
python experiments/section-search/dimensions/score.py grade --approve-api
```

Record coordinating-agent spot grades before looking at model grades or aggregate results. Public `adjudications.json` documents any corrections; original model grades are never overwritten. The same corrected judgment is applied to all arms.

Export the local server audit, deactivate only this experiment's helper, and capture the after fingerprint before generating the report:

```powershell
docker compose -f wordpress-production/compose.local.yaml cp wordpress:/opt/ehrman-section-pilot/requests.jsonl experiments/section-search/private/dimensions-300-2026-10-17/server-requests.jsonl
docker compose -f wordpress-production/compose.local.yaml exec -T wordpress wp plugin deactivate ehrman-dimension-pilot --allow-root --path=/var/www/html
./wordpress-production/snapshot-database.ps1 -OutputPath experiments/section-search/private/dimensions-300-2026-10-17/database-after.json
python experiments/section-search/dimensions/score.py report
python experiments/section-search/dimensions/report.py
```

The report verifies all 19 discovery tables retain identical counts and content hashes. Full production regression, PHPStan, PHPCS and offline tests must also pass. No commit, push, Render deployment, production conversion, ingestion change or download-skill change is part of this experiment.

## Interpretation

The primary comparison is section 512 versus section 1,536. Inspect relevance and ranking alongside candidate overlap, selected passages, fallback behavior, response time and cost. P@5/P@10 count missing slots as nonrelevant; nDCG uses one common pool of full-post judgments. The pool is not exhaustive corpus-wide relevance, and AI grades are not a human gold standard. Reused questions and a selected 500-post pilot require fresh-question and human confirmation before deployment.

Full HTTP time includes common local fixture-validation overhead and the extra embedding round trip in the 1,536-dimensional arm. Do not treat it as browser-paint latency or compare it directly with a different harness. Stored 512 vectors and newly generated 1,536 vectors are very close under normalized prefix comparison but not bitwise identical; see the recorded prefix diagnostic.
