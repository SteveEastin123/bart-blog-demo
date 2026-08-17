"""Apply approved removals for the Kingdom of God secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "kingdom_of_god_secondary_keyword_audit.json"
KEYWORD = "Kingdom of God"

LAST_SUPPER_QUOTATIONS = {
    "2693", "9165", "9167", "9187", "14986", "27618", "27644",
    "31760", "31763", "48977", "48982",
}
ANGER_PASSAGES = {"4858", "36963"}
VICE_LIST_QUOTATIONS = {"15898", "17291"}
APPROVED_REMOVALS = (
    LAST_SUPPER_QUOTATIONS | ANGER_PASSAGES | VICE_LIST_QUOTATIONS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in LAST_SUPPER_QUOTATIONS:
        return (
            "Kingdom of God occurs only inside the repeated Last Supper text; "
            "the post analyzes atonement, translation, or a textual variant."
        )
    if wp_id in ANGER_PASSAGES:
        return (
            "Kingdom language appears only in a quoted Markan scene used to "
            "analyze Jesus' anger and healing activity."
        )
    if wp_id in VICE_LIST_QUOTATIONS:
        return (
            "Kingdom of God appears only in Paul's quoted vice list; the post "
            "analyzes terminology concerning same-sex relations."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 15:
        raise RuntimeError("Expected 15 approved Kingdom of God removals")

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
                    "Retain Kingdom of God when the coming divine kingdom, its "
                    "meaning, membership, ethics, timing, or relationship to "
                    "Jesus' and Paul's teaching materially supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "lastSupperQuotations": len(LAST_SUPPER_QUOTATIONS),
                    "angerPassages": len(ANGER_PASSAGES),
                    "viceListQuotations": len(VICE_LIST_QUOTATIONS),
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
