#!/usr/bin/env python3
"""Apply the approved follow-up audit of singleton secondary keywords."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENTS_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)
AUDIT_PATH = (
    ROOT / "data" / "audits" / "singleton_followup_secondary_keyword_audit.json"
)

REMOVALS = {
    "3547": {
        "keyword": "Biblical Numerology",
        "reason": (
            "The keyword reflects background from an earlier gematria post; the "
            "sustained discussion concerns Barnabas and Marcion."
        ),
    },
    "12088": {
        "keyword": "Infancy Gospels",
        "reason": (
            "The post discusses the canonical infancy narratives rather than "
            "non-canonical infancy gospels."
        ),
    },
    "4273": {
        "keyword": "Jewish Christian Gospels",
        "reason": (
            "The phrase appears only as one item in a proposed project outline and "
            "is not a meaningful supporting subject."
        ),
    },
    "50167": {
        "keyword": "Judy Siker",
        "reason": (
            "The name appears only in a list of earlier guest posts and is not part "
            "of the post's substantive discussion."
        ),
    },
    "2472": {
        "keyword": "Modern Forgery Claims",
        "reason": (
            "Modern signature forgery is only an analogy within a discussion of "
            "paleography."
        ),
    },
    "48814": {
        "keyword": "Valentinian Gnostics",
        "reason": (
            "Valentinians appear only as one passing example in a list and are not "
            "a supporting subject of the post."
        ),
    },
}

SOURCE_KEYWORD = "Oxyrhynchus"
TARGET_KEYWORD = "Oxyrhynchus Papyri"
EXPECTED_SOURCE_POSTS = {"8862", "16314"}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def keyword_counts(posts: list[dict]) -> Counter[str]:
    return Counter(
        keyword
        for post in posts
        for keyword in dict.fromkeys(post.get("secondaryKeywords") or [])
    )


def post_record(post: dict, keyword: str, reason: str | None = None) -> dict:
    record = {
        "wpId": str(post.get("wpId")),
        "title": post.get("title"),
        "keyword": keyword,
    }
    if reason:
        record["reason"] = reason
    return record


def main() -> int:
    posts = load_json(POSTS_PATH)
    by_id = {str(post.get("wpId")): post for post in posts}
    before = keyword_counts(posts)
    before_singletons = sorted(
        (keyword for keyword, count in before.items() if count == 1),
        key=str.casefold,
    )

    if len(before) != 934 or len(before_singletons) != 286:
        raise ValueError(
            "Unexpected pre-audit counts: "
            f"{len(before)} unique and {len(before_singletons)} singleton keywords"
        )

    removed_records = []
    for wp_id, removal in REMOVALS.items():
        post = by_id.get(wp_id)
        if post is None:
            raise ValueError(f"Missing expected post {wp_id}")
        keyword = removal["keyword"]
        keywords = post.get("secondaryKeywords") or []
        if keywords.count(keyword) != 1 or before[keyword] != 1:
            raise ValueError(f"Unexpected assignment for {keyword!r} on post {wp_id}")
        post["secondaryKeywords"] = [item for item in keywords if item != keyword]
        removed_records.append(post_record(post, keyword, removal["reason"]))

    source_posts = {
        str(post.get("wpId"))
        for post in posts
        if SOURCE_KEYWORD in (post.get("secondaryKeywords") or [])
    }
    if source_posts != EXPECTED_SOURCE_POSTS or before[TARGET_KEYWORD] != 1:
        raise ValueError(
            f"Unexpected {SOURCE_KEYWORD!r}/{TARGET_KEYWORD!r} assignments: "
            f"{source_posts} and {before[TARGET_KEYWORD]}"
        )

    normalized_records = []
    for wp_id in sorted(EXPECTED_SOURCE_POSTS):
        post = by_id[wp_id]
        revised = []
        for keyword in post.get("secondaryKeywords") or []:
            normalized = TARGET_KEYWORD if keyword == SOURCE_KEYWORD else keyword
            if normalized not in revised:
                revised.append(normalized)
        post["secondaryKeywords"] = revised
        normalized_records.append(post_record(post, SOURCE_KEYWORD))

    retirements = load_json(RETIREMENTS_PATH)
    retired = set(retirements.get("keywords") or [])
    retired.update(removal["keyword"] for removal in REMOVALS.values())
    retired.add(SOURCE_KEYWORD)
    retired.discard(TARGET_KEYWORD)
    retirements["keywords"] = sorted(retired, key=str.casefold)

    after = keyword_counts(posts)
    after_singletons = sorted(
        (keyword for keyword, count in after.items() if count == 1),
        key=str.casefold,
    )
    if len(after) != 927 or len(after_singletons) != 279:
        raise ValueError(
            "Unexpected post-audit counts: "
            f"{len(after)} unique and {len(after_singletons)} singleton keywords"
        )
    retired_sources = {
        removal["keyword"] for removal in REMOVALS.values()
    } | {SOURCE_KEYWORD}
    remaining_sources = sorted(
        (keyword for keyword in retired_sources if after[keyword]),
        key=str.casefold,
    )
    if remaining_sources:
        raise ValueError(f"Retired keywords remain: {remaining_sources}")
    if after[TARGET_KEYWORD] != 3:
        raise ValueError(
            f"Expected three {TARGET_KEYWORD!r} assignments; found {after[TARGET_KEYWORD]}"
        )

    retained_singletons = sorted(
        (
            keyword
            for keyword in before_singletons
            if keyword not in {item["keyword"] for item in removed_records}
        ),
        key=str.casefold,
    )
    if len(retained_singletons) != 280:
        raise ValueError(
            f"Expected 280 retained singleton assignments; found {len(retained_singletons)}"
        )

    audit = {
        "auditType": "full-text singleton secondary-keyword follow-up",
        "criterion": (
            "Retain a singleton keyword only when it represents a meaningful "
            "supporting subject, person, text, place, or concept in the full post."
        ),
        "summary": {
            "beforeUniqueKeywords": len(before),
            "beforeSingletonKeywords": len(before_singletons),
            "removedAssignments": len(removed_records),
            "normalizedAssignments": len(normalized_records),
            "retainedSingletonAssignments": len(retained_singletons),
            "afterUniqueKeywords": len(after),
            "afterSingletonKeywords": len(after_singletons),
        },
        "removedAssignments": removed_records,
        "normalization": {
            "from": SOURCE_KEYWORD,
            "to": TARGET_KEYWORD,
            "posts": normalized_records,
            "resultingPostCount": after[TARGET_KEYWORD],
        },
        "retainedSingletonKeywords": retained_singletons,
        "auditedKeywords": retained_singletons,
    }

    write_json(POSTS_PATH, posts)
    write_json(RETIREMENTS_PATH, retirements)
    write_json(AUDIT_PATH, audit)
    print(json.dumps(audit["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
