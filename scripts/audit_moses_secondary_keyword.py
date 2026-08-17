"""Apply approved removals for the Moses secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "moses_secondary_keyword_audit.json"
KEYWORD = "Moses"

WRONG_PERSON_REMOVALS = {"17569", "8232"}

GENERIC_REMOVALS = {
    "49514", "48954", "48013", "47851", "47088", "41667", "40864",
    "39619", "35702", "28489", "26653", "20097", "17708", "17670",
    "17642", "15392", "15172", "16082", "4088", "6587", "2551",
}

TANGENTIAL_REMOVALS = {"40543", "38488", "35238", "26895", "11332"}

APPROVED_REMOVALS = (
    WRONG_PERSON_REMOVALS | GENERIC_REMOVALS | TANGENTIAL_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in WRONG_PERSON_REMOVALS:
        return "The post refers to Moses of Ingila, not the biblical Moses."
    if wp_id in GENERIC_REMOVALS:
        return (
            "Moses appears only in a generic list, joke, analogy, chronological "
            "marker, or transition rather than as a meaningful supporting subject."
        )
    return (
        "The reference to Moses or Mosaic law is tangential to the post's actual subject."
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Moses removals reference unknown posts: "
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
                    "Retain Moses where Mosaic law, Pentateuchal authorship, Exodus "
                    "traditions, Moses typology, Jewish interpretation, or traditions "
                    "specifically about Moses materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "wrongPerson": len(WRONG_PERSON_REMOVALS),
                    "generic": len(GENERIC_REMOVALS),
                    "tangential": len(TANGENTIAL_REMOVALS),
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
