"""Human-readable account of the completed, isolated dimension comparison."""

import statistics

import common as c


def render():
    d=c.read(c.PUBLIC/'results.json')
    s=d['summary']
    primary=next(p for p in d['pairwise'] if p['first']=='sections-512' and p['second']=='sections-1536')
    ci=primary['ndcgDifference']
    lines=['# Section Vectors: 512 vs. 1,536 Dimensions','',f'Run: {c.RUN}. Local pilot only.','',
           '## Recommendation','',
           'Keep current Ask AI 2 in production and retain 512-dimensional sections as an experiment. This run does not justify expanding section vectors to 1,536 dimensions: the ranking improvement was small and uncertain, top-five relevance did not improve, and raw vector storage triples. Both section methods cost about 3.8 times the current reference because the reviewer reads passage text.',
           'Do not convert the full corpus or change ingestion/download workflows on this evidence. A later experiment should separately investigate selective passage review or reviewer reliability, using fresh holdout questions and human review.','',
           '## Design','',
           'Three fresh searches for each of the same 50 previous pilot questions, across the same 500 posts: current Ask AI 2; 300-word sections at 512 dimensions with passage review; the identical sections at 1,536 dimensions with passage review. Both section arms use text-embedding-3-small, the unchanged title/summary 512-dimensional branch, the same fusion and tie rules, 100 unique candidate posts, best-passage selection algorithm, passage-aware reviewer instructions, gpt-5.4-mini low reasoning, 800 output tokens, relevance tiers and fallback.',
           'Only section embedding dimensionality changes between the two section arms. The changed vectors can change candidate order, which passage is selected, and which posts reach final review. Ask AI 2 remains a separate production reference with its original title/summary-only reviewer. Comparisons with that reference therefore also change retrieval evidence and reviewer instructions.',
           'The 1,536-dimensional arm makes two query-embedding requests: 512 dimensions for the unchanged title/summary branch and 1,536 dimensions for the section branch. The controls make one 512-dimensional query request. All paid phases are included in cost and full HTTP timing. Existing vectors were retained; no production data was imported or overwritten.',
           'An offline prefix check found close, but not bitwise-identical, agreement between the saved 512-dimensional vectors and normalized first 512 components of the new vectors: mean cosine 0.9999919, minimum 0.9961691; six of 2,636 pairs were below 0.999. This compares saved and newly generated API configurations, not exact truncations of one generation. Minor generation/numeric variation is a limitation; its cause was not established. See prefix-check.json.',
           'These questions were reused from earlier pilots. This is a controlled diagnostic, not fresh-question confirmation or representative reader traffic.','',
           '## Results','', '| Measure | Ask AI 2 | Sections: 512 | Sections: 1,536 |','|---|---:|---:|---:|']
    fields=[('Top-five relevance (P@5)','precisionAt5','.1%',1),('Top-ten relevance (P@10)','precisionAt10','.1%',1),
            ('Ranking score (nDCG@10 x 100)','ndcgAt10','.1f',100),('Relevant posts in top ten','relevantTop10','.2f',1),
            ('Posts displayed in top ten','returnedTop10','.2f',1),('Relevance among displayed top ten','displayedPrecisionAt10','.1%',1),
            ('Mean full HTTP time (seconds)','seconds','.2f',1),('Median HTTP time (seconds)','medianSeconds','.2f',1),
            ('95th-percentile HTTP time (seconds)','p95Seconds','.2f',1),('Actual cost (cents/question)','costUsd','.3f',100),
            ('Cost without prompt-cache discounts (cents/question)','uncachedCostUsd','.3f',100),
            ('Query embeddings (cents/question)','embeddingCostUsd','.6f',100),('Review input tokens','inputTokens','.0f',1),
            ('Provider-cached input tokens','cachedTokens','.0f',1),('Broader fallback','fallback','.1%',1),
            ('Candidate top-ten relevance','candidatePrecisionAt10','.1%',1),
            ('Known relevant pool coverage in 100 candidates','knownRelevantCoverageAt100','.1%',1)]
    for label,key,fmt,mult in fields:
        lines.append('| '+label+' | '+' | '.join(format(s[a][key]*mult,fmt) for a in c.ARMS)+' |')
    lines += ['','P@5/P@10 use fixed denominators; missing slots count as nonrelevant. Grades 2 and 3 are relevant. nDCG weights higher relevance and earlier placement using a shared judged pool. Scores and candidate coverage are not exhaustive corpus-wide recall and are not comparable directly with full-blog benchmarks.','',
              '## Pairwise Results','', '| Comparison | First wins | Second wins | Ties | Mean ranking change (second minus first) | Paired 95% interval |','|---|---:|---:|---:|---:|---|']
    for p in d['pairwise']:
        interval=p['ndcgDifference']
        lines.append(f"| {c.LABELS[p['first']]} vs {c.LABELS[p['second']]} | {p['firstWins']} | {p['secondWins']} | {p['ties']} | {interval['mean']*100:+.2f} | [{interval['lower95']*100:+.2f}, {interval['upper95']*100:+.2f}] |")
    lines += ['','Tie threshold: absolute nDCG difference no greater than 0.03. Paired bootstrap intervals describe sampling across these questions, not AI grading error or repeat-run variability. The primary planned comparison is 512- versus 1,536-dimensional sections; other pairwise comparisons are exploratory.','',
              '## Assessment','']
    if ci['lower95']<=0<=ci['upper95']:
        lines.append('The larger section vectors did not demonstrate a clear ranking advantage: the primary paired interval includes zero. Do not adopt 1,536 dimensions across the full blog on this pilot alone.')
    elif ci['mean']>0:
        lines.append('The larger section vectors improved average ranking in this pilot, with a positive paired interval. This supports fresh-question and human confirmation before any production conversion, not an immediate replacement.')
    else:
        lines.append('The 512-dimensional section arm had the better average ranking and the primary interval favors it. Do not expand the production index to 1,536 dimensions on this evidence.')
    overlap=d['candidateOverlap']
    lines += [f"The section arms shared an average of {statistics.mean(r['sharedCandidates'] for r in overlap):.1f} of 100 candidates. Among shared candidates, an average of {statistics.mean(r['differentBestPassages'] for r in overlap):.1f} selected passages differed per question.",
              'Neither section arm demonstrated a clear ranking advantage over the Ask AI 2 reference in this run: both exploratory paired intervals include zero. This is weaker evidence than the earlier section pilot, which reinforces the need to measure repeat-run variability and use fresh questions rather than assume that one pilot establishes a reliable improvement.',
              'Changing vector size does not remove the cost of reviewing passage text. Any selective-passage or reviewer-prompt experiment should be separate so its effects can be distinguished.','',
              '## Grading','',
              f"The common pool contains {d['gradedPairs']} question/post judgments: {d['gradedPairs']-d['newGrades']} retained prior judgments and {d['newGrades']} new judgments. It includes all retained prior judgments plus final top-ten and candidate top-ten posts from all three arms. New pairs were graded from full stored posts, with neutral IDs and no method labels, ranks, summaries or tags. Grades: 0 irrelevant, 1 marginal, 2 substantial support, 3 directly and substantially answering. Evidence quotations are source-verified. AI judgments are not a human gold standard.",
              f"There were {len(d['adjudications']['checks'])} additional coordinating-agent full-post checks and {len(d['adjudications']['overrides'])} current numerical corrections. Original grades and receipts remain unchanged. Earlier documented corrections are inherited; latest metadata-review judgments take precedence for overlapping pairs. All arms use identical grades per question/post. See adjudications.json for selection and blinding.",
              'The eight checks covered newly surfaced pairs chosen by SHA-256 order with distinct questions, before reading API grades or aggregate scores. Three grades changed; these remain judgment calls rather than a human reference standard. Before those corrections, the primary comparison was also 21 wins, 19 losses and 10 ties for 1,536 dimensions, with a +0.74-point ranking difference and an interval spanning zero. The conclusion is unchanged.',
              'One new quotation exceeded 25 words after three automated validation attempts. The coordinator read all three posts in that batch and shortened the last-attempt quotation to an exact contiguous phrase; no numerical grades or reasons changed. All three receipts are retained and included in cost. See evidence-repairs.json and repair_evidence.py.',
              'Older retained grades lacked individual textHash fields. The original full corpus hash was verified against the frozen earlier manifest before binding its existing per-post hashes; no old grade changed. The initial and amended grading-code manifests document that compatibility fix and stronger receipt/ranking audits.',
              'The 500-post sample includes deliberately difficult cases and one known stored opening excerpt (post 2359). The reviewer cannot return posts outside its candidates, and pooled labels do not establish that no relevant unjudged posts exist.','',
              '## Cost and Timing','',
              'Timing covers HTTP submission through complete JSON receipt, including fixture validation, query embeddings, retrieval and final AI review. It excludes login and browser painting. The isolated harness validates both indexes for every arm, adding common preflight overhead; compare within this run, not directly with earlier benchmark timings. The 1,536-dimensional arm also has an additional query-embedding round trip. There is no WordPress result cache; provider prompt-cache discounts may differ and are shown separately.',
              f"Accepted 150 searches: ${d['costs']['acceptedSearchUsd']:.4f}. All saved search phases: ${d['costs']['allSearchAttemptsUsd']:.4f}, across {d['costs']['paidSearchPhases']} paid phases. New grading: ${d['costs']['gradingUsd']:.4f} across {d['costs']['gradingCalls']} calls. New section-index embeddings: ${d['costs']['sectionEmbeddingUsd']:.6f}. Total new API cost: ${d['costs']['totalNewUsd']:.4f}. Earlier experiments and reused judgments are not charged again or included.",
              f"The production parser discarded invalid selections in {len(d['responseWarnings'])} responses, totaling {sum(len(v) for v in d['responseWarnings'].values())} discarded selections. Raw outputs were retained, not retried for more favorable answers.",
              'Rates checked October 17, 2026: embeddings $0.02/million input; reviewer/grader $0.75/million input, $0.075/million cached input, $4.50/million output. Saved usage supports these estimates; invoices are authoritative. [Embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small); [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).','',
              '## Storage','',
              'The 2,636 new float32 vectors occupy 16,195,584 bytes (15.45 MiB), versus 5,398,528 bytes (5.15 MiB) for 512 dimensions. The separate compressed JSONL package includes section text and metadata and is 13,053,816 bytes. These are experimental files, not database growth. Keeping both indexes requires storage for both.','',
              '## Individual Questions','', '| # | Question | Ask AI 2 | Sections: 512 | Sections: 1,536 |','|---|---|---:|---:|---:|']
    for q in d['questions']:
        lines.append(f"| {q['id']} | {q['question']} | "+' | '.join(f"{q['versions'][a]['metrics']['ndcgAt10']*100:.1f}" for a in c.ARMS)+' |')
    lines += ['','## Verification','',
              'Local production regression passed, including syntax checks on 77 PHP files, canonical import counts, semantic coverage, ingestion/analytics regressions, public-page responses, private-file protection, search modes and pagination. The experiment helper passed 1,466 mocked WordPress checks (32 mocked HTTP calls), PHPCS WordPress-Core and PHPStan level 5. Offline Python suites passed: 21 dimension tests, 20 section tests, 29 summary tests and 15 metadata-review tests.',
              'The final audit reconciled all 150 saved search results with real provider receipts, replayed relevance-tier ordering and fallback, and checked every new grade against its receipt. The temporary dimension helper was deactivated after the measured searches. See validation.json.','',
              '## Preservation','',f"All frozen earlier inputs, results and source hashes remain unchanged. All {d['discoveryTablesUnchanged']} discovery tables retain identical counts and content fingerprints. Production plugin code, canonical JSON, summaries, title/summary vectors and existing section vectors are unchanged. No commit, push, deployment, ingestion change or download-skill change is included. Full text and raw provider evidence remain in the ignored private directory, excluded from Render packaging.",'']
    (c.PUBLIC/'report.md').write_text('\n'.join(lines),encoding='utf-8')
    print(c.PUBLIC/'report.md')


if __name__=='__main__':
    render()
