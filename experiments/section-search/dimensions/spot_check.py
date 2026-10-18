"""Prepare deterministic, method-blind full-post checks before aggregate scoring."""

import argparse

import common as c
import score


def select():
    c.integrity()
    previous = {q['id']: q for q in c.read(c.WORK / 'prior-results.json')['questions']}
    pairs = []
    for q in c.read(c.PUBLIC / 'questions.json')['questions']:
        known = {g['wpId'] for g in previous[q['id']]['grades']}
        for wp in score.pool_ids(previous[q['id']], score.runs(q)) - known:
            pairs.append({'questionId': q['id'], 'wpId': wp, 'question': q['question']})
    pairs.sort(key=lambda p: c.sha(c.RUN + ':blind-check:' + str(p['questionId']) + ':' + p['wpId']))
    selected, questions = [], set()
    for p in pairs:
        if p['questionId'] not in questions:
            selected.append(p)
            questions.add(p['questionId'])
        if len(selected) == 8:
            break
    if len(selected) != 8:
        raise ValueError('Need eight distinct questions with newly judged posts')
    c.save(c.WORK / 'spot-selection.json', {'selection': 'First eight SHA-256 ordered new pairs, distinct questions; no method, rank, model grade or aggregate metric used.', 'pairs': selected}, frozen=True)
    print('Selected eight new pairs from distinct questions.')


def show(start, count):
    corpus = {p['wpId']: p for p in c.authoring.source()['posts']}
    rows = c.read(c.WORK / 'spot-selection.json')['pairs']
    for i, p in enumerate(rows[start:start + count], start=start + 1):
        post = corpus[p['wpId']]
        print(f"CHECK {i}; question {p['questionId']}; post {p['wpId']}")
        print(p['question'])
        print(post['title'])
        print(post['text'])
        print()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['select', 'show'])
    parser.add_argument('--start', type=int, default=0)
    parser.add_argument('--count', type=int, default=2)
    args = parser.parse_args()
    select() if args.command == 'select' else show(args.start, args.count)
