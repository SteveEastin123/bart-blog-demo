"""Document one manually verified quotation repair; never change model grades."""

import copy
import json

import common as c
import score


def repair():
    c.integrity()
    q = c.read(c.PUBLIC / 'questions.json')['questions'][37]
    prior = c.read(c.WORK / 'prior-results.json')['questions'][37]
    corpus = {p['wpId']: p for p in c.authoring.source()['posts']}
    known = {g['wpId']: g for g in prior['grades']}
    ids = score.pool_ids(prior, score.runs(q))
    pending = sorted(ids - known.keys(), key=lambda wp: c.sha(c.RUN + str(q['id']) + wp))
    if pending != ['41678', '4667', '32259']:
        raise ValueError('Unexpected repair pool')
    receipt = c.read(c.WORK / 'grading-cache/88bd3d3170e0423da14803c21e063051c1ec9314402bea68bee6d8a3a17cc473.json')
    posts = [{'id': f'P{i+1}', 'title': corpus[wp]['title'], 'text': corpus[wp]['text']} for i, wp in enumerate(pending)]
    response = receipt['response']
    payload = {'model': receipt['model'], 'store': False, 'instructions': response['instructions'],
               'input': json.dumps({'question': q['question'], 'posts': posts}, ensure_ascii=False),
               'reasoning': {'effort': 'medium'}, 'max_output_tokens': 6000,
               'text': {'format': {'type': 'json_schema', 'name': 'evaluation', 'strict': True,
                                   'schema': response['text']['format']['schema']}}}
    if c.sha(json.dumps(payload, ensure_ascii=False)) != receipt['requestHash']:
        raise ValueError('Receipt does not belong to the expected blinded batch')
    judged = [score.retained_grade(g, corpus[g['wpId']]) for g in known.values()]
    fixes = []
    for g in c.json_output(receipt)['grades']:
        wp = pending[int(g['id'][1:]) - 1]
        row = {**g, 'wpId': wp, 'textHash': corpus[wp]['textHash'], 'evidenceVerified': True,
               'origin': 'new_blinded_full_post_grade', 'model': receipt['model'], 'requestHash': receipt['requestHash']}
        if wp == '32259':
            row['beforeDimensionAdjudication'] = copy.deepcopy(row)
            row['evidence'] = 'has changed in the many years since I was involved in the movement'
            fixes.append({'questionId': q['id'], 'wpId': wp, 'requestHash': receipt['requestHash'],
                          'originalEvidence': g['evidence'], 'evidence': row['evidence'],
                          'gradeUnchanged': row['grade'],
                          'reason': 'After three automatic quotation checks failed, coordinator read all three complete posts. Shortened the last attempt quote to one exact contiguous phrase under 25 words. No score or reason changed; all receipts retained and charged.'})
        c.grading.check_grade(row, corpus[wp]['text'], strict=True)
        judged.append(row)
    result = {**q, 'poolHash': c.grading.pool_identity(q, ids), 'grades': judged}
    c.grading.validate_grades(result, q, ids, corpus)
    c.save(c.PUBLIC / 'evidence-repairs.json', {'repairs': fixes}, frozen=True)
    c.save(c.WORK / 'grades/q38.json', result, frozen=True)
    print('Question 38 restored from its exact last receipt; one quotation shortened, zero numerical grades changed, zero new API calls.')


if __name__ == '__main__':
    repair()
