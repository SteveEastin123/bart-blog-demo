"""Apply approved removals for the Translation secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "translation_secondary_keyword_audit.json"
KEYWORD = "Translation"

APPROVED_REMOVALS = {"4667", "16397"}


def removal_reason(wp_id: str) -> str:
    if wp_id == "4667":
        return (
            "Translation appears only when identifying the reading found in "
            "most English Bibles; the post concerns textual witnesses, "
            "patristic evidence, and the original reading."
        )
    if wp_id == "16397":
        return (
            "The post examines an intentional scribal alteration and notes "
            "that it had almost no effect on modern translations; Mark's "
            "translation of an Aramaic sentence is merely quoted context."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 2:
        raise RuntimeError("Expected 2 approved Translation removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Translation removals reference unknown posts: "
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
                    "Retain Translation when translating, translation "
                    "choices, linguistic equivalence, ancient versions, or "
                    "the consequences of a rendering materially support the post."
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
