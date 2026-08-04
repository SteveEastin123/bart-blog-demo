"""Create a compact diagnostic report for a post-topic audit batch."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"


def normalize(value: str) -> str:
    value = value.casefold().replace("’", "'")
    value = re.sub(r"[^a-z0-9']+", " ", value)
    return " ".join(value.split())


def contains_phrase(text: str, phrase: str) -> bool:
    return f" {normalize(phrase)} " in f" {normalize(text)} "


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", type=int, required=True, help="Zero-based index")
    parser.add_argument("--count", type=int, required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    topics = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    topic_names = [topic["name"] for topic in topics if topic["name"] != "Ignore"]
    with RAW_PATH.open(encoding="utf-8") as handle:
        raw_by_id = {str(post["wpId"]): post for post in map(json.loads, handle)}

    rows = []
    for source_index, post in enumerate(
        posts[args.start : args.start + args.count], args.start
    ):
        raw_text = raw_by_id[str(post["wpId"])].get("text", "")
        title_description = f"{post['title']} {post.get('description', '')}"
        current = list(post.get("topics", []))
        rows.append(
            {
                "auditSequence": source_index + 1,
                "wpId": str(post["wpId"]),
                "title": post["title"],
                "description": post.get("description", ""),
                "topics": current,
                "secondaryKeywords": post.get("secondaryKeywords", []),
                "wordCount": len(raw_text.split()),
                "currentTopicExactInTitleOrDescription": [
                    topic
                    for topic in current
                    if contains_phrase(title_description, topic)
                ],
                "currentTopicExactAbsentFromFullText": [
                    topic
                    for topic in current
                    if topic != "Ignore" and not contains_phrase(raw_text, topic)
                ],
                "unassignedTopicExactInTitleOrDescription": [
                    topic
                    for topic in topic_names
                    if topic not in current and contains_phrase(title_description, topic)
                ],
            }
        )

    output = args.output or (
        ROOT
        / ".tmp"
        / f"post-topic-diagnostics-{args.start + 1}-{args.start + args.count}.json"
    )
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(rows, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {len(rows)} diagnostics to {output}")


if __name__ == "__main__":
    main()
