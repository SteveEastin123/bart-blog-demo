#!/usr/bin/env python3
"""Apply the approved re-audit of all current two-post secondary keywords."""

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
    / "two_post_secondary_keyword_reaudit_secondary_keyword_audit.json"
)

EXPECTED_KEYWORD_COUNT = 181
REMOVALS = {
    "Critical Editions": {
        "13419": (
            "The post explains why translators retain familiar textual additions; "
            "it does not discuss the creation or use of critical editions."
        ),
    },
    "Deconversion": {
        "4365": (
            "Deconversion appears only as background identifying introductory "
            "material removed from a book, not as a subject of the post."
        ),
    },
    "Left Behind": {
        "32743": (
            "The Left Behind franchise is mentioned only as a later comparison; "
            "the post concerns the film A Thief in the Night."
        ),
        "9032": (
            "The Left Behind franchise is mentioned only as a later comparison; "
            "the post concerns the film A Thief in the Night."
        ),
    },
    "Messianic Secret": {
        "7385": (
            "The term occurs only in a brief parenthetical example while the post "
            "focuses on form criticism and oral tradition."
        ),
    },
    "Reimarus": {
        "50273": (
            "Reimarus receives only a passing historical attribution in a post "
            "about whether Jesus was a pacifist."
        ),
    },
    "Valentinus": {
        "7648": (
            "The post discusses Valentinian interpretation and Ptolemy, but not "
            "Valentinus himself."
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

    updated_counts = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    retired_keywords = sorted(
        (keyword for keyword in REMOVALS if updated_counts[keyword] == 0),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly two posts before this "
            "re-audit"
        ),
        "criterion": (
            "Retain terms representing meaningful supporting subjects, people, "
            "texts, places, events, or concepts; remove terms based only on passing "
            "mentions, incidental examples, or misleading personal-name matches."
        ),
        "auditedKeywords": audited_keywords,
        "summary": {
            "keywordsAudited": len(audited_keywords),
            "linksReviewed": len(audited_keywords) * 2,
            "linksRemoved": len(removed_posts),
            "linksRetained": len(audited_keywords) * 2 - len(removed_posts),
            "keywordsRetired": len(retired_keywords),
        },
        "retiredKeywords": retired_keywords,
        "removedPosts": removed_posts,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    print("Retired:", ", ".join(retired_keywords) or "none")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
