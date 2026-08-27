#!/usr/bin/env python3
"""Rename the Heaven and Hell book topic without changing its keyword."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
OLD_NAME = "Heaven and Hell"
NEW_NAME = "Heaven and Hell (Book)"


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, payload: Any) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    temporary.replace(path)


def replace_exact(values: list[str]) -> int:
    replacements = 0
    for index, value in enumerate(values):
        if value == OLD_NAME:
            values[index] = NEW_NAME
            replacements += 1
    return replacements


def main() -> None:
    topics_path = ROOT / "data/index/ehrman_post_topics.json"
    categories_path = ROOT / "data/index/ehrman_post_categories.json"
    posts_path = ROOT / "data/index/ehrman_post_search_index.json"
    topic_tracker_path = ROOT / "data/audits/ehrman_topic_audit_tracker.json"
    link_tracker_path = ROOT / "data/audits/ehrman_post_topic_link_audit_tracker.json"

    topics = load_json(topics_path)
    topic_records = topics["topics"]
    old_topics = [topic for topic in topic_records if topic["name"] == OLD_NAME]
    if len(old_topics) != 1:
        raise ValueError(f"Expected one {OLD_NAME!r} topic; found {len(old_topics)}")
    if any(topic["name"] == NEW_NAME for topic in topic_records):
        raise ValueError(f"Topic {NEW_NAME!r} already exists")
    old_topics[0]["name"] = NEW_NAME

    categories = load_json(categories_path)
    category_replacements = sum(
        replace_exact(category.get("topicOrder", []))
        for category in categories["categories"]
    )
    if category_replacements != 1:
        raise ValueError(
            f"Expected one category-order replacement; found {category_replacements}"
        )

    posts = load_json(posts_path)
    post_replacements = sum(replace_exact(post.get("topics", [])) for post in posts)
    if post_replacements != 37:
        raise ValueError(f"Expected 37 post replacements; found {post_replacements}")

    topic_tracker = load_json(topic_tracker_path)
    tracker_topics = [
        topic for topic in topic_tracker["topics"] if topic["topic"] == OLD_NAME
    ]
    if len(tracker_topics) != 1:
        raise ValueError(
            f"Expected one topic-tracker replacement; found {len(tracker_topics)}"
        )
    tracker_topics[0]["topic"] = NEW_NAME

    link_tracker = load_json(link_tracker_path)
    link_replacements = 0
    for post in link_tracker["posts"]:
        for field in (
            "topicsBefore",
            "topicsRecommended",
            "topicsAdded",
            "topicsRemoved",
        ):
            link_replacements += replace_exact(post.get(field, []))

    if link_replacements == 0:
        raise ValueError("Expected topic references in the post-topic link tracker")

    write_json(topics_path, topics)
    write_json(categories_path, categories)
    write_json(posts_path, posts)
    write_json(topic_tracker_path, topic_tracker)
    write_json(link_tracker_path, link_tracker)

    print(
        "Renamed topic in definition, category order, "
        f"{post_replacements} post assignments, and {link_replacements} tracker references."
    )


if __name__ == "__main__":
    main()
