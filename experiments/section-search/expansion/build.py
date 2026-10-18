"""Build an isolated remaining-post section package; never install or import it."""

import argparse
import base64
import collections
import gzip
import hashlib
import json
import math
from pathlib import Path
import re
import struct
import sys

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
SECTION = HERE.parent
sys.path.insert(0, str(SECTION))
import corpus
import api

ROOT = corpus.ROOT
RUN = "remaining-300-512-2026-10-18"
WORK = SECTION / "private" / RUN
PUBLIC = SECTION / "runs" / RUN
MODEL, DIMENSIONS, TARGET, OVERLAP, BATCH = "text-embedding-3-small", 512, 300, 60, 32
RATE, BUDGET = 0.02, 0.20


def require(condition, message):
    if not condition:
        raise ValueError(message)


def digest(path):
    with path.open("rb") as handle:
        return hashlib.file_digest(handle, "sha256").hexdigest()


def frozen_bytes(path, value):
    if path.exists():
        require(path.read_bytes() == value, "Refusing to overwrite frozen file: " + str(path))
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    partial = path.with_suffix(path.suffix + ".partial")
    partial.write_bytes(value)
    partial.replace(path)


def save(path, value):
    frozen_bytes(path, (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8"))


def preservation_files():
    paths = set()
    for directory in (SECTION, ROOT / "experiments/summary-search",
                      ROOT / "wordpress-plugin/ehrman-blog-discovery", ROOT / "data/index"):
        for path in directory.rglob("*"):
            if not path.is_file() or any(p in path.parts for p in ("vendor", "__pycache__", ".git")):
                continue
            if any(path.is_relative_to(p) for p in (HERE, WORK, PUBLIC)):
                continue
            paths.add(path)
    paths.update([ROOT / "data/raw/posts.jsonl", ROOT / ".gitignore", ROOT / ".dockerignore"])
    return sorted(paths)


def preserve(manifest):
    for name, expected in manifest["preservedFiles"].items():
        require(digest(ROOT / name) == expected, "Preserved file changed: " + name)
    for name, expected in manifest["generatorFiles"].items():
        require(digest(ROOT / name) == expected, "Frozen generator changed: " + name)


def validate_chunks(text, rows):
    matches = list(re.finditer(r"\S+", text))
    require(bool(rows) and bool(matches), "Missing text or chunks")
    require(rows[0]["startWord"] == 0 and rows[-1]["endWord"] == len(matches), "Incomplete coverage")
    for number, row in enumerate(rows):
        start, end = row["startWord"], row["endWord"]
        require(0 <= start < end <= len(matches), "Invalid chunk offsets")
        require(end - start <= TARGET * 1.2, "Chunk exceeds paragraph-boundary allowance")
        require(row["text"] == text[matches[start].start():matches[end - 1].end()], "Changed source text")
        require(len(row["text"].split()) == end - start, "Chunk word count mismatch")
        if number:
            previous = rows[number - 1]
            require(previous["endWord"] - start == OVERLAP, "Incorrect overlap")
            require(start > previous["startWord"] and end > previous["endWord"], "Non-progressing chunks")


def vector_bytes(values):
    require(isinstance(values, list) and len(values) == DIMENSIONS, "Wrong vector dimensions")
    require(all(type(v) in (int, float) and math.isfinite(v) for v in values), "Nonfinite/nonnumeric vector")
    require(.99 <= math.sqrt(sum(v * v for v in values)) <= 1.01, "Vector is not normalized")
    packed = struct.pack("<512f", *values)
    require(all(math.isfinite(v) for v in struct.unpack("<512f", packed)), "Float32 overflow")
    return packed


def decode_vector(value):
    packed = base64.b64decode(value, validate=True)
    require(len(packed) == DIMENSIONS * 4, "Wrong encoded vector length")
    vector_bytes(list(struct.unpack("<512f", packed)))
    return value


def pilot_vectors():
    index = corpus.read(SECTION / "private/runtime/index.json")
    path = SECTION / "private/runtime/sections-300.jsonl.gz"
    require(index["model"] == MODEL and index["dimensions"] == DIMENSIONS, "Incompatible pilot vectors")
    require(digest(path) == index["sectionFiles"]["300"]["sha256"], "Pilot package hash mismatch")
    source = [r for r in corpus.read(SECTION / "private/sections.json")["sections"] if r["size"] == TARGET]
    with gzip.open(path, "rt", encoding="utf-8") as handle:
        rows = [json.loads(line) for line in handle]
    require(len(rows) == len(source) == index["sectionFiles"]["300"]["count"], "Pilot count mismatch")
    result = {}
    for row, original in zip(rows, source):
        require({k: v for k, v in row.items() if k != "embedding"} ==
                {k: v for k, v in original.items() if k != "input"}, "Pilot section mismatch")
        require(corpus.digest(original["input"]) == row["hash"], "Pilot input hash mismatch")
        value = decode_vector(row["embedding"])
        if row["hash"] in result:
            require(result[row["hash"]] == value, "Conflicting duplicate pilot vectors")
        result[row["hash"]] = value
    return result


def prepare():
    baseline = {p.relative_to(ROOT).as_posix(): digest(p) for p in preservation_files()}
    posts, _ = corpus.source_posts()
    sample = corpus.read(SECTION / "sample.json")
    pilot = corpus.read(SECTION / "private/corpus.json")["posts"]
    pilot_ids = {str(p["wpId"]) for p in sample["posts"]}
    require(len(pilot_ids) == 500, "Expected the unchanged 500-post pilot")
    by_id = {str(p["wpId"]): p for p in posts}
    require(len(by_id) == len(posts), "Duplicate eligible post IDs")
    for old in pilot:
        current = by_id[str(old["wpId"])]
        require(all(current[k] == old[k] for k in ("title", "textHash", "searchSummary")), "Pilot post changed")
    eligible = [p for p in posts if p["wordCount"] >= 100]
    remaining = [p for p in eligible if str(p["wpId"]) not in pilot_ids]
    vectors = pilot_vectors()
    metadata, sections = [], []
    for post in remaining:
        rows = list(corpus.chunks(post["text"], TARGET))
        validate_chunks(post["text"], rows)
        metadata.append({k: post[k] for k in ("wpId", "title", "url", "textHash", "wordCount")})
        for number, chunk in enumerate(rows):
            value = "Title: " + post["title"] + "\nPassage: " + chunk["text"]
            sections.append({**chunk, "wpId": post["wpId"], "size": TARGET, "section": number,
                             "hash": corpus.digest(value), "input": value})
    unique = {r["hash"]: r for r in sections}
    pending = [r for h, r in unique.items() if h not in vectors]
    pilot_sections = [s for s in corpus.read(SECTION / "private/sections.json")["sections"] if s["size"] == TARGET]
    old_usage = corpus.read(SECTION / "runs/dimensions-300-2026-10-17/embedding-results.json")
    token_ratio = old_usage["inputTokens"] / sum(len(s["input"]) for s in pilot_sections)
    tokens = round(sum(len(r["input"]) for r in pending) * token_ratio)
    canonical = corpus.read(ROOT / "data/index/ehrman_post_search_index.json")
    excluded = collections.Counter()
    excluded_ids = collections.defaultdict(list)
    for post in canonical:
        identity = str(post["wpId"])
        reason = ("Ignore" if "Ignore" in post["topics"] else
                  "Missing current vector or stored text" if identity not in by_id else
                  "Fewer than 100 stored words" if by_id[identity]["wordCount"] < 100 else None)
        if reason:
            excluded[reason] += 1
            excluded_ids[reason].append(identity)
    require(len(canonical) == sum(excluded.values()) + len(pilot_ids) + len(remaining), "Unaccounted posts")
    prepared = {"posts": metadata, "sections": sections, "excludedPostIds": dict(excluded_ids)}
    save(WORK / "prepared.json", prepared)
    manifest = {"run": RUN, "model": MODEL, "dimensions": DIMENSIONS, "targetWords": TARGET,
                "overlapWords": OVERLAP, "paragraphTolerance": .2, "batchSize": BATCH,
                "canonicalPostCount": len(canonical), "eligiblePostCount": len(eligible),
                "preservedPilotPostCount": len(pilot_ids), "remainingPostCount": len(remaining),
                "sectionCount": len(sections), "uniqueInputs": len(unique),
                "reusedPilotInputs": len(unique) - len(pending), "newEmbeddingInputs": len(pending),
                "excludedCounts": dict(excluded), "estimatedInputTokens": tokens,
                "estimatedCostUsd": tokens * RATE / 1e6, "priceUsdPerMillion": RATE,
                "priceChecked": "2026-10-18", "priceSource": "https://developers.openai.com/api/docs/models/text-embedding-3-small",
                "maxRecordedCostUsd": BUDGET, "preparedHash": digest(WORK / "prepared.json"),
                "preservedFiles": baseline,
                "generatorFiles": {p.relative_to(ROOT).as_posix(): digest(p) for p in (Path(__file__), HERE / "test_build.py")}}
    require(manifest["estimatedCostUsd"] < BUDGET, "Estimate exceeds budget")
    preserve(manifest)
    save(PUBLIC / "manifest.json", manifest)
    print(json.dumps({k: v for k, v in manifest.items() if k not in ("preservedFiles", "generatorFiles")}, indent=2))


def inputs():
    manifest = corpus.read(PUBLIC / "manifest.json")
    preserve(manifest)
    require(digest(WORK / "prepared.json") == manifest["preparedHash"], "Prepared data changed")
    source = corpus.read(WORK / "prepared.json")
    vectors = pilot_vectors()
    unique = {r["hash"]: r for r in source["sections"]}
    pending = [r for h, r in unique.items() if h not in vectors]
    require(len(pending) == manifest["newEmbeddingInputs"], "Pending input set changed")
    return manifest, source, vectors, pending


def payload(rows):
    return {"model": MODEL, "dimensions": DIMENSIONS, "encoding_format": "float", "input": [r["input"] for r in rows]}


def request_hash(body):
    return corpus.digest(json.dumps(body, ensure_ascii=False))


def checked_receipt(receipt, rows):
    require(receipt["requestHash"] == request_hash(payload(rows)), "Receipt does not match input batch")
    body = receipt["response"]
    require(receipt["model"] == MODEL and body["model"] == MODEL, "Wrong embedding model")
    data = body["data"]
    require(sorted(r["index"] for r in data) == list(range(len(rows))), "Missing/duplicate response index")
    usage = body["usage"]
    require(type(usage["prompt_tokens"]) is int and usage["prompt_tokens"] > 0 and
            usage["prompt_tokens"] == usage["total_tokens"], "Invalid token usage")
    vectors = {rows[r["index"]]["hash"]: base64.b64encode(vector_bytes(r["embedding"])).decode("ascii") for r in data}
    return vectors, usage["prompt_tokens"]


def receipts(allow_api=False):
    manifest, source, vectors, pending = inputs()
    tokens, ledger = 0, []
    for start in range(0, len(pending), BATCH):
        rows = pending[start:start + BATCH]
        body = payload(rows)
        path = WORK / "embedding-cache" / (request_hash(body) + ".json")
        if path.exists():
            receipt = corpus.read(path)
        else:
            require(allow_api, "Missing saved receipt; offline verification cannot call the API")
            # UTF-8 bytes bound tokens conservatively, including highly unusual text.
            require(all(len(r["input"].encode("utf-8")) <= 8192 for r in rows), "Input needs token-length inspection")
            maximum_tokens = sum(len(r["input"].encode("utf-8")) for r in rows)
            require(maximum_tokens <= 300000, "Request exceeds conservative token bound")
            require((tokens + maximum_tokens) * RATE / 1e6 <= BUDGET, "Embedding budget reached")
            receipt = api.call("embeddings", body, RUN, cache_root=WORK / "embedding-cache")
        received, used = checked_receipt(receipt, rows)
        vectors.update(received)
        tokens += used
        require(tokens * RATE / 1e6 <= BUDGET, "Recorded cost exceeds budget")
        ledger.append({"requestHash": receipt["requestHash"], "receiptHash": digest(path), "inputTokens": used})
        if allow_api and (len(ledger) % 20 == 0 or start + BATCH >= len(pending)):
            print(json.dumps({"verifiedInputs": min(start + BATCH, len(pending)), "totalInputs": len(pending),
                              "requests": len(ledger), "costUsd": round(tokens * RATE / 1e6, 8)}), flush=True)
    saved = {p.stem for p in (WORK / "embedding-cache").glob("*.json")}
    require(saved == {r["requestHash"] for r in ledger}, "Unaccounted embedding receipts")
    return manifest, source, vectors, {"inputTokens": tokens, "costUsd": tokens * RATE / 1e6,
                                      "requests": len(ledger), "receipts": ledger}


def gzip_rows(path, rows):
    encoded = "".join(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n" for row in rows).encode("utf-8")
    frozen_bytes(path, gzip.compress(encoded, mtime=0))


def package():
    manifest, source, vectors, usage = receipts()
    rows = [{k: v for k, v in row.items() if k != "input"} | {"embedding": vectors[row["hash"]]}
            for row in source["sections"]]
    folder = WORK / "package"
    gzip_rows(folder / "sections-300-512.jsonl.gz", rows)
    gzip_rows(folder / "posts.jsonl.gz", source["posts"])
    files = {p.name: {"sha256": digest(p), "bytes": p.stat().st_size}
             for p in (folder / "sections-300-512.jsonl.gz", folder / "posts.jsonl.gz")}
    record = {"version": 1, "run": RUN, "model": MODEL, "dimensions": DIMENSIONS,
              "targetWords": TARGET, "overlapWords": OVERLAP, "encoding": "base64 little-endian float32",
              "postCount": len(source["posts"]), "sectionCount": len(rows), "files": files,
              "manifestSha256": digest(PUBLIC / "manifest.json"), "preparedSha256": manifest["preparedHash"],
              "existingPilotExcluded": True, "requiresExplicitIntegration": True,
              "pilotPackage": {"path": "experiments/section-search/private/runtime/sections-300.jsonl.gz",
                               "sha256": digest(SECTION / "private/runtime/sections-300.jsonl.gz")}}
    save(folder / "manifest.json", record)
    save(PUBLIC / "usage.json", usage)
    verify()


def verify():
    manifest, source, vectors, usage = receipts()
    require(corpus.read(PUBLIC / "usage.json") == usage, "Usage ledger changed")
    folder = WORK / "package"
    record = corpus.read(folder / "manifest.json")
    require(record["manifestSha256"] == digest(PUBLIC / "manifest.json"), "Wrong package provenance")
    for name, expected in record["files"].items():
        require(name in ("posts.jsonl.gz", "sections-300-512.jsonl.gz"), "Unexpected package file")
        require(digest(folder / name) == expected["sha256"], "Package checksum mismatch")
        require((folder / name).stat().st_size == expected["bytes"], "Package size mismatch")
    with gzip.open(folder / "posts.jsonl.gz", "rt", encoding="utf-8") as handle:
        posts = [json.loads(line) for line in handle]
    require(posts == source["posts"], "Package post metadata mismatch")
    with gzip.open(folder / "sections-300-512.jsonl.gz", "rt", encoding="utf-8") as handle:
        rows = [json.loads(line) for line in handle]
    require(len(rows) == len(source["sections"]) == record["sectionCount"], "Package section count mismatch")
    current, _ = corpus.source_posts()
    current = {str(p["wpId"]): p for p in current}
    grouped = collections.defaultdict(list)
    for row, original in zip(rows, source["sections"]):
        require({k: v for k, v in row.items() if k != "embedding"} ==
                {k: v for k, v in original.items() if k != "input"}, "Package section text/identity changed")
        require(decode_vector(row["embedding"]) == vectors[row["hash"]], "Vector does not match provider receipt")
        value = "Title: " + current[str(row["wpId"])]["title"] + "\nPassage: " + row["text"]
        require(corpus.digest(value) == row["hash"], "Embedding input hash mismatch")
        grouped[str(row["wpId"])].append(row)
    require(len(grouped) == record["postCount"] == manifest["remainingPostCount"], "Package post count mismatch")
    for identity, chunks in grouped.items():
        require([r["section"] for r in chunks] == list(range(len(chunks))), "Duplicate/missing section IDs")
        validate_chunks(current[identity]["text"], chunks)
    preserve(manifest)
    result = {"run": RUN, "postCount": len(grouped), "sectionCount": len(rows),
              "uniqueInputs": manifest["uniqueInputs"], "newEmbeddingInputs": manifest["newEmbeddingInputs"],
              "reusedPilotInputs": manifest["reusedPilotInputs"], "inputTokens": usage["inputTokens"],
              "requests": usage["requests"], "costUsd": usage["costUsd"],
              "rawVectorBytes": len(rows) * DIMENSIONS * 4,
              "packageBytes": sum(p.stat().st_size for p in folder.iterdir()),
              "preservedFileCount": len(manifest["preservedFiles"]),
              "allStoredWordsCovered": True, "overlapAndBoundariesVerified": True,
              "vectorsMatchedToReceipts": True, "priorFilesUnchanged": True,
              "installedOrImported": False, "productionChanged": False}
    save(PUBLIC / "results.json", result)
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("prepare", "embed", "package", "verify"))
    parser.add_argument("--approve-api", action="store_true")
    args = parser.parse_args()
    if args.command == "embed" and not args.approve_api:
        parser.error("Paid embedding generation requires --approve-api after user authorization")
    if args.command == "embed":
        _, _, _, usage = receipts(allow_api=True)
        print(json.dumps({k: v for k, v in usage.items() if k != "receipts"}, indent=2))
    else:
        {"prepare": prepare, "package": package, "verify": verify}[args.command]()
