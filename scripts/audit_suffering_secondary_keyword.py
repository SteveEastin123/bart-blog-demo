"""Apply approved removals for the Suffering secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "suffering_secondary_keyword_audit.json"
KEYWORD = "Suffering"

FORMULA_OR_IDIOM_REMOVALS = {
    "16100", "16236",
}

INTRODUCTORY_OR_LIST_REMOVALS = {
    "11510", "16757", "22919", "47217",
}

BIBLIOGRAPHIC_REMOVALS = {
    "20749",
}

APPROVED_REMOVALS = (
    FORMULA_OR_IDIOM_REMOVALS
    | INTRODUCTORY_OR_LIST_REMOVALS
    | BIBLIOGRAPHIC_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in FORMULA_OR_IDIOM_REMOVALS:
        return (
            "Suffering appears only in a formula or idiomatic expression rather "
            "than as a meaningful supporting subject."
        )
    if wp_id in INTRODUCTORY_OR_LIST_REMOVALS:
        return (
            "Suffering appears only in introductory material, a brief list, or one "
            "part of a multi-question post rather than as a sustained subject."
        )
    return (
        "Suffering appears only in a cited book title rather than as a meaningful "
        "supporting subject."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 7:
        raise RuntimeError("Expected 7 approved Suffering removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Suffering removals reference unknown posts: "
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
                    "Retain Suffering when physical or emotional pain, persecution, "
                    "grief, illness, injustice, or theological responses to "
                    "suffering materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "formulaOrIdiom": len(FORMULA_OR_IDIOM_REMOVALS),
                    "introductoryOrList": len(INTRODUCTORY_OR_LIST_REMOVALS),
                    "bibliographic": len(BIBLIOGRAPHIC_REMOVALS),
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
