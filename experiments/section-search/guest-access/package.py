"""Combine existing pilot/expansion vectors for local guest search; no API calls."""
import base64
from collections import Counter
import gzip
import hashlib
import json
import math
from pathlib import Path
import struct

ROOT = Path(__file__).resolve().parents[3]
PRIVATE = ROOT / 'experiments/section-search/private'
PILOT = PRIVATE / 'runtime'
EXPANSION = PRIVATE / 'remaining-300-512-2026-10-18/package'
OUTPUT = PRIVATE / 'guest-access-runtime'


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def checked_file(path, expected):
    if digest(path) != expected:
        raise ValueError(f'Source hash mismatch: {path}')
    return path


def rows(path):
    with gzip.open(path, 'rt', encoding='utf-8') as stream:
        for line in stream:
            yield json.loads(line)


def check_vector(encoded):
    values = struct.unpack('<512f', base64.b64decode(encoded, validate=True))
    if not all(math.isfinite(value) for value in values) or sum(v*v for v in values) <= 0:
        raise ValueError('Invalid vector')


def unique_rows(records, key):
    result = {}
    for row in records:
        identifier = str(row[key])
        if identifier in result:
            raise ValueError(f'Duplicate {key}: {identifier}')
        result[identifier] = row
    return result


def package():
    pilot = read(PILOT / 'index.json')
    expansion = read(EXPANSION / 'manifest.json')
    if (pilot['model'], pilot['dimensions']) != ('text-embedding-3-small', 512):
        raise ValueError('Unexpected pilot embedding configuration')
    if (expansion['model'], expansion['dimensions'], expansion['targetWords']) != ('text-embedding-3-small', 512, 300):
        raise ValueError('Unexpected expansion embedding configuration')
    for name, entry in expansion['files'].items():
        checked_file(EXPANSION / name, entry['sha256'])
    pilot_sections = checked_file(PILOT / 'sections-300.jsonl.gz', pilot['sectionFiles']['300']['sha256'])
    checked_file(pilot_sections, expansion['pilotPackage']['sha256'])
    for name, expected in pilot['sourceHashes'].items():
        checked_file(ROOT / 'wordpress-plugin/ehrman-blog-discovery' / name, expected)

    selected = unique_rows(pilot['posts'], 'wpId')
    added = unique_rows(rows(EXPANSION / 'posts.jsonl.gz'), 'wpId')
    if set(selected) & set(added) or len(selected) != 500 or len(added) != 3486:
        raise ValueError('Pilot and expansion must be disjoint complete sets')
    selected.update(added)
    canonical = unique_rows(read(ROOT / 'data/index/ehrman_post_search_index.json'), 'wpId')
    vector_rows = rows(ROOT / 'data/index/ehrman_post_embeddings.jsonl.gz')
    header = next(vector_rows)
    if (header['model'], header['dimensions']) != ('text-embedding-3-small', 512):
        raise ValueError('Unexpected title/summary vector configuration')
    vectors = unique_rows(vector_rows, 'sourceWpId')
    if len(vectors) != header['count']:
        raise ValueError('Incomplete title/summary vectors')

    posts = []
    for identifier, source in sorted(selected.items(), key=lambda item: int(item[0])):
        post, vector = canonical[identifier], vectors[identifier]
        expected = hashlib.sha256(f"Title: {post['title']}\nSummary: {post['searchSummary']}".encode()).hexdigest()
        if vector['contentHash'] != expected or (vector['model'], vector['dimensions']) != ('text-embedding-3-small', 512):
            raise ValueError(f'Stale title/summary vector: {identifier}')
        if source['title'] != post['title'] or 'Ignore' in post['topics']:
            raise ValueError(f'Ineligible or changed source: {identifier}')
        if 'vector' in source and source['vector'] != vector:
            raise ValueError(f'Pilot vector changed: {identifier}')
        check_vector(vector['embedding'])
        posts.append({key: post[key] for key in ('wpId', 'title', 'url', 'description', 'searchSummary')}
                     | {'vector': vector, 'textHash': source['textHash']})

    OUTPUT.mkdir(parents=True, exist_ok=True)
    target = OUTPUT / 'sections-300.jsonl.gz'
    temporary = target.with_suffix('.tmp')
    counts = Counter()
    seen = set()
    with gzip.open(temporary, 'wt', encoding='utf-8') as stream:
        for path in (pilot_sections, EXPANSION / 'sections-300-512.jsonl.gz'):
            for section in rows(path):
                identifier = str(section['wpId'])
                key = (identifier, section['section'])
                if identifier not in selected or key in seen or section['size'] != 300 or not section['text'].strip():
                    raise ValueError(f'Invalid or duplicate section: {key}')
                check_vector(section['embedding'])
                seen.add(key)
                counts[identifier] += 1
                stream.write(json.dumps(section, ensure_ascii=False, separators=(',', ':')) + '\n')
    if set(counts) != set(selected) or sum(counts.values()) != 19951:
        raise ValueError('Incomplete section coverage')
    temporary.replace(target)
    identity = hashlib.sha256(json.dumps([(p['wpId'], p['textHash'], p['vector']['contentHash']) for p in posts]).encode()).hexdigest()
    metadata = {'version': 1, 'sampleHash': identity, 'dimensions': 512,
                'model': 'text-embedding-3-small', 'sizes': [300], 'posts': posts,
                'sourceHashes': pilot['sourceHashes'],
                'sectionFiles': {'300': {'sha256': digest(target), 'count': sum(counts.values())}},
                'sources': {'pilotIndex': digest(PILOT / 'index.json'),
                            'expansionManifest': digest(EXPANSION / 'manifest.json')}}
    temporary = OUTPUT / 'index.json.tmp'
    temporary.write_text(json.dumps(metadata, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    temporary.replace(OUTPUT / 'index.json')
    print(json.dumps({'posts': len(posts), 'sections': sum(counts.values()),
                      'newEmbeddingCalls': 0, 'runtime': str(OUTPUT)}))


if __name__ == '__main__':
    package()
