#!/usr/bin/env python3
"""Apply the approved full-text audit of all three-post secondary keywords."""

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
    / "three_post_secondary_keyword_audit.json"
)

EXPECTED_KEYWORD_COUNT = 84
REMOVALS = {
    "Atheism": {
        "40339": (
            "Atheism is mentioned only to contrast modern deconversion with ancient "
            "conversion from one religion to another."
        ),
    },
    "Ishmael": {
        "37901": (
            "Ishmael appears only briefly in one example of an angel helping Hagar."
        ),
    },
    "Zlatko Plese": {
        "50022": (
            "Zlatko Plese is credited once as co-editor of the source volume but is "
            "not otherwise discussed."
        ),
    },
}
RENAMES = {
    "Christian Numbers": "Early Christian Population",
    "Greek": "Greek Language",
    "New Testament Versions": "Ancient New Testament Versions",
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
    original_keywords = sorted(
        (keyword for keyword, count in counts.items() if count == 3),
        key=str.casefold,
    )
    if len(original_keywords) != EXPECTED_KEYWORD_COUNT:
        raise ValueError(
            f"Expected {EXPECTED_KEYWORD_COUNT} three-post keywords; "
            f"found {len(original_keywords)}"
        )

    removed_posts = []
    for keyword, removals in REMOVALS.items():
        if keyword not in original_keywords:
            raise ValueError(f"Expected {keyword!r} in the three-post audit scope")
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

    renamed_keywords = []
    for old_keyword, new_keyword in RENAMES.items():
        if old_keyword not in original_keywords:
            raise ValueError(f"Expected {old_keyword!r} in the audit scope")
        if counts.get(new_keyword, 0):
            raise ValueError(f"Replacement keyword already exists: {new_keyword!r}")
        affected_posts = []
        for post in posts:
            values = post.get("secondaryKeywords", [])
            if old_keyword not in values:
                continue
            post["secondaryKeywords"] = sorted(
                [new_keyword if value == old_keyword else value for value in values],
                key=str.casefold,
            )
            affected_posts.append(
                {"wpId": str(post["wpId"]), "title": post["title"]}
            )
        if len(affected_posts) != 3:
            raise ValueError(
                f"Expected three {old_keyword!r} assignments; "
                f"found {len(affected_posts)}"
            )
        renamed_keywords.append(
            {
                "from": old_keyword,
                "to": new_keyword,
                "posts": affected_posts,
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

    final_audited_keywords = sorted(
        (RENAMES.get(keyword, keyword) for keyword in original_keywords),
        key=str.casefold,
    )
    audit = {
        "auditDate": "2026-08-18",
        "scope": (
            "Every secondary keyword assigned to exactly three posts before this audit"
        ),
        "criterion": (
            "Retain terms representing meaningful supporting subjects, people, "
            "texts, places, events, or concepts; remove terms based only on passing "
            "mentions or incidental examples, and clarify ambiguous labels."
        ),
        "auditedKeywords": final_audited_keywords,
        "originalKeywords": original_keywords,
        "summary": {
            "keywordsAudited": len(original_keywords),
            "linksReviewed": len(original_keywords) * 3,
            "linksRemoved": len(removed_posts),
            "linksRetained": len(original_keywords) * 3 - len(removed_posts),
            "keywordsRenamed": len(renamed_keywords),
            "keywordsRetired": 0,
        },
        "removedPosts": removed_posts,
        "renamedKeywords": renamed_keywords,
    }
    write_json(POSTS_PATH, posts)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
