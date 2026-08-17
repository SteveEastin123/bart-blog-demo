"""Apply approved removals for the Early Christian Writings keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "early_christian_writings_secondary_keyword_audit.json"
)
KEYWORD = "Early Christian Writings"

JEWISH_SECTS = {"12975", "41594"}
HEBREW_BIBLE_TEXTS = {"25647", "28443", "32546", "33023"}
UNRELATED_MODERN_SUBJECTS = {"8942", "10314", "32674", "35332", "8417"}
APPROVED_REMOVALS = JEWISH_SECTS | HEBREW_BIBLE_TEXTS | UNRELATED_MODERN_SUBJECTS


def removal_reason(wp_id: str) -> str:
    if wp_id in JEWISH_SECTS:
        return (
            "The post describes Jewish groups in the time of Jesus rather than "
            "early Christian writings."
        )
    if wp_id in HEBREW_BIBLE_TEXTS:
        return (
            "The post examines a Hebrew Bible text or its authorship rather "
            "than an early Christian writing."
        )
    if wp_id in UNRELATED_MODERN_SUBJECTS:
        return (
            "The post's main subject is modern personal, academic, blog, or "
            "historical speculation rather than early Christian writings."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 11:
        raise RuntimeError("Expected 11 approved Early Christian Writings removals")

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
                    "Retain Early Christian Writings when New Testament, "
                    "apostolic-father, apocryphal, Gnostic, lost, forged, or "
                    "other early Christian texts materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "jewishSects": len(JEWISH_SECTS),
                    "hebrewBibleTexts": len(HEBREW_BIBLE_TEXTS),
                    "unrelatedModernSubjects": len(UNRELATED_MODERN_SUBJECTS),
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
