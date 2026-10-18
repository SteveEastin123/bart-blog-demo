"""Repair only capitalization/whitespace in rejected evidence, preserving grades and receipts."""
import hashlib
import json
import re
import common as c
from grade import check


def main():
    c.integrity()
    corpus=c.read(c.WORK/'corpus.json')
    overrides_path=c.HERE/'manual-grade-audits.json'
    overrides=c.read(overrides_path)['audits'] if overrides_path.exists() else []
    overrides += [c.read(p) for p in (c.WORK/'rejudgments').glob('*.json')]
    fixed=0
    pending=[]
    for rejection in sorted((c.WORK/'grade-rejections').glob('*-2.json')):
        stem=rejection.stem.rsplit('-',1)[0]
        target=c.WORK/'grade-batches'/(stem+'.json')
        if target.exists(): continue
        number=int(stem.split('-')[0][1:])
        results=[c.read(c.WORK/'results'/f'q{number:03}-{arm}.json') for arm in c.ARMS]
        ids=sorted({p['wpId'] for r in results for p in r['posts'][:10]},key=lambda wp:hashlib.sha256(f'{c.RUN}:{number}:{wp}'.encode()).hexdigest())
        labels={f'P{i+1:02}':wp for i,wp in enumerate(ids)}
        receipt=c.read(rejection)['receipt']
        body=c.read(c.WORK/'grade-receipts'/(receipt+'.json'))['response']
        rows=json.loads(''.join(p.get('text','') for b in body['output'] for p in b.get('content',[]) if p.get('type')=='output_text'))['grades']
        start=int(stem.split('-')[1])
        expected=list(labels)[start:start+6]
        if sorted(row['id'] for row in rows)!=sorted(expected):
            accepted=[]
            for label in expected:
                wp=labels[label]
                override=next((o for o in overrides if o['questionId']==number and o['wpId']==wp and o['receipt']==receipt),None)
                if not override or not override.get('auditReceipt') or override.get('id')!=label:
                    pending.append({'questionId':number,'receipt':receipt,'reason':'Batch identity mismatch requires fresh individual grades for every expected post.'})
                    break
                check(override,corpus[wp]['text'])
                accepted.append({k:override[k] for k in ('id','wpId','grade','reason','evidence','receipt','auditReceipt','auditNote')} |
                                {'evidenceVerified':True,'originalBatchIdentityMismatch':True})
            else:
                c.save(target,{'questionId':number,'grades':accepted,'repair':'Entire mislabeled batch replaced by fresh blind individual grades; original receipt preserved.'})
                fixed+=1
            continue
        accepted=[]
        for row in rows:
            wp=labels[row['id']]
            source=corpus[wp]['text']
            revised=dict(row)
            override=next((o for o in overrides if o['questionId']==number and o['wpId']==wp and o['receipt']==receipt),None)
            if override:
                revised.update({k:override[k] for k in ('grade','reason','evidence','auditNote')})
                if 'auditReceipt' in override: revised['auditReceipt']=override['auditReceipt']
                revised['originalJudgment']=dict(row)
                check(revised,source)
                accepted.append({**revised,'wpId':wp,'receipt':receipt,'evidenceVerified':True})
                continue
            try: check(row,source)
            except ValueError:
                quote=row['evidence']
                match=re.search(r'\s+'.join(re.escape(word) for word in quote.split()),source,re.IGNORECASE) if quote and len(quote.split()) <= 25 else None
                if match is None:
                    pending.append({'questionId':number,'wpId':wp,'receipt':receipt,'evidence':quote})
                    break
                revised.update({'originalEvidence':quote,'evidence':match.group(0),'evidenceCorrection':'Capitalization/whitespace copied from source; grade and rationale unchanged.'})
                check(revised,source)
            accepted.append({**revised,'wpId':wp,'receipt':receipt,'evidenceVerified':True})
        else:
            c.save(target,{'questionId':number,'grades':accepted,'repair':'Literal case/whitespace only, original receipts preserved.'})
            fixed+=1
    print(json.dumps({'repairedBatches':fixed,'needsReading':pending},ensure_ascii=True))


if __name__=='__main__': main()
