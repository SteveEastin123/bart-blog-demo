"""Frozen full-corpus paired comparison; private raw text and provider receipts."""
from pathlib import Path
import hashlib
import json
import sys

HERE = Path(__file__).resolve().parent
SECTION = HERE.parent
ROOT = HERE.parents[2]
RUN = 'full-corpus-250-2026-10-18'
PUBLIC = SECTION / 'runs' / RUN
WORK = SECTION / 'private' / RUN
ARMS = ('ask-ai-2', 'ask-ai-3')
MODEL = 'gpt-5.4-mini-2026-03-17'
sys.path.insert(0, str(SECTION))
from corpus import source_posts
from evidence import GRADE_PROMPT, usage_cost
from evaluate import metrics, confidence_interval
from api import call, object_schema


def read(path):
    return json.loads(path.read_text(encoding='utf-8-sig'))


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def save(path, value, frozen=True):
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists() and frozen:
        if read(path) != value:
            raise ValueError(f'Frozen file differs: {path}')
        return
    temp = path.with_suffix(path.suffix + '.tmp')
    temp.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temp.replace(path)


def integrity():
    m = read(PUBLIC / 'manifest.json')
    for name, expected in m['preserved'].items():
        if digest(ROOT / name) != expected:
            raise ValueError('Frozen input changed: ' + name)
    return m


def phase_cost(u):
    if u['model'].startswith('text-embedding-3-small'):
        return u['input_tokens'] * .02 / 1e6
    if not u['model'].startswith('gpt-5.4-mini'):
        raise ValueError('Unexpected billed model')
    return ((u['input_tokens']-u['cached_input_tokens'])*.75 + u['cached_input_tokens']*.075 + u['output_tokens']*4.5)/1e6


def validate(result, question, arm, manifest):
    expected = {'run': RUN, 'questionId': question['id'], 'question': question['question'],
                'arm': arm, 'group': question['group'], 'sampleCount': 3986,
                'sampleHash': manifest['sampleHash'], 'cacheHit': False, 'controlled': True,
                'evidence': arm == 'ask-ai-3', 'model': MODEL}
    if any(result.get(k) != v for k,v in expected.items()):
        raise ValueError('Result identity or configuration mismatch')
    ids = [p['wpId'] for p in result['candidates']]
    selected = [p['wpId'] for p in result['posts']]
    if len(ids) != 100 or len(set(ids)) != 100 or len(selected) > 25 or len(set(selected)) != len(selected) or not set(selected) <= set(ids):
        raise ValueError('Invalid candidate/selected IDs')
    if len(result['usage']) != 2 or result['usage'][0]['model'] != 'text-embedding-3-small' or result['usage'][1]['model'] != MODEL:
        raise ValueError('Missing real embedding or refinement phase')
    for usage in result['usage']:
        if usage['input_tokens'] <= 0 or abs(phase_cost(usage)-usage['estimated_cost_usd']) > 1e-10:
            raise ValueError('Invalid paid-phase cost')
    if abs(sum(phase_cost(u) for u in result['usage'])-result['costUsd']) > 1e-10:
        raise ValueError('Incorrect total cost')
