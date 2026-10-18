# Ask AI 2 versus Ask AI 3: full-corpus comparison

Run: `full-corpus-250-2026-10-18`. Completed: 249/250 question pairs.
**Final status:** All 250 questions were attempted. Paired relevance grades are available for 249; question 207 had an unresolved Ask AI 3 technical failure. The challenge tables cover 49 completed pairs, not all 50. [Read the reliability and complete cost accounting notes](completion-notes.md).

Both methods searched the same 3,986 posts. Ask AI 2 used title/summary hybrid retrieval; Ask AI 3 added 300-word section retrieval and a matching passage for candidate review. Both used 100 candidates, small/512 embeddings and the same pinned reviewer model. This is a complete-method comparison, not an isolated embedding test.

The original 200 questions and 50 new diagnostic questions are reported separately. New questions were frozen before either search ran; they are specific challenges, not a representative sample of reader traffic.

## batch-1 (50 questions)

Wins: Ask AI 2 **17**, Ask AI 3 **24**, ties **9**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 75.6% | 77.6% |
| Top 10 relevance | 57.0% | 57.6% |
| Ranking score (nDCG@10) | 86.0% | 89.1% |
| Average successful submit-to-display | 2.37s | 4.08s |
| Median / 95th percentile | 2.23s / 3.23s | 3.95s / 4.81s |
| Average successful-search cost | $0.01001 (1.001 cents) | $0.03914 (3.914 cents) |
| Unique relevant top-10 posts/question | 1.84 | 1.90 |
| No relevant posts in top 10 | 0 | 0 |

Paired ranking difference (AI 3 minus AI 2): 3.17 percentage points; bootstrap 95% interval -0.59 to 7.25.

## batch-2 (50 questions)

Wins: Ask AI 2 **23**, Ask AI 3 **19**, ties **8**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 89.6% | 89.2% |
| Top 10 relevance | 74.8% | 72.4% |
| Ranking score (nDCG@10) | 88.2% | 87.5% |
| Average successful submit-to-display | 2.75s | 4.39s |
| Median / 95th percentile | 2.67s / 3.86s | 4.31s / 5.10s |
| Average successful-search cost | $0.01010 (1.010 cents) | $0.03909 (3.909 cents) |
| Unique relevant top-10 posts/question | 2.46 | 2.22 |
| No relevant posts in top 10 | 0 | 0 |

Paired ranking difference (AI 3 minus AI 2): -0.72 percentage points; bootstrap 95% interval -4.30 to 2.85.

## batch-3 (50 questions)

Wins: Ask AI 2 **21**, Ask AI 3 **25**, ties **4**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 90.4% | 92.4% |
| Top 10 relevance | 70.6% | 70.4% |
| Ranking score (nDCG@10) | 83.6% | 86.2% |
| Average successful submit-to-display | 2.26s | 4.11s |
| Median / 95th percentile | 2.19s / 2.74s | 3.99s / 4.95s |
| Average successful-search cost | $0.00999 (0.999 cents) | $0.03893 (3.893 cents) |
| Unique relevant top-10 posts/question | 2.64 | 2.62 |
| No relevant posts in top 10 | 0 | 0 |

Paired ranking difference (AI 3 minus AI 2): 2.60 percentage points; bootstrap 95% interval -2.83 to 8.05.

## batch-4 (50 questions)

Wins: Ask AI 2 **22**, Ask AI 3 **19**, ties **9**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 77.6% | 80.0% |
| Top 10 relevance | 59.6% | 57.4% |
| Ranking score (nDCG@10) | 82.2% | 84.2% |
| Average successful submit-to-display | 2.40s | 4.29s |
| Median / 95th percentile | 2.30s / 3.27s | 4.15s / 5.08s |
| Average successful-search cost | $0.01007 (1.007 cents) | $0.03910 (3.910 cents) |
| Unique relevant top-10 posts/question | 2.32 | 2.10 |
| No relevant posts in top 10 | 3 | 2 |

Paired ranking difference (AI 3 minus AI 2): 1.99 percentage points; bootstrap 95% interval -3.43 to 8.02.

## batch-5 (49 questions)

Wins: Ask AI 2 **12**, Ask AI 3 **27**, ties **10**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 52.7% | 56.7% |
| Top 10 relevance | 33.9% | 35.1% |
| Ranking score (nDCG@10) | 71.5% | 83.8% |
| Average successful submit-to-display | 2.48s | 4.20s |
| Median / 95th percentile | 2.40s / 3.28s | 4.10s / 5.25s |
| Average successful-search cost | $0.01008 (1.008 cents) | $0.03940 (3.940 cents) |
| Unique relevant top-10 posts/question | 1.10 | 1.22 |
| No relevant posts in top 10 | 10 | 7 |

Paired ranking difference (AI 3 minus AI 2): 12.26 percentage points; bootstrap 95% interval 3.69 to 20.99.

## original-200 (200 questions)

Wins: Ask AI 2 **83**, Ask AI 3 **87**, ties **30**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 83.3% | 84.8% |
| Top 10 relevance | 65.5% | 64.5% |
| Ranking score (nDCG@10) | 85.0% | 86.8% |
| Average successful submit-to-display | 2.44s | 4.22s |
| Median / 95th percentile | 2.31s / 3.40s | 4.12s / 5.11s |
| Average successful-search cost | $0.01004 (1.004 cents) | $0.03906 (3.906 cents) |
| Unique relevant top-10 posts/question | 2.31 | 2.21 |
| No relevant posts in top 10 | 3 | 2 |

Paired ranking difference (AI 3 minus AI 2): 1.76 percentage points; bootstrap 95% interval -0.57 to 4.12.

## challenge-50 (49 questions)

Wins: Ask AI 2 **12**, Ask AI 3 **27**, ties **10**.

| Measurement | Ask AI 2 | Ask AI 3 |
|---|---:|---:|
| Top 5 relevance | 52.7% | 56.7% |
| Top 10 relevance | 33.9% | 35.1% |
| Ranking score (nDCG@10) | 71.5% | 83.8% |
| Average successful submit-to-display | 2.48s | 4.20s |
| Median / 95th percentile | 2.40s / 3.28s | 4.10s / 5.25s |
| Average successful-search cost | $0.01008 (1.008 cents) | $0.03940 (3.940 cents) |
| Unique relevant top-10 posts/question | 1.10 | 1.22 |
| No relevant posts in top 10 | 10 | 7 |

Paired ranking difference (AI 3 minus AI 2): 12.26 percentage points; bootstrap 95% interval 3.69 to 20.99.

### Useful-Result Caveat

7 challenge pairs had no substantially relevant result from either method; their ranking wins can reflect marginal posts only. Among the 42 pairs with a relevant result from at least one method, Ask AI 3 won 23, Ask AI 2 won 11, and 8 tied. The predefined 49-pair totals above are unchanged. [Coverage details](completion-notes.md).

## How to interpret these results

- The AI grader receives the complete posts, without method labels or ranking positions. The union of the two final top tens is deduplicated and one accepted grade per question/post pair is applied to both methods, with fresh rechecks when evidence or labels fail validation; evidence quotations are checked against source text. AI grading is not a substitute for human review.
- Grades: 3 directly answers, 2 substantial useful support, 1 marginal, 0 irrelevant. Grades 2 and 3 count as relevant. Precision divides by 5/10, including empty slots; earlier historical reports used returned-result denominators. Do not directly compare their percentages.
- Wins use nDCG@10; absolute differences of 0.03 or less are ties. The ideal ranking is based on the judged pooled results, not all relevant posts in the corpus. This is not corpus-wide recall.
- Browser timing starts at form submission and ends after results render and two animation frames, using an identical renderer for both arms. It excludes login/page loading. Backend phase timings are saved separately. Experimental file loading and integrity checks contribute to latency; these are not production-capacity benchmarks.
- WordPress query/result caches were bypassed. Provider prompt-cache tokens were counted at their discounted rate. Costs include embedding plus candidate review, not initial index construction, hosting, or separate evaluation grading.
- The confidence interval resamples paired questions; it does not quantify grader error or all model nondeterminism. No tuning occurred between batches.
- Full-text grading cost, including rechecks: $10.9163. Raw provider receipts are private; no API keys or full post text are in this report.

[Question-by-question results](results.json) | [Frozen questions](questions.json) | [Run protocol](manifest.json)
