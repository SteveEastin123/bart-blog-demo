from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDIT_PATH = (
    ROOT
    / "data"
    / "audits"
    / "pauline_epistles_secondary_keyword_audit.json"
)

OLD_KEYWORD = "Pauline Letters"
NEW_KEYWORD = "Pauline Epistles"
GENERAL_TOPIC = "Pauline Epistles (General)"
EXPECTED_BEFORE = 102
EXPECTED_TOPIC_OVERLAP = 10


def record(post: dict) -> dict:
    return {"wpId": str(post["wpId"]), "title": post["title"]}


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    assigned = [
        post
        for post in posts
        if OLD_KEYWORD in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != EXPECTED_BEFORE:
        raise RuntimeError(
            f"Expected {EXPECTED_BEFORE} {OLD_KEYWORD} assignments; "
            f"found {len(assigned)}"
        )

    topic_overlap = [
        post for post in assigned if GENERAL_TOPIC in post.get("topics", [])
    ]
    if len(topic_overlap) != EXPECTED_TOPIC_OVERLAP:
        raise RuntimeError(
            f"Expected {EXPECTED_TOPIC_OVERLAP} overlapping topic assignments; "
            f"found {len(topic_overlap)}"
        )

    overlap_ids = {str(post["wpId"]) for post in topic_overlap}
    retained = []
    removed_as_redundant = []
    for post in assigned:
        post_id = str(post["wpId"])
        keywords = [
            value
            for value in post.get("secondaryKeywords", [])
            if value != OLD_KEYWORD
        ]
        if post_id in overlap_ids:
            removed_as_redundant.append(record(post))
        else:
            keywords.append(NEW_KEYWORD)
            retained.append(record(post))
        post["secondaryKeywords"] = sorted(set(keywords), key=str.casefold)

    old_after = sum(
        OLD_KEYWORD in post.get("secondaryKeywords", []) for post in posts
    )
    new_after = sum(
        NEW_KEYWORD in post.get("secondaryKeywords", []) for post in posts
    )
    duplicate_after = sum(
        NEW_KEYWORD in post.get("secondaryKeywords", [])
        and GENERAL_TOPIC in post.get("topics", [])
        for post in posts
    )
    expected_after = EXPECTED_BEFORE - EXPECTED_TOPIC_OVERLAP
    if old_after != 0 or new_after != expected_after or duplicate_after != 0:
        raise RuntimeError(
            "Unexpected normalization result: "
            f"old={old_after}, new={new_after}, duplicate={duplicate_after}"
        )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    audit = {
        "keyword": NEW_KEYWORD,
        "renamedFrom": OLD_KEYWORD,
        "criterion": (
            "Rename the Pauline Letters secondary keyword to Pauline Epistles. "
            "Remove the keyword when the post already has the Pauline Epistles "
            "(General) topic so that the same concept is not assigned twice."
        ),
        "before": EXPECTED_BEFORE,
        "after": new_after,
        "renamed": len(retained),
        "removedAsRedundant": len(removed_as_redundant),
        "retainedPosts": sorted(retained, key=lambda item: int(item["wpId"])),
        "removedPosts": sorted(
            removed_as_redundant, key=lambda item: int(item["wpId"])
        ),
    }
    AUDIT_PATH.write_text(
        json.dumps(audit, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(
        f"{OLD_KEYWORD}: {EXPECTED_BEFORE} -> 0; "
        f"{NEW_KEYWORD}: {new_after}; "
        f"topic duplicates removed: {len(removed_as_redundant)}"
    )


if __name__ == "__main__":
    main()
