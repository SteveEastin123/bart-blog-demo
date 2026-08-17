"""Apply approved removals for the Church Fathers secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "church_fathers_secondary_keyword_audit.json"
KEYWORD = "Church Fathers"

AUTOBIOGRAPHICAL_OR_CAREER_REMOVALS = {
    "12101", "2523", "2624", "21126", "26744", "47061",
}

WRITING_OR_PUBLISHING_REMOVALS = {
    "2093", "7669", "10314", "11904", "16543",
}

INCIDENTAL_REFERENCE_REMOVALS = {
    "3605", "8359", "16263", "30248", "32298", "33005", "33268",
}

APPROVED_REMOVALS = (
    AUTOBIOGRAPHICAL_OR_CAREER_REMOVALS
    | WRITING_OR_PUBLISHING_REMOVALS
    | INCIDENTAL_REFERENCE_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in AUTOBIOGRAPHICAL_OR_CAREER_REMOVALS:
        return (
            "A Church Father appears only as background to Bart's education, "
            "career, or earlier scholarship rather than as a meaningful subject."
        )
    if wp_id in WRITING_OR_PUBLISHING_REMOVALS:
        return (
            "Church Fathers appear only in a book, article, or publishing example "
            "rather than as a meaningful supporting subject."
        )
    return (
        "A Church Father appears only in an incidental example, list, analogy, "
        "or bibliographic citation rather than as a meaningful supporting subject."
    )


def main() -> None:
    if len(APPROVED_REMOVALS) != 18:
        raise RuntimeError("Expected 18 approved Church Fathers removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Church Fathers removals reference unknown posts: "
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
                    "Retain Church Fathers when patristic writers, writings, "
                    "theology, historical testimony, textual evidence, or "
                    "institutional roles materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "autobiographicalOrCareer": len(
                        AUTOBIOGRAPHICAL_OR_CAREER_REMOVALS
                    ),
                    "writingOrPublishing": len(WRITING_OR_PUBLISHING_REMOVALS),
                    "incidentalReference": len(INCIDENTAL_REFERENCE_REMOVALS),
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
