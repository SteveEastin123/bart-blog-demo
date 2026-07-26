"""Print full post text or label-centered excerpts for manual audit verification."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"


def load_raw_posts() -> dict[str, dict[str, Any]]:
    with RAW_PATH.open(encoding="utf-8") as handle:
        return {str(post.get("wpId")): post for post in map(json.loads, handle)}


def excerpts(text: str, labels: list[str], radius: int) -> list[str]:
    results: list[str] = []
    for label in labels:
        pattern = re.compile(rf"(?i)(?<![A-Za-z0-9]){re.escape(label)}(?![A-Za-z0-9])")
        for match in pattern.finditer(text):
            start = max(0, match.start() - radius)
            end = min(len(text), match.end() + radius)
            results.append(f"[{label}] ...{text[start:end].strip()}...")
    return results


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--indices", required=True, help="Comma-separated zero-based indices")
    parser.add_argument("--labels", default="", help="Comma-separated labels for excerpts")
    parser.add_argument("--radius", type=int, default=350)
    parser.add_argument("--full", action="store_true")
    args = parser.parse_args()

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    raw_posts = load_raw_posts()
    indices = [int(value) for value in args.indices.split(",") if value.strip()]
    labels = [value.strip() for value in args.labels.split(",") if value.strip()]

    for index in indices:
        post = posts[index]
        raw = raw_posts.get(str(post.get("wpId")), {})
        text = raw.get("text", "")
        print(f"\n=== {index}: {post.get('title')} ===")
        print("Topics:", json.dumps(post.get("topics", []), ensure_ascii=False))
        print("Keywords:", json.dumps(post.get("secondaryKeywords", []), ensure_ascii=False))
        if args.full:
            print(text)
        else:
            for excerpt in excerpts(text, labels, args.radius):
                print(excerpt)


if __name__ == "__main__":
    main()
