"""Apply approved removals for the Son of God secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "son_of_god_secondary_keyword_audit.json"
KEYWORD = "Son of God"

INCIDENTAL_DESIGNATION_REMOVALS = {
    "21081", "33535", "15908", "13222", "46956", "47203", "37148", "5016",
}

QUOTED_OR_SURROUNDING_MATERIAL_REMOVALS = {
    "46935", "48712", "7494", "36282",
}

PROMOTIONAL_OR_NAVIGATIONAL_REMOVALS = {
    "33548", "12432",
}

APPROVED_REMOVALS = (
    INCIDENTAL_DESIGNATION_REMOVALS
    | QUOTED_OR_SURROUNDING_MATERIAL_REMOVALS
    | PROMOTIONAL_OR_NAVIGATIONAL_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in INCIDENTAL_DESIGNATION_REMOVALS:
        return (
            "Son of God appears only as a routine designation, comparison, aside, "
            "or narrative detail rather than a meaningful supporting subject."
        )
    if wp_id in QUOTED_OR_SURROUNDING_MATERIAL_REMOVALS:
        return (
            "Son of God occurs only in quoted or surrounding material while the "
            "post's argument concerns another subject."
        )
    return (
        "Son of God appears only in promotional, navigational, or introductory "
        "material rather than as a meaningful supporting subject."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 14:
        raise RuntimeError("Expected 14 approved Son of God removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Son of God removals reference unknown posts: "
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
                    "Retain Son of God when the designation's meaning, development, "
                    "scriptural use, or theological significance materially supports "
                    "the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "incidentalDesignation": len(INCIDENTAL_DESIGNATION_REMOVALS),
                    "quotedOrSurroundingMaterial": len(
                        QUOTED_OR_SURROUNDING_MATERIAL_REMOVALS
                    ),
                    "promotionalOrNavigational": len(
                        PROMOTIONAL_OR_NAVIGATIONAL_REMOVALS
                    ),
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
