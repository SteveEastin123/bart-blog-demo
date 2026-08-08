#!/usr/bin/env python3
"""Calculate the importer counts expected from the authoritative JSON files."""

from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "data" / "index"


def load(name: str):
    with (INDEX / name).open("r", encoding="utf-8") as handle:
        return json.load(handle)


def normalize(value: str) -> str:
    value = value.strip().lower().replace("&", " and ")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9]+", " ", value).strip())


def main() -> None:
    posts = load("ehrman_post_search_index.json")
    topics = load("ehrman_post_topics.json")["topics"]
    categories = load("ehrman_post_categories.json")["categories"]
    subject_areas_1 = load("ehrman_post_subject_areas.json")["subjectAreas"]
    subject_areas_2 = load("ehrman_post_subject_areas_2.json")["subjectAreas"]

    keywords = {
        normalize(keyword)
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
        if normalize(keyword)
    }
    post_topics = sum(len(post.get("topics", [])) for post in posts)
    post_keywords = sum(len(post.get("secondaryKeywords", [])) for post in posts)

    counts = {
        "browse_paths": 2,
        "subject_areas": len(subject_areas_1) + len(subject_areas_2),
        "categories": len(categories),
        "topics": len(topics),
        "external_posts": len(posts),
        "keywords": len(keywords),
        "subject_area_categories": sum(
            len(area.get("categories", []))
            for area in subject_areas_1 + subject_areas_2
        ),
        "topic_categories": sum(len(topic.get("categories", [])) for topic in topics),
        "post_topics": post_topics,
        "post_keywords": post_keywords,
        "post_search_terms": post_topics + post_keywords,
    }
    print(json.dumps(counts, sort_keys=True))


if __name__ == "__main__":
    main()
