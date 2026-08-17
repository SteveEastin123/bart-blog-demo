"""Apply approved removals for the Early Christianity secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "early_christianity_secondary_keyword_audit.json"
KEYWORD = "Early Christianity"

BLOG_OR_PROMOTION_REMOVALS = {
    "8942", "10994", "11832", "13781", "15738", "17457", "17790",
    "22919", "30120", "32728", "33161", "47625",
}

ACADEMIC_CAREER_OR_PUBLISHING_REMOVALS = {
    "11823", "11904", "13240", "20959", "2100", "33026", "4733",
    "7122", "7747", "8423", "9315", "11198", "11280", "24506",
}

INCIDENTAL_REFERENCE_REMOVALS = {
    "48392", "16606", "17780", "26434", "35545", "48527", "4963",
    "11515",
}

APPROVED_REMOVALS = (
    BLOG_OR_PROMOTION_REMOVALS
    | ACADEMIC_CAREER_OR_PUBLISHING_REMOVALS
    | INCIDENTAL_REFERENCE_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in BLOG_OR_PROMOTION_REMOVALS:
        return (
            "Early Christianity describes the blog's or podcast's general mission "
            "rather than a meaningful subject of the post."
        )
    if wp_id in ACADEMIC_CAREER_OR_PUBLISHING_REMOVALS:
        return (
            "Early Christianity identifies an academic field, degree program, "
            "publishing market, or professional background rather than a subject "
            "examined by the post."
        )
    return (
        "Early Christianity appears only in a lecture description, biography, "
        "course list, audience description, or broad contextual statement."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 34:
        raise RuntimeError("Expected 34 approved Early Christianity removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Early Christianity removals reference unknown posts: "
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
                    "Retain Early Christianity when early Christian history, "
                    "communities, beliefs, institutions, expansion, diversity, "
                    "or practices materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "blogOrPromotion": len(BLOG_OR_PROMOTION_REMOVALS),
                    "academicCareerOrPublishing": len(
                        ACADEMIC_CAREER_OR_PUBLISHING_REMOVALS
                    ),
                    "incidentalReference": len(INCIDENTAL_REFERENCE_REMOVALS),
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
