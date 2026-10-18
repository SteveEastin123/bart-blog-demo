"""Render the completed metadata-review results without making API calls."""

import compare as c


def render():
    data = c.read(c.PUBLIC / 'results.json')
    s = data['summary']
    lines = ['# Ask AI 2: Topics and Keywords in Candidate Review', '', f"Run: {c.RUN}.", '',
             '## Design', '',
             'Three fresh reviews per question, for 50 existing pilot questions: titles/current short summaries; those fields plus assigned topics; those fields plus assigned topics and secondary keywords. All arms receive exactly the same original 100 candidates in the same order from the frozen 500-post Ask AI 2 pilot. No longer summaries, section vectors, new embeddings, new retrieval or Ask AI 1 changes are involved.',
             'The baseline uses the production prompt. Both enriched arms share one replacement instruction permitting labels as supplementary, fallible context. Topics are described as central subjects and secondary keywords as supporting context; matching labels alone do not prove relevance. Existing direct/related/background criteria, model, low reasoning effort, 800 output-token limit, parser, tier ordering and empty-selection fallback remain unchanged. Thus this tests adding metadata with minimal instructions to use it, not a metadata-only change under identical baseline instructions.',
             'The WordPress CLI exports requests and parses responses through the real production helpers. Paid review calls are sent directly from the experiment runner. No public endpoint or plugin is installed, and no production cache or analytics entries are written.', '',
             '## Results', '',
             '| Measure | Titles + summaries | + Topics | + Topics + keywords |', '|---|---:|---:|---:|']
    fields = [('Top-five relevance (P@5)', 'precisionAt5', '.1%', 1),
              ('Top-ten relevance (P@10)', 'precisionAt10', '.1%', 1),
              ('Ranking score (nDCG@10 x 100)', 'ndcgAt10', '.1f', 100),
              ('Relevant posts in top ten', 'relevantTop10', '.2f', 1),
              ('Displayed posts in top ten', 'returnedTop10', '.2f', 1),
              ('Relevance among displayed posts', 'displayedPrecisionAt10', '.1%', 1),
              ('Mean review API response time (seconds)', 'seconds', '.2f', 1),
              ('Measured review cost (cents/question)', 'costUsd', '.3f', 100),
              ('Estimated question cost incl. unchanged query embedding (cents)', 'estimatedQuestionCostUsd', '.3f', 100),
              ('Review cost without provider-cache discounts (cents/question)', 'uncachedReviewCostUsd', '.3f', 100),
              ('Review input tokens', 'inputTokens', '.0f', 1),
              ('Provider-cached input tokens', 'cachedTokens', '.0f', 1),
              ('Broader fallback rate', 'fallback', '.1%', 1)]
    for label, key, fmt, scale in fields:
        lines.append('| ' + label + ' | ' + ' | '.join(format(s[a][key] * scale, fmt) for a in c.ARMS) + ' |')
    lines += ['', 'P@5/P@10 have fixed denominators: unfilled slots count as nonrelevant. Grades 2 and 3 are relevant. nDCG uses the same pooled full-post judgments for all three arms, weighting directly answering posts and earlier ranks more strongly. These restricted-corpus scores are not directly comparable with full-blog benchmarks.', '',
              '## Pairwise Outcomes', '', '| Comparison | First wins | Second wins | Ties | Mean ranking change, second minus first | Paired 95% interval |', '|---|---:|---:|---:|---:|---|']
    for pair in data['pairwise']:
        ci = pair['ndcgDifference']
        lines.append(f"| {c.LABELS[pair['first']]} vs {c.LABELS[pair['second']]} | {pair['firstWins']} | {pair['secondWins']} | {pair['ties']} | {ci['mean']*100:+.2f} | [{ci['lower95']*100:+.2f}, {ci['upper95']*100:+.2f}] |")
    lines += ['', 'A tie is an absolute nDCG@10 difference no greater than 0.03. Intervals are paired question-level bootstrap estimates, not measures of grader error or repeated-run variability. Three pairwise comparisons are exploratory and not adjusted for multiple comparisons.', '',
              '## Recommendation', '',
              'Keep titles and summaries as the production reviewer input. The baseline had the best average top-five relevance, top-ten relevance and ranking score in this pilot. Neither metadata addition demonstrated an improvement; all three primary ranking-difference intervals include zero, so this is not proof of a universal advantage for the baseline.',
              f"Without provider-cache discounts, topics increased average review cost by {s['topics']['uncachedReviewCostUsd']/s['baseline']['uncachedReviewCostUsd']-1:.1%}, and topics plus keywords by {s['topics-keywords']['uncachedReviewCostUsd']/s['baseline']['uncachedReviewCostUsd']-1:.1%}. The small measured time differences do not establish a reliable speed advantage.",
              'A plausible explanation is that broad topic and keyword labels encourage matches that are related to the subject but do not answer the specific question. That is a hypothesis supported by the full-text spot checks, not a causal conclusion from this test. More metadata did not reliably overcome that problem even when the prompt described labels as fallible clues.',
              'Do not expand production inputs based on this result. Before another broad experiment, inspect specific missed or misranked posts to distinguish retrieval failures from review failures. Any later proposed improvement should be confirmed with fresh questions and human judgments on a broader corpus.', '',
              '## Sensitivity Checks', '',
              'Numerical full-post adjudications were decided before inspecting aggregate quality results. Before the current corrections, the pairwise outcomes were:', '']
    for pair in data['beforeAdjudication']:
        lines.append(f"- {c.LABELS[pair['first']]} vs {c.LABELS[pair['second']]}: {pair['firstWins']} first wins, {pair['secondWins']} second wins, {pair['ties']} ties.")
    diagnostic = data['postHocUnflaggedSensitivity']
    lines += ['', f"As a post-hoc diagnostic, removing whole questions with any discarded model selection or fallback leaves {diagnostic['retainedQuestions']} questions. This outcome-dependent subset is not the primary analysis and is not confirmatory. The baseline still has the higher average ranking score than either enriched arm. Details and excluded question IDs are saved in results.json.", '',
              '## Grading and Limitations', '',
              f"The common pool contains {data['gradedPairs']} question/post judgments: {data['gradedPairs']-data['newGrades']} reused, verified prior full-post judgments and {data['newGrades']} newly graded pairs. The pool includes all prior judgments for these questions plus newly returned top-ten posts. New grades use complete stored post text, neutral labels, and no method names, ranks, summaries or topic/keyword labels. Evidence quotations are checked against the source text. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly and substantially answering.",
              f"There are {len(data['adjudications']['checks'])} additional coordinating-agent full-text spot checks and {len(data['adjudications']['overrides'])} current corrections. See adjudications.json for selection and blinding details. Original grade files are preserved, and corrections apply equally to every arm. Earlier documented corrections are inherited. AI grades are not a human gold standard.",
              'The final grading batch required one offline evidence repair: all three saved attempts omitted literal emphasis asterisks in a quoted source sentence. After reading both complete stored posts, the coordinator restored the exact quotation without changing the saved numerical API grades. A separately documented relevance adjudication is applied in the report. The three raw receipts remain unchanged and all attempts are included in cost; see evidence-repair.json and final-batch-source-check.json.',
              'The same questions have been used in earlier pilots. This is diagnostic, not independent confirmation. The 500-post sample includes deliberately difficult cases and one known stored opening excerpt (post 2359). The reviewer cannot recover relevant posts missing from the frozen candidate lists. Absent tags also do not prove a post lacks relevant discussion.', '',
              '## Timing and Costs', '',
              'All 150 accepted reviews are fresh calls, run sequentially in seeded randomized order within each question. Saved raw provider receipts support request identity, usage and cost. No WordPress result cache is used. Re-running the command resumes from saved receipts rather than paying again.',
              f"{len(data['responseWarnings'])} responses contained selections discarded by the production parser (unknown IDs/tiers or duplicates); {sum(len(v) for v in data['responseWarnings'].values())} selections were discarded. Raw responses are preserved, not retried for better answers. See responseWarnings in results.json and validation-note.json for the early validation correction.",
              'Timing measures successful direct review API submission through complete response receipt. It excludes vector search, query embedding, WordPress page rendering and browser painting, so it is not end-to-end reader response time. Provider prompt caching is permitted and can differ across arms, particularly because the unchanged baseline inputs were used in earlier tests. Compare both measured and no-discount costs; do not attribute all observed latency differences to metadata.',
              'The estimated total question cost adds the previously measured query-embedding cost, identical across arms, to each new review cost. No new embedding calls were made or charged in this experiment. These estimates do not add server hosting costs.',
              f"Accepted review cost: ${data['costs']['acceptedReviewsUsd']:.4f}. All review attempts: ${data['costs']['allReviewAttemptsUsd']:.4f} across {data['costs']['reviewCalls']} saved calls. New grading: ${data['costs']['gradingUsd']:.4f} across {data['costs']['gradingCalls']} saved calls. Total new API cost: ${data['costs']['totalUsd']:.4f}. Earlier retrieval and grading costs are not charged again or included.",
              'Rates checked October 17, 2026: $0.75/million input tokens, $0.075/million cached input tokens and $4.50/million output tokens for gpt-5.4-mini. Provider invoices are authoritative. [Official model pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).', '',
              '## Question Results', '', '| # | Question | Titles + summaries | + Topics | + Topics + keywords |', '|---|---|---:|---:|---:|']
    for q in data['questions']:
        lines.append(f"| {q['id']} | {q['question']} | " + ' | '.join(f"{q['versions'][a]['metrics']['ndcgAt10']*100:.1f}" for a in c.ARMS) + ' |')
    lines += ['', '## Preservation', '',
              f"All original frozen inputs remain unchanged. All {data['discoveryTablesUnchanged']} discovery tables retain their row counts and content fingerprints. Production PHP, canonical JSON, summaries, vectors, download skill and WordPress ingestion guidance are unchanged. Nothing was committed, pushed or deployed. Private full text, inputs and raw receipts stay in the existing ignored private directory, outside Render packaging.",
              'No variant has been adopted. Any promising result requires fresh-question confirmation and human review before production changes.', '']
    (c.PUBLIC / 'report.md').write_text('\n'.join(lines), encoding='utf-8')
    print(c.PUBLIC / 'report.md')


if __name__ == '__main__':
    render()
