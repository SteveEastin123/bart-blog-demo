"""Apply approved removals for the Judaism secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "judaism_secondary_keyword_audit.json"
KEYWORD = "Judaism"

APPROVED_REMOVALS = {
    "2059", "2378", "2618", "3836", "4150", "4957", "6332", "7475",
    "7732", "9315", "10321", "11332", "12512", "13072", "15108",
    "15738", "17217", "17553", "17676", "20836", "21209", "27166",
    "28005", "33028", "48392", "48836", "49991",
}

ACADEMIC_CONTEXT = {
    "2059", "4150", "6332", "7732", "9315", "12512", "15108",
    "15738", "17217", "20836", "21209", "33028",
}
PASSING_CONTEXT = {
    "2378", "2618", "10321", "11332", "13072", "17676", "28005",
    "48836",
}
EVENT_OR_PUBLISHING_CONTEXT = {
    "3836", "4957", "7475", "17553", "27166", "48392", "49991",
}


def removal_reason(wp_id: str) -> str:
    if wp_id in ACADEMIC_CONTEXT:
        return (
            "Judaism appears only in a list or description of academic fields, "
            "courses, seminars, examinations, or professional activities."
        )
    if wp_id in PASSING_CONTEXT:
        return (
            "Judaism supplies a passing comparison, historical transition, "
            "credential, book title, or isolated background reference rather "
            "than a meaningful supporting subject."
        )
    if wp_id in EVENT_OR_PUBLISHING_CONTEXT:
        return (
            "Judaism appears only in conference scope, bibliographic or planned "
            "publication material, manuscript-scandal background, or a personal "
            "identity reference rather than a substantive discussion."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 27:
        raise RuntimeError("Expected 27 approved Judaism removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Judaism removals reference unknown posts: "
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
                    "Retain Judaism when Jewish religion, identity, practice, "
                    "history, interpretation, or Jewish-Christian relations "
                    "materially support the post rather than serving as a "
                    "passing comparison or background label."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "academicContext": len(ACADEMIC_CONTEXT),
                    "passingContext": len(PASSING_CONTEXT),
                    "eventPublishingOrPersonalContext": len(
                        EVENT_OR_PUBLISHING_CONTEXT
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
