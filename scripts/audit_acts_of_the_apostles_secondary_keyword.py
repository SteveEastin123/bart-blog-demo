"""Apply approved removals for the Acts of the Apostles secondary keyword."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = ROOT / "data" / "audits" / "acts_of_the_apostles_secondary_keyword_audit.json"
KEYWORD = "Acts of the Apostles"

WRONG_MEANING_REMOVALS = {
    "10331", "11875", "14249", "15896", "15898", "17205", "26922",
    "27060", "29925", "32739", "33106", "3408", "36118", "47266",
    "48120", "7504",
}

APOCRYPHAL_ACTS_REMOVALS = {
    "11821", "12262", "12924", "14941", "15444", "15457", "15459",
    "15607", "16425", "16467", "16473", "17368", "21016", "21145",
    "21300", "21403", "2259", "2268", "2551", "30969", "30975",
    "36803", "37167", "37411", "37435", "40849", "47276", "4792",
    "5067", "6637", "7411", "7648", "9309",
}

PASSING_REFERENCE_REMOVALS = {
    "10318", "11964", "12006", "15272", "2591", "3295", "33985",
    "3488", "37565", "38583", "46927", "46983", "47012", "47131",
    "47866", "49317", "7026", "7254",
}

APPROVED_REMOVALS = (
    WRONG_MEANING_REMOVALS
    | APOCRYPHAL_ACTS_REMOVALS
    | PASSING_REFERENCE_REMOVALS
)


def removal_reason(wp_id: str) -> str:
    if wp_id in WRONG_MEANING_REMOVALS:
        return (
            "The word 'acts' is used as an ordinary noun rather than as the title "
            "of the canonical Acts of the Apostles."
        )
    if wp_id in APOCRYPHAL_ACTS_REMOVALS:
        return (
            "The post discusses one or more apocryphal Acts rather than the "
            "canonical Acts of the Apostles."
        )
    return (
        "Canonical Acts appears only as an isolated example, bibliographic "
        "reference, attribution detail, or transition rather than as a meaningful "
        "supporting subject."
    )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    known_post_ids = {str(post.get("wpId", "")) for post in posts}
    unknown = APPROVED_REMOVALS - known_post_ids
    if unknown:
        raise RuntimeError(
            "Approved Acts of the Apostles removals reference unknown posts: "
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
                    "Retain Acts of the Apostles where the canonical book's "
                    "narratives, speeches, theology, characters, historicity, or "
                    "relationship to Luke or Paul materially supports the post."
                ),
                "approvedRemovalPostIds": sorted(APPROVED_REMOVALS),
                "before": before,
                "retained": after,
                "removed": len(removed),
                "previouslyRemoved": len(APPROVED_REMOVALS) - len(removed),
                "removalCounts": {
                    "wrongMeaning": len(WRONG_MEANING_REMOVALS),
                    "apocryphalActs": len(APOCRYPHAL_ACTS_REMOVALS),
                    "passingReference": len(PASSING_REFERENCE_REMOVALS),
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
