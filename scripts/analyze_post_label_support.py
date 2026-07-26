"""Find post labels that merit manual full-text review.

This is a conservative screening tool, not an automatic topic or keyword auditor.
It measures where current labels occur in each post's title, audited description,
and full raw text so a human reviewer can focus on potentially incidental labels.
"""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INDEX = ROOT / "data" / "index" / "ehrman_post_search_index.json"
DEFAULT_RAW = ROOT / "data" / "raw" / "posts.jsonl"
STOP_WORDS = {"a", "an", "and", "as", "for", "from", "in", "of", "on", "or", "the", "to", "with"}


def normalize(value: str) -> str:
    return " ".join(re.findall(r"[a-z0-9]+", value.casefold().replace("’", "'")))


def phrase_count(needle: str, haystack: str) -> int:
    if not needle:
        return 0
    return len(re.findall(rf"(?<![a-z0-9]){re.escape(needle)}(?![a-z0-9])", haystack))


def load_raw_posts(path: Path) -> dict[str, dict[str, Any]]:
    posts: dict[str, dict[str, Any]] = {}
    with path.open(encoding="utf-8") as handle:
        for line in handle:
            post = json.loads(line)
            posts[str(post.get("wpId", ""))] = post
    return posts


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", type=int, default=0, help="Zero-based start index")
    parser.add_argument("--count", type=int, default=100)
    parser.add_argument("--index", type=Path, default=DEFAULT_INDEX)
    parser.add_argument("--raw", type=Path, default=DEFAULT_RAW)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    posts = json.loads(args.index.read_text(encoding="utf-8"))
    raw_posts = load_raw_posts(args.raw)
    candidates: list[dict[str, Any]] = []

    for index, post in enumerate(posts[args.start : args.start + args.count], args.start):
        raw = raw_posts.get(str(post.get("wpId")), {})
        title = normalize(post.get("title", ""))
        description = normalize(post.get("description", ""))
        full_text = normalize(raw.get("text", ""))
        combined = " ".join((title, description, full_text))
        token_counts = Counter(combined.split())

        for field in ("topics", "secondaryKeywords"):
            for label in post.get(field, []):
                normalized_label = normalize(label)
                count = phrase_count(normalized_label, combined)
                in_heading = normalized_label in title or normalized_label in description
                if count == 0 or (count == 1 and not in_heading):
                    content_tokens = [
                        token for token in normalized_label.split() if token not in STOP_WORDS
                    ]
                    candidates.append(
                        {
                            "index": index,
                            "wpId": post.get("wpId"),
                            "title": post.get("title"),
                            "field": field,
                            "label": label,
                            "occurrences": count,
                            "inTitleOrDescription": in_heading,
                            "contentTokenCounts": {
                                token: token_counts[token] for token in content_tokens
                            },
                        }
                    )

    report = {
        "start": args.start,
        "count": min(args.count, max(0, len(posts) - args.start)),
        "candidateCount": len(candidates),
        "candidatePostCount": len({row["index"] for row in candidates}),
        "candidateLabels": Counter(row["label"] for row in candidates).most_common(),
        "candidates": candidates,
    }
    rendered = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        args.output.write_text(rendered, encoding="utf-8")
    else:
        print(rendered, end="")


if __name__ == "__main__":
    main()
