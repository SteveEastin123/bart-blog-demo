"""Reproducible corpus selection and paragraph-aware experimental chunks."""

import collections
import gzip
import hashlib
import json
import math
import random
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
PRIVATE = HERE / "private"
SEED = "ehrman-section-pilot-2026-10-16-v1"
SIZES = (300, 400, 500)


def digest(value):
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def save(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + ".partial")
    temp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temp.replace(path)


def read(path):
    return json.loads(path.read_text(encoding="utf-8"))


def source_posts():
    canonical = read(ROOT / "data/index/ehrman_post_search_index.json")
    topics = read(ROOT / "data/index/ehrman_post_topics.json")["topics"]
    categories = {t["name"]: t.get("categories", []) for t in topics}
    raw = {}
    with (ROOT / "data/raw/posts.jsonl").open(encoding="utf-8") as handle:
        for line in handle:
            post = json.loads(line)
            raw[str(post.get("wpId"))] = post
    with gzip.open(ROOT / "data/index/ehrman_post_embeddings.jsonl.gz", "rt", encoding="utf-8") as handle:
        header = json.loads(next(handle))
        vectors = {str(p["sourceWpId"]): p for p in map(json.loads, handle)}
    posts = []
    for post in canonical:
        wp_id = str(post["wpId"])
        text = raw.get(wp_id, {}).get("text", "").strip()
        words = len(text.split())
        if wp_id not in vectors or "Ignore" in post["topics"] or not text:
            continue
        basis = "Title: " + post["title"] + "\nSummary: " + post["searchSummary"]
        if digest(basis) != vectors[wp_id]["contentHash"]:
            raise ValueError(f"Stale summary vector for {wp_id}")
        cats = sorted({c for t in post["topics"] for c in categories.get(t, [])})
        posts.append({**post, "text": text, "wordCount": words, "textHash": digest(text),
                      "categories": cats, "vector": vectors[wp_id]})
    return posts, header


def primary_category(post):
    cats = post["categories"] or ["Uncategorized"]
    # A stable assignment prevents multi-category posts from being sampled repeatedly.
    return min(cats, key=lambda c: digest(SEED + post["wpId"] + c))


def length_band(post):
    n = post["wordCount"]
    return "short" if n < 800 else "medium" if n < 1400 else "long"


def challenge_signals(post):
    summary = post["searchSummary"].lower()
    full = post["text"].lower()
    omitted = [t for t in post["secondaryKeywords"]
               if len(t) > 3 and full.count(t.lower()) >= 3 and t.lower() not in summary]
    return {"long": post["wordCount"] >= 1600,
            "multi_subject": len(post["topics"]) >= 3,
            "summary_omissions": omitted[:8]}


def nominate():
    if (HERE / "sample.json").exists():
        raise ValueError("Sample is frozen; use a new experiment directory to reselect.")
    posts, header = source_posts()
    # Tiny extracted texts may be notices, media-only articles, or incomplete downloads.
    eligible = [p for p in posts if p["wordCount"] >= 100]
    groups = collections.defaultdict(list)
    for post in eligible:
        groups[(primary_category(post), length_band(post))].append(post)
    counts = {k: 400 * len(v) / len(eligible) for k, v in groups.items()}
    quotas = {k: math.floor(n) for k, n in counts.items()}
    for key in sorted(counts, key=lambda k: (-(counts[k] - quotas[k]), k))[:400 - sum(quotas.values())]:
        quotas[key] += 1
    representative = []
    for key, group in sorted(groups.items()):
        ordered = sorted(group, key=lambda p: digest(SEED + p["wpId"]))
        representative.extend(ordered[:quotas[key]])
    ids = {p["wpId"] for p in representative}
    # Nominate extra cases for full-text review, not automatic claims of missing coverage.
    remainder = [p for p in eligible if p["wpId"] not in ids]
    nominated = []
    for kind, quota in (("summary_omissions", 50), ("multi_subject", 50), ("long", 50)):
        pool = [p for p in remainder if p["wpId"] not in ids and challenge_signals(p)[kind]]
        pool.sort(key=lambda p: digest(SEED + kind + p["wpId"]))
        for post in pool[:quota]:
            ids.add(post["wpId"])
            nominated.append({**post, "nomination": kind, "signals": challenge_signals(post)})
    save(PRIVATE / "selection-work.json", {"representative": representative, "nominations": nominated,
                                          "sourceCount": len(posts), "eligibleCount": len(eligible),
                                          "embeddingHeader": header})
    return {"representative": len(representative), "challengeNominations": len(nominated),
            "eligible": len(eligible), "excludedShort": len(posts) - len(eligible)}


def freeze():
    if (HERE / "sample.json").exists():
        raise ValueError("Sample already frozen")
    work = read(PRIVATE / "selection-work.json")
    reviews = read(PRIVATE / "challenge-reviews.json")
    by_id = {r["wpId"]: r for r in reviews}
    candidates = []
    for post in work["nominations"]:
        review = by_id[post["wpId"]]
        if review["complete"] and review["challenge"]:
            candidates.append((post, review))
    if len(candidates) < 100:
        raise ValueError(f"Only {len(candidates)} full-text-confirmed challenge posts; need 100")
    candidates.sort(key=lambda pair: digest(SEED + "confirmed" + pair[0]["wpId"]))
    chosen = [(p, "representative", "Seeded proportional category/length sample") for p in work["representative"]]
    chosen += [(p, "challenge", r["reason"]) for p, r in candidates[:100]]
    manifest, corpus = [], []
    for post, group, reason in chosen:
        metadata = {k: post[k] for k in ("wpId", "title", "url", "dateText", "topics", "categories", "wordCount", "textHash")}
        metadata.update(group=group, reason=reason, primaryCategory=primary_category(post), lengthBand=length_band(post))
        manifest.append(metadata)
        corpus.append({**post, "group": group, "reason": reason})
    assert len({p["wpId"] for p in manifest}) == 500
    identity = digest(json.dumps(manifest, sort_keys=True))
    save(HERE / "sample.json", {"version": 1, "seed": SEED, "sampleHash": identity,
                               "eligibleSourceCount": work["eligibleCount"], "posts": manifest})
    save(PRIVATE / "corpus.json", {"sampleHash": identity, "posts": corpus})
    return {"posts": len(corpus), "sampleHash": identity}


def chunks(text, target):
    """Cover every word; prefer a paragraph end within 20% of the target."""
    matches = list(re.finditer(r"\S+", text))
    boundaries = set()
    for index in range(1, len(matches)):
        if "\n" in text[matches[index - 1].end():matches[index].start()]:
            boundaries.add(index)
    start = 0
    while start < len(matches):
        ideal = min(start + target, len(matches))
        end = ideal
        if ideal < len(matches):
            nearby = [b for b in boundaries if start + target * .8 <= b <= min(len(matches), start + target * 1.2)]
            if nearby:
                end = min(nearby, key=lambda b: (abs(b - ideal), b))
        yield {"startWord": start, "endWord": end,
               "text": text[matches[start].start():matches[end - 1].end()]}
        if end == len(matches):
            break
        start = max(start + 1, end - round(target * .2))


def make_sections():
    corpus = read(PRIVATE / "corpus.json")
    records = []
    for size in SIZES:
        for post in corpus["posts"]:
            for number, chunk in enumerate(chunks(post["text"], size)):
                text = "Title: " + post["title"] + "\nPassage: " + chunk["text"]
                records.append({**chunk, "wpId": post["wpId"], "size": size, "section": number,
                                "hash": digest(text), "input": text})
    save(PRIVATE / "sections.json", {"sampleHash": corpus["sampleHash"], "sections": records})
    return dict(collections.Counter(str(r["size"]) for r in records))


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["nominate", "freeze", "sections"])
    args = parser.parse_args()
    print(json.dumps({"nominate": nominate, "freeze": freeze, "sections": make_sections}[args.command](), indent=2))
