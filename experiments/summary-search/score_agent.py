"""Blind full-post grading and paired metrics for the longer-summary pilot."""

import argparse
import collections
import copy
import json
import math
import statistics

import agent_work
import compare_agent as comparison
from api import configuration, json_response, object_schema
from evaluate import confidence_interval, metrics, verify_evidence
from evidence import GRADE_PROMPT, usage_cost

RUN, WORK, PUBLIC = comparison.RUN, comparison.WORK, comparison.PUBLIC
ARMS = comparison.ARMS
read, save = comparison.read, comparison.save


def pool_identity(question, ids):
    return comparison.embed_agent.sha(json.dumps([question, sorted(ids)], sort_keys=True))


def check_grade(row, source, strict=False):
    if type(row["grade"]) is not int or row["grade"] not in (0, 1, 2, 3) or not row["reason"].strip():
        raise ValueError("Invalid relevance grade or missing reason")
    evidence = row["evidence"]
    if not verify_evidence(evidence, source)[0] or (row["grade"] >= 2 and not evidence.strip()):
        raise ValueError("Grade lacks verified source evidence")
    if strict and evidence and (len(evidence.split()) > 25 or agent_work.normalize(evidence) not in agent_work.normalize(source)):
        raise ValueError("New evidence must be one exact phrase of at most 25 words")


def question_runs(question, manifest):
    runs = {}
    for arm in ARMS:
        run = read(WORK / "searches" / f"q{question['id']:02}-{arm}.json")
        comparison.validate_run(run, question, arm, manifest)
        runs[arm] = run
    return runs


def pooled_ids(runs):
    return {p["wpId"] for run in runs.values() for p in run["posts"][:10] + run["candidates"][:10]}


def grade(limit):
    manifest = comparison.integrity()
    _, model = configuration()
    if model != manifest["gradeModel"] or comparison.embed_agent.sha(GRADE_PROMPT) != manifest["gradePromptHash"]:
        raise ValueError("Grader model or rubric changed")
    corpus = {p["wpId"]: p for p in agent_work.source()["posts"]}
    previous = {q["id"]: q for q in read(WORK / "prior-results.json")["questions"]}
    schema = object_schema({"grades": {"type": "array", "items": object_schema({
        "id": {"type": "string"}, "grade": {"type": "integer", "enum": [0, 1, 2, 3]},
        "reason": {"type": "string"}, "evidence": {"type": "string"}})}})
    for question in read(PUBLIC / "questions.json")["questions"][:limit]:
        ids = pooled_ids(question_runs(question, manifest))
        identity = pool_identity(question, ids)
        path = WORK / "grades" / f"q{question['id']:02}.json"
        if path.exists():
            validate_grades(read(path), question, ids, corpus)
            continue
        prior = previous[question["id"]]
        if prior["question"] != question["question"]:
            raise ValueError("Prior question changed")
        known = {row["wpId"]: row for row in prior["grades"]}
        grades = []
        for wp in sorted(ids & known.keys()):
            check_grade(known[wp], corpus[wp]["text"])
            grades.append({**known[wp], "origin": "verified_prior_full_post_grade", "textHash": corpus[wp]["textHash"]})
        pending = sorted(ids - known.keys(), key=lambda wp: comparison.embed_agent.sha(RUN + str(question["id"]) + wp))
        for start in range(0, len(pending), 4):
            labels = {f"P{i + 1}": wp for i, wp in enumerate(pending[start:start + 4])}
            submitted = [{"id": label, "title": corpus[wp]["title"], "text": corpus[wp]["text"]} for label, wp in labels.items()]
            for attempt in range(3):
                prompt = GRADE_PROMPT + (" Recheck that every evidence phrase is copied exactly." * attempt)
                output, receipt = json_response(prompt, {"question": question["question"], "posts": submitted}, schema,
                                                "blind-agent-summary-grading", effort="medium", cache_root=WORK / "grading-cache")
                if sorted(row["id"] for row in output["grades"]) != sorted(labels):
                    raise ValueError("Grader omitted or duplicated an ID")
                batch = []
                try:
                    for row in output["grades"]:
                        wp = labels[row["id"]]
                        check_grade(row, corpus[wp]["text"], strict=True)
                        batch.append({**row, "wpId": wp, "textHash": corpus[wp]["textHash"], "origin": "new_blinded_full_post_grade",
                                      "model": receipt["model"], "requestHash": receipt["requestHash"], "evidenceVerified": True})
                except ValueError:
                    if attempt == 2:
                        raise
                    continue
                grades.extend(batch)
                break
        result = {**question, "poolHash": identity, "newGraderBlinded": True, "grades": grades}
        validate_grades(result, question, ids, corpus)
        save(path, result, frozen=True)
        print(json.dumps({"gradedQuestion": question["id"], "pooledPosts": len(ids),
                          "reusedGrades": len(ids & known.keys()), "newGrades": len(pending)}), flush=True)
    comparison.integrity()


def validate_grades(judged, question, ids, corpus):
    rows = judged["grades"]
    if judged["question"] != question["question"] or judged["poolHash"] != pool_identity(question, ids):
        raise ValueError("Judgment question or pool changed")
    if len(rows) != len(ids) or {r["wpId"] for r in rows} != ids:
        raise ValueError("Missing, extra or duplicate grades")
    for row in rows:
        if row["textHash"] != corpus[row["wpId"]]["textHash"]:
            raise ValueError("Graded source changed")
        check_grade(row, corpus[row["wpId"]]["text"])


def audit_costs(accepted):
    audit = [json.loads(line) for line in (WORK / "server-requests.jsonl").read_text(encoding="utf-8").splitlines()]
    starts = {r["requestId"] for r in audit if r.get("type") == "summary-comparison-start" and r["run"] == RUN and not r["test"]}
    paid = [r for r in audit if r.get("type") == "end-to-end-api-response" and r["requestId"] in starts]
    counts = collections.Counter((r["requestId"], r["endpoint"]) for r in paid)
    if len(accepted) != 100 or not set(accepted) <= starts:
        raise ValueError("Require 100 distinct measured searches with start audits")
    for rid, run in accepted.items():
        for phase, expected in zip(("embeddings", "responses"), run["usage"]):
            if counts[(rid, phase)] != 1:
                raise ValueError("An accepted search lacks exactly one audited response per phase")
            record = next(r for r in paid if r["requestId"] == rid and r["endpoint"] == phase)
            if record["usage"] != expected:
                raise ValueError("Reported usage differs from saved API evidence")
            if phase == "responses" and not record["response"].get("id", "").startswith("resp_"):
                raise ValueError("Mock response in measured results")
    grading = [read(p) for p in (WORK / "grading-cache").glob("*.json")]
    if any(not r["model"].startswith("gpt-5.4-mini") for r in grading):
        raise ValueError("Unexpected grading model")
    search_cost = sum(run["costUsd"] for run in accepted.values())
    all_cost = sum(comparison.phase_cost(r["usage"]) for r in paid)
    grade_cost = sum(usage_cost(r["response"]["usage"]) for r in grading)
    embedding_cost = read(PUBLIC / "embedding-results.json")["estimatedEmbeddingCostUsd"]
    return {"acceptedSearches": len(accepted), "acceptedSearchUsd": search_cost,
            "allSearchAttemptUsd": all_cost, "extraPaidPhases": len(paid) - 200,
            "gradingCalls": len(grading), "gradingUsd": grade_cost,
            "priorEmbeddingGenerationUsd": embedding_cost, "comparisonUsd": all_cost + grade_cost,
            "includingEmbeddingGenerationUsd": all_cost + grade_cost + embedding_cost}


def database_preserved():
    before, after = (read(WORK / f"database-{name}.json") for name in ("before", "after"))
    tables = {name: {key: value[key] for key in ("rows", "sha256")}
              for name, value in before["tables"].items() if name.startswith("wp_ehrman_")}
    if not tables:
        raise ValueError("No discovery tables in database fingerprint")
    for name, expected in tables.items():
        if expected != {key: after["tables"][name][key] for key in expected}:
            raise ValueError("Discovery table changed: " + name)
    return tables


def report():
    manifest = comparison.integrity()
    corpus = {p["wpId"]: p for p in agent_work.source()["posts"]}
    improved = {p["wpId"]: p for p in read(WORK / "fixtures/improved.json")["posts"]}
    adjudication_path = PUBLIC / "comparison-adjudications.json"
    adjudications = read(adjudication_path) if adjudication_path.exists() else {"checks": [], "overrides": []}
    overrides = {(r["questionId"], r["wpId"]): r for r in adjudications["overrides"]}
    rows, accepted = [], {}
    for question in read(PUBLIC / "questions.json")["questions"]:
        runs = question_runs(question, manifest)
        ids = pooled_ids(runs)
        judged = read(WORK / "grades" / f"q{question['id']:02}.json")
        validate_grades(judged, question, ids, corpus)
        before_current = {g["wpId"]: g["grade"] for g in judged["grades"]}
        raw = {g["wpId"]: g.get("preAdjudication", g)["grade"] for g in judged["grades"]}
        for row in judged["grades"]:
            override = overrides.get((question["id"], row["wpId"]))
            if override:
                if row["grade"] != override["originalGrade"]:
                    raise ValueError("Adjudication does not match the original grade")
                row["beforeCurrentAdjudication"] = copy.deepcopy(row)
                row.update({key: override[key] for key in ("grade", "reason", "evidence")})
                check_grade(row, corpus[row["wpId"]]["text"])
        grades = {g["wpId"]: g["grade"] for g in judged["grades"]}
        relevant = {wp for wp, grade in grades.items() if grade >= 2}
        versions = {}
        for arm, run in runs.items():
            comparison.validate_metadata(run, corpus, improved)
            values = metrics(run, grades)
            candidates = [p["wpId"] for p in run["candidates"]]
            usage = run["usage"]
            values.update({"candidatePrecisionAt10": sum(grades[wp] >= 2 for wp in candidates[:10]) / 10,
                           "pooledRelevantCoverageAt100": len(relevant & set(candidates)) / len(relevant) if relevant else 0,
                           "embeddingCostUsd": usage[0]["estimated_cost_usd"], "reviewCostUsd": usage[1]["estimated_cost_usd"],
                           "reviewInputTokens": usage[1]["input_tokens"], "reviewCachedTokens": usage[1]["cached_input_tokens"],
                           "uncachedCostUsd": usage[0]["estimated_cost_usd"] + (usage[1]["input_tokens"] * .75 + usage[1]["output_tokens"] * 4.5) / 1e6})
            versions[arm] = {"metrics": values, "rawMetrics": metrics(run, raw), "beforeCurrentMetrics": metrics(run, before_current), "candidateWpIds": candidates,
                             "requestId": run["requestId"], "promptHash": run["promptHash"], "settingsHash": run["settingsHash"],
                             "posts": [{"rank": i + 1, "wpId": p["wpId"], "title": p["title"], "url": p["url"], "grade": grades[p["wpId"]]}
                                       for i, p in enumerate(run["posts"][:10])]}
            if run["requestId"] in accepted:
                raise ValueError("Duplicate measured request ID")
            accepted[run["requestId"]] = run
        rows.append({**question, "versions": versions, "grades": judged["grades"]})
    summary = {arm: {key: statistics.mean(q["versions"][arm]["metrics"][key] for q in rows)
                     for key in rows[0]["versions"][arm]["metrics"]} for arm in ARMS}
    for arm in ARMS:
        times = sorted(q["versions"][arm]["metrics"]["seconds"] for q in rows)
        summary[arm]["medianSeconds"] = statistics.median(times)
        summary[arm]["p95Seconds"] = times[math.ceil(.95 * len(times)) - 1]
    differences = [q["versions"][ARMS[1]]["metrics"]["ndcgAt10"] - q["versions"][ARMS[0]]["metrics"]["ndcgAt10"] for q in rows]
    raw_diff = [q["versions"][ARMS[1]]["rawMetrics"]["ndcgAt10"] - q["versions"][ARMS[0]]["rawMetrics"]["ndcgAt10"] for q in rows]
    before_diff = [q["versions"][ARMS[1]]["beforeCurrentMetrics"]["ndcgAt10"] - q["versions"][ARMS[0]]["beforeCurrentMetrics"]["ndcgAt10"] for q in rows]
    def outcome(values):
        return {"longerSummaryWins": sum(d > manifest["tieTolerance"] for d in values),
                "baselineWins": sum(d < -manifest["tieTolerance"] for d in values),
                "ties": sum(abs(d) <= manifest["tieTolerance"] for d in values),
                "ndcgDifference": confidence_interval(values)}
    pairs = [(q["versions"][ARMS[0]]["candidateWpIds"], q["versions"][ARMS[1]]["candidateWpIds"]) for q in rows]
    overlap = {"meanSharedCandidatesOf100": statistics.mean(len(set(a) & set(b)) for a, b in pairs),
               "identicalSets": sum(set(a) == set(b) for a, b in pairs), "identicalOrderedLists": sum(a == b for a, b in pairs)}
    groups = {group: {arm: {key: statistics.mean(q["versions"][arm]["metrics"][key] for q in rows if q["group"] == group)
                           for key in ("precisionAt5", "precisionAt10", "ndcgAt10")} for arm in ARMS}
              for group in sorted({q["group"] for q in rows})}
    grades = [g for q in rows for g in q["grades"]]
    data = {"run": RUN, "manifest": manifest, "summary": summary, "comparison": outcome(differences),
            "priorAdjudicationSensitivity": outcome(raw_diff), "beforeCurrentAdjudication": outcome(before_diff),
            "adjudications": adjudications, "candidateOverlap": overlap, "groups": groups,
            "costs": audit_costs(accepted), "databaseTablesUnchanged": database_preserved(),
            "gradedPairs": len(grades), "reusedGrades": sum(g["origin"] == "verified_prior_full_post_grade" for g in grades),
            "newGrades": sum(g["origin"] == "new_blinded_full_post_grade" for g in grades),
            "uniqueGradedPosts": len({g["wpId"] for g in grades}), "questions": rows,
            "noRelevantPoolQuestions": [q["id"] for q in rows if not any(g["grade"] >= 2 for g in q["grades"])]}
    save(PUBLIC / "comparison-results.json", data)
    write_report(data)
    print(json.dumps({k: data[k] for k in ("summary", "comparison", "candidateOverlap", "costs", "gradedPairs", "reusedGrades", "newGrades")}, indent=2))


def write_report(data):
    summary, outcome = data["summary"], data["comparison"]
    lines = ["# Ask AI 2: Existing vs. Longer Summaries", "", "Run: agent-summaries-2026-10-17. Local pilot only.", "",
             "## Design", "",
             "Fresh paired searches for the same 50 previous pilot questions on the same 500 posts. Control: existing short summaries and their vectors. Experimental: 500 agent-written longer summaries and separately generated title/summary vectors. Both use text-embedding-3-small at 512 dimensions, unchanged production hybrid ranking, 100 unique candidates, and the same gpt-5.4-mini reviewer prompt, settings, tier ordering, and fallback.",
             "Both retrieval and reviewer evidence change. This tests the combined effect of longer, newly written summaries, not word count alone. No section vectors or Ask AI 1 are involved. These questions were used earlier: this is a diagnostic pilot, not independent confirmation or representative reader traffic.", "",
             "## Results", "", "| Measure | Existing summaries | Longer summaries |", "|---|---:|---:|"]
    measures = [("Top-five relevance (P@5)", "precisionAt5", ".1%", 1), ("Top-ten relevance (P@10)", "precisionAt10", ".1%", 1),
                ("Ranking score (nDCG@10 x 100)", "ndcgAt10", ".1f", 100), ("Relevant posts in top ten", "relevantTop10", ".2f", 1),
                ("Posts displayed in top ten", "returnedTop10", ".2f", 1), ("Relevance among displayed posts", "displayedPrecisionAt10", ".1%", 1),
                ("Mean response time (seconds)", "seconds", ".2f", 1), ("Median response time (seconds)", "medianSeconds", ".2f", 1),
                ("95th-percentile response time (seconds)", "p95Seconds", ".2f", 1), ("Total cost (cents/question)", "costUsd", ".3f", 100),
                ("Query embedding (cents/question)", "embeddingCostUsd", ".6f", 100), ("Final AI review (cents/question)", "reviewCostUsd", ".3f", 100),
                ("Cost without provider-cache discount (cents/question)", "uncachedCostUsd", ".3f", 100),
                ("Review input tokens", "reviewInputTokens", ".0f", 1), ("Provider-cached input tokens", "reviewCachedTokens", ".0f", 1),
                ("Broader-result fallback rate", "fallback", ".1%", 1), ("Pre-review candidate P@10", "candidatePrecisionAt10", ".1%", 1),
                ("Known relevant pool coverage in 100 candidates", "pooledRelevantCoverageAt100", ".1%", 1)]
    for label, key, fmt, multiplier in measures:
        lines.append("| " + label + " | " + " | ".join(format(summary[a][key] * multiplier, fmt) for a in ARMS) + " |")
    ci = outcome["ndcgDifference"]
    lines += ["", f"Longer summaries won **{outcome['longerSummaryWins']}** questions; existing summaries won **{outcome['baselineWins']}**; **{outcome['ties']}** tied. A tie means an absolute nDCG@10 difference of at most 0.03.",
              f"Mean longer-minus-existing ranking difference: {ci['mean']:+.4f}; paired 95% bootstrap interval [{ci['lower95']:+.4f}, {ci['upper95']:+.4f}]. This interval measures question-sampling uncertainty, not grader error or repeated-run variability.",
              "The scores apply to this restricted 500-post corpus and should not be compared directly with earlier full-blog benchmarks. With fewer than ten results often returned, fixed-denominator P@10 can be much lower than relevance among the posts actually displayed.",
              "", "## Assessment", "",
              f"The longer-summary arm costs {summary[ARMS[1]]['costUsd'] / summary[ARMS[0]]['costUsd'] - 1:.1%} more per question. Its measured response time is {summary[ARMS[1]]['seconds'] - summary[ARMS[0]]['seconds']:+.2f} seconds different, but one request per arm/question does not establish a reliable speed advantage.",
              ("There is no clear ranking improvement: the paired interval includes zero. Retain the existing production summaries and vectors rather than adopting a bulk rewrite on this evidence."
               if ci['lower95'] <= 0 <= ci['upper95'] else
               "The paired ranking interval excludes zero, but adoption still requires fresh-question confirmation and review of the cost and precision tradeoffs."),
              "Do not change the download skill or WordPress summary generation based on this pilot alone. Any follow-up should investigate specific retrieval or reviewer errors before assuming that more summary text will help. The optional agent-versus-API summary comparison remains deferred, not automatically approved.",
              "", "## Full-Post Grading", "",
              f"{data['gradedPairs']} question/post judgments covering {data['uniqueGradedPosts']} distinct posts: {data['reusedGrades']} verified prior judgments reused and {data['newGrades']} new judgments. Both final top tens and candidate top tens were pooled and deduplicated per question. Newly graded posts were supplied in full, with neutral labels and no method names, ranks, summaries or prior judgments. Grades: 0 irrelevant, 1 marginal, 2 useful/substantial, 3 directly answering. Positive grades require source-verified quotations.",
              "Prior judgments include documented full-text corrections from the earlier run; those corrections were not blind to the earlier outcomes. AI grading is not a human gold standard. Quotation verification establishes textual support, not perfect relevance judgment.",
              f"This run also received {len(data['adjudications']['checks'])} coordinating-agent full-text spot checks and {len(data['adjudications']['overrides'])} documented numerical corrections. Selection and blinding details are in comparison-adjudications.json. Original grade files remain unchanged; corrections apply equally to both versions. Before these new corrections: {data['beforeCurrentAdjudication']['longerSummaryWins']} longer-summary wins, {data['beforeCurrentAdjudication']['baselineWins']} existing-summary wins, {data['beforeCurrentAdjudication']['ties']} ties.",
              "P@5/P@10 use fixed denominators; missing result slots count as nonrelevant. nDCG uses a common judged pool for each question, not exhaustive labels for all 500 posts. Candidate coverage refers only to known relevant pooled posts and is not corpus-wide recall.",
              f"Questions with no grade-2-or-3 post in the pool: {', '.join(map(str, data['noRelevantPoolQuestions'])) or 'none'}. This does not prove there are no relevant posts elsewhere.",
              f"Without either the prior or current numerical adjudication corrections, the outcome is {data['priorAdjudicationSensitivity']['longerSummaryWins']} longer-summary wins, {data['priorAdjudicationSensitivity']['baselineWins']} existing-summary wins, and {data['priorAdjudicationSensitivity']['ties']} ties.",
              "", "## Retrieval Diagnostic", "",
              f"The two versions shared an average of {data['candidateOverlap']['meanSharedCandidatesOf100']:.1f} of 100 candidates. Identical candidate sets: {data['candidateOverlap']['identicalSets']}/50; identical ordered lists: {data['candidateOverlap']['identicalOrderedLists']}/50.",
              "", "## Timing and Cost", "",
              "Timing covers authenticated HTTP submission through receipt of the full result JSON, including query embedding, retrieval and AI review. It excludes login and browser painting. Searches ran sequentially in seeded randomized order within each question. WordPress result caching was bypassed; OpenAI prompt-cache discounts are reported from actual usage.",
              f"100 accepted searches cost an estimated ${data['costs']['acceptedSearchUsd']:.4f}. All search attempts cost ${data['costs']['allSearchAttemptUsd']:.4f}, including {data['costs']['extraPaidPhases']} extra paid phases. New full-post grading used {data['costs']['gradingCalls']} calls costing ${data['costs']['gradingUsd']:.4f}. Total comparison cost: ${data['costs']['comparisonUsd']:.4f}. Earlier creation of the 500 test vectors cost ${data['costs']['priorEmbeddingGenerationUsd']:.8f}; including that gives ${data['costs']['includingEmbeddingGenerationUsd']:.4f}. Summary writing used Codex, not a separate summary-generation API. Earlier grading costs are not charged again or included.",
              "Rates checked October 17, 2026: embeddings $0.02/million input tokens; reviewer/grader $0.75/million input, $0.075/million cached input, $4.50/million output. Estimates use saved API usage; invoices are authoritative. [Embedding pricing](https://developers.openai.com/api/docs/models/text-embedding-3-small); [reviewer pricing](https://developers.openai.com/api/docs/models/gpt-5.4-mini).",
              "", "## Groups", "", "| Group | Existing P@5 | Longer P@5 | Existing ranking | Longer ranking |", "|---|---:|---:|---:|---:|"]
    for group, versions in data["groups"].items():
        a, b = (versions[arm] for arm in ARMS)
        lines.append(f"| {group} | {a['precisionAt5']:.1%} | {b['precisionAt5']:.1%} | {a['ndcgAt10'] * 100:.1f} | {b['ndcgAt10'] * 100:.1f} |")
    lines += ["", "## Question Results", "", "| # | Question | Existing ranking | Longer ranking | Result |", "|---|---|---:|---:|---|"]
    for question in data["questions"]:
        a, b = (question["versions"][arm]["metrics"]["ndcgAt10"] for arm in ARMS)
        winner = "Tie" if abs(b - a) <= .03 else "Longer" if b > a else "Existing"
        lines.append(f"| {question['id']} | {question['question']} | {a * 100:.1f} | {b * 100:.1f} | {winner} |")
    lines += ["", "## Preservation and Limits", "",
              f"All 112 originally frozen files and the additional comparison inputs retained their checksums. All {len(data['databaseTablesUnchanged'])} discovery tables retained their row counts and content fingerprints. Original summaries, vectors and production code were not replaced. This local endpoint is administrator-only and excluded from Render packaging. No commit, push, deployment, skill change or production ingestion change is part of the experiment.",
              "The fixed sample includes deliberately difficult posts and one known stored opening excerpt (post 2359). Longer summaries have not been adopted in production; four exceed the current ingestion validator's 1,200-character limit. That validator is not used to truncate this experiment's reviewer inputs.",
              "Before adoption, confirm any promising improvement on fresh questions and broader data with human review. Then compare agent-written versus API-written summaries on identical posts. Only after approval should the download skill and deployed WordPress ingestion guidance change.", ""]
    (PUBLIC / "comparison-report.md").write_text("\n".join(lines), encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["grade", "report"])
    parser.add_argument("--limit", type=int, default=50)
    parser.add_argument("--approve-api", action="store_true")
    args = parser.parse_args()
    if args.command == "grade" and not args.approve_api:
        parser.error("Paid grading requires --approve-api after user authorization")
    if not 1 <= args.limit <= 50:
        parser.error("Limit must be between 1 and 50")
    grade(args.limit) if args.command == "grade" else report()
