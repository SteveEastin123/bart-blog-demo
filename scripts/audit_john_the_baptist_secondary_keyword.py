"""Apply approved removals for the John the Baptist secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT / "data" / "audits" / "john_the_baptist_secondary_keyword_audit.json"
)
KEYWORD = "John the Baptist"

UNRELATED_REFERENCES = {
    "4218", "4229", "15908", "15885", "16364", "20851", "46944",
    "47735", "48384",
}
COURSES_QUIZZES_OR_SURVEYS = {
    "2106", "4727", "4743", "6587", "11954", "17064", "21154",
    "21255", "47045",
}
PASSING_COMPARISONS_OR_LISTS = {
    "2492", "3863", "6142", "7179", "8511", "9177", "9292",
    "12064", "12744", "12747", "13183", "15248", "17708", "23806",
    "24957", "25886", "26586", "27285", "27512", "28726", "28730",
    "34669", "35679", "35872", "36122", "36268", "41050", "41058",
    "41351", "41615", "47064", "47851",
}
INCIDENTAL_GOSPEL_EXAMPLES = {
    "3440", "8350", "13608", "15206", "16088", "17403", "20653",
    "22610", "27012", "28830", "29830", "31511", "41837", "41894",
    "47084", "48176",
}
APPROVED_REMOVALS = (
    UNRELATED_REFERENCES
    | COURSES_QUIZZES_OR_SURVEYS
    | PASSING_COMPARISONS_OR_LISTS
    | INCIDENTAL_GOSPEL_EXAMPLES
)


def removal_reason(wp_id: str) -> str:
    if wp_id in UNRELATED_REFERENCES:
        return (
            "John appears only in a bibliography, scholarly credential, "
            "negative statement, or unrelated aside."
        )
    if wp_id in COURSES_QUIZZES_OR_SURVEYS:
        return (
            "John is merely one item in a broad course outline, quiz, or "
            "New Testament survey."
        )
    if wp_id in PASSING_COMPARISONS_OR_LISTS:
        return (
            "John is only named among apocalyptic Jews, prophets, historical "
            "figures, Gospel characters, or comparative examples."
        )
    if wp_id in INCIDENTAL_GOSPEL_EXAMPLES:
        return (
            "John appears in a single Gospel scene, chronology, source "
            "example, or comparison supporting another subject."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(APPROVED_REMOVALS) != 66:
        raise RuntimeError("Expected 66 approved John the Baptist removals")

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved John the Baptist removals reference unknown posts: "
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
                    "Retain John the Baptist when his life, preaching, "
                    "baptism, followers, death, historical role, relationship "
                    "to Jesus, or traditions about him materially support the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "unrelatedReferences": len(UNRELATED_REFERENCES),
                    "coursesQuizzesOrSurveys": len(COURSES_QUIZZES_OR_SURVEYS),
                    "passingComparisonsOrLists": len(PASSING_COMPARISONS_OR_LISTS),
                    "incidentalGospelExamples": len(INCIDENTAL_GOSPEL_EXAMPLES),
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
