"""Read-only verification of provider receipts, reviewer selections and preserved inputs."""
import collections
import json
import math
import common as c


def selected_from(body, candidates):
    text=''.join(p.get('text','') for b in body['output'] for p in b.get('content',[]) if p.get('type')=='output_text')
    selected=json.loads(text)['selected_posts']
    allowed={str(p['id']) for p in candidates}
    tiers={t:[] for t in ('direct','related','background')}
    seen=set()
    rejected=[]
    for row in selected:
        post=str(row.get('id',''))
        tier=row.get('relevance_tier','')
        if post not in allowed or post in seen or tier not in tiers:
            rejected.append({**row,'reason':'unknown candidate' if post not in allowed else ('duplicate' if post in seen else 'invalid tier')})
            continue
        seen.add(post)
        tiers[tier].append(post)
    ids=[post for group in tiers.values() for post in group][:25]
    return ids,rejected


def main():
    manifest=c.integrity()
    questions=c.read(c.PUBLIC/'questions.json')['questions']
    ledger=[json.loads(line) for line in (c.WORK/'server-requests.jsonl').read_text(encoding='utf-8-sig').splitlines() if line.strip()]
    receipts=collections.defaultdict(list)
    for row in ledger:
        if row.get('type')=='end-to-end-api-response': receipts[row['requestId']].append(row)
    checked=0
    warnings=[]
    settings=set()
    prompts=collections.defaultdict(set)
    requests=set()
    for file in sorted((c.WORK/'results').glob('q*-ask-ai-*.json')):
        r=c.read(file)
        c.validate(r,questions[r['questionId']-1],r['arm'],manifest)
        if r['requestId'] in requests: raise ValueError('Duplicated request ID')
        requests.add(r['requestId'])
        raw=receipts[r['requestId']]
        if [p['endpoint'] for p in raw]!=['embeddings','responses'] or [p['usage'] for p in raw]!=r['usage']:
            raise ValueError('Missing/changed paid-phase receipts')
        vector=raw[0]['response']['data'][0]['embedding']
        if len(vector)!=512: raise ValueError('Wrong query-vector dimensions')
        body=raw[1]['response']
        if body['status']!='completed' or body['model']!=c.MODEL: raise ValueError('Incomplete review')
        ids,rejected=selected_from(body,r['candidates'])
        if not ids:
            ids=[str(p['id']) for p in r['candidates'][:25]]
            if not r['broaderFallback']: raise ValueError('Unmarked fallback')
        elif r['broaderFallback']: raise ValueError('Spurious fallback')
        if ids!=[str(p['id']) for p in r['posts']]: raise ValueError('Final selection does not match paid review')
        if rejected: warnings.append({'questionId':r['questionId'],'arm':r['arm'],'rejectedReviewerRows':rejected})
        settings.add(r['settingsHash'])
        prompts[r['arm']].add(r['promptHash'])
        checked+=1
    if len(settings)!=1 or any(len(p)!=1 for p in prompts.values()): raise ValueError('Prompt/settings changed mid-run')
    failures_path=c.PUBLIC/'search-failures.json'
    failure_data=c.read(failures_path) if failures_path.exists() else {'failures':[],'qualityExclusions':[]}
    failures=failure_data['failures']
    exclusions=failure_data.get('qualityExclusions',[])
    if set(receipts)-requests!={f['requestId'] for f in failures}:
        raise ValueError('Unaccounted paid attempt, or missing failed-attempt receipt')
    failure_cost=collections.defaultdict(float)
    for failure in failures:
        raw=receipts[failure['requestId']]
        if [p['endpoint'] for p in raw]!=['embeddings','responses']:
            raise ValueError('Failed attempt is missing phase receipts')
        body=raw[1]['response']
        if body.get('status')!='incomplete' or body.get('incomplete_details',{}).get('reason')!=failure['reason']:
            raise ValueError('Failed-attempt diagnosis does not match provider response')
        cost=sum(c.phase_cost(p['usage']) for p in raw)
        if not math.isclose(cost,failure['costUsd'],abs_tol=1e-10): raise ValueError('Failed-attempt cost mismatch')
        failure_cost[failure['arm']]+=cost
    for exclusion in exclusions:
        number=exclusion['questionId']
        arm=exclusion['arm']
        if (c.WORK/'results'/f'q{number:03}-{arm}.json').exists():
            raise ValueError('Excluded search has a completed result')
        attempts=[f['attempt'] for f in failures if f['questionId']==number and f['arm']==arm]
        if sorted(attempts)!=[1,2]: raise ValueError('Expected original attempt and one failed retry')
    original=c.read(c.SECTION/'runs/remaining-300-512-2026-10-18/manifest.json')['preservedFiles']
    for name,expected in original.items():
        if c.digest(c.ROOT/name)!=expected: raise ValueError('Historical file changed: '+name)
    before=c.read(c.WORK/'database-before.json')
    after_path=c.WORK/'database-after.json'
    database_unchanged=None
    if after_path.exists():
        after=c.read(after_path)
        discovery=lambda snapshot:{name:table for name,table in snapshot['tables'].items() if name.startswith('wp_ehrman_')}
        database_unchanged=discovery(before)==discovery(after)
        if not database_unchanged: raise ValueError('Discovery table fingerprint changed')
    output={'verifiedSearches':checked,'apiReceipts':sum(len(v) for v in receipts.values()),
            'historicalFilesUnchanged':len(original),'frozenInputsUnchanged':len(manifest['preserved']),
            'databaseUnchanged':database_unchanged,'reviewerOutputWarnings':warnings,
            'failedSearchAttempts':len(failures),'failedSearchCostByArmUsd':dict(failure_cost),
            'failedReviewUsageAnomalies':[f['requestId'] for f in failures if f.get('reviewUsageAnomaly')],
            'uncompletedQuestionArms':exclusions,
            'providerCostUsd':sum(c.phase_cost(p['usage']) for rows in receipts.values() for p in rows),
            'completedSearchCostUsd':sum(c.read(p)['costUsd'] for p in (c.WORK/'results').glob('q*-ask-ai-*.json'))}
    if not math.isclose(output['providerCostUsd'],output['completedSearchCostUsd']+sum(failure_cost.values()),abs_tol=1e-8):
        raise ValueError('Search spending ledger does not balance')
    c.save(c.PUBLIC/'audit.json',output,frozen=False)
    print(json.dumps(output))


if __name__=='__main__': main()
