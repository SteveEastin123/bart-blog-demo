"""Paths and preservation guards for the isolated section-dimension pilot."""

import hashlib
import gzip
import json
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
SECTION = HERE.parent
SUMMARY = SECTION.parent / 'summary-search'
sys.path.insert(0, str(SUMMARY))
import agent_work as authoring
import compare_agent as previous
import score_agent as grading

sys.path.insert(0, str(SECTION))
from api import call, configuration, json_response, object_schema
from evaluate import Browser, confidence_interval, metrics
from evidence import GRADE_PROMPT, usage_cost

ROOT = authoring.ROOT
RUN = 'dimensions-300-2026-10-17'
WORK = SECTION / 'private' / RUN
PUBLIC = SECTION / 'runs' / RUN
ARMS = ('ask-ai-2', 'sections-512', 'sections-1536')
LABELS = {'ask-ai-2': 'Ask AI 2', 'sections-512': '300-word sections / 512', 'sections-1536': '300-word sections / 1536'}
read, save, digest = authoring.read, authoring.save, authoring.digest


def sha(text):
    return hashlib.sha256(text.encode('utf-8')).hexdigest()


def preserve():
    previous.integrity()
    manifest = read(SUMMARY / 'runs/metadata-review-2026-10-17/manifest.json')
    for relative, expected in manifest['preserved'].items():
        if digest(ROOT / relative) != expected:
            raise ValueError('Earlier experiment changed: ' + relative)


def sections():
    preserve()
    data = read(SECTION / 'private/sections.json')
    rows = [s for s in data['sections'] if s['size'] == 300]
    if len(rows) != 2636 or len({s['wpId'] for s in rows}) != 500:
        raise ValueError('Expected the original 2636 sections across 500 posts')
    for s in rows:
        if sha(s['input']) != s['hash']:
            raise ValueError('Section embedding text changed')
    index = read(SECTION / 'private/runtime/index.json')
    original_file = SECTION / 'private/runtime/sections-300.jsonl.gz'
    if digest(original_file) != index['sectionFiles']['300']['sha256']:
        raise ValueError('Original512 section package changed')
    with gzip.open(original_file, 'rt', encoding='utf-8') as handle:
        original = [json.loads(line) for line in handle]
    if len(original) != len(rows):
        raise ValueError('Section counts differ')
    for before, after in zip(original, rows):
        if {k:v for k,v in before.items() if k != 'embedding'} != {k:v for k,v in after.items() if k != 'input'}:
            raise ValueError('Section boundaries, text, identity or order changed')
    return rows


def integrity():
    preserve()
    manifest = read(PUBLIC / 'manifest.json')
    for relative, expected in manifest['preserved'].items():
        if digest(ROOT / relative) != expected:
            raise ValueError('Frozen dimension experiment changed: ' + relative)
    if read(WORK / 'fixtures/manifest.json') != manifest:
        raise ValueError('Runtime manifest changed')
    code = read(PUBLIC / 'runner-code.json')
    for relative, expected in code['files'].items():
        if digest(ROOT / relative) != expected:
            raise ValueError('Frozen Python runner changed: ' + relative)
    return manifest


def json_output(receipt):
    body = receipt['response']
    if body.get('status') != 'completed':
        raise ValueError('Incomplete response')
    return json.loads(''.join(p.get('text', '') for o in body.get('output', [])
                              for p in o.get('content', []) if p.get('type') == 'output_text'))
