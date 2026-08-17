"""Apply approved removals for the Crucifixion secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "crucifixion_secondary_keyword_audit.json"
KEYWORD = "Crucifixion"

APPROVED_REMOVALS = {
    "3079",
    "7199",
    "8763",
    "12830",
    "16873",
    "17247",
    "17428",
    "24590",
    "37661",
    "38488",
}

CHRONOLOGICAL_REFERENCES = {"3079", "7199", "16873", "24590", "37661"}
LIST_OR_TITLE_REFERENCES = {"8763", "12830", "17247", "17428"}
INCIDENTAL_GOSPEL_LOCATION = {"38488"}


def removal_reason(wp_id: str) -> str:
    if wp_id in CHRONOLOGICAL_REFERENCES:
        return (
            "Crucifixion is used only as a chronological boundary for a post "
            "about resurrection visions or Jesus' reputation as a miracle worker."
        )
    if wp_id in LIST_OR_TITLE_REFERENCES:
        return (
            "Crucifixion appears only in a book title, course list, reader's "
            "list of beliefs, or list of generally accepted facts about Jesus."
        )
    if wp_id in INCIDENTAL_GOSPEL_LOCATION:
        return (
            "The post only notes that Mary Magdalene appears at the "
            "crucifixion before addressing a different tradition about her."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 10:
        raise RuntimeError("Expected 10 approved Crucifixion removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Crucifixion removals reference unknown posts: "
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
                    "Retain Crucifixion when Jesus' execution, its causes, "
                    "events, meaning, historical practice, aftermath, or "
                    "another materially discussed crucifixion supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "chronologicalReferences": len(CHRONOLOGICAL_REFERENCES),
                    "listOrTitleReferences": len(LIST_OR_TITLE_REFERENCES),
                    "incidentalGospelLocation": len(INCIDENTAL_GOSPEL_LOCATION),
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
