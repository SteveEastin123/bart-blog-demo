"""Offline preparation/validation for agent-written summaries. No network code."""

import argparse
import hashlib
import json
from pathlib import Path
import statistics
import unicodedata

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
RUN = "agent-summaries-2026-10-17"
WORK = HERE / "private" / RUN
PUBLIC = HERE / "runs" / RUN
SOURCE = HERE.parent / "section-search/private/corpus.json"
VERBS = {"examines", "explains", "argues", "compares", "traces", "describes", "discusses",
         "evaluates", "reconstructs", "explores", "considers", "analyzes", "assesses", "outlines",
         "distinguishes", "reviews", "introduces", "presents", "recounts", "investigates",
         "challenges", "clarifies", "reflects", "illustrates", "contrasts", "addresses", "defends",
         "announces", "surveys", "proposes", "summarizes", "reports", "recommends", "criticizes",
         "uses", "marks", "celebrates", "appeals", "invites", "questions", "reads",
         "revises", "locates", "pairs", "interprets", "answers", "corrects", "responds",
         "maps", "reconsiders", "places", "separates", "sets", "defines", "rejects", "applies",
         "connects", "disputes", "develops", "extends", "shows"}


def read(path):
    return json.loads(path.read_text(encoding="utf-8-sig"))


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def save(path, value, frozen=False):
    if frozen and path.exists():
        if read(path) != value:
            raise ValueError("Frozen file differs: " + str(path))
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(path)


def source():
    return read(SOURCE)


def prepare():
    data = source()
    posts = data["posts"]
    if len(posts) != 500 or len({p["wpId"] for p in posts}) != 500:
        raise ValueError("Require the unchanged 500-post sample")
    paths = [SOURCE, HERE / "summary-prompt.txt"]
    paths += sorted((ROOT / "wordpress-plugin/ehrman-blog-discovery/src").rglob("*.php"))
    paths += [p for p in (ROOT / "data/index").glob("*") if p.is_file()]
    paths += sorted((HERE.parent / "section-search/runs").rglob("*.json"))
    paths += sorted((HERE.parent / "section-search/private/runtime").glob("*"))
    paths += sorted((HERE / "private/improved-summaries-2026-10-17/summaries").glob("*.json"))
    manifest = {"run": RUN, "sampleHash": data["sampleHash"], "postCount": len(posts),
                "generation": "Codex agent reading full stored posts; no summary-generation API calls",
                "targetWords": [120, 180], "originalsPreserved": True,
                "preserved": {str(p.relative_to(ROOT)).replace("\\", "/"): digest(p) for p in paths},
                "batches": {f"batch-{i//25+1:02}": [p["wpId"] for p in posts[i:i+25]] for i in range(0, 500, 25)},
                "sourceFieldsShownToWriter": ["wpId", "title", "text"],
                "excludedFromWriterInput": ["old summaries", "API summaries", "questions", "retrieval results", "grades"],
                "nextSteps": ["Approve limited embedding/search API calls separately", "Measure search value", "If useful compare agent/API summaries", "Use API summary generation only after WordPress deployment"]}
    save(PUBLIC / "manifest.json", manifest, frozen=True)
    print(json.dumps({"preparedPosts": len(posts), "batches": len(manifest["batches"]), "apiCalls": 0}))


def integrity():
    manifest = read(PUBLIC / "manifest.json")
    for relative, expected in manifest["preserved"].items():
        if digest(ROOT / relative) != expected:
            raise ValueError("Preserved file changed: " + relative)
    return manifest


def show(batch, offset, count):
    manifest = integrity()
    ids = manifest["batches"][batch][offset:offset + count]
    posts = {p["wpId"]: p for p in source()["posts"]}
    for wp in ids:
        post = posts[wp]
        print(json.dumps({"wpId": wp, "title": post["title"], "text": post["text"]}, ensure_ascii=True))


def normalize(text):
    text = unicodedata.normalize("NFKC", text).translate(str.maketrans({"\u2018": "'", "\u2019": "'", "\u201c": '"', "\u201d": '"'}))
    return " ".join(text.split()).casefold()


def validate_record(row, post):
    summary = row["searchSummary"]
    if not isinstance(summary, str) or "\n" in summary or "\r" in summary or not summary.strip():
        raise ValueError("Expected nonempty single-line summary")
    words = summary.split()
    if words[0].rstrip(",;:").casefold() not in VERBS:
        raise ValueError("Use a verb-led declarative opening")
    if not 50 <= len(words) <= 190:
        raise ValueError("Summary must have 50-190 words; normal target is 120-180")
    if len(words) < 120 and not row.get("shortSourceReason", "").strip():
        raise ValueError("Explain a short-summary exception")
    quotes = row["sourceQuotes"]
    if not 2 <= len(quotes) <= 3:
        raise ValueError("Provide two or three supporting source quotations")
    for quote in quotes:
        if not quote.strip() or len(quote.split()) > 20 or normalize(quote) not in normalize(post["text"]):
            raise ValueError("Supporting quotation not exact or longer than 20 words: " + quote)
    return len(words)


def validate(batch=None, complete=False):
    manifest = integrity()
    posts = {p["wpId"]: p for p in source()["posts"]}
    records, errors = {}, []
    batches = [batch] if batch else list(manifest["batches"])
    for name in batches:
        path = WORK / "batches" / (name + ".json")
        if not path.exists():
            if complete:
                errors.append(name + ": missing")
            continue
        rows = read(path)
        ids = [r["wpId"] for r in rows]
        expected = manifest["batches"][name]
        if len(ids) != len(set(ids)) or not set(ids) <= set(expected) or (complete and set(ids) != set(expected)):
            errors.append(name + ": duplicate, wrong or missing IDs")
        for row in rows:
            try:
                words = validate_record(row, posts[row["wpId"]])
                records[row["wpId"]] = {**row, "words": words, "title": posts[row["wpId"]]["title"],
                                        "textHash": posts[row["wpId"]]["textHash"], "generation": "Codex agent", "apiCalls": 0}
            except (KeyError, ValueError) as error:
                errors.append(name + "/" + row.get("wpId", "unknown") + ": " + str(error))
    status = {"run": RUN, "validSummaries": len(records), "expected": sum(len(manifest["batches"][b]) for b in batches),
              "errors": errors, "apiCalls": 0}
    if records:
        status.update({"meanWords": statistics.mean(r["words"] for r in records.values()),
                       "shortExceptions": sum(r["words"] < 120 for r in records.values())})
    if not batch:
        save(PUBLIC / "progress.json", status)
    print(json.dumps(status, indent=2))
    if errors:
        raise SystemExit(1)
    if complete and not batch:
        save(WORK / "summaries.json", {"run": RUN, "sampleHash": manifest["sampleHash"], "posts": [records[p["wpId"]] for p in source()["posts"]]}, frozen=True)
    return records


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["prepare", "show", "validate", "verify"])
    parser.add_argument("--batch", default=None)
    parser.add_argument("--offset", type=int, default=0)
    parser.add_argument("--count", type=int, default=5)
    parser.add_argument("--complete", action="store_true")
    args = parser.parse_args()
    if args.command == "prepare":
        prepare()
    elif args.command == "show":
        show(args.batch, args.offset, args.count)
    elif args.command == "validate":
        validate(args.batch, args.complete)
    else:
        print(json.dumps({"unchangedPreservedFiles": len(integrity()["preserved"]), "apiCalls": 0}))
