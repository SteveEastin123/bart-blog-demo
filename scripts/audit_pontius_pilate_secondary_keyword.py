"""Apply approved removals for the Pontius Pilate secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "pontius_pilate_secondary_keyword_audit.json"
KEYWORD = "Pontius Pilate"

APPROVED_REMOVALS = {
    "15852", "47270", "39694", "13189", "4846", "13336", "7686",
    "35561", "28208", "8657", "24468", "17813", "2047", "12494",
    "35404", "47288", "37067", "15723", "37837", "12844", "20471",
    "13196", "49069", "34368", "29790", "41185", "38235", "47141",
    "12355", "15497", "47223", "39619", "3512", "33314", "7031",
    "12652", "7326", "17387", "21145", "21154", "8650", "6578",
    "4727", "8961", "33175", "8833", "12002", "38176", "36687",
    "25879", "2259", "17223", "36834", "9297", "4743", "7046",
    "47100", "47056", "47115", "16599", "19669", "26327", "15221",
    "12432", "6919", "28131", "7348", "20725", "17763", "10579",
    "7681", "8405", "4403", "47045", "16085", "9399", "12762",
    "8476",
}


def main() -> None:
    if len(APPROVED_REMOVALS) != 78:
        raise RuntimeError("Expected 78 approved Pontius Pilate removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Pontius Pilate removals reference unknown posts: "
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
                    "Pontius Pilate appears only in a general summary, list, "
                    "chronological formula, creed, course material, manuscript "
                    "reference, or other passing context rather than as a "
                    "meaningful supporting subject of the post."
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
                    "Retain Pontius Pilate when his actions, responsibility, "
                    "historical role, administration, trial proceedings, burial "
                    "role, or portrayal materially supports the post."
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
