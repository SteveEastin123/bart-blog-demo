"""Apply approved removals for the Abraham secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "abraham_secondary_keyword_audit.json"
KEYWORD = "Abraham"

APPROVED_REMOVALS = {
    "48013", "7899", "8145", "21016", "15622", "41062", "36122",
    "15676", "11619", "28269", "15315", "11857", "47025", "7577",
    "41343", "3997", "36539", "12420", "23130", "48830", "37850",
    "17642", "9288", "36599", "14905", "15795", "33480", "34231",
    "34586", "3729", "12301", "7197", "15072", "8613", "13186",
    "27402", "3657", "3222", "38785", "15559", "19929", "7224",
    "4071", "12943", "4388", "27343", "17708", "14935", "47851",
    "40864", "4505", "46992", "46945", "8513", "8741", "39865",
    "47269", "37140", "25439", "15392", "38132", "13316", "26653",
    "10268", "12277", "31240", "16111", "32369", "41262", "4885",
}


def main() -> None:
    if len(APPROVED_REMOVALS) != 70:
        raise RuntimeError("Expected 70 approved Abraham removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Abraham removals reference unknown posts: "
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
                "reason": (
                    "Abraham appears only as a passing quotation, name in a list, "
                    "genealogical reference, comparison, or illustrative example "
                    "rather than as a meaningful supporting subject of the post."
                ),
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
                    "Retain Abraham when Abraham, Abrahamic traditions, the "
                    "covenant, ancestry, faith, or scriptural interpretation "
                    "involving Abraham materially supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
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
