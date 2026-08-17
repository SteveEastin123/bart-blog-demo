"""Apply approved removals for the How Jesus Became God keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "how_jesus_became_god_secondary_keyword_audit.json"
)
KEYWORD = "How Jesus Became God"

PROMOTIONAL_OR_BIOGRAPHICAL_LIST = {
    "17780", "17662", "14804", "14766", "10288", "8748",
}
UNRELATED_PROJECT_CONTEXT = {
    "16082", "12080", "4513", "4273", "4088", "4036", "3366", "2296",
}
APPROVED_REMOVALS = PROMOTIONAL_OR_BIOGRAPHICAL_LIST | UNRELATED_PROJECT_CONTEXT


def removal_reason(wp_id: str) -> str:
    if wp_id in PROMOTIONAL_OR_BIOGRAPHICAL_LIST:
        return (
            "How Jesus Became God appears only in a promotional, bibliographical, "
            "biographical, or publication list rather than as a subject of the post."
        )
    if wp_id in UNRELATED_PROJECT_CONTEXT:
        return (
            "The book supplies only writing-project background or is explicitly "
            "identified as unrelated to the post's substantive subject."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 14:
        raise RuntimeError("Expected 14 approved How Jesus Became God removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_ids
    if unknown:
        raise RuntimeError("Unknown post IDs: " + ", ".join(sorted(unknown)))

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

    after = sum(KEYWORD in post.get("secondaryKeywords", []) for post in posts)
    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    AUDIT_PATH.write_text(
        json.dumps(
            {
                "keyword": KEYWORD,
                "criterion": (
                    "Retain How Jesus Became God for posts directly discussing the "
                    "book, its arguments, reception, promotion, lectures, or "
                    "substantive material developed for it."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "promotionalOrBiographicalList": len(
                        PROMOTIONAL_OR_BIOGRAPHICAL_LIST
                    ),
                    "unrelatedProjectContext": len(UNRELATED_PROJECT_CONTEXT),
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
