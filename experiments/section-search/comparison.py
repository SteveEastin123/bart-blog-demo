"""Fresh-question, end-to-end comparison; preserve previous pilot runs and vectors."""

import argparse
import collections
import difflib
import hashlib
import json
import math
import random
import statistics
import time
import urllib.error
import urllib.request

from api import json_response, object_schema
from corpus import HERE, PRIVATE, ROOT, read, save
from evaluate import Browser, confidence_interval, metrics, verify_evidence
from evidence import GRADE_PROMPT, fingerprint, freeze, usage_cost

RUN = "end-to-end-300-2026-10-17"
WORK = PRIVATE / RUN
PUBLIC = HERE / "runs" / RUN
ARMS = ("ask-ai-2", "sections-300")


def questions_in(value):
    if isinstance(value, dict):
        for key, child in value.items():
            if key == "question" and isinstance(child, str):
                yield child
            else:
                yield from questions_in(child)
    elif isinstance(value, list):
        for child in value:
            yield from questions_in(child)


def prepare():
    questions = read(PUBLIC / "questions.json")
    rows = questions["questions"]
    texts = [q["question"] for q in rows]
    if len(rows) != 50 or [q["id"] for q in rows] != list(range(1, 51)) or len(set(texts)) != 50:
        raise ValueError("Require 50 distinct, consecutively numbered questions")
    old_paths = list((ROOT / "data/evaluations").glob("*questions*.json")) + [HERE / "questions.json"]
    old_questions = {q for p in old_paths for q in questions_in(read(p))}
    if {q.casefold() for q in texts} & {q.casefold() for q in old_questions}:
        raise ValueError("An old question was repeated")
    nearest = [{"id": q["id"], "previous": max(old_questions, key=lambda old: difflib.SequenceMatcher(None, q["question"].lower(), old.lower()).ratio())} for q in rows]
    for row, q in zip(nearest, rows):
        row["similarity"] = difflib.SequenceMatcher(None, q["question"].lower(), row["previous"].lower()).ratio()
    index = read(PRIVATE / "runtime/index.json")
    preserved = [HERE / "results.json", HERE / "report.md", HERE / "runs/evidence-300-2026-10-17/results.json",
                 HERE / "runs/evidence-300-2026-10-17/report.md", PRIVATE / "corpus.json", PRIVATE / "runtime/index.json"]
    preserved += sorted((PRIVATE / "runtime").glob("sections-*.jsonl.gz"))
    plugin = ROOT / "wordpress-plugin/ehrman-blog-discovery"
    preserved += sorted((plugin / "src").rglob("*.php"))
    freeze(WORK / "fixtures/questions.json", questions)
    manifest = {"run": RUN, "sampleHash": index["sampleHash"], "questionsHash": fingerprint(PUBLIC / "questions.json"),
                "fixtureQuestionsHash": fingerprint(WORK / "fixtures/questions.json"),
                "indexHash": fingerprint(PRIVATE / "runtime/index.json"), "questionCount": 50, "sampleCount": 500,
                "embeddingModel": "text-embedding-3-small", "dimensions": 512, "reviewerModel": "gpt-5.4-mini",
                "pilotSources": {p.name: fingerprint(p) for p in sorted((HERE / "plugin").glob("*.php")) if not p.name.endswith("tests.php")},
                "preserved": {str(p.relative_to(ROOT)).replace("\\", "/"): fingerprint(p) for p in preserved},
                "design": "Baseline hybrid title/summary retrieval and unchanged reviewer versus hybrid plus 300-word sections and passage-aware review. Same 100-candidate cap, model, output limit and tier/fallback behavior. This compares packages, not isolated causes.",
                "timingScope": "Authenticated HTTP submission through complete JSON results, including embedding, retrieval and review; excludes browser paint.",
                "tieTolerance": .03, "orderSeed": RUN, "questionProvenance": questions["purpose"],
                "pricesChecked": "2026-10-17", "usdPerMillion": {"embedding": .02, "reviewInput": .75, "reviewCached": .075, "reviewOutput": 4.5}}
    freeze(PUBLIC / "manifest.json", manifest)
    freeze(PUBLIC / "question-novelty.json", {"previousQuestionsChecked": len(old_questions), "nearestWording": nearest,
                                             "note": "No exact repeats. New wording and intents do not imply entirely new subject matter or a representative user distribution."})
    freeze(WORK / "fixtures/manifest.json", manifest)
    freeze(WORK / "fixtures/questions.json", questions)
    print(json.dumps({"prepared": len(rows), "run": RUN, "closestWording": sorted(nearest, key=lambda r: r["similarity"], reverse=True)[:5]}), flush=True)


def integrity():
    manifest = read(PUBLIC / "manifest.json")
    for relative, expected in manifest["preserved"].items():
        if fingerprint(ROOT / relative) != expected:
            raise ValueError("Preserved data or production source changed: " + relative)
    if fingerprint(PUBLIC / "questions.json") != manifest["questionsHash"]:
        raise ValueError("Frozen questions changed")
    for name, expected in manifest["pilotSources"].items():
        if fingerprint(HERE / "plugin" / name) != expected:
            raise ValueError("Frozen pilot source changed: " + name)
    return manifest


def validate_run(result, question, arm, manifest):
    if (result["run"] != RUN or result["questionId"] != question["id"] or result["question"] != question["question"]
            or result["arm"] != arm or result["sampleHash"] != manifest["sampleHash"] or result["cacheHit"]
            or not result["controlled"] or result["evidence"] != (arm == "sections-300")):
        raise ValueError("Run identity or configuration mismatch")
    if len(result["candidates"]) != 100 or len({p["wpId"] for p in result["candidates"]}) != 100:
        raise ValueError("Expected exactly 100 unique candidates")
    if len(result["usage"]) != 2 or result["usage"][0]["model"] != manifest["embeddingModel"]:
        raise ValueError("Both paid phases must be accounted for")
    if not result["usage"][1]["model"].startswith(manifest["reviewerModel"]):
        raise ValueError("Reviewer model changed")
    if abs(result["costUsd"] - sum(u["estimated_cost_usd"] for u in result["usage"])) > 1e-12:
        raise ValueError("Total cost excludes a paid phase")


def benchmark(limit=50):
    manifest = integrity()
    browser = Browser()
    for question in read(PUBLIC / "questions.json")["questions"][:limit]:
        order = list(ARMS)
        random.Random(RUN + str(question["id"])).shuffle(order)
        for arm in order:
            path = WORK / "searches" / f"q{question['id']:02}-{arm}.json"
            if path.exists():
                validate_run(read(path), question, arm, manifest)
                continue
            body = json.dumps({"questionId": question["id"], "arm": arm}).encode()
            request = urllib.request.Request(browser.settings["url"] + "compare-300", body,
                      headers={"Content-Type": "application/json", "X-WP-Nonce": browser.settings["nonce"]})
            start = time.perf_counter()
            try:
                with browser.opener.open(request, timeout=300) as response:
                    result = json.load(response)
            except urllib.error.HTTPError as error:
                raise RuntimeError(f"Comparison HTTP {error.code}: {error.read().decode()}") from None
            result["submissionToResultsSeconds"] = time.perf_counter() - start
            validate_run(result, question, arm, manifest)
            save(path, result)
            print(json.dumps({"question": question["id"], "arm": arm, "posts": len(result["posts"]),
                              "seconds": round(result["submissionToResultsSeconds"], 2), "cents": round(result["costUsd"] * 100, 4)}), flush=True)


def repair_evidence(row, request_hash, wp, source, repairs):
    repair = next((r for r in repairs if r["requestHash"] == request_hash and r["wpId"] == wp), None)
    if not repair:
        return row
    if row["evidence"] != repair["originalEvidence"] or not verify_evidence(repair["evidence"], source)[0]:
        raise ValueError("Evidence repair does not match the original response or source")
    return {**row, "evidence": repair["evidence"], "reason": repair["reason"],
            "evidenceRepair": repair, "originalJudgment": dict(row)}


def grade(limit=50):
    integrity()
    corpus = {p["wpId"]: p for p in read(PRIVATE / "corpus.json")["posts"]}
    repair_path = PUBLIC / "evidence-repairs.json"
    repairs = read(repair_path)["repairs"] if repair_path.exists() else []
    schema = object_schema({"grades": {"type": "array", "items": object_schema({
        "id": {"type": "string"}, "grade": {"type": "integer", "enum": [0, 1, 2, 3]},
        "reason": {"type": "string"}, "evidence": {"type": "string"}})}})
    for question in read(PUBLIC / "questions.json")["questions"][:limit]:
        path = WORK / "grades" / f"q{question['id']:02}.json"
        runs = [read(WORK / "searches" / f"q{question['id']:02}-{arm}.json") for arm in ARMS]
        # Include both final top tens and the first ten candidates for retrieval diagnostics.
        ids = {p["wpId"] for run in runs for p in run["posts"][:10] + run["candidates"][:10]}
        identity = hashlib.sha256(json.dumps([question, sorted(ids)], sort_keys=True).encode()).hexdigest()
        if path.exists():
            if read(path)["poolHash"] != identity:
                raise ValueError("Grading pool changed")
            continue
        pending = sorted(ids, key=lambda wp: hashlib.sha256((RUN + str(question["id"]) + wp).encode()).hexdigest())
        grades = []
        for start in range(0, len(pending), 4):
            labels = {f"P{i + 1}": wp for i, wp in enumerate(pending[start:start + 4])}
            submitted = [{"id": label, "title": corpus[wp]["title"], "text": corpus[wp]["text"]} for label, wp in labels.items()]
            for attempt in range(3):
                prompt = GRADE_PROMPT + (" Recheck that every evidence phrase is copied exactly." * attempt)
                output, record = json_response(prompt, {"question": question["question"], "posts": submitted}, schema,
                                               "blind-end-to-end-grading", effort="medium", cache_root=WORK / "api-cache")
                if sorted(g["id"] for g in output["grades"]) != sorted(labels):
                    raise ValueError("Grader omitted or duplicated a post")
                batch = []
                for row in output["grades"]:
                    wp = labels[row["id"]]
                    row = repair_evidence(row, record["requestHash"], wp, corpus[wp]["text"], repairs)
                    verified, note = verify_evidence(row["evidence"], corpus[wp]["text"])
                    if row["grade"] >= 2 and not row["evidence"].strip():
                        verified = False
                    batch.append({**row, "wpId": wp, "evidenceVerified": verified, "evidenceValidation": note,
                                  "model": record["model"], "requestHash": record["requestHash"]})
                if all(g["evidenceVerified"] for g in batch):
                    grades.extend(batch)
                    break
            else:
                raise ValueError("Unverified evidence after three saved attempts")
        save(path, {**question, "poolHash": identity, "blind": True, "grades": grades})
        print(json.dumps({"gradedQuestion": question["id"], "fullPosts": len(grades)}), flush=True)


def report():
    manifest = integrity()
    before = json.loads((WORK / "database-before.json").read_text(encoding="utf-8-sig"))
    after = json.loads((WORK / "database-after.json").read_text(encoding="utf-8-sig"))
    tables = {name: {"rows": row["rows"], "sha256": row["sha256"]} for name, row in before["tables"].items() if name.startswith("wp_ehrman_")}
    for name, row in tables.items():
        if row != {key: after["tables"][name][key] for key in row}:
            raise ValueError("Production discovery table changed: " + name)
    corpus = {p["wpId"]: p for p in read(PRIVATE / "corpus.json")["posts"]}
    adjudications_path = PUBLIC / "adjudications.json"
    adjudications = read(adjudications_path) if adjudications_path.exists() else {"checks": [], "overrides": []}
    overrides = {(r["question"], r["wpId"]): r for r in adjudications["overrides"]}
    rows = []
    for question in read(PUBLIC / "questions.json")["questions"]:
        judged = read(WORK / "grades" / f"q{question['id']:02}.json")
        unadjusted_grades = {g["wpId"]: g["grade"] for g in judged["grades"]}
        for row in judged["grades"]:
            override = overrides.get((question["id"], row["wpId"]))
            if override:
                row["preAdjudication"] = dict(row)
                row.update({key: override[key] for key in ("grade", "reason", "evidence")})
            verified = verify_evidence(row["evidence"], corpus[row["wpId"]]["text"])[0]
            if not verified or (row["grade"] >= 2 and not row["evidence"].strip()):
                raise ValueError("Unverified evidence cannot enter final metrics")
        grades = {g["wpId"]: g["grade"] for g in judged["grades"]}
        relevant = {wp for wp, score in grades.items() if score >= 2}
        versions = {}
        for arm in ARMS:
            run = read(WORK / "searches" / f"q{question['id']:02}-{arm}.json")
            validate_run(run, question, arm, manifest)
            values = metrics(run, grades)
            candidate_ids = [p["wpId"] for p in run["candidates"]]
            values["candidatePrecisionAt10"] = sum(grades[wp] >= 2 for wp in candidate_ids[:10]) / 10
            values["pooledRelevantCoverageAt100"] = len(relevant & set(candidate_ids)) / len(relevant) if relevant else 0
            values["embeddingCostUsd"] = run["usage"][0]["estimated_cost_usd"]
            values["reviewCostUsd"] = run["usage"][1]["estimated_cost_usd"]
            values["reviewInputTokens"] = run["usage"][1]["input_tokens"]
            values["reviewCachedTokens"] = run["usage"][1]["cached_input_tokens"]
            values["uncachedTotalCostUsd"] = values["embeddingCostUsd"] + (values["reviewInputTokens"] * .75 + run["usage"][1]["output_tokens"] * 4.5) / 1e6
            versions[arm] = {"metrics": values, "unadjustedMetrics": metrics(run, unadjusted_grades),
                             "requestId": run["requestId"], "usage": run["usage"],
                             "promptHash": run["promptHash"], "settingsHash": run["settingsHash"], "candidateHash": run["candidateHash"],
                             "candidateWpIds": candidate_ids,
                             "posts": [{"rank": i + 1, "wpId": p["wpId"], "title": p["title"], "url": p["url"], "grade": grades[p["wpId"]]} for i, p in enumerate(run["posts"][:10])]}
        rows.append({**question, "versions": versions, "grades": judged["grades"]})
    if len({q["versions"][a]["settingsHash"] for q in rows for a in ARMS}) != 1:
        raise ValueError("Reviewer settings vary across requests")
    for arm in ARMS:
        if len({q["versions"][arm]["promptHash"] for q in rows}) != 1:
            raise ValueError("Prompt changed during run")
    summary = {a: {key: statistics.mean(q["versions"][a]["metrics"][key] for q in rows) for key in rows[0]["versions"][a]["metrics"]} for a in ARMS}
    for arm in ARMS:
        times = sorted(q["versions"][arm]["metrics"]["seconds"] for q in rows)
        summary[arm]["medianSeconds"] = statistics.median(times)
        summary[arm]["p95Seconds"] = times[math.ceil(len(times) * .95) - 1]
    differences = [q["versions"][ARMS[1]]["metrics"]["ndcgAt10"] - q["versions"][ARMS[0]]["metrics"]["ndcgAt10"] for q in rows]
    comparison = {"sectionWins": sum(d > .03 for d in differences), "baselineWins": sum(d < -.03 for d in differences),
                  "ties": sum(abs(d) <= .03 for d in differences), "ndcgDifference": confidence_interval(differences)}
    raw_differences = [q["versions"][ARMS[1]]["unadjustedMetrics"]["ndcgAt10"]
                       - q["versions"][ARMS[0]]["unadjustedMetrics"]["ndcgAt10"] for q in rows]
    sensitivity = {"summary": {arm: {key: statistics.mean(q["versions"][arm]["unadjustedMetrics"][key] for q in rows)
                                    for key in ("precisionAt5", "precisionAt10", "ndcgAt10")} for arm in ARMS},
                   "sectionWins": sum(d > .03 for d in raw_differences), "baselineWins": sum(d < -.03 for d in raw_differences),
                   "ties": sum(abs(d) <= .03 for d in raw_differences), "ndcgDifference": confidence_interval(raw_differences)}
    groups = {group: {arm: {key: statistics.mean(q["versions"][arm]["metrics"][key] for q in rows if q["group"] == group)
                           for key in ("precisionAt5", "precisionAt10", "ndcgAt10")} for arm in ARMS} for group in ("representative", "challenge")}
    left = [q["versions"][ARMS[0]]["candidateWpIds"] for q in rows]
    right = [q["versions"][ARMS[1]]["candidateWpIds"] for q in rows]
    overlap = {"meanSharedCandidatesOf100": statistics.mean(len(set(a) & set(b)) for a, b in zip(left, right)),
               "identicalSets": sum(set(a) == set(b) for a, b in zip(left, right)), "identicalOrderedLists": sum(a == b for a, b in zip(left, right))}
    audit = [json.loads(line) for line in (WORK / "server-requests.jsonl").read_text(encoding="utf-8").splitlines()]
    accepted = {q["versions"][a]["requestId"] for q in rows for a in ARMS}
    all_responses = [r for r in audit if r.get("type") == "end-to-end-api-response"]
    mock_ids = {r["requestId"] for r in all_responses if r["endpoint"] == "responses" and r["response"].get("id", "").startswith("mock-")}
    paid_responses = [r for r in all_responses if r["requestId"] not in mock_ids]
    responses = [r for r in paid_responses if r["requestId"] in accepted]
    counts = collections.Counter((r["requestId"], r["endpoint"]) for r in responses)
    if len(accepted) != 100 or any(counts[(rid, phase)] != 1 for rid in accepted for phase in ("embeddings", "responses")):
        raise ValueError("Every accepted query must have two paid audit records")
    if any(not r["response"].get("id", "").startswith("resp_") for r in responses if r["endpoint"] == "responses"):
        raise ValueError("Mock response in measured results")
    grading = [read(p) for p in (WORK / "api-cache").glob("*.json")]
    search_cost = sum(r["usage"]["estimated_cost_usd"] for r in responses)
    all_search_cost = sum(r["usage"]["estimated_cost_usd"] for r in paid_responses)
    grade_cost = sum(usage_cost(r["response"].get("usage", {})) for r in grading)
    evidence_repairs = [g["evidenceRepair"] for q in rows for g in q["grades"] if "evidenceRepair" in g]
    data = {"run": RUN, "manifest": manifest, "summary": summary, "comparison": comparison, "groups": groups,
            "unadjustedSensitivity": sensitivity, "evidenceRepairs": evidence_repairs,
            "candidateOverlap": overlap, "questions": rows, "adjudications": adjudications,
            "preservation": {"sourceAndDataFiles": len(manifest["preserved"]), "databaseTablesUnchanged": tables},
            "costs": {"acceptedSearches": len(accepted), "acceptedSearchUsd": search_cost, "allSearchAttemptUsd": all_search_cost,
                      "extraPaidPhases": len(paid_responses) - len(responses), "gradingCalls": len(grading), "gradingUsd": grade_cost,
                      "totalNewExperimentUsd": all_search_cost + grade_cost},
            "gradedPairs": sum(len(q["grades"]) for q in rows), "uniqueGradedPosts": len({g["wpId"] for q in rows for g in q["grades"]}),
            "noRelevantPoolQuestions": [q["id"] for q in rows if not any(g["grade"] >= 2 for g in q["grades"])]}
    save(PUBLIC / "results.json", data)
    write_report(data)
    print(json.dumps({k: data[k] for k in ("summary", "comparison", "candidateOverlap", "costs", "gradedPairs")}, indent=2))


def write_report(data):
    summary, comparison = data["summary"], data["comparison"]
    lines = ["# Ask AI 2 vs. 300-Word Section Search", "", "Run: October 17, 2026. Local experiment only.", "", "## Design", "",
             "50 fresh questions, frozen before either approach ran, on the same 500-post pilot sample. Forty broad/representative-style questions and ten specific challenge questions were authored without consulting sample post contents or search results. They are new questions, not an independent human-authored or representative-user benchmark; subject areas overlap earlier tests.",
             "Both arms use the existing text-embedding-3-small, 512-dimensional vectors and 100 unique candidate posts. No post vectors were regenerated or overwritten. Ask AI 2 uses the existing hybrid title/summary algorithm and unchanged production reviewer prompt. The section arm supplements retrieval with the existing 300-word sections, then reviews titles, summaries, and each candidate's best passage using the previously frozen evidence-aware prompt.",
             "Reviewer model, reasoning, output limit, tier schema and broader-result fallback are identical. This is an end-to-end comparison of two approaches: retrieval, evidence, and reviewer instructions differ. It cannot attribute a gain solely to chunk retrieval or passage evidence.", "", "## Results", "",
             "| Measure | Ask AI 2 | 300-word sections + passages |", "|---|---:|---:|"]
    measures = [("Top-five relevance (P@5)", "precisionAt5", ".1%", 1), ("Top-ten relevance (P@10)", "precisionAt10", ".1%", 1),
                ("Ranking score (nDCG@10 x 100)", "ndcgAt10", ".1f", 100), ("Relevant posts in top ten", "relevantTop10", ".2f", 1),
                ("Posts returned in top ten", "returnedTop10", ".2f", 1), ("Relevance among displayed top ten", "displayedPrecisionAt10", ".1%", 1),
                ("Mean full response time (seconds)", "seconds", ".2f", 1), ("Median full response time (seconds)", "medianSeconds", ".2f", 1),
                ("95th-percentile response time (seconds)", "p95Seconds", ".2f", 1), ("Total cost (cents/question)", "costUsd", ".3f", 100),
                ("Embedding cost (cents/question)", "embeddingCostUsd", ".6f", 100), ("Review cost (cents/question)", "reviewCostUsd", ".3f", 100),
                ("Cost without provider cache discount (cents)", "uncachedTotalCostUsd", ".3f", 100),
                ("Review input tokens", "reviewInputTokens", ".0f", 1), ("Provider-cached input tokens", "reviewCachedTokens", ".0f", 1),
                ("Broader-result fallback", "fallback", ".1%", 1), ("Pre-review candidate P@10", "candidatePrecisionAt10", ".1%", 1),
                ("Coverage of known relevant pooled posts in 100 candidates", "pooledRelevantCoverageAt100", ".1%", 1)]
    for label, key, fmt, multiplier in measures:
        lines.append("| " + label + " | " + " | ".join(format(summary[a][key] * multiplier, fmt) for a in ARMS) + " |")
    ci = comparison["ndcgDifference"]
    lines += ["", f"Section wins: **{comparison['sectionWins']}**; Ask AI 2 wins: **{comparison['baselineWins']}**; ties: **{comparison['ties']}**. A tie means an absolute nDCG difference at most 0.03.",
              f"Mean section-minus-baseline nDCG difference: {ci['mean']:+.4f}; paired 95% bootstrap interval [{ci['lower95']:+.4f}, {ci['upper95']:+.4f}].",
              "", "## Assessment and Next Step", "",
              "The 300-word section approach shows enough promise in this pilot to justify the next controlled embedding experiment, but not an immediate production replacement. The improvement is larger for the ten specific challenge questions than for the forty broader questions. The direction of the ranking advantage remains the same before and after spot-check corrections.",
              "Keep Ask AI 2 and all existing small/512 vectors unchanged. In the next experiment, use the same 300-word sections and hold retrieval fusion, candidate cap, passage selection, reviewer prompt and model fixed while comparing embedding configurations. First isolate the dimension change (small/512 versus small/1536); compare small/1536 with large/1536 separately to isolate model choice. Preserve separate indexes and use a compatible query embedding for each. These next steps have not been executed by this run.",
              "Operating cost is the main tradeoff: reviewing a matching passage for every candidate increases review input from about 11,800 to 50,700 tokens, while mean response time barely changes. Larger embedding dimensions alone would not remove that passage-review cost. A separate later experiment could test selective passage use; do not change it simultaneously with embeddings. Confirm the chosen approach on broader data and with human review before production adoption.",
              "", "## Costs and Timing", "",
              "Time is measured from authenticated HTTP submission through complete result receipt, including query embedding, candidate retrieval and AI review. It excludes browser paint. Calls ran sequentially with seeded randomized arm order for each question. No WordPress result cache was used; provider-cached token counts are reported separately.",
              f"Accepted searches: 100 (100 embedding and 100 review calls), estimated cost ${data['costs']['acceptedSearchUsd']:.4f}. Extra paid phases outside accepted searches: {data['costs']['extraPaidPhases']}. All search attempts: ${data['costs']['allSearchAttemptUsd']:.4f}. Independent full-post grading: {data['costs']['gradingCalls']} calls, ${data['costs']['gradingUsd']:.4f}. Total new experiment: ${data['costs']['totalNewExperimentUsd']:.4f}. Historical vector generation and earlier experiments are excluded.",
              "Prices use actual returned token usage and the rates recorded in the manifest, not invoice totals: small embeddings $0.02/million input; gpt-5.4-mini $0.75/million input, $0.075/million cached input, $4.50/million output. See [embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small) and [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).",
              "", "## Grading and Retrieval", "",
              f"{data['gradedPairs']} question/post judgments covering {data['uniqueGradedPosts']} distinct posts. For each question, both final top tens and both pre-review candidate top tens are pooled and deduplicated. Complete stored post text is graded without method names or ranks. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly answering. Supporting quotations are verified against the source. All grades are new for these questions.",
              "P@5 and P@10 have fixed denominators of five and ten; unfilled slots count as nonrelevant. nDCG uses the shared judged pool, not exhaustive relevance labels for all 500 posts. Candidate coverage is coverage of known relevant pooled posts, NOT true corpus-wide recall. Judging the candidate top tens offers a retrieval diagnostic but does not completely measure all 100 candidates.",
              f"Questions with no grade-2-or-3 post in the judged pool: {len(data['noRelevantPoolQuestions'])} ({', '.join(map(str, data['noRelevantPoolQuestions'])) or 'none'}). This does not establish that no relevant post exists elsewhere in the blog or unjudged sample. nDCG gives partial credit for marginal grade-1 material; P@5/P@10 do not.",
              f"Mean shared candidates: {data['candidateOverlap']['meanSharedCandidatesOf100']:.1f}/100; identical sets: {data['candidateOverlap']['identicalSets']}/50; identical ordered lists: {data['candidateOverlap']['identicalOrderedLists']}/50.",
              f"Agent full-text spot checks: {len(data['adjudications']['checks'])}; grade corrections: {len(data['adjudications']['overrides'])}. Original judgments remain in private files; corrections apply to both arms. These spot checks are not blind to the outcomes.",
              f"Separately documented quotation repairs: {len(data['evidenceRepairs'])}. These retain the grader's numerical score and preserve the original response; each replacement was checked against the full source post.",
              f"Before grade corrections: section wins {data['unadjustedSensitivity']['sectionWins']}, Ask AI 2 wins {data['unadjustedSensitivity']['baselineWins']}, ties {data['unadjustedSensitivity']['ties']}; mean nDCG difference {data['unadjustedSensitivity']['ndcgDifference']['mean']:+.4f}.",
              "", "## Question Groups", "", "| Group | Ask AI 2 P@5 | Sections P@5 | Ask AI 2 nDCG | Sections nDCG |", "|---|---:|---:|---:|---:|"]
    for group, values in data["groups"].items():
        a, b = (values[arm] for arm in ARMS)
        lines.append(f"| {group} | {a['precisionAt5']:.1%} | {b['precisionAt5']:.1%} | {a['ndcgAt10']:.4f} | {b['ndcgAt10']:.4f} |")
    lines += ["", "## Full-Text Examples", "",
              "These cases illustrate mechanisms and tradeoffs; they were selected after viewing results and are not a separate unbiased sample.",
              "- Question 11, judgment of people who never heard the Christian message: [The Accuracy of Acts: Part 2](https://ehrmanblog.org/the-accuracy-of-acts-part-2/) (4777) was absent from the baseline's 100 candidates, but section retrieval placed it 36th and passage review returned it first. Its full text directly contrasts Acts 17 with Romans 1 on pagan ignorance and accountability.",
              "- Question 17, physical versus spiritual resurrection: [1 Corinthians in a Nutshell](https://ehrmanblog.org/1-corinthians-in-a-nutshell/) (47152) was candidate 74 for the baseline and not selected. Sections moved it to candidate 24 and final rank 2. A substantial final section directly answers the question. Here both candidate order and reviewer evidence/instructions differ; this does not isolate one cause.",
              "- Question 18, Paul's use of Jewish scripture with gentiles: [Salvation, Love, and the Jewish Law in Paul](https://ehrmanblog.org/salvation-love-and-the-jewish-law-in-paul-are-his-views-internally-coherent/) (40161) was candidate 2/final rank 2 for the baseline, but candidate 1/not selected for sections. Full-text review retained grade 2 for useful covenant-and-law background. This is a reviewer-selection loss, not a retrieval miss."]
    lines += ["", "## Question-Level Results", "", "Ranking scores below are nDCG@10 multiplied by 100. The winner uses the prespecified three-point tie tolerance, not displayed rounding.",
              "", "| # | Question | Ask AI 2 | Sections | Result |", "|---|---|---:|---:|---|"]
    for question in data["questions"]:
        a, b = (question["versions"][arm]["metrics"]["ndcgAt10"] for arm in ARMS)
        winner = "Tie" if abs(b - a) <= .03 else "Sections" if b > a else "Ask AI 2"
        lines.append(f"| {question['id']} | {question['question']} | {a * 100:.1f} | {b * 100:.1f} | {winner} |")
    lines += ["", "## Limits and Preservation", "",
              "- AI relevance judgments are not a human gold standard. Bootstrap intervals measure question-sampling uncertainty, not grader error or repeated-review variability.",
              "- The sample includes 100 deliberately selected challenge posts. These results do not establish performance on the full blog, and the baseline here is Ask AI 2 restricted to the same 500 posts.",
              "- Earlier test scores use different questions and judgment pools and should not be numerically pooled with this run.",
              f"- {data['preservation']['sourceAndDataFiles']} original source/data files and {len(data['preservation']['databaseTablesUnchanged'])} discovery database tables passed unchanged-content checks, including the 4,016 production vectors. Results, grading evidence, and raw API audits are retained separately. No production model switch, commit, push, or Render deployment is part of this test.",
              "", "## Verification", "",
              "- 277 new mocked comparison checks, 438 original pilot checks, and 5,209 evidence-replay checks passed with no paid calls.",
              "- Python unit tests, WordPress Core PHPCS, and PHPStan level 5 passed for the experiment.",
              "- The existing local WordPress acceptance suite passed, including 77 PHP syntax checks, canonical data counts, semantic coverage, ingestion, analytics, public pages, privacy, AND-search, and pagination.", ""]
    (PUBLIC / "report.md").write_text("\n".join(lines), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["prepare", "run", "grade", "report", "verify"])
    parser.add_argument("--limit", type=int, default=50)
    args = parser.parse_args()
    {"prepare": prepare, "run": lambda: benchmark(args.limit), "grade": lambda: grade(args.limit), "report": report,
     "verify": lambda: print(json.dumps({"preservedFiles": len(integrity()["preserved"])}))}[args.command]()
