"""Generate separate 1536-dimensional vectors for the unchanged 300-word sections."""

import argparse
import base64
import gzip
import json
import math
import struct

import common as c

MODEL, DIMENSIONS, BATCH = 'text-embedding-3-small', 1536, 32


def payload(rows):
    return {'model': MODEL, 'dimensions': DIMENSIONS, 'encoding_format': 'float', 'input': [r['input'] for r in rows]}


def validate_vector(values):
    if len(values) != DIMENSIONS or any(type(v) not in (int, float) or not math.isfinite(v) for v in values):
        raise ValueError('Invalid section vector')
    norm = math.sqrt(sum(v*v for v in values))
    if not .99 <= norm <= 1.01:
        raise ValueError('Section embedding is not normalized')


def checked(receipt, rows):
    expected = c.sha(json.dumps(payload(rows), ensure_ascii=False))
    body = receipt['response']
    if receipt['requestHash'] != expected or receipt['model'] != MODEL or body['model'] != MODEL:
        raise ValueError('Wrong embedding request or model')
    if sorted(r['index'] for r in body['data']) != list(range(len(rows))):
        raise ValueError('Missing or duplicate response indices')
    if body['usage']['prompt_tokens'] <= 0 or body['usage']['prompt_tokens'] != body['usage']['total_tokens']:
        raise ValueError('Missing embedding usage')
    result = {}
    for row in body['data']:
        validate_vector(row['embedding'])
        packed = struct.pack('<1536f', *row['embedding'])
        validate_vector(struct.unpack('<1536f', packed))
        result[rows[row['index']]['hash']] = base64.b64encode(packed).decode('ascii')
    return result


def execute(command):
    rows = c.sections()
    manifest = {'run': c.RUN, 'model': MODEL, 'dimensions': DIMENSIONS, 'sectionCount': len(rows),
                'sourceHash': c.digest(c.SECTION / 'private/sections.json'), 'batchSize': BATCH,
                'generatorHash': c.digest(c.HERE / 'embed.py'),
                'priceUsdPerMillion': .02, 'priceChecked': '2026-10-17',
                'priceSource': 'https://developers.openai.com/api/docs/models/text-embedding-3-small',
                'requestHashes': [c.sha(json.dumps(payload(rows[i:i+BATCH]), ensure_ascii=False)) for i in range(0, len(rows), BATCH)]}
    if command == 'plan':
        print(json.dumps({'sections': len(rows), 'requests': len(manifest['requestHashes']),
                          'rawVectorBytes512': len(rows)*512*4, 'rawVectorBytes1536': len(rows)*1536*4, 'apiCalls': 0}))
        return
    c.save(c.PUBLIC / 'embedding-manifest.json', manifest, frozen=True)
    vectors, receipts = {}, []
    for start in range(0, len(rows), BATCH):
        batch = rows[start:start+BATCH]
        identity = c.sha(json.dumps(payload(batch), ensure_ascii=False))
        path = c.WORK / 'embedding-cache' / (identity + '.json')
        if path.exists():
            receipt = c.read(path)
        elif command == 'embed':
            receipt = c.call('embeddings', payload(batch), c.RUN, cache_root=c.WORK / 'embedding-cache')
        else:
            raise ValueError('Offline verification found a missing receipt')
        vectors.update(checked(receipt, batch))
        receipts.append({'requestHash': identity, 'receiptHash': c.digest(path),
                         'inputTokens': receipt['response']['usage']['prompt_tokens']})
        print(json.dumps({'verifiedSections': len(vectors), 'total': len(rows)}), flush=True)
    target = c.WORK / 'fixtures/sections-1536.jsonl.gz'
    encoded = ''.join(json.dumps({k: v for k, v in s.items() if k != 'input'} | {'embedding': vectors[s['hash']]}, ensure_ascii=False, separators=(',', ':')) + '\n' for s in rows).encode('utf-8')
    packed = gzip.compress(encoded, mtime=0)
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        if target.read_bytes() != packed:
            raise ValueError('Frozen section package changed')
    elif command == 'embed':
        target.write_bytes(packed)
    else:
        raise ValueError('Missing package')
    c.preserve()
    tokens = sum(r['inputTokens'] for r in receipts)
    result = {'run': c.RUN, 'sectionCount': len(rows), 'postCount': 500, 'dimensions': DIMENSIONS,
              'model': MODEL, 'packageHash': c.digest(target), 'packageBytes': target.stat().st_size,
              'rawVectorBytes': len(rows)*DIMENSIONS*4, 'inputTokens': tokens,
              'embeddingCostUsd': tokens*.02/1e6, 'requests': len(receipts), 'receipts': receipts,
              'productionChanged': False, 'oldVectorsPreserved': True}
    c.save(c.PUBLIC / 'embedding-results.json', result, frozen=True)
    print(json.dumps({k: v for k, v in result.items() if k != 'receipts'}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['plan', 'embed', 'verify'])
    parser.add_argument('--approve-api', action='store_true')
    args = parser.parse_args()
    if args.command == 'embed' and not args.approve_api:
        parser.error('Paid embeddings require --approve-api after user authorization')
    execute(args.command)
