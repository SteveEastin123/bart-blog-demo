"""Print reproducible full-text packets for post-to-topic linkage audits."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"


def load_raw_posts() -> dict[str, dict[str, Any]]:
    with RAW_PATH.open(encoding="utf-8") as handle:
        return {str(post.get("wpId")): post for post in map(json.loads, handle)}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", type=int, required=True, help="Zero-based index")
    parser.add_argument("--count", type=int, default=10)
    parser.add_argument(
        "--wpids",
        help="Optional comma-separated wpIds; when supplied, ignores start/count selection.",
    )
    parser.add_argument(
        "--topic-names-only",
        action="store_true",
        help="Print current topic names without repeating their descriptions",
    )
    args = parser.parse_args()

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    raw_posts = load_raw_posts()
    topic_records = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    topic_descriptions = {
        topic["name"]: topic.get("description", "") for topic in topic_records
    }

    if args.wpids:
        requested = {value.strip() for value in args.wpids.split(",") if value.strip()}
        selected_with_indexes = [
            (index, post)
            for index, post in enumerate(posts)
            if str(post.get("wpId")) in requested
        ]
        found = {str(post.get("wpId")) for _, post in selected_with_indexes}
        missing = sorted(requested - found)
        if missing:
            raise ValueError(f"No indexed post found for wpId(s): {', '.join(missing)}")
    else:
        selected_with_indexes = list(
            enumerate(posts[args.start : args.start + args.count], args.start)
        )

    for offset, post in selected_with_indexes:
        raw = raw_posts.get(str(post.get("wpId")))
        if raw is None:
            raise ValueError(f"No raw post found for wpId {post.get('wpId')}")
        print(f"\n{'=' * 88}")
        print(f"POST {offset + 1} | wpId {post.get('wpId')} | {post.get('dateText', '')}")
        print(f"TITLE: {post.get('title', '')}")
        print(f"DESCRIPTION: {post.get('description', '')}")
        print("CURRENT TOPICS:")
        for topic in post.get("topics", []):
            if args.topic_names_only:
                print(f"- {topic}")
            else:
                print(f"- {topic}: {topic_descriptions.get(topic, '[missing topic record]')}")
        print("FULL TEXT:")
        print(raw.get("text", ""))


if __name__ == "__main__":
    main()
