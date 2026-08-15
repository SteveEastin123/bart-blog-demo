#!/usr/bin/env python3
"""Remove secondary keywords duplicated by a topic on the same post."""

from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"


def normalize(value: object) -> str:
    """Match the normalization used by the public search implementation."""
    text = str(value or "").strip().lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text).strip()
    return re.sub(r"\s+", " ", text)


def main() -> int:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    removed: Counter[tuple[str, str]] = Counter()
    changed_posts = 0

    for post in posts:
        topic_names = {
            normalize(topic): topic
            for topic in post.get("topics", [])
            if normalize(topic)
        }
        original = post.get("secondaryKeywords", [])
        revised: list[str] = []

        for keyword in original:
            normalized = normalize(keyword)
            if normalized in topic_names:
                removed[(topic_names[normalized], keyword)] += 1
                continue
            revised.append(keyword)

        if revised != original:
            post["secondaryKeywords"] = revised
            changed_posts += 1

    remaining = []
    for post in posts:
        topic_keys = {normalize(topic) for topic in post.get("topics", [])}
        for keyword in post.get("secondaryKeywords", []):
            if normalize(keyword) in topic_keys:
                remaining.append((post.get("wpId"), keyword))
    if remaining:
        raise ValueError(f"Topic-keyword overlaps remain: {remaining[:10]}")

    POSTS_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )

    print(f"Updated {changed_posts} posts.")
    print(f"Removed {sum(removed.values())} redundant keyword assignments.")
    print(f"Affected {len(removed)} distinct topic/keyword label pairs.")
    for (topic, keyword), count in sorted(
        removed.items(), key=lambda item: (item[0][0].casefold(), item[0][1].casefold())
    ):
        print(f"{count}\tTOPIC={topic}\tKEYWORD={keyword}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
