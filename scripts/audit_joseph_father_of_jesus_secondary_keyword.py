"""Apply approved removals for the Joseph, Father of Jesus keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "joseph_father_of_jesus_secondary_keyword_audit.json"
)
KEYWORD = "Joseph, Father of Jesus"

JOSEPH_OF_ARIMATHEA = {"46971", "47204"}
PASSING_IDENTIFICATION = {"46944", "15635", "2106"}
APPROVED_REMOVALS = JOSEPH_OF_ARIMATHEA | PASSING_IDENTIFICATION


def removal_reason(wp_id: str) -> str:
    if wp_id in JOSEPH_OF_ARIMATHEA:
        return (
            "The post discusses Joseph of Arimathea rather than Joseph, the "
            "father of Jesus."
        )
    if wp_id == "46944":
        return (
            "Joseph appears only in a list distinguishing New Testament figures "
            "named James."
        )
    if wp_id == "15635":
        return (
            "Joseph's supposed earlier marriage is only a passing tradition in "
            "a post about debates over the Gospel of Peter."
        )
    if wp_id == "2106":
        return (
            "Joseph appears only in a brief census example within a broader "
            "description of an undergraduate course."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 5:
        raise RuntimeError("Expected 5 approved Joseph removals")

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
                    "Retain Joseph, Father of Jesus when Joseph materially "
                    "supports a discussion of Jesus' birth, genealogy, family, "
                    "parentage, siblings, social setting, or later legends."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "josephOfArimathea": len(JOSEPH_OF_ARIMATHEA),
                    "passingIdentification": len(PASSING_IDENTIFICATION),
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
