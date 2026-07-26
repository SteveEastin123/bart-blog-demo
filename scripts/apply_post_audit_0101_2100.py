"""Apply the approved full-text audit for search-index posts 101-2100."""

from __future__ import annotations

import json
from pathlib import Path

from report_post_audit_0101_2100 import UPDATES


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
START = 100
END = 2100


def update_values(
    values: list[str], remove: list[str], add: list[str], label: str
) -> list[str]:
    missing = sorted(set(remove) - set(values))
    if missing:
        raise ValueError(f"{label}: expected values are missing: {missing}")
    result = [value for value in values if value not in set(remove)]
    for value in add:
        if value not in result:
            result.append(value)
    if len(result) != len(set(result)):
        raise ValueError(f"{label}: update produced duplicate values")
    return result


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    allowed_topics = {
        topic["name"]
        for topic in json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    }

    for index, changes in UPDATES.items():
        if not START <= index < END:
            raise ValueError(f"Update outside audited range: {index}")
        post = posts[index]
        unknown_topics = sorted(set(changes.get("topic_add", [])) - allowed_topics)
        if unknown_topics:
            raise ValueError(f"{post['title']}: unknown topics: {unknown_topics}")
        post["topics"] = update_values(
            post.get("topics", []),
            changes.get("topic_remove", []),
            changes.get("topic_add", []),
            f"{post['title']} topics",
        )
        post["secondaryKeywords"] = update_values(
            post.get("secondaryKeywords", []),
            changes.get("kw_remove", []),
            changes.get("kw_add", []),
            f"{post['title']} secondary keywords",
        )

    duplicate_posts = 0
    duplicate_labels = 0
    for post in posts[START:END]:
        duplicated = set(post.get("topics", [])) & set(post.get("secondaryKeywords", []))
        if duplicated:
            duplicate_posts += 1
            duplicate_labels += len(duplicated)
            post["secondaryKeywords"] = [
                keyword
                for keyword in post.get("secondaryKeywords", [])
                if keyword not in duplicated
            ]

    for index, post in enumerate(posts):
        topics = post.get("topics", [])
        keywords = post.get("secondaryKeywords", [])
        if not topics:
            raise ValueError(f"Post {index} has no topics: {post['title']}")
        unknown_topics = sorted(set(topics) - allowed_topics)
        if unknown_topics:
            raise ValueError(f"Post {index} has unknown topics: {unknown_topics}")
        if len(topics) != len(set(topics)):
            raise ValueError(f"Post {index} has duplicate topics")
        if len(keywords) != len(set(keywords)):
            raise ValueError(f"Post {index} has duplicate secondary keywords")

    POSTS_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Applied substantive recommendations to {len(UPDATES)} posts.")
    print(
        f"Removed {duplicate_labels} redundant topic-keyword labels "
        f"from {duplicate_posts} posts."
    )


if __name__ == "__main__":
    main()
