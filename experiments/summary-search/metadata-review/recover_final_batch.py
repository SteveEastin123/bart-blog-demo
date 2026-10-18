"""Offline, audited quotation repair for the final saved grading batch."""

import json

import compare as c


def recover():
    c.integrity()
    source = {p['wpId']: p for p in c.authoring.source()['posts']}
    full = c.read(c.WORK / 'input.json')['questions'][49]
    question = {k: full[k] for k in ('id', 'question', 'group')}
    previous = c.read(c.WORK / 'prior-results.json')['questions'][49]
    runs = c.question_runs(full, c.read(c.WORK / 'parsed.json'), c.read(c.WORK / 'receipts.json'))
    known = {g['wpId'] for g in previous['grades']}
    ids = known | {p['wpId'] for run in runs.values() for p in run['posts'][:10]}
    if question['id'] != 50 or ids - known != {'47072', '35255'}:
        raise ValueError('This repair applies only to the frozen final two posts')
    request_hash = '4e06f164509ada342e0fc780be4d949cd6eefac032f9b325ed9ade2327c94f98'
    receipt = c.read(c.WORK / 'grading-cache' / (request_hash + '.json'))
    if receipt['requestHash'] != request_hash or receipt['response']['status'] != 'completed':
        raise ValueError('Unexpected final grading receipt')
    text = ''.join(part.get('text', '') for output in receipt['response']['output']
                   for part in output.get('content', []) if part.get('type') == 'output_text')
    original = json.loads(text)['grades']
    if [(r['id'], r['grade']) for r in original] != [('P1', 0), ('P2', 2)]:
        raise ValueError('Saved grading output changed')
    quote = 'The gentile followers of Jesus were not, *absolutely* not, supposed to become Jewish.'
    if original[1]['evidence'] != quote.replace('*', '') or quote not in source['35255']['text']:
        raise ValueError('Not the inspected emphasis-marker omission')
    grades = [{**g, 'origin': 'verified_prior_full_post_grade'} for g in previous['grades']]
    for row, wp in zip(original, ('47072', '35255')):
        repaired = {**row}
        if wp == '35255':
            repaired.update({'evidence': quote, 'originalEvidence': row['evidence'],
                             'evidenceRepair': 'Restored literal asterisks present in the supplied full text; numerical grade unchanged here.'})
        c.grading.check_grade(repaired, source[wp]['text'], strict=True)
        grades.append({**repaired, 'wpId': wp, 'textHash': source[wp]['textHash'],
                       'origin': 'new_blinded_full_post_grade', 'model': receipt['model'],
                       'requestHash': request_hash, 'evidenceVerified': True})
    result = {**question, 'poolHash': c.grading.pool_identity(question, ids), 'grades': grades}
    c.grading.validate_grades(result, question, ids, source)
    c.save(c.WORK / 'grades/q50.json', result, frozen=True)
    c.save(c.PUBLIC / 'evidence-repair.json', {
        'questionId': 50, 'wpId': '35255', 'requestHash': request_hash,
        'attempts': 3, 'paidAgain': False, 'originalEvidence': original[1]['evidence'],
        'repairedEvidence': quote,
        'reason': 'All three saved API attempts omitted literal emphasis asterisks from the quoted source sentence. The coordinator read both full posts and restored the exact source characters without weakening quotation validation.',
        'gradeHandling': 'The final API grades remain 0 and 2 in the grade file. Separate, previously recorded full-text adjudication changes 35255 from 2 to 1 in reported metrics; raw API receipts are unchanged and all attempts are costed.',
        'sourceCheck': 'final-batch-source-check.json'
    }, frozen=True)
    c.integrity()
    print(json.dumps({'recoveredQuestion': 50, 'quotationRepairs': 1, 'apiCalls': 0}))


if __name__ == '__main__':
    recover()
