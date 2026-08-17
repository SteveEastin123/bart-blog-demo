"""Normalize Letter to the Romans to Romans and remove topic duplicates."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "romans_secondary_keyword_normalization.json"
OLD_KEYWORD = "Letter to the Romans"
NEW_KEYWORD = "Romans"
TOPIC = "Romans"


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    renamed = []
    topic_duplicates_removed = []

    for post in posts:
        keywords = post.get("secondaryKeywords", [])
        had_old = OLD_KEYWORD in keywords
        if had_old:
            keywords = [NEW_KEYWORD if value == OLD_KEYWORD else value for value in keywords]
            renamed.append(
                {"wpId": str(post.get("wpId", "")), "title": post.get("title", "")}
            )

        # Preserve keyword order while merging any duplicate created by normalization.
        keywords = list(dict.fromkeys(keywords))
        if TOPIC in post.get("topics", []) and NEW_KEYWORD in keywords:
            keywords = [value for value in keywords if value != NEW_KEYWORD]
            topic_duplicates_removed.append(
                {"wpId": str(post.get("wpId", "")), "title": post.get("title", "")}
            )
        post["secondaryKeywords"] = keywords

    remaining_old = sum(
        OLD_KEYWORD in post.get("secondaryKeywords", []) for post in posts
    )
    remaining_new = sum(
        NEW_KEYWORD in post.get("secondaryKeywords", []) for post in posts
    )
    remaining_topic_duplicates = sum(
        TOPIC in post.get("topics", [])
        and NEW_KEYWORD in post.get("secondaryKeywords", [])
        for post in posts
    )
    if remaining_old or remaining_topic_duplicates:
        raise RuntimeError("Romans keyword normalization did not satisfy its invariants")

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    AUDIT_PATH.write_text(
        json.dumps(
            {
                "oldKeyword": OLD_KEYWORD,
                "newKeyword": NEW_KEYWORD,
                "matchingTopic": TOPIC,
                "renamedAssignments": len(renamed),
                "topicDuplicateAssignmentsRemoved": len(topic_duplicates_removed),
                "remainingNewKeywordAssignments": remaining_new,
                "remainingTopicKeywordDuplicates": remaining_topic_duplicates,
                "renamedPosts": renamed,
                "topicDuplicatePosts": topic_duplicates_removed,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(
        f"{OLD_KEYWORD} -> {NEW_KEYWORD}: {len(renamed)} assignments renamed; "
        f"{len(topic_duplicates_removed)} topic duplicates removed; "
        f"{remaining_new} keyword assignments remain"
    )


if __name__ == "__main__":
    main()
