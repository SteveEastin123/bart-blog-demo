"""Fresh, blinded full-text grading. Private receipts retain every billed attempt."""
import argparse
import concurrent.futures
import hashlib
import json
import threading
import unicodedata
import common as c

LOCK = threading.Lock()
SPENT = 0.0
RESERVED = 0.0
SCHEMA = c.object_schema({'grades': {'type':'array','items':c.object_schema({
    'id':{'type':'string'}, 'grade':{'type':'integer','enum':[0,1,2,3]},
    'reason':{'type':'string'}, 'evidence':{'type':'string'}})}})


def normalize(text):
    return ' '.join(unicodedata.normalize('NFKC',text).split())


def check(row, source):
    if type(row.get('grade')) is not int or row['grade'] not in range(4) or not row.get('reason','').strip():
        raise ValueError('Invalid grade or missing rationale')
    quote = row.get('evidence','')
    if len(quote.split()) > 25 or (quote and normalize(quote) not in normalize(source)):
        raise ValueError('Evidence must be a short contiguous quotation from the full post')
    if row['grade'] >= 2 and not quote.strip():
        raise ValueError('Positive grade requires evidence')


def grade_question(question, corpus, manifest):
    target = c.WORK/'grades'/f"q{question['id']:03}.json"
    if target.exists():
        return
    results = [c.read(c.WORK/'results'/f"q{question['id']:03}-{arm}.json") for arm in c.ARMS]
    for arm,result in zip(c.ARMS,results):
        c.validate(result,question,arm,manifest)
    ids = {p['wpId'] for result in results for p in result['posts'][:10]}
    ids = sorted(ids,key=lambda wp:hashlib.sha256(f'{c.RUN}:{question["id"]}:{wp}'.encode()).hexdigest())
    labels = {f'P{i+1:02}':wp for i,wp in enumerate(ids)}
    judgments=[]
    for start in range(0,len(ids),6):
        batch = list(labels.items())[start:start+6]
        output_path = c.WORK/'grade-batches'/f"q{question['id']:03}-{start:02}.json"
        if output_path.exists():
            judgments.extend(c.read(output_path)['grades'])
            continue
        submitted = [{'id':label,'title':corpus[wp]['title'],'text':corpus[wp]['text']} for label,wp in batch]
        for attempt in range(3):
            instructions = c.GRADE_PROMPT + (' Verify each quoted phrase character-for-character against its post.' * attempt)
            payload={'model':c.MODEL,'store':False,'instructions':instructions,
                     'input':json.dumps({'question':question['question'],'posts':submitted},ensure_ascii=False),
                     'reasoning':{'effort':'medium'},'max_output_tokens':10000,
                     'text':{'format':{'type':'json_schema','name':'blind_grades','strict':True,'schema':SCHEMA}}}
            request_hash=hashlib.sha256(json.dumps(payload,ensure_ascii=False).encode()).hexdigest()
            cache=c.WORK/'grade-receipts'
            cached=(cache/(request_hash+'.json')).exists()
            global SPENT, RESERVED
            # Reserve more than the worst-case output allowance plus the entire UTF-8 input byte count.
            reserve=len(json.dumps(payload).encode())*.75/1e6 + 10000*4.5/1e6
            with LOCK:
                if not cached and SPENT+RESERVED+reserve > manifest['gradingSafetyCeilingUsd']:
                    raise RuntimeError('Grading safety ceiling reached')
                if not cached: RESERVED+=reserve
            try:
                receipt=c.call('responses',payload,'fresh-blind-full-posts',cache_root=cache)
                body=receipt['response']
                cost=c.usage_cost(body.get('usage',{}))
                with LOCK:
                    if not cached: SPENT+=cost
            finally:
                with LOCK:
                    if not cached: RESERVED-=reserve
            if body.get('status')!='completed' or body.get('model')!=c.MODEL:
                raise ValueError('Incomplete or wrong-model grading response; receipt retained')
            text=''.join(part.get('text','') for block in body.get('output',[]) for part in block.get('content',[]) if part.get('type')=='output_text')
            try:
                rows=json.loads(text)['grades']
                if sorted(row['id'] for row in rows)!=sorted(label for label,_ in batch):
                    raise ValueError('Missing or duplicated grade ID')
                for row in rows:
                    check(row,corpus[labels[row['id']]]['text'])
                accepted=[{**row,'wpId':labels[row['id']],'evidenceVerified':True,'receipt':request_hash} for row in rows]
                c.save(output_path,{'questionId':question['id'],'grades':accepted})
                judgments.extend(accepted)
                break
            except (ValueError,KeyError) as error:
                c.save(c.WORK/'grade-rejections'/f"q{question['id']:03}-{start:02}-{attempt}.json",{'receipt':request_hash,'reason':str(error)})
                if attempt==2: raise
    if {j['wpId'] for j in judgments} != set(ids) or len(judgments)!=len(ids):
        raise ValueError('Incomplete pooled judgments')
    c.save(target,{'questionId':question['id'],'question':question['question'],'group':question['group'],'grades':judgments})
    with LOCK:
        print(json.dumps({'gradedQuestion':question['id'],'fullPostsRead':len(ids),'gradingCostUsd':round(SPENT,5)}),flush=True)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--approve-api',action='store_true',required=True)
    parser.add_argument('--limit',type=int,default=250)
    parser.add_argument('--workers',type=int,default=3)
    args=parser.parse_args()
    manifest=c.integrity()
    corpus=c.read(c.WORK/'corpus.json')
    questions=c.read(c.PUBLIC/'questions.json')['questions'][:args.limit]
    global SPENT
    SPENT=sum(c.usage_cost(c.read(p)['response'].get('usage',{})) for p in (c.WORK/'grade-receipts').glob('*.json'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=min(4,max(1,args.workers))) as pool:
        futures=[pool.submit(grade_question,q,corpus,manifest) for q in questions]
        for future in concurrent.futures.as_completed(futures): future.result()
    print(json.dumps({'gradingCompleteThrough':args.limit,'costUsd':SPENT}),flush=True)


if __name__=='__main__': main()
