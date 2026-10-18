"""Reuse the frozen grader, excluding explicitly documented technical failures."""
import argparse
import concurrent.futures
import json
import common as c
import grade


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--approve-api', action='store_true', required=True)
    parser.add_argument('--limit', type=int, default=250)
    args = parser.parse_args()
    manifest = c.integrity()
    failures = c.read(c.PUBLIC / 'search-failures.json')
    excluded = {row['questionId'] for row in failures['qualityExclusions']}
    if excluded != {207}:
        raise ValueError('Unexpected quality exclusions require review')
    if (c.WORK / 'results/q207-ask-ai-3.json').exists():
        raise ValueError('Excluded method has a completed result; review required')
    corpus = c.read(c.WORK / 'corpus.json')
    questions = [q for q in c.read(c.PUBLIC / 'questions.json')['questions'][:args.limit] if q['id'] not in excluded]
    grade.SPENT = sum(c.usage_cost(c.read(p)['response'].get('usage', {}))
                      for p in (c.WORK / 'grade-receipts').glob('*.json'))
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        futures = [pool.submit(grade.grade_question, q, corpus, manifest) for q in questions]
        for future in concurrent.futures.as_completed(futures):
            future.result()
    print(json.dumps({'gradingCompleteThrough': args.limit, 'excludedTechnicalFailures': sorted(excluded),
                      'costUsd': grade.SPENT}))


if __name__ == '__main__':
    main()
