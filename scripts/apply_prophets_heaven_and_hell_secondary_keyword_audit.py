from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Prophets": {
        "before": 82,
        "remove_ids": {
            "4428",
            "4588",
            "7369",
            "9340",
            "9479",
            "12186",
            "12447",
            "15676",
            "20867",
            "21072",
            "21114",
            "21139",
            "25439",
            "25919",
            "28670",
            "32441",
            "33124",
            "33221",
            "36640",
            "38977",
            "47215",
        },
        "criterion": (
            "Retain Prophets when prophets, prophetic teaching, prophetic roles, "
            "or the Prophets as a scriptural collection materially support the "
            "post. Remove incidental quotations, labels, comparisons, lists, and "
            "background references that do not meaningfully advance its subject."
        ),
    },
    "Heaven and Hell": {
        "before": 80,
        "remove_ids": {
            "14395",
            "15609",
            "16202",
            "20337",
            "27343",
            "29035",
            "30164",
            "30461",
            "32319",
            "32441",
            "32445",
        },
        "criterion": (
            "Retain Heaven and Hell when postmortem reward or punishment, the "
            "realms of heaven and hell, related afterlife traditions, or Bart's "
            "books on those subjects materially support the post. Remove passing "
            "book-title citations, comparisons, source notices, and list entries."
        ),
    },
}


def post_record(post: dict) -> dict:
    return {
        "wpId": str(post["wpId"]),
        "title": post["title"],
        "topics": post.get("topics", []),
    }


def apply_audit(posts: list[dict], keyword: str, config: dict) -> dict:
    assigned = [
        post for post in posts if keyword in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != config["before"]:
        raise RuntimeError(
            f"Expected {config['before']} {keyword} assignments; found "
            f"{len(assigned)}"
        )

    assigned_ids = {str(post["wpId"]) for post in assigned}
    missing = sorted(config["remove_ids"] - assigned_ids, key=int)
    if missing:
        raise RuntimeError(
            f"Expected removable {keyword} assignments are missing: {missing}"
        )

    removed = []
    retained = []
    for post in assigned:
        if str(post["wpId"]) in config["remove_ids"]:
            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != keyword
            ]
            removed.append(post_record(post))
        else:
            retained.append(post_record(post))

    after = sum(
        keyword in post.get("secondaryKeywords", []) for post in posts
    )
    expected_after = config["before"] - len(config["remove_ids"])
    if after != expected_after:
        raise RuntimeError(
            f"Expected {expected_after} {keyword} assignments after audit; "
            f"found {after}"
        )

    return {
        "keyword": keyword,
        "criterion": config["criterion"],
        "before": len(assigned),
        "after": after,
        "retained": len(retained),
        "removed": len(removed),
        "added": 0,
        "retainedPosts": sorted(retained, key=lambda item: int(item["wpId"])),
        "removedPosts": sorted(removed, key=lambda item: int(item["wpId"])),
        "addedPosts": [],
    }


def audit_filename(keyword: str) -> str:
    return keyword.casefold().replace(" ", "_") + "_secondary_keyword_audit.json"


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    results = [
        apply_audit(posts, keyword, config)
        for keyword, config in AUDITS.items()
    ]

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    for result in results:
        audit_path = AUDITS_DIR / audit_filename(result["keyword"])
        audit_path.write_text(
            json.dumps(result, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(
            f"{result['keyword']}: {result['before']} -> {result['after']} "
            f"({result['removed']} removed)"
        )


if __name__ == "__main__":
    main()
