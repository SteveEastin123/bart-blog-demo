"""Isolated three-arm review experiment using frozen Ask AI 2 candidates."""

import argparse
import copy
import hashlib
import itertools
import json
from pathlib import Path
import random
import statistics
import sys

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
import agent_work as authoring
import compare_agent as prior
import score_agent as grading
from api import call, configuration, json_response, object_schema
from evaluate import confidence_interval, metrics
from evidence import GRADE_PROMPT, usage_cost

RUN = "metadata-review-2026-10-17"
ROOT = authoring.ROOT
WORK = authoring.HERE / "private" / RUN
PUBLIC = authoring.HERE / "runs" / RUN
ARMS = ("baseline", "topics", "topics-keywords")
LABELS = {"baseline": "Titles + summaries", "topics": "+ Topics", "topics-keywords": "+ Topics + keywords"}
read, save, digest = authoring.read, authoring.save, authoring.digest


def object_hash(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True).encode()).hexdigest()


def prepare():
    old_manifest = prior.integrity()
    corpus = {p["wpId"]: p for p in authoring.source()["posts"]}
    canonical = {str(p["wpId"]): p for p in read(ROOT / "data/index/ehrman_post_search_index.json")}
    questions = read(prior.PUBLIC / "questions.json")["questions"]
    frozen = []
    for q in questions:
        run = read(prior.WORK / "searches" / f"q{q['id']:02}-ask-ai-2.json")
        prior.validate_run(run, q, "ask-ai-2", old_manifest)
        prior.validate_metadata(run, corpus, {})
        candidates = []
        for p in run["candidates"]:
            source = corpus[p["wpId"]]
            for field in ("topics", "secondaryKeywords"):
                if source[field] != canonical[p["wpId"]][field]:
                    raise ValueError("Canonical labels differ from the frozen sample")
                if not all(isinstance(v, str) and v.strip() for v in source[field]):
                    raise ValueError("Invalid topic or keyword labels")
            candidates.append({**p, "topics": source["topics"], "secondaryKeywords": source["secondaryKeywords"]})
        frozen.append({**q, "candidates": candidates, "candidateHash": run["candidateHash"],
                       "embeddingCostUsd": run["usage"][0]["estimated_cost_usd"]})
    reviewer = ROOT / "wordpress-plugin/ehrman-blog-discovery/src/AskAI/AI_Candidate_Reviewer.php"
    data = {"run": RUN, "sampleHash": old_manifest["sampleHash"], "questions": frozen,
            "promptHash": old_manifest["promptHash"], "settingsHash": old_manifest["settingsHash"],
            "reviewerSourceHash": digest(reviewer)}
    save(WORK / "input.json", data, frozen=True)
    save(WORK / "prior-results.json", read(prior.PUBLIC / "comparison-results.json"), frozen=True)
    save(PUBLIC / "questions.json", {"questions": questions}, frozen=True)
    print(json.dumps({"questionsPrepared": len(frozen), "candidatesPerQuestion": 100, "apiCalls": 0}))


def validate_requests(requests, source):
    if len(requests) != 150 or len({r["key"] for r in requests}) != 150:
        raise ValueError("Require 150 distinct requests")
    by_key = {r["key"]: r for r in requests}
    common_settings, enriched_prompts = set(), set()
    for q in source["questions"]:
        base = by_key[f"q{q['id']:02}-baseline"]
        for arm in ARMS:
            row = by_key[f"q{q['id']:02}-{arm}"]
            if row["questionId"] != q["id"] or row["arm"] != arm or row["candidateHash"] != q["candidateHash"]:
                raise ValueError("Request identity mismatch")
            metadata, payload = row["metadata"], row["payload"]
            if len(metadata) != 100:
                raise ValueError("Candidate count changed")
            for expected, actual in zip(q["candidates"], metadata):
                fields = {"id", "title", "summary"}
                if arm != "baseline":
                    fields.add("topics")
                    if actual["topics"] != list(dict.fromkeys(expected["topics"])):
                        raise ValueError("Topics changed or leaked")
                if arm == "topics-keywords":
                    fields.add("secondary_keywords")
                    if actual["secondary_keywords"] != list(dict.fromkeys(expected["secondaryKeywords"])):
                        raise ValueError("Keywords changed or leaked")
                # Production sanitize_text_field collapses whitespace before submission.
                if set(actual) != fields or any(actual[k] != " ".join(expected[k].split()) for k in ("id", "title", "summary")):
                    raise ValueError("Candidate order/content changed or extra fields leaked")
            prefix = f"Reader question:\n{q['question']}\n\nCandidate posts:\n"
            if not payload["input"].startswith(prefix) or json.loads(payload["input"][len(prefix):]) != metadata:
                raise ValueError("Payload input differs from checked metadata")
            if hashlib.sha256(payload["instructions"].encode()).hexdigest() != row["promptHash"]:
                raise ValueError("Prompt hash mismatch")
            if arm == "baseline" and row["promptHash"] != source["promptHash"]:
                raise ValueError("Baseline is not the production prompt")
            if arm != "baseline":
                enriched_prompts.add(payload["instructions"])
            settings = {k: v for k, v in payload.items() if k not in ("input", "instructions")}
            common_settings.add(object_hash(settings))
            if (payload["model"] != "gpt-5.4-mini" or payload["reasoning"] != {"effort": "low"}
                    or payload["max_output_tokens"] != 800 or payload["store"] is not False):
                raise ValueError("Model settings changed")
        if base["metadata"] != [{k: " ".join(p[k].split()) for k in ("id", "title", "summary")} for p in q["candidates"]]:
            raise ValueError("Wrong baseline")
    if len(common_settings) != 1 or len(enriched_prompts) != 1:
        raise ValueError("Settings or enriched instructions vary by arm/question")


def freeze():
    prior.integrity()
    source, requests = read(WORK / "input.json"), read(WORK / "requests.json")
    validate_requests(requests, source)
    paths = [WORK / "input.json", WORK / "requests.json", WORK / "prior-results.json", PUBLIC / "questions.json"]
    paths += sorted(HERE.glob("*.php"))
    paths += sorted((prior.WORK / "searches").glob("q*-ask-ai-2.json"))
    paths += [prior.PUBLIC / "comparison-results.json", prior.PUBLIC / "comparison-adjudications.json"]
    manifest = {"run": RUN, "sampleHash": source["sampleHash"], "sampleCount": 500, "questionCount": 50,
                "arms": list(ARMS), "candidateCount": 100, "reviewerModel": "gpt-5.4-mini", "reviewerReasoning": "low",
                "tieTolerance": .03, "seed": RUN, "graderModel": "gpt-5.4-mini", "graderReasoning": "medium",
                "gradePromptHash": hashlib.sha256(GRADE_PROMPT.encode()).hexdigest(),
                "design": "Freeze original Ask AI 2 ordered candidates, titles and short summaries. Fresh reviews in randomized arm order. Baseline prompt unchanged; enriched arms share one minimal supplementary-label instruction replacement. No retrieval or vector changes.",
                "timingScope": "Direct review API request through complete response; not full website or query latency.",
                "grading": "Common pool includes all prior full-post judgments plus any new final top-ten posts. Reuse verified identical question/post judgments; new judgments blind to arms, ranks, summaries and tags.",
                "pricesChecked": "2026-10-17", "usdPerMillion": {"input": .75, "cachedInput": .075, "output": 4.5},
                "preserved": {str(p.relative_to(ROOT)).replace('\\', '/'): digest(p) for p in paths},
                "runnerAtFreezeHash": digest(HERE / "compare.py"),
                "promptHashes": {arm: next(r["promptHash"] for r in requests if r["arm"] == arm) for arm in ARMS}}
    save(PUBLIC / "manifest.json", manifest, frozen=True)
    print(json.dumps({"frozenRequests": 150, "apiCalls": 0}))


def integrity():
    prior.integrity()
    manifest = read(PUBLIC / "manifest.json")
    for relative, expected in manifest["preserved"].items():
        if digest(ROOT / relative) != expected:
            raise ValueError("Frozen experiment input changed: " + relative)
    return manifest


def validate_receipt(request, receipt):
    expected = hashlib.sha256(json.dumps(request["payload"], ensure_ascii=False).encode()).hexdigest()
    body = receipt["response"]
    if receipt["requestHash"] != expected or receipt["model"] != "gpt-5.4-mini":
        raise ValueError("Receipt belongs to a different request")
    if not body.get("id", "").startswith("resp_") or body.get("status") != "completed":
        raise ValueError("Incomplete or unaudited response; not an empty result")
    if not body.get("model", "").startswith("gpt-5.4-mini") or receipt["seconds"] <= 0:
        raise ValueError("Unexpected model or missing timing")
    u = body["usage"]
    if u["input_tokens"] <= 0 or not 0 <= u.get("input_tokens_details", {}).get("cached_tokens", 0) <= u["input_tokens"]:
        raise ValueError("Invalid usage accounting")
    text = "".join(c.get("text", "") for o in body.get("output", []) for c in o.get("content", []) if c.get("type") == "output_text")
    decoded = json.loads(text)
    selected = decoded.get("selected_posts")
    if not isinstance(selected, list) or len(selected) > 25:
        raise ValueError("Invalid selection")
    ids = {p["id"] for p in request["metadata"]}
    if any(not isinstance(p, dict) or not isinstance(p.get('id'), str) or not isinstance(p.get('relevance_tier'), str) for p in selected):
        raise ValueError('Malformed structured selection')
    # Production ignores unknown IDs/tiers and duplicates; preserve and count them.
    seen, warnings = set(), []
    for p in selected:
        if p['id'] not in ids:
            warnings.append('unknown_id')
        elif p['relevance_tier'] not in ('direct', 'related', 'background'):
            warnings.append('unknown_tier')
        elif p['id'] in seen:
            warnings.append('duplicate_id')
        else:
            seen.add(p['id'])
    return warnings


def run(limit):
    manifest = integrity()
    if not (WORK / "database-before.json").exists():
        raise ValueError("Capture the database fingerprint first")
    if configuration()[1] != manifest["reviewerModel"]:
        raise ValueError("Configured model changed")
    requests = {r["key"]: r for r in read(WORK / "requests.json")}
    receipts = read(WORK / "receipts.json") if (WORK / "receipts.json").exists() else {}
    for q in read(PUBLIC / "questions.json")["questions"][:limit]:
        order = list(ARMS)
        random.Random(manifest["seed"] + str(q["id"])).shuffle(order)
        for arm in order:
            key = f"q{q['id']:02}-{arm}"
            request = requests[key]
            receipt = receipts.get(key)
            if receipt is None:
                receipt = call("responses", request["payload"], key, cache_root=WORK / "review-cache")
            validate_receipt(request, receipt)
            receipts[key] = receipt
            save(WORK / "receipts.json", receipts)
            print(json.dumps({"question": q["id"], "arm": arm, "seconds": round(receipt['seconds'], 2),
                              "reviewCents": round(usage_cost(receipt['response']['usage']) * 100, 4)}), flush=True)
    integrity()


def question_runs(question, parsed, receipts):
    by_id = {p["id"]: p for p in question["candidates"]}
    runs = {}
    for arm in ARMS:
        key = f"q{question['id']:02}-{arm}"
        selected = parsed[key]
        if len(set(selected["post_ids"])) != len(selected["post_ids"]) or not set(selected["post_ids"]) <= set(by_id):
            raise ValueError("Invalid parsed post IDs")
        runs[arm] = {"posts": [by_id[i] for i in selected["post_ids"]], "broaderFallback": selected["broaderFallback"],
                     "submissionToResultsSeconds": receipts[key]["seconds"], "costUsd": usage_cost(receipts[key]["response"]["usage"])}
    return runs


def grade(limit):
    manifest = integrity()
    if configuration()[1] != manifest["graderModel"] or hashlib.sha256(GRADE_PROMPT.encode()).hexdigest() != manifest["gradePromptHash"]:
        raise ValueError("Grader model or rubric changed")
    corpus = {p["wpId"]: p for p in authoring.source()["posts"]}
    previous = {q["id"]: q for q in read(WORK / "prior-results.json")["questions"]}
    parsed, receipts = read(WORK / "parsed.json"), read(WORK / "receipts.json")
    schema = object_schema({"grades": {"type": "array", "items": object_schema({
        "id": {"type": "string"}, "grade": {"type": "integer", "enum": [0, 1, 2, 3]},
        "reason": {"type": "string"}, "evidence": {"type": "string"}})}})
    for full in read(WORK / "input.json")["questions"][:limit]:
        q = {k: full[k] for k in ("id", "question", "group")}
        old = previous[q["id"]]
        if old["question"] != q["question"]:
            raise ValueError("Prior question changed")
        known = {g["wpId"]: g for g in old["grades"]}
        runs = question_runs(full, parsed, receipts)
        ids = set(known) | {p["wpId"] for r in runs.values() for p in r["posts"][:10]}
        path = WORK / "grades" / f"q{q['id']:02}.json"
        if path.exists():
            grading.validate_grades(read(path), q, ids, corpus)
            continue
        grades = [{**row, "origin": "verified_prior_full_post_grade"} for row in known.values()]
        pending = sorted(ids - known.keys(), key=lambda wp: object_hash([RUN, q['id'], wp]))
        for start in range(0, len(pending), 4):
            labels = {f"P{i+1}": wp for i, wp in enumerate(pending[start:start+4])}
            posts = [{"id": label, "title": corpus[wp]["title"], "text": corpus[wp]["text"]} for label, wp in labels.items()]
            for attempt in range(3):
                instructions = GRADE_PROMPT + (" Recheck that every evidence phrase is copied exactly." * attempt)
                output, receipt = json_response(instructions, {"question": q["question"], "posts": posts}, schema,
                                                "blind-metadata-grading", effort="medium", cache_root=WORK / "grading-cache")
                if sorted(row["id"] for row in output["grades"]) != sorted(labels):
                    raise ValueError("Grader duplicated or omitted a label")
                batch = []
                try:
                    for row in output["grades"]:
                        wp = labels[row["id"]]
                        grading.check_grade(row, corpus[wp]["text"], strict=True)
                        batch.append({**row, "wpId": wp, "textHash": corpus[wp]["textHash"], "origin": "new_blinded_full_post_grade",
                                      "model": receipt["model"], "requestHash": receipt["requestHash"], "evidenceVerified": True})
                except ValueError:
                    if attempt == 2:
                        raise
                    continue
                grades.extend(batch)
                break
        result = {**q, "poolHash": grading.pool_identity(q, ids), "grades": grades}
        grading.validate_grades(result, q, ids, corpus)
        save(path, result, frozen=True)
        print(json.dumps({"gradedQuestion": q['id'], "reused": len(known), "new": len(pending)}), flush=True)
    integrity()


def pairwise(rows, first, second, version="metrics"):
    differences = [q['versions'][second][version]['ndcgAt10'] - q['versions'][first][version]['ndcgAt10'] for q in rows]
    return {"first": first, "second": second, "firstWins": sum(d < -.03 for d in differences),
            "secondWins": sum(d > .03 for d in differences), "ties": sum(abs(d) <= .03 for d in differences),
            "ndcgDifference": confidence_interval(differences)}


def report():
    manifest = integrity()
    source = read(WORK / "input.json")
    corpus = {p['wpId']: p for p in authoring.source()['posts']}
    parsed, receipts = read(WORK / 'parsed.json'), read(WORK / 'receipts.json')
    requests = read(WORK / 'requests.json')
    if set(receipts) != {r['key'] for r in requests} or set(parsed) != set(receipts):
        raise ValueError('Require all 150 responses and parsed selections')
    response_warnings = {r['key']: validate_receipt(r, receipts[r['key']]) for r in requests}
    if len({r['response']['id'] for r in receipts.values()}) != 150:
        raise ValueError('Response reused across different arms or questions')
    changes_path = PUBLIC / 'adjudications.json'
    changes = read(changes_path) if changes_path.exists() else {'checks': [], 'overrides': []}
    overrides = {(r['questionId'], r['wpId']): r for r in changes['overrides']}
    if len(overrides) != len(changes['overrides']):
        raise ValueError('Duplicate adjudication')
    applied, rows = set(), []
    previous = {q['id']: q for q in read(WORK / 'prior-results.json')['questions']}
    for full in source['questions']:
        q = {k: full[k] for k in ('id', 'question', 'group')}
        judged = read(WORK / 'grades' / f"q{q['id']:02}.json")
        runs = question_runs(full, parsed, receipts)
        ids = {g['wpId'] for g in previous[q['id']]['grades']} | {p['wpId'] for r in runs.values() for p in r['posts'][:10]}
        grading.validate_grades(judged, q, ids, corpus)
        before = {g['wpId']: g['grade'] for g in judged['grades']}
        for g in judged['grades']:
            identity = (q['id'], g['wpId'])
            if identity in overrides:
                change = overrides[identity]
                if g['grade'] != change['originalGrade']:
                    raise ValueError('Correction does not match original grade')
                g['beforeMetadataAdjudication'] = copy.deepcopy(g)
                g.update({k: change[k] for k in ('grade', 'reason', 'evidence')})
                grading.check_grade(g, corpus[g['wpId']]['text'], strict=True)
                applied.add(identity)
        grades = {g['wpId']: g['grade'] for g in judged['grades']}
        versions = {}
        for arm, run in runs.items():
            key = f"q{q['id']:02}-{arm}"
            usage = receipts[key]['response']['usage']
            values = metrics(run, grades)
            values.update({'estimatedQuestionCostUsd': run['costUsd'] + full['embeddingCostUsd'],
                           'inputTokens': usage['input_tokens'], 'cachedTokens': usage.get('input_tokens_details', {}).get('cached_tokens', 0),
                           'outputTokens': usage['output_tokens'], 'uncachedReviewCostUsd': (usage['input_tokens'] * .75 + usage['output_tokens'] * 4.5) / 1e6})
            versions[arm] = {'metrics': values, 'beforeAdjudication': metrics(run, before),
                             'posts': [{'rank': i+1, 'wpId': p['wpId'], 'title': p['title'], 'url': p['url'], 'grade': grades[p['wpId']]}
                                       for i, p in enumerate(run['posts'][:10])]}
        rows.append({**q, 'versions': versions, 'grades': judged['grades']})
    if applied != set(overrides):
        raise ValueError('Unused correction')
    summary = {arm: {k: statistics.mean(q['versions'][arm]['metrics'][k] for q in rows)
                     for k in rows[0]['versions'][arm]['metrics']} for arm in ARMS}
    before_db, after_db = (read(WORK / f'database-{phase}.json')['tables'] for phase in ('before', 'after'))
    tables = [name for name in before_db if name.startswith('wp_ehrman_')]
    if len(tables) != 19 or any(any(before_db[n][k] != after_db[n][k] for k in ('rows', 'sha256')) for n in tables):
        raise ValueError('Discovery database changed')
    review_cache = [read(p) for p in (WORK / 'review-cache').glob('*.json')]
    grade_cache = [read(p) for p in (WORK / 'grading-cache').glob('*.json')]
    accepted_cost = sum(usage_cost(r['response']['usage']) for r in receipts.values())
    all_review_cost = sum(usage_cost(r['response'].get('usage', {})) for r in review_cache)
    grade_cost = sum(usage_cost(r['response'].get('usage', {})) for r in grade_cache)
    pairs = [pairwise(rows, a, b) for a, b in itertools.combinations(ARMS, 2)]
    flagged = {int(key.split('-')[0][1:]) for key, warnings in response_warnings.items() if warnings}
    flagged |= {q['id'] for q in rows if any(q['versions'][a]['metrics']['fallback'] for a in ARMS)}
    unflagged = [q for q in rows if q['id'] not in flagged]
    data = {'run': RUN, 'manifest': manifest, 'summary': summary, 'pairwise': pairs,
            'beforeAdjudication': [pairwise(rows, a, b, 'beforeAdjudication') for a, b in itertools.combinations(ARMS, 2)],
            'adjudications': changes, 'questions': rows, 'discoveryTablesUnchanged': len(tables),
            'responseWarnings': {k: v for k, v in response_warnings.items() if v},
            'postHocUnflaggedSensitivity': {
                'scope': 'Diagnostic only: exclude whole paired questions with any discarded selection or fallback. All 50 questions remain in the primary analysis; this subset is outcome-dependent, not confirmatory.',
                'excludedQuestionIds': sorted(flagged), 'retainedQuestions': len(unflagged),
                'pairwise': [pairwise(unflagged, a, b) for a, b in itertools.combinations(ARMS, 2)]},
            'gradedPairs': sum(len(q['grades']) for q in rows),
            'newGrades': sum(g['origin'] == 'new_blinded_full_post_grade' for q in rows for g in q['grades']),
            'costs': {'acceptedReviewsUsd': accepted_cost, 'allReviewAttemptsUsd': all_review_cost, 'gradingUsd': grade_cost,
                      'totalUsd': all_review_cost + grade_cost, 'reviewCalls': len(review_cache), 'gradingCalls': len(grade_cache)}}
    save(PUBLIC / 'results.json', data)
    print(json.dumps({k: data[k] for k in ('summary', 'pairwise', 'gradedPairs', 'newGrades', 'costs')}, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['prepare', 'freeze', 'verify', 'run', 'grade', 'report'])
    parser.add_argument('--limit', type=int, default=50)
    parser.add_argument('--approve-api', action='store_true')
    args = parser.parse_args()
    if args.command in ('run', 'grade') and not args.approve_api:
        parser.error('Paid requests require explicit user authorization and --approve-api')
    if not 1 <= args.limit <= 50:
        parser.error('Limit must be 1 to 50')
    if args.command in ('run', 'grade'):
        {'run': run, 'grade': grade}[args.command](args.limit)
    elif args.command == 'verify':
        print(json.dumps({'unchanged': True, 'run': integrity()['run']}))
    else:
        {'prepare': prepare, 'freeze': freeze, 'report': report}[args.command]()
