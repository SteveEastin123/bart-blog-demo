"""Recheck rejected evidence one complete post at a time, still blind to method/rank."""
import hashlib
import json
import re
import common as c
from grade import SCHEMA, check
from repair_quotes import main as repair


def main():
    manifest=c.integrity()
    corpus=c.read(c.WORK/'corpus.json')
    questions=c.read(c.PUBLIC/'questions.json')['questions']
    manual=c.read(c.HERE/'manual-grade-audits.json')['audits']
    for rejection in sorted((c.WORK/'grade-rejections').glob('*-2.json')):
        stem=rejection.stem.rsplit('-',1)[0]
        if (c.WORK/'grade-batches'/(stem+'.json')).exists(): continue
        number=int(stem.split('-')[0][1:])
        results=[c.read(c.WORK/'results'/f'q{number:03}-{arm}.json') for arm in c.ARMS]
        ids=sorted({p['wpId'] for r in results for p in r['posts'][:10]},key=lambda wp:hashlib.sha256(f'{c.RUN}:{number}:{wp}'.encode()).hexdigest())
        labels={f'P{i+1:02}':wp for i,wp in enumerate(ids)}
        rejected_hash=c.read(rejection)['receipt']
        body=c.read(c.WORK/'grade-receipts'/(rejected_hash+'.json'))['response']
        extract=lambda b:json.loads(''.join(p.get('text','') for block in b['output'] for p in block.get('content',[]) if p.get('type')=='output_text'))['grades']
        original_rows=extract(body)
        start=int(stem.split('-')[1])
        expected=list(labels)[start:start+6]
        identity_mismatch=sorted(row['id'] for row in original_rows)!=sorted(expected)
        rows_to_check=[{'id':label,'grade':None,'evidence':''} for label in expected] if identity_mismatch else original_rows
        for old in rows_to_check:
            wp=labels[old['id']]
            source=corpus[wp]['text']
            target=c.WORK/'rejudgments'/f'q{number:03}-{wp}.json'
            if target.exists() or any(x['questionId']==number and x['wpId']==wp for x in manual): continue
            if not identity_mismatch:
                try:
                    check(old,source)
                    continue
                except ValueError: pass
            quote=old['evidence']
            if quote and len(quote.split()) <= 25 and re.search(r'\s+'.join(re.escape(w) for w in quote.split()),source,re.IGNORECASE): continue
            for attempt in range(3):
                spent=sum(c.usage_cost(c.read(p)['response'].get('usage',{})) for p in (c.WORK/'grade-receipts').glob('*.json'))
                if spent+.15 > manifest['gradingSafetyCeilingUsd']: raise ValueError('Grading safety ceiling reached')
                payload={'model':c.MODEL,'store':False,'instructions':c.GRADE_PROMPT+(' Select a shorter exact phrase from this post.'*attempt),
                         'input':json.dumps({'question':questions[number-1]['question'],'posts':[{'id':old['id'],'title':corpus[wp]['title'],'text':source}]},ensure_ascii=False),
                         'reasoning':{'effort':'medium'},'max_output_tokens':4000,
                         'text':{'format':{'type':'json_schema','name':'blind_grades','strict':True,'schema':SCHEMA}}}
                receipt=c.call('responses',payload,'single-post-blind-evidence-audit',cache_root=c.WORK/'grade-receipts')
                response=receipt['response']
                if response.get('status')!='completed' or response.get('model')!=c.MODEL: raise ValueError('Incomplete audit response')
                rows=extract(response)
                if len(rows)!=1 or rows[0]['id']!=old['id']: raise ValueError('Audit ID mismatch')
                row=rows[0]
                try:
                    check(row,source)
                except ValueError:
                    match=re.search(r'\s+'.join(re.escape(w) for w in row['evidence'].split()),source,re.IGNORECASE) if row['evidence'] else None
                    if match:
                        row={**row,'originalEvidence':row['evidence'],'evidence':match.group(0)}
                    try: check(row,source)
                    except ValueError:
                        if attempt<2: continue
                        raise
                c.save(target,{'questionId':number,'wpId':wp,'receipt':rejected_hash,'auditReceipt':receipt['requestHash'],**row,
                               'auditNote':'Fresh single-post full-text judgment; no prior grade, method or rank supplied.'})
                print(json.dumps({'auditedQuestion':number,'wpId':wp,'oldGrade':old['grade'],'newGrade':row['grade']}),flush=True)
                break
    repair()


if __name__=='__main__': main()
