"""Apply approved removals for the Christology secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "christology_secondary_keyword_audit.json"
KEYWORD = "Christology"

APPROVED_REMOVALS = {
    "3801",
    "3883",
    "3924",
    "7531",
    "8679",
    "31878",
    "35188",
    "48297",
}

THREAD_OR_SERIES_REFERENCES = {"3801", "3883", "3924"}
LIST_OR_BACKGROUND_REFERENCES = {"7531", "8679", "31878", "35188"}
PASSION_WITHOUT_CHRISTOLOGY = {"48297"}


def removal_reason(wp_id: str) -> str:
    if wp_id in THREAD_OR_SERIES_REFERENCES:
        return (
            "Christology appears only while pausing, contrasting with, or "
            "referring to a separate Christology series."
        )
    if wp_id in LIST_OR_BACKGROUND_REFERENCES:
        return (
            "Christology appears only in a list, introductory background, or "
            "brief reference to material outside the post's central subject."
        )
    if wp_id in PASSION_WITHOUT_CHRISTOLOGY:
        return (
            "The post discusses imitation of Christ's suffering and martyrdom "
            "rather than Christ's identity or nature."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 8:
        raise RuntimeError("Expected 8 approved Christology removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Christology removals reference unknown posts: "
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
                    "Retain Christology when the post meaningfully addresses "
                    "Jesus' identity, divinity, humanity, preexistence, "
                    "exaltation, incarnation, or competing understandings of "
                    "Christ."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "threadOrSeriesReferences": len(THREAD_OR_SERIES_REFERENCES),
                    "listOrBackgroundReferences": len(LIST_OR_BACKGROUND_REFERENCES),
                    "passionWithoutChristology": len(PASSION_WITHOUT_CHRISTOLOGY),
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
