"""Freeze and run fresh three-arm searches through the local WordPress endpoint."""

import argparse
import json
import random
import time
import urllib.error
import urllib.request

import common as c


def prepare():
    import embed
    embed.execute('verify')
    c.preserve()
    sections = c.sections()
    old = c.read(c.SECTION / 'runs/end-to-end-300-2026-10-17/results.json')
    meta = c.read(c.SUMMARY / 'runs/metadata-review-2026-10-17/results.json')
    questions = c.read(c.SECTION / 'runs/end-to-end-300-2026-10-17/questions.json')
    pool = []
    for first, latest in zip(old['questions'], meta['questions']):
        if (first['id'], first['question']) != (latest['id'], latest['question']):
            raise ValueError('Prior questions disagree')
        merged = {g['wpId']: g for g in first['grades']}
        merged.update({g['wpId']: g for g in latest['grades']})
        pool.append({k: latest[k] for k in ('id', 'question', 'group')} | {'grades': list(merged.values())})
    c.save(c.WORK / 'prior-results.json', {'questions': pool}, frozen=True)
    c.save(c.PUBLIC / 'questions.json', questions, frozen=True)
    c.save(c.WORK / 'fixtures/questions.json', questions, frozen=True)
    prompts, settings = {}, set()
    for arm in c.ARMS:
        old_arm = 'ask-ai-2' if arm == 'ask-ai-2' else 'sections-300'
        values = {q['versions'][old_arm]['promptHash'] for q in old['questions']}
        settings |= {q['versions'][old_arm]['settingsHash'] for q in old['questions']}
        if len(values) != 1:
            raise ValueError('Old prompt varied')
        prompts[arm] = next(iter(values))
    if len(settings) != 1:
        raise ValueError('Old settings varied')
    paths = [c.SECTION / 'private/sections.json', c.SECTION / 'private/runtime/index.json',
             c.SECTION / 'private/runtime/sections-300.jsonl.gz', c.WORK / 'fixtures/sections-1536.jsonl.gz',
             c.WORK / 'fixtures/questions.json', c.WORK / 'prior-results.json', c.PUBLIC / 'questions.json',
             c.PUBLIC / 'embedding-manifest.json', c.PUBLIC / 'embedding-results.json',
             c.SECTION / 'runs/end-to-end-300-2026-10-17/results.json',
             c.SUMMARY / 'runs/metadata-review-2026-10-17/results.json']
    paths += sorted((c.HERE / 'plugin').glob('*.php'))
    manifest = {'run': c.RUN, 'sampleHash': old['manifest']['sampleHash'], 'sampleCount': 500,
                'questionCount': 50, 'sectionCount': len(sections), 'sectionWords': 300, 'arms': list(c.ARMS),
                'embeddingModel': 'text-embedding-3-small', 'sectionDimensions': [512, 1536], 'titleSummaryDimensions': 512,
                'reviewerModel': 'gpt-5.4-mini', 'reviewerReasoning': 'low', 'maxOutputTokens': 800,
                'candidateCount': 100, 'promptHashes': prompts, 'settingsHash': next(iter(settings)),
                'indexHash': c.digest(c.SECTION / 'private/runtime/index.json'),
                'questionsHash': c.digest(c.WORK / 'fixtures/questions.json'),
                'sectionHash': c.digest(c.WORK / 'fixtures/sections-1536.jsonl.gz'),
                'runnerHash': c.digest(c.HERE / 'plugin/dimension-pilot.php'),
                'pilotSources': {p.name: c.digest(p) for p in sorted((c.SECTION / 'plugin').glob('*.php')) if not p.name.endswith('tests.php')},
                'preserved': {str(p.relative_to(c.ROOT)).replace('\\', '/'): c.digest(p) for p in paths},
                'orderSeed': c.RUN, 'tieTolerance': .03, 'graderModel': 'gpt-5.4-mini',
                'gradePromptHash': c.sha(c.GRADE_PROMPT), 'graderReasoning': 'medium',
                'questionProvenance': 'Same 50 previous pilot questions; diagnostic, not independent confirmation.',
                'design': 'Only section embedding dimensions differ between section arms; same section text, boundaries, source titles/summaries, title-summary512 branch, hybrid fusion, best-passage selection algorithm,100 candidates,passage prompt and reviewer settings. Ask AI 2 is an additional production reference with its unchanged title-summary-only reviewer.',
                'timingScope': 'HTTP submission to complete JSON result, excluding authentication/browser paint. Each1536 request uses an extra1536query embedding plus512query for unchanged title-summary branch. All arms include identical fixture validation overhead.',
                'grading': 'Shared pool retains previous full-text judgments, latest documented metadata adjudications take precedence on overlap. Add new final-top10 and candidate-top10 members, grade full stored text blind to arms,ranks,summaries,labels.',
                'pricesChecked': '2026-10-17', 'usdPerMillion': {'embedding': .02, 'input': .75, 'cachedInput': .075, 'output': 4.5}}
    c.save(c.PUBLIC / 'manifest.json', manifest, frozen=True)
    c.save(c.WORK / 'fixtures/manifest.json', manifest, frozen=True)
    c.save(c.PUBLIC / 'runner-code.json', {'scope':'Frozen before any measured searches',
           'files':{str((c.HERE/name).relative_to(c.ROOT)).replace('\\','/'):c.digest(c.HERE/name)
                    for name in ('common.py','embed.py','compare.py')}}, frozen=True)
    c.integrity()
    print(json.dumps({'preparedQuestions': 50, 'arms': c.ARMS, 'priorGradedPairs': sum(len(q['grades']) for q in pool), 'apiCalls': 0}))


def validate(result, question, arm, manifest, corpus, passages):
    if any(result.get(k) != v for k, v in {'run': c.RUN, 'questionId': question['id'], 'question': question['question'],
            'arm': arm, 'sampleHash': manifest['sampleHash'], 'sampleCount': 500, 'cacheHit': False, 'test': False,
            'promptHash': manifest['promptHashes'][arm], 'settingsHash': manifest['settingsHash']}.items()):
        raise ValueError('Unexpected search identity, cache or prompt')
    ids, selected = [p['wpId'] for p in result['candidates']], [p['wpId'] for p in result['posts']]
    if len(ids) != 100 or len(set(ids)) != 100 or len(selected) > 25 or len(set(selected)) != len(selected) or not set(selected) <= set(ids):
        raise ValueError('Invalid candidates or selected identities')
    by_id = {p['wpId']: p for p in result['candidates']}
    for p in result['candidates'] + result['posts']:
        if p != by_id[p['wpId']] or p['title'] != corpus[p['wpId']]['title'] or p['summary'] != corpus[p['wpId']]['searchSummary']:
            raise ValueError('Wrong frozen metadata or result content')
        if (arm == 'ask-ai-2' and p['passage']) or (arm != 'ask-ai-2' and p['passage'] not in passages[p['wpId']]):
            raise ValueError('Wrong matching passage')
    usage = result['usage']
    expected_phases = 3 if arm == 'sections-1536' else 2
    if len(usage) != expected_phases or any(u['model'] != 'text-embedding-3-small' for u in usage[:-1]) or not usage[-1]['model'].startswith('gpt-5.4-mini'):
        raise ValueError('Missing or wrong API phases')
    for u in usage:
        if u['input_tokens'] <= 0 or not 0 <= u['cached_input_tokens'] <= u['input_tokens'] or abs(c.previous.phase_cost(u)-u['estimated_cost_usd']) > 1e-10:
            raise ValueError('Invalid cost evidence')
    if abs(result['costUsd'] - sum(c.previous.phase_cost(u) for u in usage)) > 1e-10 or result['submissionToResultsSeconds'] <= 0:
        raise ValueError('Missing cost or timing')


def run(limit):
    manifest = c.integrity()
    if not (c.WORK / 'database-before.json').exists():
        raise ValueError('Capture database fingerprint first')
    corpus = {p['wpId']: p for p in c.authoring.source()['posts']}
    passages = {wp: set() for wp in corpus}
    for s in c.sections():
        passages[s['wpId']].add(s['text'])
    browser = c.Browser()
    for q in c.read(c.PUBLIC / 'questions.json')['questions'][:limit]:
        order = list(c.ARMS)
        random.Random(manifest['orderSeed'] + str(q['id'])).shuffle(order)
        for arm in order:
            path = c.WORK / 'searches' / f"q{q['id']:02}-{arm}.json"
            if path.exists():
                result = c.read(path)
            else:
                saved = [r for p in (c.WORK/'received').glob('*.json')
                         if (r:=c.read(p)).get('questionId')==q['id'] and r.get('arm')==arm]
                if len(saved)>1:
                    raise ValueError('Multiple saved responses require explicit audit before resuming')
                if saved:
                    result=saved[0]
                    validate(result,q,arm,manifest,corpus,passages)
                    c.save(path,result,frozen=True)
                    print(json.dumps({'recoveredQuestion':q['id'],'arm':arm,'apiCalls':0}),flush=True)
                    continue
                request = urllib.request.Request(browser.settings['url']+'compare-dimensions',
                            json.dumps({'questionId': q['id'], 'arm': arm}).encode(),
                            headers={'Content-Type': 'application/json', 'X-WP-Nonce': browser.settings['nonce']})
                start = time.perf_counter()
                try:
                    with browser.opener.open(request, timeout=300) as response:
                        result = json.load(response)
                except urllib.error.HTTPError as error:
                    raise RuntimeError(f"Comparison HTTP {error.code}: {error.read().decode()}") from None
                result['submissionToResultsSeconds'] = time.perf_counter()-start
                c.save(c.WORK / 'received' / (result['requestId']+'.json'), result, frozen=True)
            validate(result, q, arm, manifest, corpus, passages)
            c.save(path, result, frozen=True)
            print(json.dumps({'question': q['id'], 'arm': arm, 'posts': len(result['posts']), 'seconds': round(result['submissionToResultsSeconds'],2), 'cents': round(result['costUsd']*100,4)}), flush=True)
    c.integrity()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['prepare', 'run', 'verify'])
    parser.add_argument('--approve-api', action='store_true')
    parser.add_argument('--limit', type=int, default=50)
    args = parser.parse_args()
    if args.command == 'run' and not args.approve_api:
        parser.error('Paid searches require --approve-api after user authorization')
    if not 1 <= args.limit <= 50:
        parser.error('Limit must be1-50')
    if args.command == 'prepare':
        prepare()
    elif args.command == 'run':
        run(args.limit)
    else:
        print(json.dumps({'unchanged': True, 'run': c.integrity()['run']}))
