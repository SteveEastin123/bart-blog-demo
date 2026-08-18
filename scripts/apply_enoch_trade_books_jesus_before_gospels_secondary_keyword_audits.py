from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Enoch": {
        "before": 70,
        "remove_ids": {
            "2551", "8307", "8511", "8513", "12450", "12854", "13508",
            "13773", "15559", "15622", "15633", "17134", "17688",
            "21114", "21311", "23289", "26586", "26653", "28985",
            "33532", "34912", "38881", "38983", "39461", "40864",
            "48954",
        },
        "add_ids": {"17320", "49218", "50201"},
        "criterion": (
            "Retain or add Enoch when the biblical figure, 1 Enoch, or Enochic "
            "traditions materially support the post's argument. Remove names "
            "in catalogs, manuscript contents, broad source lists, passing "
            "comparisons, and brief examples that are not developed."
        ),
    },
    "Trade Books": {
        "before": 70,
        "remove_ids": {
            "2822", "4821", "11859", "13336", "15494", "17551", "30695",
            "33529", "47869",
        },
        "add_ids": {
            "3938", "4036", "7369", "7676", "7681", "7688", "7736",
            "7740", "7747", "7764", "10314", "11198", "11287", "11904",
            "12101", "12638", "12873", "14968",
            "15162", "15246", "15349", "15455", "15502", "15740",
            "15798", "15900", "16202", "16549", "16606", "17134",
            "17320", "17360", "25914", "26592", "27871", "29016",
            "32319", "33177", "35045", "39995", "40002", "47003",
        },
        "criterion": (
            "Retain or add Trade Books when writing, researching, publishing, "
            "marketing, or comparing books for general audiences is a "
            "meaningful subject. Remove passing references to trade books, "
            "incidental publishing history, and brief contrasts in posts "
            "whose sustained subject is something else."
        ),
    },
    "Jesus Before the Gospels": {
        "before": 67,
        "remove_ids": {
            "10314", "11188", "11198", "11287", "15880", "16618",
        },
        "add_ids": {
            "7664", "8629", "9504", "9671", "10404", "11863", "12413",
            "14872", "34048", "35270", "39090", "40268",
        },
        "criterion": (
            "Retain or add Jesus Before the Gospels when the book, an excerpt "
            "from it, its development, or its central arguments about memory, "
            "eyewitnesses, and oral tradition materially support the post. "
            "Remove incidental publishing references and posts whose actual "
            "subject is unrelated methodology or publishing history."
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
    missing_removals = sorted(config["remove_ids"] - assigned_ids, key=int)
    unexpected_additions = sorted(config["add_ids"] & assigned_ids, key=int)
    missing_posts = sorted(
        (config["remove_ids"] | config["add_ids"]) - posts_by_id.keys(),
        key=int,
    )
    topic_duplicates = sorted(
        post_id
        for post_id in config["add_ids"]
        if keyword in posts_by_id[post_id].get("topics", [])
    )
    if missing_removals:
        raise RuntimeError(
            f"Expected removable {keyword} posts without keyword: "
            f"{missing_removals}"
        )
    if unexpected_additions:
        raise RuntimeError(
            f"Expected new {keyword} posts already assigned: "
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
