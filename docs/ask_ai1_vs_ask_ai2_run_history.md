# Ask AI 1 vs. Ask AI 2 Benchmark History

Last updated: 2026-08-31

Each run uses blinded relevance grading from complete local post text. Grades 2 and 3 count as relevant, and an nDCG@10 difference of 0.03 or less is a tie.

## Runs

| Run | Questions | Ask AI 1 wins | Ask AI 2 wins | Ties | AI 1 nDCG@10 | AI 2 nDCG@10 | AI 1 cost/question | AI 2 cost/question |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| [1](../docs/ask_ai1_vs_ask_ai2_same50_full_text_evaluation_2026-08-31.md) | 50 | 13 | 32 | 5 | 0.6254 | 0.8597 | 0.506 cents | 0.999 cents |
| [2](../docs/ask_ai1_vs_ask_ai2_new50_full_text_evaluation_2026-08-31_run02.md) | 50 | 10 | 35 | 5 | 0.6612 | 0.9089 | 0.560 cents | 1.001 cents |
| [3](../docs/ask_ai1_vs_ask_ai2_new50_full_text_evaluation_2026-08-31_run03.md) | 50 | 9 | 31 | 10 | 0.7190 | 0.8902 | 0.536 cents | 1.005 cents |
| [4](../docs/ask_ai1_vs_ask_ai2_new50_full_text_evaluation_2026-08-31_run04.md) | 50 | 6 | 35 | 9 | 0.6210 | 0.8933 | 0.560 cents | 1.002 cents |

## Cumulative

Across 4 runs and 200 questions, Ask AI 1 won 38, Ask AI 2 won 133, and 29 were ties.

| Weighted mean metric | Ask AI 1 | Ask AI 2 |
|---|---:|---:|
| Precision@5 | 77.6% | 80.6% |
| Precision@10 | 75.4% | 73.6% |
| Recall@10 within judged pool | 54.3% | 82.3% |
| nDCG@10 | 0.6566 | 0.8880 |

Average measured retrieval cost per question across all runs was 0.540 cents for Ask AI 1 and 1.002 cents for Ask AI 2. Blinded evaluation cost is tracked separately.

Separately recorded benchmark retry overhead totals $0.0218. This is excluded from normal per-question cost.
