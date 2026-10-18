# Ask AI 3 Section Pilot

## Recommendation

The highest observed mean ranking score is **300**. None of the section-size comparisons has a 95% paired interval wholly above zero in this frozen run. There is no convincing overall winner; keep Ask AI 2 unchanged.
The 300-word configuration has a small ranking lead, but slightly lower fixed-slot P@5 than baseline and about 0.33 seconds more latency. The representative-question subset does not show a gain; most of the apparent benefit comes from the ten challenge questions. Do not expand section indexing to the full collection on this evidence alone.
A useful next controlled test is 300-word retrieval with matching passages supplied to the reviewer. The current benchmark kept that reviewer limited to titles and summaries, so details found in a section may still be invisible during final review. The local UI already offers this optional mode; its quality has not yet been benchmarked.

## Configuration

Run date: October 16, 2026. Local MariaDB demo only; no production search changes.
500 frozen posts: 400 proportional seeded selections and 100 full-text-reviewed challenge cases.
50 questions fixed before search results. Same embedding model (512 dimensions), production hybrid baseline, 100 unique candidates, and unchanged summary-only reviewer in every arm.
Sections supplement existing retrieval with a fixed reciprocal-rank contribution (weight 1, constant 60), using each post's best section. Paragraph-aware targets have 20% overlap.

## Results

| Configuration | P@5 | P@10 | nDCG@10 | Wins / losses / ties vs baseline | Mean seconds | Cents/question |
|---|---:|---:|---:|---|---:|---:|
| baseline | 60.4% | 40.4% | 0.7151 | 0 / 0 / 50 | 2.35 | 0.997 |
| 300 | 59.6% | 40.2% | 0.7367 | 26 / 18 / 6 | 2.68 | 0.997 |
| 400 | 58.0% | 40.4% | 0.7172 | 19 / 17 / 14 | 2.60 | 1.006 |
| 500 | 59.6% | 38.8% | 0.7032 | 21 / 20 / 9 | 2.60 | 0.991 |

## Uncertainty and Candidate Changes

Paired bootstrap intervals measure question-sampling uncertainty only, not grader error or repeated-review variability. An interval crossing zero is not evidence of a clear improvement.

- 300 words: mean nDCG difference +0.0216; 95% interval [-0.0259, +0.0696]. Mean shared candidates: 84.1/100; identical sets: 0/50; identical ordered lists: 0/50.
- 400 words: mean nDCG difference +0.0021; 95% interval [-0.0432, +0.0481]. Mean shared candidates: 83.7/100; identical sets: 0/50; identical ordered lists: 0/50.
- 500 words: mean nDCG difference -0.0118; 95% interval [-0.0596, +0.0355]. Mean shared candidates: 83.5/100; identical sets: 0/50; identical ordered lists: 0/50.

## Cost Ledger

Estimated USD, using the same recorded rates as the pilot. Not invoice totals.

- section-index: $0.0562 (194 API calls).
- blind-full-post-grading: $1.2400 (123 API calls).
- challenge-selection: $0.3780 (150 API calls).
- evidence-audit-regrading: $0.1682 (32 API calls).
- benchmark-search: $1.9952 (200 searches).
- development-search: $0.1995 (20 searches).
- initial-memory-layout-search: $0.0411 (4 searches).

Total recorded experiment cost: **$4.0783**. Mocked tests incur no API cost.
Section vectors alone occupy 12,867,584 bytes as float32 values (12.87 MB decimal), across all three configurations. Runtime files total 16,176,149 bytes including text and the 500 baseline vectors; no production database vector table was changed.

## Grading Audit

525 question/post judgments covering 272 distinct complete stored posts. 45/50 questions had at least one useful (grade 2 or 3) post in the judged pool.
63 judgments with initially unverified evidence were independently regraded without earlier grades or method labels. Both versions are preserved. Quotation matching allows case, whitespace, quotation typography, and explicitly marked ordered omissions; it does not allow paraphrases.
The coding agent also read eleven complete posts for spot checks, including a pair of near-duplicate posts graded inconsistently. Two grades were adjusted to the same marginal-relevance level; six quotations were corrected with grades retained. See adjudications.json. This is not a human gold-standard assessment.
Remaining unverified evidence quotations: 0. See results.json for identifiers.

## Question Groups

| Group | Baseline nDCG@10 | 300 words | 400 words | 500 words |
|---|---:|---:|---:|---:|
| representative | 0.7346 | 0.7301 | 0.7194 | 0.6940 |
| challenge | 0.6369 | 0.7631 | 0.7081 | 0.7401 |

## Interpretation Limits

- P@5 and P@10 use fixed denominators of 5 and 10; displayed-results precision and counts are also saved. Historical reports used available-results denominators, so those percentages are not directly comparable.
- Relevance grades come from complete stored post text, with method names and ranks hidden. Automated judgments need human spot-checking, especially disagreements and unverified evidence quotations.
- nDCG uses the pooled top-ten judgments, not exhaustive relevance labels for the whole blog. It can be high even when only marginal results are available; inspect precision and useful-post counts together.
- No WordPress result cache was used. Provider cached-input tokens are retained in results.json. Measured time is authenticated HTTP submission through complete JSON results, excluding browser painting.
- The summary-only reviewer and its existing broader-result fallback are deliberately unchanged. This isolates candidate retrieval; the optional passage-evidence UI mode is not part of this four-arm benchmark.
- Search costs include query embedding and review, not index creation, sample selection, or independent grading. Prices are estimates using the plugin's recorded rates, not invoice totals.
- This pilot cannot establish full-collection superiority. Confirm promising configurations on all eligible posts and new held-out questions before adoption.

## Verification

- 438 local PHP regression checks pass with mocked API calls and unchanged production analytics/vector counts.
- Python corpus, chunking, evidence, and metric unit tests pass.
- Pilot WordPress Core coding-standard scan and PHPStan level 5 pass. Production plugin source is unchanged; its existing max-level configuration is retained.
- Full local WordPress acceptance suite passes, including 77 PHP syntax checks, canonical counts, ingestion, analytics, public pages, search, and pagination.
- Desktop (1440px) and mobile (390px) browser checks pass, including anonymous denial and non-overflowing two-column/stacked layouts. Browser tests mock responses; the benchmark itself uses real REST requests.
