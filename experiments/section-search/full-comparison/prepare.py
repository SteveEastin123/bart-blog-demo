"""Freeze questions, corpus, source fingerprints and run protocol before paid requests."""
import difflib
import hashlib
import json
import common as c
import argparse


def prepare(preflight_update=False):
    if preflight_update:
        if any((c.WORK/'results').glob('*.json')) or (c.WORK/'server-requests.jsonl').exists():
            raise ValueError('Cannot revise a benchmark after searches begin')
        for name in ('manifest.json','questions.json','question-novelty.json'):
            path=c.PUBLIC/name
            if path.exists():
                c.save(c.WORK/'preflight-history'/f'{c.digest(path)}-{name}',c.read(path))
    registry = c.read(c.ROOT / 'data/evaluations/ask_ai1_vs_ask_ai2_run_registry.json')
    questions = []
    paths = []
    for run in registry['runs']:
        path = c.ROOT / run['questionFile']
        paths.append(path)
        for number, item in enumerate(c.read(path)['questions'], 1):
            questions.append({'id':len(questions)+1,'question':item['question'],'group':'original-200',
                              'originalRun':run['runNumber'],'originalNumber':number})
    if len(questions) != 200 or len({q['question'].casefold() for q in questions}) != 200:
        raise ValueError('Expected 200 unique original questions')
    challenge = c.read(c.HERE / 'challenge.json')
    if len(challenge['questions']) != 50:
        raise ValueError('Expected 50 challenge questions')
    old = set(q['question'] for q in questions)
    def collect(value):
        if isinstance(value,dict):
            for k,v in value.items():
                if k=='question' and isinstance(v,str): old.add(v)
                else: collect(v)
        elif isinstance(value,list):
            for v in value: collect(v)
    for path in (c.ROOT/'data/evaluations').glob('*questions*.json'):
        collect(c.read(path))
    for path in c.SECTION.glob('runs/*/questions.json'):
        if path.parent != c.PUBLIC:
            collect(c.read(path))
    collect(c.read(c.SECTION/'questions.json'))
    novelty=[]
    for category,text in challenge['questions']:
        if text.casefold() in {x.casefold() for x in old}:
            raise ValueError('Repeated challenge question')
        closest=max(old,key=lambda x:difflib.SequenceMatcher(None,text.lower(),x.lower()).ratio())
        novelty.append({'question':text,'closestPrior':closest,'similarity':difflib.SequenceMatcher(None,text.lower(),closest.lower()).ratio()})
        questions.append({'id':len(questions)+1,'question':text,'group':'challenge-50','category':category})
    if len({q['question'].casefold() for q in questions}) != 250:
        raise ValueError('Duplicate questions')
    for question in questions:
        question['batch'] = (question['id'] - 1) // 50 + 1
    index = c.read(c.SECTION/'private/guest-access-runtime/index.json')
    ids={p['wpId'] for p in index['posts']}
    posts,_=c.source_posts()
    corpus={p['wpId']:{k:p[k] for k in ('wpId','title','url','text','textHash','searchSummary')} for p in posts if p['wpId'] in ids}
    if len(corpus)!=3986 or any(corpus[p['wpId']]['textHash']!=p['textHash'] for p in index['posts']):
        raise ValueError('Full-text corpus does not match section scope')
    c.save(c.WORK/'corpus.json',corpus)
    c.save(c.PUBLIC/'questions.json',{'run':c.RUN,'challengePurpose':challenge['purpose'],'questions':questions},frozen=not preflight_update)
    c.save(c.PUBLIC/'question-novelty.json',novelty,frozen=not preflight_update)
    preserved = {str(p.relative_to(c.ROOT)).replace('\\','/'):c.digest(p) for p in paths}
    for path in [c.WORK/'corpus.json',c.PUBLIC/'questions.json',c.HERE/'challenge.json',
                 c.ROOT/'data/index/ehrman_post_search_index.json',c.ROOT/'data/index/ehrman_post_embeddings.jsonl.gz',
                 c.ROOT/'data/raw/posts.jsonl',c.SECTION/'private/guest-access-runtime/index.json',
                 c.SECTION/'private/guest-access-runtime/sections-300.jsonl.gz']:
        preserved[str(path.relative_to(c.ROOT)).replace('\\','/')]=c.digest(path)
    for folder,pattern in [(c.HERE,'*.py'),(c.HERE,'*.cjs'),(c.HERE/'plugin','*.php'),
                            (c.SECTION/'plugin','*.php'),(c.SECTION/'guest-access/plugin','*'),
                            (c.ROOT/'wordpress-plugin/ehrman-blog-discovery/src','*.php')]:
        for path in folder.rglob(pattern):
            if path.is_file(): preserved[str(path.relative_to(c.ROOT)).replace('\\','/')]=c.digest(path)
    runtime={'index.json':c.digest(c.SECTION/'private/guest-access-runtime/index.json'),
             'sections-300.jsonl.gz':c.digest(c.SECTION/'private/guest-access-runtime/sections-300.jsonl.gz'),
             'questions.json':c.digest(c.PUBLIC/'questions.json')}
    runtime.update({'plugin/'+p.name:c.digest(p) for p in (c.HERE/'plugin').glob('*.php') if p.name!='tests.php'})
    manifest={'run':c.RUN,'createdDate':'2026-10-18','sampleHash':index['sampleHash'],'sampleCount':3986,
              'questionCount':250,'groups':{'original-200':200,'challenge-50':50},'arms':list(c.ARMS),
              'batches':'Five fixed batches of50questions: original runs1-4, then the new challenge set. No tuning between batches.',
              'reviewerModel':c.MODEL,'graderModel':c.MODEL,'candidateLimit':100,'sectionWords':300,'dimensions':512,
              'tieThresholdNdcg':.03,'relevantGrades':[2,3],'judgedDepth':10,'grading':'Fresh blinded full text, deduplicated union of final top ten. No prior grades reused.',
              'timing':'Identical browser form, submit event through next animation frame after results render. Backend phases also recorded. Authentication/page load excluded.',
              'cachePolicy':'Fresh independent query embedding and review on every arm; no WordPress result/query caches. Provider prompt caching observed and billed normally.',
              'order':'Alternating first method across questions. Each pair consecutive and sequential.',
              'precision':'Fixed denominator 5/10; absent slots count as not relevant. Displayed-result precision also reported; earlier 200-run precision used a different denominator.',
              'design':'Controlled package comparison: title-summary hybrid baseline versus same retrieval plus sections and passage-aware review. Restricts both to3986posts;doesnotmodifyliveAskAI2.',
              'prices':{'input':.75,'cachedInput':.075,'output':4.5,'embedding':.02},
              'priceSource':'https://developers.openai.com/api/docs/models/gpt-5.4-mini','pricesChecked':'2026-10-18',
              'searchSafetyCeilingUsd':25,'gradingSafetyCeilingUsd':20,'runtimeHashes':runtime,'preserved':preserved}
    c.save(c.PUBLIC/'manifest.json',manifest,frozen=not preflight_update)
    print(json.dumps({'questions':len(questions),'posts':len(corpus),'closestNovelty':sorted(novelty,key=lambda x:x['similarity'],reverse=True)[:8]}))


if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--preflight-update',action='store_true')
    prepare(parser.parse_args().preflight_update)
