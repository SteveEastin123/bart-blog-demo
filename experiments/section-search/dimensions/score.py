"""Blind full-post grading, receipt audit and common-pool comparison metrics."""

import argparse
import copy
import itertools
import json
import math
import re
import statistics

import common as c


def runs(question):
    return {a: c.read(c.WORK / 'searches' / f"q{question['id']:02}-{a}.json") for a in c.ARMS}


def pool_ids(prior, searches):
    return {g['wpId'] for g in prior['grades']} | {p['wpId'] for r in searches.values() for p in r['posts'][:10]+r['candidates'][:10]}


def retained_grade(row, post):
    if row.get('textHash', post['textHash']) != post['textHash']:
        raise ValueError('Retained grade source changed')
    c.grading.check_grade(row, post['text'])
    return {**row, 'textHash': post['textHash'], 'origin': 'verified_prior_full_post_grade'}


def grade(limit):
    import compare
    m = c.integrity()
    if c.configuration()[1] != m['graderModel'] or c.sha(c.GRADE_PROMPT) != m['gradePromptHash']:
        raise ValueError('Grading configuration changed')
    corpus = {p['wpId']: p for p in c.authoring.source()['posts']}
    legacy = c.read(c.SECTION / 'runs/end-to-end-300-2026-10-17/manifest.json')
    source_path = 'experiments/section-search/private/corpus.json'
    if c.digest(c.ROOT / source_path) != legacy['preserved'][source_path]:
        raise ValueError('Legacy full-post grading corpus changed')
    passages = {wp: set() for wp in corpus}
    for section in c.sections():
        passages[section['wpId']].add(section['text'])
    previous = {q['id']: q for q in c.read(c.WORK / 'prior-results.json')['questions']}
    schema = c.object_schema({'grades': {'type': 'array', 'items': c.object_schema({
        'id': {'type': 'string'}, 'grade': {'type': 'integer', 'enum': [0,1,2,3]},
        'reason': {'type': 'string'}, 'evidence': {'type': 'string'}})}})
    for q in c.read(c.PUBLIC / 'questions.json')['questions'][:limit]:
        prior = previous[q['id']]
        if q['question'] != prior['question']:
            raise ValueError('Prior question changed')
        searches = runs(q)
        for arm, result in searches.items():
            compare.validate(result, q, arm, m, corpus, passages)
        ids = pool_ids(prior, searches)
        path = c.WORK / 'grades' / f"q{q['id']:02}.json"
        if path.exists():
            c.grading.validate_grades(c.read(path), q, ids, corpus)
            continue
        known = {g['wpId']: g for g in prior['grades']}
        judged = [retained_grade(g, corpus[g['wpId']]) for g in known.values()]
        pending = sorted(ids-known.keys(), key=lambda wp:c.sha(c.RUN+str(q['id'])+wp))
        for start in range(0, len(pending), 4):
            labels = {f'P{i+1}': wp for i,wp in enumerate(pending[start:start+4])}
            posts = [{'id': label, 'title': corpus[wp]['title'], 'text': corpus[wp]['text']} for label,wp in labels.items()]
            for attempt in range(3):
                prompt = c.GRADE_PROMPT + (' Recheck that every evidence phrase is copied exactly.' * attempt)
                output, receipt = c.json_response(prompt, {'question':q['question'], 'posts':posts}, schema,
                                                 'blind-dimension-grading', effort='medium', cache_root=c.WORK/'grading-cache')
                if sorted(g['id'] for g in output['grades']) != sorted(labels):
                    raise ValueError('Missing or duplicate grading labels')
                batch = []
                try:
                    for g in output['grades']:
                        wp = labels[g['id']]
                        c.grading.check_grade(g, corpus[wp]['text'], strict=True)
                        batch.append({**g, 'wpId':wp, 'textHash':corpus[wp]['textHash'], 'evidenceVerified':True,
                                      'origin':'new_blinded_full_post_grade', 'model':receipt['model'], 'requestHash':receipt['requestHash']})
                except ValueError:
                    if attempt == 2:
                        raise
                    continue
                judged.extend(batch)
                break
        result = {**q, 'poolHash': c.grading.pool_identity(q, ids), 'grades':judged}
        c.grading.validate_grades(result,q,ids,corpus)
        c.save(path,result,frozen=True)
        print(json.dumps({'gradedQuestion':q['id'],'reused':len(known),'new':len(pending)}),flush=True)
    c.integrity()


def pairwise(rows, first, second, key='metrics'):
    differences = [q['versions'][second][key]['ndcgAt10']-q['versions'][first][key]['ndcgAt10'] for q in rows]
    return {'first':first,'second':second,'firstWins':sum(d < -.03 for d in differences),
            'secondWins':sum(d > .03 for d in differences),'ties':sum(abs(d)<=.03 for d in differences),
            'ndcgDifference':c.confidence_interval(differences)}


def replay_selection(run, selected):
    allowed = {p['id']: p for p in run['candidates']}
    tiers, notes = {}, []
    buckets = {tier: [] for tier in ('direct', 'related', 'background')}
    for row in selected:
        identity, tier = row['id'], row['relevance_tier']
        if not isinstance(identity, str) or not isinstance(tier, str) or not re.fullmatch(r'[A-Za-z0-9_-]+', identity):
            raise ValueError('Noncanonical reviewer identifier requires WordPress parser inspection')
        tier = re.sub(r'[^a-z0-9_-]', '', tier.lower())
        if identity not in allowed:
            notes.append('unknown_id')
        elif tier not in buckets:
            notes.append('unknown_tier')
        elif identity in tiers:
            notes.append('duplicate_id')
        else:
            tiers[identity] = tier
            buckets[tier].append(identity)
    chosen = [identity for bucket in buckets.values() for identity in bucket][:25]
    expected_tiers = {identity: tiers[identity] for identity in chosen}
    fallback = not chosen
    expected = [allowed[identity] for identity in chosen] if chosen else run['candidates'][:25]
    if run['posts'] != expected or run['broaderFallback'] != fallback or (run['tiers'] or {}) != expected_tiers:
        raise ValueError('Saved results, tier order or fallback differ from raw reviewer output')
    return notes


def grading_receipts(rows):
    receipts = {}
    for path in (c.WORK / 'grading-cache').glob('*.json'):
        row = c.read(path)
        response = row['response']
        usage = response['usage']
        inputs, outputs = usage['input_tokens'], usage['output_tokens']
        cached = usage['input_tokens_details']['cached_tokens']
        if (path.stem != row['requestHash'] or row['label'] != 'blind-dimension-grading'
                or row['model'] != 'gpt-5.4-mini' or not response['model'].startswith(row['model'])
                or not response['id'].startswith('resp_') or inputs <= 0 or outputs < 0
                or not 0 <= cached <= inputs):
            raise ValueError('Invalid real grading receipt or usage')
        receipts[row['requestHash']] = row
    for question in rows:
        for g in question['grades']:
            if g['origin'] != 'new_blinded_full_post_grade':
                continue
            original = g.get('beforeDimensionAdjudication', g)
            receipt = receipts[original['requestHash']]
            matches = [r for r in c.json_output(receipt)['grades'] if r['id'] == original['id']]
            if len(matches) != 1 or any(matches[0][k] != original[k] for k in ('grade', 'reason', 'evidence')):
                raise ValueError('New grade differs from its saved provider receipt')
    return list(receipts.values())


def audit(accepted, rows):
    events = [json.loads(line) for line in (c.WORK/'server-requests.jsonl').read_text(encoding='utf-8').splitlines()]
    starts = {r['requestId']:r for r in events if r.get('type')=='dimension-comparison-start' and r['run']==c.RUN and not r['test']}
    paid = [r for r in events if r.get('type')=='end-to-end-api-response' and r['requestId'] in starts]
    if len(accepted)!=150 or not set(accepted)<=set(starts):
        raise ValueError('Require150 distinct accepted search audit trails')
    warnings = {}
    for rid, run in accepted.items():
        if any(starts[rid][k] != run[k] for k in ('run', 'questionId', 'arm', 'test')):
            raise ValueError('Search audit identity differs from accepted result')
        records = [r for r in paid if r['requestId']==rid]
        expected_dims = [512,1536] if run['arm']=='sections-1536' else [512]
        if [r['endpoint'] for r in records] != ['embeddings']*len(expected_dims)+['responses']:
            raise ValueError('Wrong billed phase sequence')
        for record,u in zip(records,run['usage']):
            if record['usage']!=u:
                raise ValueError('Receipt usage differs from result')
        for record,dimensions in zip(records,expected_dims):
            if len(record['response']['data'][0]['embedding'])!=dimensions:
                raise ValueError('Wrong query dimension in billed response')
        review=records[-1]['response']
        if not review.get('id','').startswith('resp_') or review['status']!='completed':
            raise ValueError('Not a completed real review')
        selected=c.json_output({'response':review})['selected_posts']
        notes = replay_selection(run, selected)
        if notes:
            warnings[f"q{run['questionId']:02}-{run['arm']}"]=notes
    grade_receipts=grading_receipts(rows)
    embedding=c.read(c.PUBLIC/'embedding-results.json')['embeddingCostUsd']
    search=sum(c.previous.phase_cost(r['usage']) for r in paid)
    grading=sum(c.usage_cost(r['response']['usage']) for r in grade_receipts)
    return {'acceptedSearchUsd':sum(r['costUsd'] for r in accepted.values()),'allSearchAttemptsUsd':search,
            'paidSearchPhases':len(paid),'gradingUsd':grading,'gradingCalls':len(grade_receipts),
            'sectionEmbeddingUsd':embedding,'totalNewUsd':search+grading+embedding},warnings


def report():
    import compare
    manifest=c.integrity()
    corpus={p['wpId']:p for p in c.authoring.source()['posts']}
    passages={wp:set() for wp in corpus}
    for s in c.sections():
        passages[s['wpId']].add(s['text'])
    previous={q['id']:q for q in c.read(c.WORK/'prior-results.json')['questions']}
    changes_path=c.PUBLIC/'adjudications.json'
    changes=c.read(changes_path) if changes_path.exists() else {'checks':[],'overrides':[]}
    corrections={(g['questionId'],g['wpId']):g for g in changes['overrides']}
    applied=set()
    rows,accepted=[],{}
    overlaps=[]
    for q in c.read(c.PUBLIC/'questions.json')['questions']:
        searches=runs(q)
        ids=pool_ids(previous[q['id']],searches)
        judged=c.read(c.WORK/'grades'/f"q{q['id']:02}.json")
        c.grading.validate_grades(judged,q,ids,corpus)
        before={g['wpId']:g['grade'] for g in judged['grades']}
        for g in judged['grades']:
            identity=(q['id'],g['wpId'])
            if identity in corrections:
                correction=corrections[identity]
                if g['grade']!=correction['originalGrade']:
                    raise ValueError('Wrong original adjudication grade')
                g['beforeDimensionAdjudication']=copy.deepcopy(g)
                g.update({k:correction[k] for k in ('grade','reason','evidence')})
                c.grading.check_grade(g,corpus[g['wpId']]['text'],strict=True)
                applied.add(identity)
        grades={g['wpId']:g['grade'] for g in judged['grades']}
        relevant={wp for wp,g in grades.items() if g>=2}
        versions={}
        for arm,run in searches.items():
            compare.validate(run,q,arm,manifest,corpus,passages)
            if run['requestId'] in accepted:
                raise ValueError('Duplicate request ID')
            accepted[run['requestId']]=run
            values=c.metrics(run,grades)
            candidates=[p['wpId'] for p in run['candidates']]
            u=run['usage'][-1]
            embedding=sum(p['estimated_cost_usd'] for p in run['usage'][:-1])
            values.update({'candidatePrecisionAt10':sum(grades[wp]>=2 for wp in candidates[:10])/10,
                           'knownRelevantCoverageAt100':len(relevant&set(candidates))/len(relevant) if relevant else 0,
                           'embeddingCostUsd':embedding,'reviewCostUsd':u['estimated_cost_usd'],
                           'inputTokens':u['input_tokens'],'cachedTokens':u['cached_input_tokens'],
                           'uncachedCostUsd':embedding+(u['input_tokens']*.75+u['output_tokens']*4.5)/1e6})
            versions[arm]={'metrics':values,'beforeAdjudication':c.metrics(run,before),'candidateWpIds':candidates,
                           'posts':[{'rank':i+1,'wpId':p['wpId'],'title':p['title'],'url':p['url'],'grade':grades[p['wpId']]}
                                    for i,p in enumerate(run['posts'][:10])]}
        left,right=searches['sections-512'],searches['sections-1536']
        common=set(p['wpId'] for p in left['candidates'])&set(p['wpId'] for p in right['candidates'])
        passages512={p['wpId']:p['passage'] for p in left['candidates']}
        different=sum(p['passage']!=passages512[p['wpId']] for p in right['candidates'] if p['wpId'] in common)
        overlaps.append({'questionId':q['id'],'sharedCandidates':len(common),'differentBestPassages':different})
        rows.append({**q,'versions':versions,'grades':judged['grades']})
    if applied!=set(corrections):
        raise ValueError('Unused numerical correction')
    summary={a:{k:statistics.mean(q['versions'][a]['metrics'][k] for q in rows) for k in rows[0]['versions'][a]['metrics']} for a in c.ARMS}
    for arm in c.ARMS:
        times=sorted(q['versions'][arm]['metrics']['seconds'] for q in rows)
        summary[arm].update({'medianSeconds':statistics.median(times),'p95Seconds':times[math.ceil(.95*len(times))-1]})
    costs,warnings=audit(accepted, rows)
    before,after=(c.read(c.WORK/f'database-{phase}.json')['tables'] for phase in ('before','after'))
    tables=[k for k in before if k.startswith('wp_ehrman_')]
    if len(tables)!=19 or any(any(before[t][k]!=after[t][k] for k in ('rows','sha256')) for t in tables):
        raise ValueError('Discovery tables changed')
    pairs=[pairwise(rows,a,b) for a,b in itertools.combinations(c.ARMS,2)]
    result={'run':c.RUN,'manifest':manifest,'summary':summary,'pairwise':pairs,'questions':rows,
            'beforeAdjudication':[pairwise(rows,a,b,'beforeAdjudication') for a,b in itertools.combinations(c.ARMS,2)],
            'candidateOverlap':overlaps,'costs':costs,'responseWarnings':warnings,'adjudications':changes,
            'gradedPairs':sum(len(q['grades']) for q in rows),
            'newGrades':sum(g['origin']=='new_blinded_full_post_grade' for q in rows for g in q['grades']),
            'discoveryTablesUnchanged':len(tables)}
    c.save(c.PUBLIC/'results.json',result)
    print(json.dumps({k:result[k] for k in ('summary','pairwise','gradedPairs','newGrades','costs')},indent=2))


if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('command',choices=['grade','report'])
    parser.add_argument('--approve-api',action='store_true')
    parser.add_argument('--limit',type=int,default=50)
    args=parser.parse_args()
    if args.command=='grade' and not args.approve_api:
        parser.error('Paid grading requires explicit authorization and --approve-api')
    if not 1<=args.limit<=50:
        parser.error('Limit must be1-50')
    grade(args.limit) if args.command=='grade' else report()
