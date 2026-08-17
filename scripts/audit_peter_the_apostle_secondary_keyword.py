"""Apply approved removals for the Peter the Apostle secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "peter_the_apostle_secondary_keyword_audit.json"
)
KEYWORD = "Peter the Apostle"

APPROVED_REMOVALS = {
    "2915", "6587", "6875", "6891", "8294", "8326", "11954", "14899",
    "15672", "17095", "17615", "21403", "23254", "33236", "37384",
    "38483", "38493", "38564", "38802", "46924", "48712", "48954",
}

PASSING_REFERENCES = {
    "8326", "11954", "15672", "21403", "33236", "38802", "48954",
}
MARY_MAGDALENE_POSTS = {
    "6875", "6891", "14899", "17615", "23254", "37384", "38483",
    "38493",
}
OTHER_APOSTLES_BOOKS_OR_SURVEYS = {
    "2915", "6587", "8294", "17095", "38564", "46924", "48712",
}


def removal_reason(wp_id: str) -> str:
    if wp_id in PASSING_REFERENCES:
        return (
            "Peter appears only in a list, quiz, course preview, or comparison "
            "rather than as a meaningful supporting subject of the post."
        )
    if wp_id in MARY_MAGDALENE_POSTS:
        return (
            "The post concerns Mary Magdalene; Peter occurs only in a book "
            "title, comparison, or brief reference to male disciples' jealousy."
        )
    if wp_id in OTHER_APOSTLES_BOOKS_OR_SURVEYS:
        return (
            "Peter appears only as background to another apostle, in a book "
            "title, or within a broad New Testament survey."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 22:
        raise RuntimeError("Expected 22 approved Peter the Apostle removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Peter the Apostle removals reference unknown posts: "
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
                    "Retain Peter the Apostle when Peter's actions, authority, "
                    "traditions, relationships, writings attributed to him, or "
                    "historical role materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "passingReferences": len(PASSING_REFERENCES),
                    "maryMagdalenePosts": len(MARY_MAGDALENE_POSTS),
                    "otherApostlesBooksOrSurveys": len(
                        OTHER_APOSTLES_BOOKS_OR_SURVEYS
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
