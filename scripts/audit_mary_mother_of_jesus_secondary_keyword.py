"""Apply approved removals for the Mary, Mother of Jesus keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "mary_mother_of_jesus_secondary_keyword_audit.json"
)
KEYWORD = "Mary, Mother of Jesus"

MARY_MAGDALENE_OR_OTHER_MARY = {
    "38483", "11520", "17591", "17579", "17569", "16892", "8232",
    "6875", "6762", "6753", "3086", "4591",
}
INCIDENTAL_MARIAN_REFERENCE = {"11921", "9395", "7185", "6211"}
NO_MEANINGFUL_DISCUSSION = {"47869"}
APPROVED_REMOVALS = (
    MARY_MAGDALENE_OR_OTHER_MARY
    | INCIDENTAL_MARIAN_REFERENCE
    | NO_MEANINGFUL_DISCUSSION
)


def removal_reason(wp_id: str) -> str:
    if wp_id in MARY_MAGDALENE_OR_OTHER_MARY:
        return (
            "Mary primarily refers to Mary Magdalene or another woman named Mary; "
            "Jesus' mother is not a meaningful subject of the post."
        )
    if wp_id in INCIDENTAL_MARIAN_REFERENCE:
        return (
            "Visions of the Virgin Mary supply only a brief comparison in a post "
            "about resurrection appearances or historical method."
        )
    if wp_id in NO_MEANINGFUL_DISCUSSION:
        return (
            "The available full text and description do not meaningfully discuss "
            "Mary, the mother of Jesus."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 17:
        raise RuntimeError("Expected 17 approved Mary removals")

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
                    "Retain Mary, Mother of Jesus when Mary herself, her role in "
                    "Jesus' birth or family, Marian traditions, or visions of the "
                    "Virgin Mary materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "maryMagdaleneOrOtherMary": len(MARY_MAGDALENE_OR_OTHER_MARY),
                    "incidentalMarianReference": len(INCIDENTAL_MARIAN_REFERENCE),
                    "noMeaningfulDiscussion": len(NO_MEANINGFUL_DISCUSSION),
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
