from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Eyewitness Testimony": {
        "before": 64,
        "remove_ids": {"12401", "16167", "49107", "49522"},
        "add_ids": {
            "6194",
            "8236",
            "10711",
            "11999",
            "15332",
            "15366",
            "35561",
            "38718",
            "40554",
        },
        "criterion": (
            "Retain or add Eyewitness Testimony when eyewitness evidence, "
            "claimed eyewitness authorship, witness reliability, or the "
            "historical value of testimony materially supports the post's "
            "argument. Remove announcements, passing references, neighboring "
            "examples, and assignments unsupported by the full post text."
        ),
    },
    "Persecution": {
        "before": 64,
        "remove_ids": {
            "2759",
            "7654",
            "11675",
            "11680",
            "13213",
            "15773",
            "16272",
            "17537",
            "27793",
            "33161",
            "35188",
            "35332",
            "47012",
        },
        "add_ids": {
            "12854",
            "49414",
            "49419",
            "49422",
            "49863",
            "49926",
        },
        "criterion": (
            "Retain or add Persecution when oppression, official or social "
            "violence, the persecution of Christians or Jews, or persecution's "
            "historical and theological effects materially support the post. "
            "Remove exam and syllabus entries, announcements, intellectual "
            "criticism mislabeled as persecution, and passing background."
        ),
    },
    "Letter of Barnabas": {
        "before": 63,
        "retain_ids": {
            "8359",
            "8503",
            "14238",
            "14251",
            "16258",
            "16309",
            "17510",
            "20990",
            "28966",
            "32505",
            "39582",
            "48025",
            "48120",
        },
        "add_ids": set(),
        "criterion": (
            "Retain Letter of Barnabas when the writing's interpretation of "
            "Scripture, anti-Judaism, numerology, language, dating, canonical "
            "status, or relationship to another text materially supports the "
            "post. Remove lists, bibliographies, syllabi, manuscript inventories, "
            "and passing examples. Do not duplicate the identical Letter of "
            "Barnabas topic as a secondary keyword."
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
    posts_by_id = {str(post["wpId"]): post for post in posts}
    assigned = [
        post for post in posts if keyword in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != config["before"]:
        raise RuntimeError(
            f"Expected {config['before']} {keyword} assignments; found "
            f"{len(assigned)}"
        )

    assigned_ids = {str(post["wpId"]) for post in assigned}
    if "retain_ids" in config:
        unknown_retained = sorted(config["retain_ids"] - assigned_ids, key=int)
        if unknown_retained:
            raise RuntimeError(
                f"Expected retained {keyword} assignments not found: "
                f"{unknown_retained}"
            )
        remove_ids = assigned_ids - config["retain_ids"]
    else:
        remove_ids = config["remove_ids"]

    missing_removals = sorted(remove_ids - assigned_ids, key=int)
    unexpected_additions = sorted(config["add_ids"] & assigned_ids, key=int)
    missing_posts = sorted(
        (remove_ids | config["add_ids"]) - posts_by_id.keys(), key=int
    )
    topic_duplicates = sorted(
        post_id
        for post_id in config["add_ids"]
        if keyword in posts_by_id[post_id].get("topics", [])
    )
    if missing_removals:
        raise RuntimeError(
            f"Expected removable {keyword} assignments not found: "
            f"{missing_removals}"
        )
    if unexpected_additions:
        raise RuntimeError(
            f"Expected new {keyword} assignments already present: "
            f"{unexpected_additions}"
        )
    if missing_posts:
        raise RuntimeError(f"Unknown post IDs in {keyword} audit: {missing_posts}")
    if topic_duplicates:
        raise RuntimeError(
            f"Refusing topic/keyword duplication for {keyword}: "
            f"{topic_duplicates}"
        )

    removed = []
    for post in assigned:
        if str(post["wpId"]) not in remove_ids:
            continue
        post["secondaryKeywords"] = [
            value
            for value in post.get("secondaryKeywords", [])
            if value != keyword
        ]
        removed.append(post_record(post))

    added = []
    for post_id in config["add_ids"]:
        post = posts_by_id[post_id]
        post.setdefault("secondaryKeywords", []).append(keyword)
        post["secondaryKeywords"] = sorted(
            set(post["secondaryKeywords"]), key=str.casefold
        )
        added.append(post_record(post))

    retained = [
        post_record(post)
        for post in assigned
        if str(post["wpId"]) not in remove_ids
    ]
    after = sum(keyword in post.get("secondaryKeywords", []) for post in posts)
    expected_after = config["before"] - len(removed) + len(added)
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
        "added": len(added),
        "retainedPosts": sorted(retained, key=lambda item: int(item["wpId"])),
        "removedPosts": sorted(removed, key=lambda item: int(item["wpId"])),
        "addedPosts": sorted(added, key=lambda item: int(item["wpId"])),
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
    AUDITS_DIR.mkdir(parents=True, exist_ok=True)
    for result in results:
        audit_path = AUDITS_DIR / audit_filename(result["keyword"])
        audit_path.write_text(
            json.dumps(result, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(
            f"{result['keyword']}: {result['before']} -> "
            f"{result['after']} ({result['removed']} removed, "
            f"{result['added']} added)"
        )


if __name__ == "__main__":
    main()
