"""Apply approved removals for the Romans secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "romans_secondary_keyword_audit.json"
KEYWORD = "Romans"

ROMAN_PEOPLE_OR_CONTEXT = {
    "47270", "47140", "41598", "41375", "37257", "36715", "36709",
    "36622", "36616", "36611", "36563", "36533", "36506", "36389",
    "36286", "36224", "36137", "36026", "35694", "35760", "35346",
    "35238", "34100", "33268", "33005", "32682", "32589", "32678",
    "32674", "30684", "7543", "15929", "29846", "29833", "29817",
    "16907", "28670", "28478", "27935", "27584", "27285", "25709",
    "21862", "20741", "16656", "15998", "15839", "14870", "14865",
    "13616", "13506", "13238", "13207", "12508", "12422", "12074",
    "12066", "11863", "9479", "9337", "8485", "7609", "7601",
    "7597", "7592", "7583", "7569", "7548", "7545", "7522", "7494",
    "6967", "6571", "6563", "6541", "6497", "6459", "6447", "6434",
    "4943", "4906", "4234", "3101", "3094", "3073", "3032", "2618",
    "1738",
}

INCIDENTAL_OR_DIFFERENT_TEXT = {
    "48297", "47673", "47195", "47164", "47152", "29310", "16612",
    "15738", "35715", "17064",
}

APPROVED_REMOVALS = ROMAN_PEOPLE_OR_CONTEXT | INCIDENTAL_OR_DIFFERENT_TEXT


def removal_reason(wp_id: str) -> str:
    if wp_id in ROMAN_PEOPLE_OR_CONTEXT:
        return (
            "Romans refers to Roman people, officials, government, society, or "
            "historical circumstances rather than Paul's Letter to the Romans."
        )
    if wp_id in INCIDENTAL_OR_DIFFERENT_TEXT:
        return (
            "Paul's Letter to the Romans is only an incidental example or citation, "
            "or the reference is instead to Ignatius's Letter to the Romans."
        )
    raise RuntimeError(f"No removal reason configured for post {wp_id}")


def main() -> None:
    if len(ROMAN_PEOPLE_OR_CONTEXT) != 88:
        raise RuntimeError("Expected 88 Roman-context removals")
    if len(INCIDENTAL_OR_DIFFERENT_TEXT) != 10:
        raise RuntimeError("Expected 10 incidental or different-text removals")
    if len(APPROVED_REMOVALS) != 98:
        raise RuntimeError("Expected 98 approved Romans removals")

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
                "meaningAudited": "Paul's Letter to the Romans",
                "criterion": (
                    "Retain Romans only when Paul's letter is directly discussed or "
                    "provides meaningful evidence for the post's argument. Do not use "
                    "it for Roman people, authorities, or incidental references."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "romanPeopleOrContext": len(ROMAN_PEOPLE_OR_CONTEXT),
                    "incidentalOrDifferentText": len(INCIDENTAL_OR_DIFFERENT_TEXT),
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
