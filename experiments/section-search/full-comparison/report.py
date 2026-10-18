"""Reproducible per-batch and cumulative paired metrics; never mixes old grades."""
import collections
import json
import statistics as stats
import common as c
from grade import check


def percentile(values,p):
    ordered=sorted(values)
    index=(len(ordered)-1)*p
    left=int(index)
    return ordered[left]+(ordered[min(left+1,len(ordered)-1)]-ordered[left])*(index-left)


def summarize(rows):
    result={'questions':len(rows),'wins':dict(collections.Counter(r['winner'] for r in rows)),'arms':{}}
    for arm in c.ARMS:
        values=[r['metrics'][arm] for r in rows]
        seconds=[v['seconds'] for v in values if v['seconds'] is not None]
        result['arms'][arm]={key:stats.mean(v[key] for v in values) for key in (
            'precisionAt5','precisionAt10','displayedPrecisionAt10','ndcgAt10','costUsd','relevantTop10','returnedTop10',
            'embeddingSeconds','retrievalSeconds','reviewSeconds','cachedTokenFraction','uniqueRelevantTop10')}
        result['arms'][arm].update({'meanSeconds':stats.mean(seconds) if seconds else None,
            'medianSeconds':stats.median(seconds) if seconds else None,'p95Seconds':percentile(seconds,.95) if seconds else None,
            'timedQuestions':len(seconds),'fallbacks':sum(v['fallback'] for v in values),
            'zeroRelevantTop10':sum(v['relevantTop10']==0 for v in values),'totalCostUsd':sum(v['costUsd'] for v in values)})
    result['pairedNdcgDifferenceAI3MinusAI2']=c.confidence_interval([r['deltaNdcg'] for r in rows])
    result['meanCandidateOverlap']=stats.mean(r['candidateOverlap'] for r in rows)
    result['meanTop10Overlap']=stats.mean(r['top10Overlap'] for r in rows)
    return result


def main():
    manifest=c.integrity()
    corpus=c.read(c.WORK/'corpus.json')
    questions=c.read(c.PUBLIC/'questions.json')['questions']
    rows=[]
    receipt_rows=[]
    ledger_path=c.WORK/'server-requests.jsonl'
    if ledger_path.exists():
        receipt_rows=[json.loads(line) for line in ledger_path.read_text(encoding='utf-8-sig').splitlines() if line.strip()]
    by_request=collections.defaultdict(list)
    for receipt in receipt_rows:
        if receipt.get('type')=='end-to-end-api-response': by_request[receipt['requestId']].append(receipt)
    for question in questions:
        grade_path=c.WORK/'grades'/f"q{question['id']:03}.json"
        if not grade_path.exists(): continue
        judged=c.read(grade_path)['grades']
        grades={g['wpId']:g['grade'] for g in judged}
        for grade in judged: check(grade,corpus[grade['wpId']]['text'])
        results={arm:c.read(c.WORK/'results'/f"q{question['id']:03}-{arm}.json") for arm in c.ARMS}
        pool={p['wpId'] for r in results.values() for p in r['posts'][:10]}
        if set(grades)!=pool or len(judged)!=len(pool): raise ValueError('Pooled grade mismatch')
        metrics={}
        for arm,r in results.items():
            c.validate(r,question,arm,manifest)
            if ledger_path.exists():
                receipts=by_request[r['requestId']]
                if [x['endpoint'] for x in receipts]!=['embeddings','responses'] or [x['usage'] for x in receipts]!=r['usage']:
                    raise ValueError('Raw provider receipt mismatch')
            for p in r['candidates']:
                original=corpus[p['wpId']]
                if p['title']!=original['title'] or p['summary']!=original['searchSummary']:
                    raise ValueError('Candidate title/summary differs from frozen full text corpus')
                if p['passage'] and ' '.join(p['passage'].split()) not in ' '.join(original['text'].split()):
                    raise ValueError('Passage does not belong to full post')
            other=results[c.ARMS[1] if arm==c.ARMS[0] else c.ARMS[0]]
            other_ids={p['wpId'] for p in other['posts'][:10]}
            review=r['usage'][1]
            metrics[arm]={**c.metrics(r,grades),**{key:r[key] for key in ('embeddingSeconds','retrievalSeconds','reviewSeconds')},
                'cachedTokenFraction':review['cached_input_tokens']/review['input_tokens'],
                'uniqueRelevantTop10':sum(grades[p['wpId']]>=2 and p['wpId'] not in other_ids for p in r['posts'][:10])}
        delta=metrics['ask-ai-3']['ndcgAt10']-metrics['ask-ai-2']['ndcgAt10']
        winner='tie' if abs(delta)<=manifest['tieThresholdNdcg'] else ('ask-ai-3' if delta>0 else 'ask-ai-2')
        sets=lambda key:[{p['wpId'] for p in results[arm][key][:100 if key=='candidates' else 10]} for arm in c.ARMS]
        candidates=sets('candidates'); final=sets('posts')
        rows.append({**question,'winner':winner,'deltaNdcg':delta,'metrics':metrics,
                     'candidateOverlap':len(candidates[0]&candidates[1]),'top10Overlap':len(final[0]&final[1]),
                     'rankedPosts':{arm:[{'wpId':p['wpId'],'title':p['title'],'url':p['url'],'grade':grades[p['wpId']]} for p in results[arm]['posts'][:10]] for arm in c.ARMS}})
    if not rows: raise ValueError('No completed graded pairs')
    summaries={}
    for batch in range(1,6):
        chosen=[r for r in rows if r['batch']==batch]
        if chosen: summaries[f'batch-{batch}']=summarize(chosen)
    for group in ('original-200','challenge-50'):
        chosen=[r for r in rows if r['group']==group]
        if chosen: summaries[group]=summarize(chosen)
    grading=sum(c.usage_cost(c.read(p)['response'].get('usage',{})) for p in (c.WORK/'grade-receipts').glob('*.json'))
    audit={'rawSearchReceipts':sum(len(x) for x in by_request.values()),'paidSearchCostUsd':sum(c.phase_cost(x['usage']) for v in by_request.values() for x in v),
           'gradingCostUsd':grading,'gradedQuestionPostPairs':sum(len(c.read(p)['grades']) for p in (c.WORK/'grades').glob('q*.json'))}
    output={'run':c.RUN,'completedQuestions':len(rows),'summaries':summaries,'audit':audit,'questions':rows}
    c.save(c.PUBLIC/'results.json',output,frozen=False)
    lines=['# Ask AI 2 versus Ask AI 3: full-corpus comparison','',f'Run: `{c.RUN}`. Completed: {len(rows)}/250 question pairs.','',
        'Both methods searched the same 3,986 posts. Ask AI 2 used title/summary hybrid retrieval; Ask AI 3 added 300-word section retrieval and a matching passage for candidate review. Both used 100 candidates, small/512 embeddings and the same pinned reviewer model. This is a complete-method comparison, not an isolated embedding test.','',
        'The original 200 questions and 50 new diagnostic questions are reported separately. New questions were frozen before either search ran; they are specific challenges, not a representative sample of reader traffic.','']
    for name,s in summaries.items():
        a=s['arms']['ask-ai-2']; b=s['arms']['ask-ai-3']
        lines += [f'## {name} ({s["questions"]} questions)','',f'Wins: Ask AI 2 **{s["wins"].get("ask-ai-2",0)}**, Ask AI 3 **{s["wins"].get("ask-ai-3",0)}**, ties **{s["wins"].get("tie",0)}**.','',
            '| Measurement | Ask AI 2 | Ask AI 3 |','|---|---:|---:|',
            f'| Top 5 relevance | {a["precisionAt5"]:.1%} | {b["precisionAt5"]:.1%} |',
            f'| Top 10 relevance | {a["precisionAt10"]:.1%} | {b["precisionAt10"]:.1%} |',
            f'| Ranking score (nDCG@10) | {a["ndcgAt10"]:.1%} | {b["ndcgAt10"]:.1%} |',
            f'| Average submit-to-display | {a["meanSeconds"]:.2f}s | {b["meanSeconds"]:.2f}s |',
            f'| Median / 95th percentile | {a["medianSeconds"]:.2f}s / {a["p95Seconds"]:.2f}s | {b["medianSeconds"]:.2f}s / {b["p95Seconds"]:.2f}s |',
            f'| Average cost per question | ${a["costUsd"]:.5f} ({a["costUsd"]*100:.3f} cents) | ${b["costUsd"]:.5f} ({b["costUsd"]*100:.3f} cents) |',
            f'| Unique relevant top-10 posts/question | {a["uniqueRelevantTop10"]:.2f} | {b["uniqueRelevantTop10"]:.2f} |',
            f'| No relevant posts in top 10 | {a["zeroRelevantTop10"]} | {b["zeroRelevantTop10"]} |','']
        ci=s['pairedNdcgDifferenceAI3MinusAI2']
        lines += [f'Paired ranking difference (AI 3 minus AI 2): {ci["mean"]*100:.2f} percentage points; bootstrap 95% interval {ci["lower95"]*100:.2f} to {ci["upper95"]*100:.2f}.','']
    lines += ['## How to interpret these results','',
        '- Fresh AI grades read the complete posts, without method labels or ranking positions. The union of the two final top tens is deduplicated and each question/post pair graded once; evidence quotations are checked against source text. AI grading is not a substitute for human review.',
        '- Grades: 3 directly answers, 2 substantial useful support, 1 marginal, 0 irrelevant. Grades 2 and 3 count as relevant. Precision divides by 5/10, including empty slots; earlier historical reports used returned-result denominators. Do not directly compare their percentages.',
        '- Wins use nDCG@10; absolute differences of 0.03 or less are ties. The ideal ranking is based on the judged pooled results, not all relevant posts in the corpus. This is not corpus-wide recall.',
        '- Browser timing starts at form submission and ends after results render and two animation frames, using an identical renderer for both arms. It excludes login/page loading. Backend phase timings are saved separately. Experimental file loading and integrity checks contribute to latency; these are not production-capacity benchmarks.',
        '- WordPress query/result caches were bypassed. Provider prompt-cache tokens were counted at their discounted rate. Costs include embedding plus candidate review, not initial index construction, hosting, or separate evaluation grading.',
        '- The confidence interval resamples paired questions; it does not quantify grader error or all model nondeterminism. No tuning occurred between batches.',
        f'- Separate full-text grading cost so far: ${grading:.4f}. Raw provider receipts are private; no API keys or full post text are in this report.','',
        '[Question-by-question results](results.json) | [Frozen questions](questions.json) | [Run protocol](manifest.json)','']
    (c.PUBLIC/'report.md').write_text('\n'.join(lines),encoding='utf-8')
    print(json.dumps({'completed':len(rows),'summaries':{k:{'wins':v['wins'],'questions':v['questions']} for k,v in summaries.items()},'audit':audit}))


if __name__=='__main__': main()
