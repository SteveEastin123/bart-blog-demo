#!/usr/bin/env python3
"""Apply the approved full-text audit of all two-post secondary keywords."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "two_post_secondary_keyword_audit.json"
)

EXPECTED_KEYWORD_COUNT = 179
REMOVALS = {
    "Gospel of the Egyptians": {
        "50148": (
            "The text is named only in a parenthetical note about one possible "
            "source for a saying quoted by 2 Clement."
        ),
    },
    "Predestination": {
        "50235": (
            "Predestination appears only in a brief autobiographical aside about "
            "Bart's former Calvinist beliefs."
        ),
    },
    "Slavery": {
        "49337": (
            "Slavery is only one item in a list of historical wrongs rather than a "
            "meaningful supporting subject."
        ),
    },
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post["wpId"]): post for post in posts}
    counts = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    audited_keywords = sorted(
        (keyword for keyword, count in counts.items() if count == 2),
        key=str.casefold,
    )
    if len(audited_keywords) != EXPECTED_KEYWORD_COUNT:
        raise ValueError(
            f"Expected {EXPECTED_KEYWORD_COUNT} two-post keywords; "
            f"found {len(audited_keywords)}"
        )

    removed_posts = []
    for keyword, removals in REMOVALS.items():
        if keyword not in audited_keywords:
            raise ValueError(f"Expected {keyword!r} in the two-post audit scope")
        for wp_id, reason in removals.items():
            post = by_id.get(wp_id)
            if post is None or keyword not in post.get("secondaryKeywords", []):
                raise ValueError(f"Missing {keyword!r} on post {wp_id}")
            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            removed_posts.append(
                {
                    "keyword": keyword,
                    "wpId": wp_id,
                    "title": post["title"],
                    "reason": reason,
                }
            )

    duplicate_keywords = [
        str(post["wpId"])
        for post in posts
        if len(post.get("secondaryKeywords", []))
        != len(
            {
                value.casefold().strip()
                for value in post.get("secondaryKeywords", [])
            }
        )
    ]
    topic_keyword_overlaps = [
        str(post["wpId"])
        for post in posts
        if {value.casefold().strip() for value in post.get("topics", [])}
        & {
            value.casefold().strip()
            for value in post.get("secondaryKeywords", [])
        }
    ]
    if duplicate_keywords or topic_keyword_overlaps:
        raise ValueError(
            "Validation failed: duplicate keywords "
            f"{duplicate_keywords[:5]}, topic/keyword overlaps "
            f"{topic_keyword_overlaps[:5]}"
        )

    audit = {
        "auditDate": "2026-08-17",
        "scope": (
            "Every secondary keyword assigned to exactly two posts before this audit"
        ),
        "criterion": (
            "Retain terms representing meaningful supporting subjects, people, "
            "texts, places, events, or concepts; remove terms based only on passing "
            "mentions or incidental examples."
        ),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(audited_keywords),
            "linksReviewed": len(audited_keywords) * 2,
            "linksRemoved": len(removed_posts),
            "linksRetained": len(audited_keywords) * 2 - len(removed_posts),
            "keywordsRetired": 0,
        },
        "removedPosts": removed_posts,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
