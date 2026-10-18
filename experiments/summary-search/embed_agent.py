"""Embed frozen agent summaries only; never install or replace production data."""

import argparse
import base64
import hashlib
import json
import math
import struct
import sys

import agent_work as authoring

sys.path.insert(0, str(authoring.HERE.parent / "section-search"))
from api import call

MODEL = "text-embedding-3-small"
DIMENSIONS = 512
BATCH_SIZE = 32
PRICE_PER_MILLION = 0.02
PRICE_SOURCE = "https://developers.openai.com/api/docs/models/text-embedding-3-small"
PRICE_CHECKED = "2026-10-17"


def sha(text):
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def load_inputs():
    manifest = authoring.integrity()
    path = authoring.WORK / "summaries.json"
    review = authoring.read(authoring.PUBLIC / "quality-review.json")
    if authoring.digest(path) != review["summaryFileSha256"]:
        raise ValueError("Reviewed summary package changed")
    summaries = authoring.read(path)
    corpus = authoring.source()
    if summaries["run"] != authoring.RUN or summaries["sampleHash"] != manifest["sampleHash"]:
        raise ValueError("Wrong summary run or sample")
    ids = [p["wpId"] for p in summaries["posts"]]
    if len(ids) != 500 or len(set(ids)) != 500 or ids != [p["wpId"] for p in corpus["posts"]]:
        raise ValueError("Require the unchanged, ordered 500-post sample")
    inputs = []
    for row, post in zip(summaries["posts"], corpus["posts"]):
        authoring.validate_record(row, post)
        if row["title"] != post["title"] or row["textHash"] != post["textHash"]:
            raise ValueError("Source metadata changed")
        # Only these two fields enter the embedding, never audit quotations.
        content = "Title: " + row["title"] + "\nSummary: " + row["searchSummary"]
        inputs.append({"wpId": row["wpId"], "title": row["title"],
                       "summary": row["searchSummary"], "content": content,
                       "contentHash": sha(content)})
    return inputs, manifest, review["summaryFileSha256"]


def payload_for(batch):
    return {"model": MODEL, "dimensions": DIMENSIONS, "encoding_format": "float",
            "input": [row["content"] for row in batch]}


def request_hash(batch):
    # Match the existing API helper's cache identity exactly.
    return sha(json.dumps(payload_for(batch), ensure_ascii=False))


def vector_norm(vector):
    if not isinstance(vector, (list, tuple)) or len(vector) != DIMENSIONS:
        raise ValueError("Wrong embedding dimensions")
    if any(type(v) not in (float, int) or not math.isfinite(v) for v in vector):
        raise ValueError("Nonfinite or nonnumeric embedding")
    norm = math.sqrt(sum(v * v for v in vector))
    if not math.isfinite(norm) or not 0.99 <= norm <= 1.01:
        raise ValueError("Embedding is not unit length")
    return norm


def decode_vector(encoded):
    raw = base64.b64decode(encoded, validate=True)
    if len(raw) != DIMENSIONS * 4:
        raise ValueError("Invalid packed vector size")
    vector = struct.unpack("<512f", raw)
    vector_norm(vector)
    return vector


def validate_response(record, batch):
    response = record["response"]
    if record["requestHash"] != request_hash(batch) or record["model"] != MODEL or response["model"] != MODEL:
        raise ValueError("Embedding response belongs to another request or model")
    rows = response["data"]
    indices = [row["index"] for row in rows]
    if any(type(i) is not int for i in indices) or sorted(indices) != list(range(len(batch))):
        raise ValueError("Embedding response indices do not match the batch")
    usage = response["usage"]
    if type(usage["prompt_tokens"]) is not int or usage["prompt_tokens"] <= 0 or usage["total_tokens"] != usage["prompt_tokens"]:
        raise ValueError("Missing or invalid embedding token usage")
    vectors = {}
    for row in rows:
        vector_norm(row["embedding"])
        source = batch[row["index"]]
        encoded = base64.b64encode(struct.pack("<512f", *row["embedding"])).decode("ascii")
        decode_vector(encoded)
        vectors[source["wpId"]] = {
            "wpId": source["wpId"], "contentHash": source["contentHash"],
            "model": MODEL, "dimensions": DIMENSIONS, "embedding": encoded,
            "requestHash": record["requestHash"],
        }
    return vectors


def collect(inputs, work, caller=None, progress=False):
    """Reuse durable receipts on resume; caller=None guarantees offline verification."""
    vectors, receipts = {}, []
    for start in range(0, len(inputs), BATCH_SIZE):
        batch = inputs[start:start + BATCH_SIZE]
        identity = request_hash(batch)
        cache = work / "embedding-cache" / (identity + ".json")
        if cache.exists():
            record = authoring.read(cache)
        elif caller is not None:
            record = caller("embeddings", payload_for(batch), "agent-summary-embedding",
                            cache_root=work / "embedding-cache")
            authoring.save(cache, record, frozen=True)
        else:
            raise ValueError("Missing saved embedding response; verify never makes API calls")
        checked = validate_response(record, batch)
        for wp, vector in checked.items():
            path = work / "vectors" / (wp + ".json")
            if caller is None:
                if authoring.read(path) != vector:
                    raise ValueError("Stored vector differs from the saved API response")
            else:
                authoring.save(path, vector, frozen=True)
        vectors.update(checked)
        receipts.append({"requestHash": identity, "responseSha256": authoring.digest(cache),
                         "posts": len(batch), "inputTokens": record["response"]["usage"]["prompt_tokens"]})
        if progress:
            print(json.dumps({"verifiedVectors": len(vectors), "total": len(inputs)}), flush=True)
    return vectors, receipts


def execute(command):
    inputs, original, summary_hash = load_inputs()
    manifest = {"run": authoring.RUN, "sampleHash": original["sampleHash"],
                "postCount": len(inputs), "summaryFileSha256": summary_hash,
                "model": MODEL, "dimensions": DIMENSIONS, "batchSize": BATCH_SIZE,
                "inputFormat": "Title: {title}\nSummary: {summary}",
                "sourceQuotesIncluded": False, "apiScope": "embeddings only",
                "pricePerMillionInputTokensUsd": PRICE_PER_MILLION,
                "priceSource": PRICE_SOURCE, "priceChecked": PRICE_CHECKED,
                "generatorSha256": authoring.digest(authoring.HERE / "embed_agent.py"),
                "requestHashes": [request_hash(inputs[i:i + BATCH_SIZE]) for i in range(0, len(inputs), BATCH_SIZE)]}
    target = authoring.PUBLIC / "embedding-manifest.json"
    if command == "plan":
        print(json.dumps({"posts": len(inputs), "model": MODEL, "dimensions": DIMENSIONS,
                          "requests": len(manifest["requestHashes"]), "preservedFiles": len(original["preserved"]),
                          "summaryFileSha256": summary_hash, "apiCalls": 0}, indent=2))
        return
    if command == "embed":
        authoring.save(target, manifest, frozen=True)
    elif authoring.read(target) != manifest:
        raise ValueError("Frozen embedding manifest changed")
    vectors, receipts = collect(inputs, authoring.WORK, call if command == "embed" else None, progress=True)
    authoring.integrity()
    if authoring.digest(authoring.WORK / "summaries.json") != summary_hash:
        raise ValueError("Summary package changed during generation")
    package = {"run": authoring.RUN, "sampleHash": original["sampleHash"],
               "summaryFileSha256": summary_hash, "model": MODEL, "dimensions": DIMENSIONS,
               "posts": [{"wpId": p["wpId"], "title": p["title"], "summary": p["summary"],
                          "vector": vectors[p["wpId"]]} for p in inputs]}
    path = authoring.WORK / "fixtures/improved.json"
    if command == "embed":
        authoring.save(path, package, frozen=True)
    elif authoring.read(path) != package:
        raise ValueError("Frozen vector package changed")
    tokens = sum(r["inputTokens"] for r in receipts)
    norms = [vector_norm(decode_vector(v["embedding"])) for v in vectors.values()]
    report = {"run": authoring.RUN, "status": "embeddings_complete_search_comparison_not_run",
              "vectors": len(vectors), "model": MODEL, "dimensions": DIMENSIONS,
              "summaryFileSha256": summary_hash, "packageSha256": authoring.digest(path),
              "packageFile": str(path.relative_to(authoring.ROOT)).replace("\\", "/"),
              "packageBytes": path.stat().st_size, "rawVectorBytes": len(vectors) * DIMENSIONS * 4,
              "successfulRequests": len(receipts), "inputTokens": tokens,
              "estimatedEmbeddingCostUsd": round(tokens / 1_000_000 * PRICE_PER_MILLION, 8),
              "pricePerMillionInputTokensUsd": PRICE_PER_MILLION,
              "priceSource": PRICE_SOURCE, "priceChecked": PRICE_CHECKED,
              "costScope": "Successful saved embedding responses only; provider invoice is authoritative.",
              "unitNormRange": [min(norms), max(norms)], "receipts": receipts,
              "unchangedPreservedFiles": len(original["preserved"]),
              "canonicalDataChanged": False, "databaseWrites": False,
              "searchComparisonRun": False, "summaryGenerationApiCalls": 0}
    report_path = authoring.PUBLIC / "embedding-results.json"
    if command == "embed":
        authoring.save(report_path, report, frozen=True)
    elif authoring.read(report_path) != report:
        raise ValueError("Embedding report no longer matches saved evidence")
    print(json.dumps({k: v for k, v in report.items() if k != "receipts"}, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["plan", "embed", "verify"])
    parser.add_argument("--approve-api", action="store_true")
    args = parser.parse_args()
    if args.command == "embed" and not args.approve_api:
        parser.error("Embedding requires explicit --approve-api after user authorization")
    execute(args.command)
