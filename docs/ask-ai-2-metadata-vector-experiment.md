# Ask AI 2 Metadata Vector Experiment

Status: Implemented and verified locally. The first paired relevance evaluation is complete; production deployment is still pending.

## Control design

- Each eligible post has one 512-dimension `text-embedding-3-small` vector.
- The embedded content contains only the post title and search summary.
- Hybrid retrieval also uses exact title/summary wording and assigned topic, alias, and secondary-keyword matches as softer ranking signals.
- The top semantic candidates are refined with AI using the reader's question plus candidate titles and summaries.
- Set `EHRMAN_DISCOVERY_SEMANTIC_RETRIEVAL=hybrid` to retain this strategy as the evaluation control.

## Implemented experiment

The existing title-and-summary vector remains unchanged. Three separate metadata vectors are generated for each eligible post when the corresponding data exists:

- **Topic vector:** assigned topic names and their descriptions
- **Topic-alias vector:** aliases assigned through the search-term index
- **Secondary-keyword vector:** assigned secondary-keyword labels

The vectors are stored separately so each source can be weighted independently. Topic evidence receives more influence than aliases, and aliases receive more influence than secondary keywords. This prevents broad supporting metadata from diluting the title-and-summary signal.

Current vector scoring:

```text
80% title-and-summary vector similarity
12% topic vector similarity
 5% topic-alias vector similarity
 3% secondary-keyword vector similarity
```

Metadata similarities must also clear minimum cosine thresholds before contributing:

```text
Topic:             0.30
Topic alias:       0.35
Secondary keyword: 0.35
```

The existing exact metadata ranking remains in place after vector retrieval, using topic `1.2`, alias `1.0`, and secondary-keyword `0.8` rank weights. The vector weights improve semantic recall; the exact-match weights reward direct matches.

Set `EHRMAN_DISCOVERY_SEMANTIC_RETRIEVAL=hybrid-metadata` to use this strategy. The previous `hybrid` and legacy `semantic` strategies remain available, so the experiment is reversible without deleting either index.

## Local verification

The local production-equivalent WordPress and MySQL stack contains:

- 3,980 current title-and-summary vectors
- 3,980 topic vectors
- 10 topic-alias vectors
- 3,668 secondary-keyword vectors
- 7,658 total metadata vectors

The active local analytics pipeline is recorded as `hybrid-metadata-1`. A public REST request completed successfully with the metadata-enhanced strategy and returned AI-refined results. This confirms the index and retrieval path work; it does not yet establish that relevance is better than the control.

## Build and deployment

After installing version 0.8.0 or changing topic, alias, keyword, description, or embedding configuration, refresh both indexes:

```bash
wp ehrman-discovery embeddings --allow-root --path=/var/www/html
```

Ask AI 2 reports the index as unavailable under `hybrid-metadata` until every expected metadata vector is current. This prevents a partially built metadata index from silently changing retrieval behavior.

## Expected impact

- Modest improvement overall, approximately 5-10%.
- Potentially 15-25% improvement for conceptual questions whose wording differs from post titles and summaries.
- Little expected difference for direct questions that already name a Gospel, person, book, or established topic.
- Primary benefit: improving recall so relevant posts enter the candidate set available to AI refinement.

## Evaluation plan

Use `hybrid` as the control and `hybrid-metadata` as the experimental strategy. Run the same 50-100 questions through both versions and compare:

- Relevant-post recall among the top 100 candidates
- Relevance of the first 10 displayed results
- Posts retained and ordered by AI refinement
- Irrelevant posts introduced by metadata
- Latency and API cost
- Per-question winner and reason

The experiment can be enabled explicitly with `hybrid-metadata`. The selected local and Render default is `hybrid`, which uses the original title-and-summary vector. Rewrite individual search summaries only when evaluation shows that a summary omits or misrepresents a post's central subject; do not plan a wholesale summary rewrite.

## First paired result

The August 29, 2026 frozen 50-question evaluation favored `hybrid-metadata` on 26 questions, favored the original `hybrid` strategy on 12, and tied on 12. Mean nDCG@10 increased from 0.886 to 0.915, precision@10 increased from 88.3% to 90.4%, and pooled recall@10 increased from 75.7% to 79.9%. Precision@5 decreased slightly from 97.2% to 96.0%.

The result supports continuing with the metadata-enhanced strategy, but the current weights are not yet final. Broad taxonomy evidence sometimes displaced more specific title-and-summary evidence near the top of the list. See [the full paired evaluation](ask_ai2_metadata_vector_evaluation_2026-08-29.md) for methodology, every question, ranked grades, and the largest improvements and regressions.

## Deployment decision

On August 30, 2026, the single-vector `hybrid` strategy was selected for Ask AI 2. The Render blueprint no longer enables `hybrid-metadata`, the normal embeddings command skips the optional metadata index, and `--purge-metadata` removes experimental metadata vectors from an existing database.
