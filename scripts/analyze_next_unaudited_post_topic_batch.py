"""Create diagnostics for the next posts not represented in the audit tracker."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"


def normalize(value: str) -> str:
    value = value.casefold().replace("â€™", "'")
    value = re.sub(r"[^a-z0-9']+", " ", value)
    return " ".join(value.split())


def contains_phrase(text: str, phrase: str) -> bool:
    return f" {normalize(phrase)} " in f" {normalize(text)} "


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--count", type=int, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    topics = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    tracker = json.loads(TRACKER_PATH.read_text(encoding="utf-8"))
    audited_ids = {str(entry["wpId"]) for entry in tracker["posts"]}
    batch = [post for post in posts if str(post["wpId"]) not in audited_ids][
        : args.count
    ]
    if len(batch) != args.count:
        raise ValueError(f"Expected {args.count} unaudited posts, found {len(batch)}")

    with RAW_PATH.open(encoding="utf-8") as handle:
        raw_by_id = {str(post["wpId"]): post for post in map(json.loads, handle)}
    topic_names = [topic["name"] for topic in topics if topic["name"] != "Ignore"]

    rows = []
    for offset, post in enumerate(batch, start=1):
        wp_id = str(post["wpId"])
        raw_text = raw_by_id[wp_id].get("text", "")
        title_description = f"{post['title']} {post.get('description', '')}"
        current = list(post.get("topics", []))
        rows.append(
            {
                "auditSequence": len(tracker["posts"]) + offset,
                "sourceIndex": posts.index(post),
                "wpId": wp_id,
                "title": post["title"],
                "dateText": post.get("dateText"),
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

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        json.dumps(rows, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Wrote diagnostics for {len(rows)} unaudited posts to {args.output}")


if __name__ == "__main__":
    main()
