"""Apply approved removals for the Forgery secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "forgery_secondary_keyword_audit.json"
KEYWORD = "Forgery"

PASSING_EXAMPLE_REMOVALS = {
    "2669", "16749", "25893", "47155",
}

BOOK_COURSE_OR_PUBLISHING_REMOVALS = {
    "2361", "7199", "7693", "8639", "12936", "14968", "32660", "40493",
}

ANONYMOUS_GOSPEL_REMOVALS = {
    "4088", "15172", "15190", "16082",
}

APPROVED_REMOVALS = (
    PASSING_EXAMPLE_REMOVALS
    | BOOK_COURSE_OR_PUBLISHING_REMOVALS
    | ANONYMOUS_GOSPEL_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in PASSING_EXAMPLE_REMOVALS:
        return (
            "Forgery appears only as a passing example, comparison, or reference "
            "to an adjacent blog thread rather than as a meaningful subject."
        )
    if wp_id in BOOK_COURSE_OR_PUBLISHING_REMOVALS:
        return (
            "Forgery appears only in a book title, course description, publishing "
            "example, or broad list of subjects rather than as a sustained topic."
        )
    return (
        "The post concerns anonymous Gospel writing rather than an author who "
        "deceptively claimed a false identity; Forged is only the source title."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 16:
        raise RuntimeError("Expected 16 approved Forgery removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Forgery removals reference unknown posts: "
            + ", ".join(sorted(unknown))
        )

    before = sum(KEYWORD in post.get("secondaryKeywords", []) for post in posts)
    removed = []
    for post in posts:
        wp_id = str(post.get("wpId", ""))
        if wp_id not in APPROVED_REMOVALS:
            continue
        if KEYWORD not in post.get("secondaryKeywords", []):
            continue

        post["secondaryKeywords"] = [
            keyword
            for keyword in post.get("secondaryKeywords", [])
            if keyword != KEYWORD
        ]
        removed.append(
            {
                "wpId": wp_id,
                "title": post.get("title", ""),
                "topics": post.get("topics", []),
                "reason": removal_reason(wp_id),
            }
        )

    after = before - len(removed)
    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    AUDIT_PATH.write_text(
        json.dumps(
            {
                "keyword": KEYWORD,
                "criterion": (
                    "Retain Forgery when false authorship, fabricated documents, "
                    "deceptive attribution, authenticity disputes, or ancient and "
                    "modern forgery materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "passingExample": len(PASSING_EXAMPLE_REMOVALS),
                    "bookCourseOrPublishing": len(
                        BOOK_COURSE_OR_PUBLISHING_REMOVALS
                    ),
                    "anonymousGospels": len(ANONYMOUS_GOSPEL_REMOVALS),
                },
                "removedPosts": removed,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"{KEYWORD}: {before} -> {after} ({len(removed)} removed)")


if __name__ == "__main__":
    main()
