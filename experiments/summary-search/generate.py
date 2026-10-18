"""Isolated, resumable fuller summaries; never writes canonical data or old vectors."""

import argparse
import base64
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
import math
from pathlib import Path
import random
import re
import statistics
import struct
import sys

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "section-search"))
from api import call, object_schema
from corpus import ROOT, read, save
from evidence import fingerprint, freeze
from evaluate import verify_evidence

RUN = "improved-summaries-2026-10-17"
WORK = HERE / "private" / RUN
PUBLIC = HERE / "runs" / RUN
OLD = HERE.parent / "section-search"
SOURCE_RUN = OLD / "runs/end-to-end-300-2026-10-17"
MODEL = "gpt-5.6-sol"
VERBS = {"examines", "explains", "argues", "compares", "traces", "describes", "discusses",
         "evaluates", "reconstructs", "explores", "considers", "analyzes", "assesses", "outlines",
         "distinguishes", "reviews", "introduces", "presents", "recounts", "investigates",
         "challenges", "clarifies", "reflects", "illustrates", "contrasts", "addresses", "defends"}


def corpus():
    return read(OLD / "private/corpus.json")


def ordered_posts():
    posts = list(corpus()["posts"])
    random.Random(RUN + "summary-quality").shuffle(posts)
    return posts


def prepare():
    data = corpus()
    paths = [OLD / "private/corpus.json", OLD / "private/runtime/index.json"]
    paths += sorted((OLD / "private/runtime").glob("sections-*.gz"))
    paths += sorted((OLD / "plugin").glob("*.php"))
    paths += sorted((OLD / "runs").rglob("*.json")) + sorted((OLD / "runs").rglob("*.md"))
    paths += sorted((ROOT / "wordpress-plugin/ehrman-blog-discovery/src").rglob("*.php"))
    paths += sorted((ROOT / "data/index").glob("*"))
    manifest = {"run": RUN, "sampleHash": data["sampleHash"], "posts": 500, "questions": 50,
                "generationModel": MODEL, "reasoning": "medium", "targetWords": [120, 180],
                "embeddingModel": "text-embedding-3-small", "dimensions": 512, "reviewerModel": "gpt-5.4-mini",
                "promptHash": fingerprint(HERE / "summary-prompt.txt"),
                "preserved": {str(p.relative_to(ROOT)).replace("\\", "/"): fingerprint(p) for p in paths if p.is_file()},
                "initialQualitySample": [p["wpId"] for p in ordered_posts()[:8]],
                "tieTolerance": .03, "pricesChecked": "2026-10-17",
                "usdPerMillion": {"summaryInput": 4, "summaryCached": .4, "summaryCacheWrite": 5,
                                  "summaryOutput": 20, "embedding": .02,
                                  "reviewInput": .75, "reviewCached": .075, "reviewOutput": 4.5},
                "adoption": "Only after user approval: download skill AND WordPress post ingestion; see README."}
    freeze(PUBLIC / "manifest.json", manifest)
    freeze(PUBLIC / "questions.json", read(SOURCE_RUN / "questions.json"))
    print(json.dumps({"run": RUN, "posts": len(data["posts"]), "preservedFiles": len(manifest["preserved"])}))


def integrity():
    manifest = read(PUBLIC / "manifest.json")
    for relative, expected in manifest["preserved"].items():
        if fingerprint(ROOT / relative) != expected:
            raise ValueError("Preserved file changed: " + relative)
    if fingerprint(HERE / "summary-prompt.txt") != manifest["promptHash"]:
        raise ValueError("Frozen summary prompt changed")
    if read(PUBLIC / "questions.json") != read(SOURCE_RUN / "questions.json"):
        raise ValueError("Question set changed")
    return manifest


def validate_summary(output, post):
    summary = output["searchSummary"]
    if not isinstance(summary, str) or not summary.strip() or "\n" in summary:
        raise ValueError("Summary must be nonempty single-line prose")
    words = summary.split()
    if not 50 <= len(words) <= 210 or words[0].casefold() not in VERBS or "?" in re.split(r"[.!]", summary)[0]:
        raise ValueError("Summary violates length or verb-led declarative style")
    quotes = output["sourceQuotes"]
    if not 2 <= len(quotes) <= 3 or any(not q.strip() or len(q.split()) > 20 or not verify_evidence(q, post["text"])[0] for q in quotes):
        raise ValueError("Source quotations are missing, too long, or not supported")
    return {"words": len(words), "targetLength": 120 <= len(words) <= 180}


def generate(limit=500):
    integrity()
    prompt = (HERE / "summary-prompt.txt").read_text(encoding="utf-8")
    schema = object_schema({"searchSummary": {"type": "string"},
                            "sourceQuotes": {"type": "array", "items": {"type": "string"}}})
    def one(post):
        path = WORK / "summaries" / (post["wpId"] + ".json")
        if path.exists():
            result = read(path)
            if result["textHash"] != post["textHash"]:
                raise ValueError("Resume source changed")
            validate_summary(result, post)
            return result
        for attempt in range(3):
            payload = {"model": MODEL, "store": False, "reasoning": {"effort": "medium"},
                       "max_output_tokens": 16000,
                       "instructions": prompt + ("\nRecheck exact quotations, prose length and the opening verb before returning." * attempt),
                       "input": json.dumps({"title": post["title"], "text": post["text"]}, ensure_ascii=False),
                       "text": {"format": {"type": "json_schema", "name": "search_summary_pilot", "strict": True, "schema": schema}}}
            record = call("responses", payload, "improved-summary-generation", cache_root=WORK / "generation-cache")
            response = record["response"]
            if response.get("status") != "completed":
                raise ValueError("Incomplete summary response retained; no draft accepted")
            text = "".join(c.get("text", "") for o in response.get("output", []) for c in o.get("content", []) if c.get("type") == "output_text")
            output = json.loads(text)
            try:
                checks = validate_summary(output, post)
                break
            except ValueError:
                if attempt == 2:
                    raise
        result = {**output, **checks, "wpId": post["wpId"], "title": post["title"], "textHash": post["textHash"],
                  "model": record["model"], "requestHash": record["requestHash"], "usage": response.get("usage", {})}
        freeze(path, result)
        return result

    with ThreadPoolExecutor(max_workers=4) as pool:
        jobs = [pool.submit(one, post) for post in ordered_posts()[:limit]]
        for number, job in enumerate(as_completed(jobs), 1):
            result = job.result()
            print(json.dumps({"summary": number, "wpId": result["wpId"], "words": result["words"]}), flush=True)


def embed():
    integrity()
    posts = corpus()["posts"]
    inputs = []
    for post in posts:
        summary = read(WORK / "summaries" / (post["wpId"] + ".json"))
        validate_summary(summary, post)
        text = "Title: " + post["title"] + "\nSummary: " + summary["searchSummary"]
        inputs.append((post["wpId"], text, hashlib.sha256(text.encode()).hexdigest()))
    for start in range(0, len(inputs), 32):
        batch = inputs[start:start + 32]
        record = call("embeddings", {"model": "text-embedding-3-small", "dimensions": 512,
                                     "encoding_format": "float", "input": [r[1] for r in batch]},
                      "improved-summary-embedding", cache_root=WORK / "embedding-cache")
        rows = record["response"]["data"]
        if sorted(r["index"] for r in rows) != list(range(len(batch))):
            raise ValueError("Embedding batch IDs mismatch")
        for row in rows:
            vector = row["embedding"]
            if len(vector) != 512 or not all(math.isfinite(x) for x in vector) or sum(x*x for x in vector) <= 0:
                raise ValueError("Invalid embedding")
            wp, _, content_hash = batch[row["index"]]
            freeze(WORK / "vectors" / (wp + ".json"), {"wpId": wp, "contentHash": content_hash,
                   "embedding": base64.b64encode(struct.pack("<512f", *vector)).decode(), "requestHash": record["requestHash"]})
        print(json.dumps({"embedded": start + len(batch)}), flush=True)


def package():
    manifest = integrity()
    posts = []
    for post in corpus()["posts"]:
        summary = read(WORK / "summaries" / (post["wpId"] + ".json"))
        validate_summary(summary, post)
        vector = read(WORK / "vectors" / (post["wpId"] + ".json"))
        content = "Title: " + post["title"] + "\nSummary: " + summary["searchSummary"]
        if vector["contentHash"] != hashlib.sha256(content.encode()).hexdigest():
            raise ValueError("Summary and vector mismatch")
        posts.append({"wpId": post["wpId"], "summary": summary["searchSummary"], "vector": vector})
    freeze(WORK / "fixtures/improved.json", {"run": RUN, "posts": posts, "sampleHash": manifest["sampleHash"]})
    freeze(WORK / "fixtures/questions.json", read(PUBLIC / "questions.json"))
    frozen = {**manifest, "indexHash": fingerprint(OLD / "private/runtime/index.json"),
              "improvedHash": fingerprint(WORK / "fixtures/improved.json"),
              "questionsHash": fingerprint(WORK / "fixtures/questions.json"),
              "runnerHash": fingerprint(HERE / "plugin/summary-pilot.php")}
    freeze(WORK / "fixtures/manifest.json", frozen)
    freeze(PUBLIC / "search-manifest.json", frozen)
    lengths = [len(p["summary"].split()) for p in posts]
    print(json.dumps({"packaged": len(posts), "meanWords": statistics.mean(lengths), "min": min(lengths), "max": max(lengths)}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["prepare", "generate", "embed", "package", "verify"])
    parser.add_argument("--limit", type=int, default=500)
    args = parser.parse_args()
    if args.command == "generate":
        generate(args.limit)
    else:
        value = {"prepare": prepare, "embed": embed, "package": package, "verify": integrity}[args.command]()
        if args.command == "verify":
            print(json.dumps({"preservedFiles": len(value["preserved"]), "unchanged": True}))
