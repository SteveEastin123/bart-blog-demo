"""Apply approved removals for the Afterlife secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "afterlife_secondary_keyword_audit.json"
KEYWORD = "Afterlife"

APPROVED_REMOVALS = {
    "17564", "13684", "13196", "29035", "17780", "15345", "15798",
    "33641", "12850", "12489", "3622", "15494", "15160", "15156",
    "16543", "15300", "49019", "20266", "3872", "15502", "26592",
}

LEGACY_WORDING_REMOVALS = {"33641", "3622", "3872"}
PROMOTION_ONLY_REMOVALS = {"17564", "17780", "20266"}


def removal_reason(wp_id: str) -> str:
    if wp_id in LEGACY_WORDING_REMOVALS:
        return (
            "Afterlife describes the King James Bible's later cultural legacy, "
            "not postmortem existence or beliefs about life after death."
        )
    if wp_id in PROMOTION_ONLY_REMOVALS:
        return (
            "The post is a raffle, preorder notice, or event announcement and "
            "does not substantively discuss afterlife beliefs."
        )
    return (
        "Afterlife appears only as a book or research-project title, publishing "
        "example, thread transition, or incidental reference rather than as a "
        "meaningful supporting subject of the post."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 21:
        raise RuntimeError("Expected 21 approved Afterlife removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Afterlife removals reference unknown posts: "
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
                    "Retain Afterlife when life after death, the fate of the "
                    "dead, resurrection, judgment, heaven, hell, Sheol, "
                    "purgatory, or another postmortem tradition materially "
                    "supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "legacyWording": len(LEGACY_WORDING_REMOVALS),
                    "promotionOnly": len(PROMOTION_ONLY_REMOVALS),
                    "incidentalOrPublishingContext": len(
                        APPROVED_REMOVALS
                        - LEGACY_WORDING_REMOVALS
                        - PROMOTION_ONLY_REMOVALS
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
