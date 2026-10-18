# Candidate-Review Metadata Pilot

Local experiment `metadata-review-2026-10-17`. Three versions of Ask AI 2's final
review: existing titles/short summaries, plus topics, plus topics/secondary
keywords. The same 50 questions and exact ordered 100-candidate lists are frozen
from the completed 500-post summary comparison. No production files are edited.

`bridge.php` is a CLI-only adapter, not an installed plugin. It exports payloads
using the real production reviewer and later parses the responses through the
same production parser. It makes no HTTP calls or database writes. The enriched
arms share one minimal prompt replacement allowing supplementary labels; all
other settings, selection rules, ordering and fallback stay unchanged.

`compare.py` validates inputs, freezes the design, runs fresh sequential API
reviews in seeded randomized order, reuses verified prior full-post grades,
grades new result pairs blindly, and writes metrics. It does not create vectors.
Its reported times are review API latency, not full website response time.
Provider prompt-cache discounts are reported separately from no-discount costs.

Private artifacts: `../private/metadata-review-2026-10-17/`.
Public reports: `../runs/metadata-review-2026-10-17/`.
Both paths are relative to this folder.

## Completed Run

All 150 reviews and 1,072 pooled question/post judgments are complete (953
verified prior judgments and 119 new ones). See the
[report](../runs/metadata-review-2026-10-17/report.md) and
[machine-readable results](../runs/metadata-review-2026-10-17/results.json).
The baseline had the highest average relevance and ranking scores; neither
metadata addition demonstrated a clear improvement. No production change is
recommended from this pilot. Total new API cost, including grading: $1.6445.

Ten additional full-post spot checks produced four documented numerical
adjudications, applied equally to all arms. One final-batch quotation required
an offline repair because the grader omitted literal emphasis asterisks.
`recover_final_batch.py` reproduces that narrowly scoped repair from the saved
receipt without another API call. Raw receipts and original numerical grades
remain intact; see the public evidence and adjudication notes.

Offline tests require the private fixtures. The final pool/evidence tests also
require the completed grading artifacts; they are verification tests, not a
prerequisite for starting a new run.

## Workflow

Use the repository's Python runtime and local Docker environment. Capture a
database fingerprint before executing the bridge or paid phases.

```powershell
python experiments/summary-search/metadata-review/compare.py prepare
./wordpress-production/snapshot-database.ps1 -OutputPath experiments/summary-search/private/metadata-review-2026-10-17/database-before.json
./experiments/summary-search/metadata-review/bridge.ps1 -Operation export
python -m unittest discover -s experiments/summary-search/metadata-review -p 'test_*.py'
python experiments/summary-search/metadata-review/compare.py freeze
# Only after explicit authorization for paid reviews and new full-post grading:
python experiments/summary-search/metadata-review/compare.py run --approve-api
./experiments/summary-search/metadata-review/bridge.ps1 -Operation parse
python experiments/summary-search/metadata-review/compare.py grade --approve-api
./wordpress-production/snapshot-database.ps1 -OutputPath experiments/summary-search/private/metadata-review-2026-10-17/database-after.json
python experiments/summary-search/metadata-review/compare.py report
python experiments/summary-search/metadata-review/report.py
python experiments/summary-search/metadata-review/compare.py verify
```

Saved API receipts are resumable. Do not delete them to force paid reruns or
replace frozen data. Use a separately identified run for a new experiment.
Reproduction on another machine requires the private fixtures. No demo rebuild,
commit, push, Render deployment or production adoption is implied.
