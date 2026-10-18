"""Fresh paired searches on the frozen agent-summary pilot; no production writes."""

import argparse
import json
import random
import sys
import time
import urllib.error
import urllib.request

import agent_work as authoring
import embed_agent

sys.path.insert(0, str(authoring.HERE.parent / "section-search"))
from evaluate import Browser
from evidence import GRADE_PROMPT

HERE, ROOT, WORK, PUBLIC, RUN = authoring.HERE, authoring.ROOT, authoring.WORK, authoring.PUBLIC, authoring.RUN
OLD = HERE.parent / "section-search"
PREVIOUS = OLD / "runs/end-to-end-300-2026-10-17"
ARMS = ("ask-ai-2", "improved-summary")
read, save, digest = authoring.read, authoring.save, authoring.digest


def prepare():
    embed_agent.execute("verify")
    questions = read(PREVIOUS / "questions.json")
    rows = questions["questions"]
    if len(rows) != 50 or [q["id"] for q in rows] != list(range(1, 51)):
        raise ValueError("Require the original 50 pilot questions")
    previous = read(PREVIOUS / "results.json")
    prompts = {q["versions"]["ask-ai-2"]["promptHash"] for q in previous["questions"]}
    settings = {q["versions"]["ask-ai-2"]["settingsHash"] for q in previous["questions"]}
    if len(prompts) != 1 or len(settings) != 1:
        raise ValueError("Prior baseline configuration is inconsistent")
    save(PUBLIC / "questions.json", questions, frozen=True)
    save(WORK / "fixtures/questions.json", questions, frozen=True)
    save(WORK / "prior-results.json", previous, frozen=True)
    paths = [OLD / "private/runtime/index.json", PREVIOUS / "results.json", PREVIOUS / "manifest.json",
             WORK / "summaries.json", WORK / "fixtures/improved.json", WORK / "prior-results.json",
             PUBLIC / "questions.json", WORK / "fixtures/questions.json", PUBLIC / "embedding-results.json",
             OLD / "evaluate.py", OLD / "evidence.py", OLD / "api.py"]
    manifest = {
        "run": RUN, "sampleHash": previous["manifest"]["sampleHash"], "sampleCount": 500, "questionCount": 50,
        "embeddingModel": embed_agent.MODEL, "dimensions": 512, "reviewerModel": "gpt-5.4-mini",
        "promptHash": next(iter(prompts)), "settingsHash": next(iter(settings)),
        "indexHash": digest(OLD / "private/runtime/index.json"),
        "improvedHash": digest(WORK / "fixtures/improved.json"),
        "questionsHash": digest(WORK / "fixtures/questions.json"),
        "runnerHash": digest(HERE / "plugin/summary-pilot.php"),
        "pilotSources": {p.name: digest(p) for p in sorted((OLD / "plugin").glob("*.php")) if not p.name.endswith("tests.php")},
        "preserved": {str(p.relative_to(ROOT)).replace("\\", "/"): digest(p) for p in paths},
        "design": "Same 500 posts and 50 previous pilot questions. Only summaries and their title/summary vectors differ. Both use unchanged production hybrid ranking and reviewer, 100 candidates, same tiers and fallback.",
        "timingScope": "HTTP submission through complete JSON receipt, including embedding, retrieval and review; excludes browser paint and authentication.",
        "tieTolerance": 0.03, "orderSeed": RUN + "-comparison",
        "questionProvenance": "Reused end-to-end-300-2026-10-17 questions; diagnostic, not an independent confirmation set.",
        "grading": "Reuse verified grades for identical question/post pairs; new pool members graded from full stored posts without method labels or ranks. Pool includes final top tens and candidate top tens from both arms.",
        "gradePromptHash": embed_agent.sha(GRADE_PROMPT), "gradeModel": "gpt-5.4-mini", "gradeReasoning": "medium",
        "pricesChecked": "2026-10-17", "usdPerMillion": {"embedding": .02, "reviewInput": .75, "reviewCached": .075, "reviewOutput": 4.5},
    }
    save(PUBLIC / "comparison-manifest.json", manifest, frozen=True)
    save(WORK / "fixtures/manifest.json", manifest, frozen=True)
    print(json.dumps({"prepared": 50, "arms": ARMS, "run": RUN, "apiCalls": 0}))


def integrity():
    authoring.integrity()
    manifest = read(PUBLIC / "comparison-manifest.json")
    for relative, expected in manifest["preserved"].items():
        if digest(ROOT / relative) != expected:
            raise ValueError("Preserved input changed: " + relative)
    for name, expected in manifest["pilotSources"].items():
        if digest(OLD / "plugin" / name) != expected:
            raise ValueError("Shared experiment helper changed: " + name)
    if digest(HERE / "plugin/summary-pilot.php") != manifest["runnerHash"]:
        raise ValueError("Frozen summary runner changed")
    if read(WORK / "fixtures/manifest.json") != manifest:
        raise ValueError("Runtime manifest changed")
    return manifest


def phase_cost(usage):
    if usage["model"] == "text-embedding-3-small":
        return usage["input_tokens"] * .02 / 1e6
    if not usage["model"].startswith("gpt-5.4-mini"):
        raise ValueError("Unexpected model")
    return ((usage["input_tokens"] - usage["cached_input_tokens"]) * .75
            + usage["cached_input_tokens"] * .075 + usage["output_tokens"] * 4.5) / 1e6


def validate_run(result, question, arm, manifest):
    if (result["run"] != RUN or result["questionId"] != question["id"] or result["question"] != question["question"]
            or result["arm"] != arm or result["sampleHash"] != manifest["sampleHash"] or result["sampleCount"] != 500
            or result["cacheHit"] or result["promptHash"] != manifest["promptHash"] or result["settingsHash"] != manifest["settingsHash"]):
        raise ValueError("Run identity, cache, prompt or settings mismatch")
    candidates = [p["wpId"] for p in result["candidates"]]
    selected = [p["wpId"] for p in result["posts"]]
    if len(candidates) != 100 or len(set(candidates)) != 100:
        raise ValueError("Expected exactly 100 unique candidates")
    if len(selected) > 25 or len(set(selected)) != len(selected) or not set(selected) <= set(candidates):
        raise ValueError("Invalid selected post IDs")
    usage = result["usage"]
    if len(usage) != 2 or usage[0]["model"] != manifest["embeddingModel"] or not usage[1]["model"].startswith(manifest["reviewerModel"]):
        raise ValueError("Both paid phases with expected models are required")
    for row in usage:
        if row["input_tokens"] <= 0 or not 0 <= row["cached_input_tokens"] <= row["input_tokens"]:
            raise ValueError("Missing token accounting")
        if abs(phase_cost(row) - row["estimated_cost_usd"]) > 1e-10:
            raise ValueError("Unexpected cost calculation")
    if abs(result["costUsd"] - sum(phase_cost(u) for u in usage)) > 1e-10:
        raise ValueError("Total cost omits a paid phase")
    if result["submissionToResultsSeconds"] <= 0:
        raise ValueError("Missing response time")


def validate_metadata(result, corpus, improved):
    for row in result["candidates"] + result["posts"]:
        source = corpus[row["wpId"]]
        expected = source["searchSummary"] if result["arm"] == "ask-ai-2" else improved[row["wpId"]]["summary"]
        if row["title"] != source["title"] or row["summary"] != expected:
            raise ValueError("Result metadata belongs to the wrong arm or source")


def benchmark(limit):
    manifest = integrity()
    if not (WORK / "database-before.json").exists():
        raise ValueError("Capture a database fingerprint before paid searches")
    corpus = {p["wpId"]: p for p in authoring.source()["posts"]}
    improved = {p["wpId"]: p for p in read(WORK / "fixtures/improved.json")["posts"]}
    browser = Browser()
    for question in read(PUBLIC / "questions.json")["questions"][:limit]:
        order = list(ARMS)
        random.Random(manifest["orderSeed"] + str(question["id"])).shuffle(order)
        for arm in order:
            path = WORK / "searches" / f"q{question['id']:02}-{arm}.json"
            if path.exists():
                result = read(path)
            else:
                request = urllib.request.Request(browser.settings["url"] + "compare-summaries",
                          json.dumps({"questionId": question["id"], "arm": arm}).encode(),
                          headers={"Content-Type": "application/json", "X-WP-Nonce": browser.settings["nonce"]})
                start = time.perf_counter()
                try:
                    with browser.opener.open(request, timeout=300) as response:
                        result = json.load(response)
                except urllib.error.HTTPError as error:
                    raise RuntimeError(f"Comparison HTTP {error.code}: {error.read().decode()}") from None
                result["submissionToResultsSeconds"] = time.perf_counter() - start
                # Preserve received evidence even if a validation check stops the run.
                save(WORK / "received" / (result["requestId"] + ".json"), result, frozen=True)
            validate_run(result, question, arm, manifest)
            validate_metadata(result, corpus, improved)
            save(path, result, frozen=True)
            print(json.dumps({"question": question["id"], "arm": arm, "posts": len(result["posts"]),
                              "seconds": round(result["submissionToResultsSeconds"], 2),
                              "cents": round(result["costUsd"] * 100, 4)}), flush=True)
    integrity()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["prepare", "run", "verify"])
    parser.add_argument("--limit", type=int, default=50)
    parser.add_argument("--approve-api", action="store_true")
    args = parser.parse_args()
    if args.command == "run" and not args.approve_api:
        parser.error("Paid searches require --approve-api after user authorization")
    if not 1 <= args.limit <= 50:
        parser.error("Limit must be between 1 and 50")
    if args.command == "prepare":
        prepare()
    elif args.command == "run":
        benchmark(args.limit)
    else:
        print(json.dumps({"unchanged": True, "run": integrity()["run"]}))
