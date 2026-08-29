# Ask AI 2 Metadata Vector Experiment

Status: Deferred for later evaluation. Not implemented.

## Current design

- Each eligible post has one 512-dimension `text-embedding-3-small` vector.
- The embedded content contains only the post title and search summary.
- Hybrid retrieval also uses exact title/summary wording and assigned topic, alias, and secondary-keyword matches as softer ranking signals.
- The top semantic candidates are refined with AI using the reader's question plus candidate titles and summaries.

## Proposed experiment

Keep the existing title-and-summary vector unchanged. Add a separate metadata vector containing carefully structured post metadata, potentially including:

- Assigned topic names
- Topic aliases
- Concise topic descriptions
- Selected secondary keywords

Do not replace or combine these fields with the existing content vector. Keeping the vectors separate allows independent weighting and prevents broad or supporting metadata from diluting the post's central content.

Initial scoring trial:

```text
80% title-and-summary vector similarity
20% metadata vector similarity
```

The metadata contribution should be configurable and easy to disable.

## Expected impact

- Modest improvement overall, approximately 5-10%.
- Potentially 15-25% improvement for conceptual questions whose wording differs from post titles and summaries.
- Little expected difference for direct questions that already name a Gospel, person, book, or established topic.
- Primary benefit: improving recall so relevant posts enter the candidate set available to AI refinement.

## Evaluation plan

Preserve the current retrieval strategy as the control and add a feature-switched experimental strategy. Run the same 50-100 questions through both versions and compare:

- Relevant-post recall among the top 100 candidates
- Relevance of the first 10 displayed results
- Posts retained and ordered by AI refinement
- Irrelevant posts introduced by metadata
- Latency and API cost
- Per-question winner and reason

Do not make the metadata strategy the default unless the paired evaluation demonstrates a clear improvement. Rewrite individual search summaries only when evaluation shows that a summary omits or misrepresents a post's central subject; do not plan a wholesale summary rewrite.
