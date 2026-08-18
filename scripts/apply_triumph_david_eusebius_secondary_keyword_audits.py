from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Triumph of Christianity": {
        "before": 63,
        "remove_ids": {
            "11935",
            "12432",
            "12873",
            "14948",
            "14963",
            "14973",
            "16202",
            "17619",
            "27166",
            "32319",
            "32805",
            "33538",
            "47139",
        },
        "add_ids": {
            "7664",
            "15003",
            "15140",
            "15156",
            "15740",
            "16381",
            "17561",
            "49733",
        },
        "criterion": (
            "Retain or add Triumph of Christianity when Bart's book, its "
            "research and arguments, or a course, interview, publication, or "
            "event substantially centered on the book supports the post. "
            "Remove author biographies, reading lists, contract history, "
            "unrelated link lists, passing citations, and generic references "
            "to Christianity's historical triumph that do not name the book."
        ),
    },
    "Eusebius of Caesarea": {
        "before": 61,
        "remove_ids": {"2435", "8307", "27209", "50150"},
        "add_ids": {
            "6299",
            "8285",
            "8857",
            "11669",
            "12425",
            "15398",
            "15713",
            "16364",
            "16565",
            "32323",
            "32326",
            "32505",
            "34486",
            "40686",
            "46944",
            "46984",
            "47004",
            "47590",
            "47847",
            "48021",
            "49419",
            "50251",
        },
        "criterion": (
            "Retain or add Eusebius of Caesarea when his testimony, historical "
            "judgment, quotation, or preservation of an otherwise lost source "
            "materially supports the post's argument. Remove bibliographic "
            "citations, assignment lists, autobiographical reading lists, and "
            "references that merely recap a previous post."
        ),
    },
}

DAVID = {
    "old_keyword": "David",
    "new_keyword": "King David",
    "before": 62,
    "remove_ids": {
        "3440",
        "12023",
        "12465",
        "15691",
        "21009",
        "21924",
        "26631",
        "35358",
        "38930",
        "38963",
        "39925",
        "47239",
        "48151",
    },
    "add_ids": {
        "3976",
        "8549",
        "9473",
        "10276",
        "10294",
        "12467",
        "22663",
        "23135",
        "24729",
        "48263",
        "50227",
        "50317",
        "50319",
    },
    "criterion": (
        "Use King David when the biblical king, Davidic ancestry, Davidic "
        "kingship, or Davidic messianic expectations materially support the "
        "post. Remove passing quotations, background examples, speculative "
        "allusions, and references to modern people named David. Rename David "
        "to King David to disambiguate the biblical figure."
    ),
}


def post_record(post: dict) -> dict:
    return {
        "wpId": str(post["wpId"]),
        "title": post["title"],
        "topics": post.get("topics", []),
    }


def validate_changes(
    posts_by_id: dict[str, dict],
    assigned_ids: set[str],
    remove_ids: set[str],
    add_ids: set[str],
    keyword: str,
) -> None:
    missing_removals = sorted(remove_ids - assigned_ids, key=int)
    unexpected_additions = sorted(add_ids & assigned_ids, key=int)
    missing_posts = sorted((remove_ids | add_ids) - posts_by_id.keys(), key=int)
    topic_duplicates = sorted(
        post_id
        for post_id in add_ids
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


def apply_standard_audit(posts: list[dict], keyword: str, config: dict) -> dict:
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
    validate_changes(
        posts_by_id,
        assigned_ids,
        config["remove_ids"],
        config["add_ids"],
        keyword,
    )

    removed = []
    for post in assigned:
        if str(post["wpId"]) not in config["remove_ids"]:
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
        if str(post["wpId"]) not in config["remove_ids"]
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


def apply_david_audit(posts: list[dict]) -> dict:
    posts_by_id = {str(post["wpId"]): post for post in posts}
    old_keyword = DAVID["old_keyword"]
    new_keyword = DAVID["new_keyword"]
    assigned = [
        post for post in posts if old_keyword in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != DAVID["before"]:
        raise RuntimeError(
            f"Expected {DAVID['before']} {old_keyword} assignments; found "
            f"{len(assigned)}"
        )
    if any(new_keyword in post.get("secondaryKeywords", []) for post in posts):
        raise RuntimeError(f"{new_keyword} already exists before normalization")

    assigned_ids = {str(post["wpId"]) for post in assigned}
    validate_changes(
        posts_by_id,
        assigned_ids,
        DAVID["remove_ids"],
        DAVID["add_ids"],
        new_keyword,
    )

    removed = []
    retained = []
    for post in assigned:
        post_id = str(post["wpId"])
        keywords = [
            value
            for value in post.get("secondaryKeywords", [])
            if value != old_keyword
        ]
        if post_id in DAVID["remove_ids"]:
            removed.append(post_record(post))
        else:
            keywords.append(new_keyword)
            retained.append(post_record(post))
        post["secondaryKeywords"] = sorted(set(keywords), key=str.casefold)

    added = []
    for post_id in DAVID["add_ids"]:
        post = posts_by_id[post_id]
        post.setdefault("secondaryKeywords", []).append(new_keyword)
        post["secondaryKeywords"] = sorted(
            set(post["secondaryKeywords"]), key=str.casefold
        )
        added.append(post_record(post))

    old_after = sum(
        old_keyword in post.get("secondaryKeywords", []) for post in posts
    )
    new_after = sum(
        new_keyword in post.get("secondaryKeywords", []) for post in posts
    )
    expected_after = DAVID["before"] - len(removed) + len(added)
    if old_after != 0 or new_after != expected_after:
        raise RuntimeError(
            "Unexpected King David normalization result: "
            f"old={old_after}, new={new_after}, expected={expected_after}"
        )

    return {
        "keyword": new_keyword,
        "renamedFrom": old_keyword,
        "criterion": DAVID["criterion"],
        "before": len(assigned),
        "after": new_after,
        "renamed": len(retained),
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
        apply_standard_audit(posts, keyword, config)
        for keyword, config in AUDITS.items()
    ]
    results.append(apply_david_audit(posts))

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
