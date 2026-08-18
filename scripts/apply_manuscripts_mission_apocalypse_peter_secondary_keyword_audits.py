from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


def id_set(values: str) -> set[str]:
    return set(values.split())


AUDITS = {
    "Manuscripts": {
        "before": 60,
        "remove_ids": id_set("48685 8801"),
        "add_ids": id_set(
            """
            50165 50101 48697 47130 47116 41678 41672 41662 41649 40091
            40084 40079 40060 40056 39086 39128 39082 37644 36737 36732
            36724 36058 36102 33421 28781 25931 25926 22945 21317 21003
            20867 20624 20540 17505 16884 16322 16318 16314 16288 16284
            16281 16275 16211 16159 15370 15330 15328 15187 15185 15146
            15142 15133 12868 12865 12454 9323 8999 8993 8978 8975
            8904 8892 8890 8876 8874 8864 8862 8857 8459 8454
            8449 8443 8440 8428 7845 4014 3299 2472 1761
            """
        ),
        "criterion": (
            "Use Manuscripts when ancient physical witnesses, their copying, "
            "dating, discovery, contents, acquisition, preservation, or use "
            "are a major or meaningful supporting subject. Remove general "
            "introductions to biblical contents and discussions of lost "
            "writings that do not materially address manuscript evidence."
        ),
    },
    "Apocalypse of Peter": {
        "before": 59,
        "remove_ids": id_set(
            """
            48816 48025 40285 40021 40007 38881 38752 36803 35188 34912
            33028 27166 25517 25712 21311 21145 20507 17169 15654 15649
            15647 15633 13773 12253 10892 8748 8405 8307 6637 6578
            4792 2268 2259 2059
            """
        ),
        "add_ids": id_set("9458 40682 17368 17320 16425 11944 6376"),
        "criterion": (
            "Use Apocalypse of Peter when either the Greek/Ethiopic "
            "afterlife apocalypse or the Coptic/Gnostic apocalypse is "
            "meaningfully discussed through its contents, theology, canon "
            "status, influence, or comparison with another text. Remove "
            "syllabi, schedules, anthology lists, shared-manuscript notices, "
            "and one-line examples that do not discuss either work."
        ),
    },
}


MISSION = {
    "old_keyword": "Mission",
    "new_keyword": "Christian Missionary Activity",
    "before": 60,
    "remove_ids": id_set(
        """
        47029 41678 41780 41684 41233 39040 38348 38313 36268 35694
        34084 31844 28663 20540 17676 15839 12862 12545 12529 10046
        9864 9674 7206 6493 4357 4007 2095
        """
    ),
    "add_ids": id_set(
        """
        49737 47169 47137 47123 47121 47234 4500 41050 8605 38840
        38639 10576 30975 11578 11576 11289 40382 35557 35561 21403
        49838 49820 22090
        """
    ),
    "criterion": (
        "Use Christian Missionary Activity when Christian evangelism, "
        "conversion efforts, apostolic or missionary travel, church "
        "expansion, or disputes arising from the mission are a major or "
        "meaningful supporting subject. Exclude organizational mission "
        "statements, fundraising, personal callings, prophetic vocations, "
        "book-title wording, and general references to Jesus' purpose."
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


def apply_mission_audit(posts: list[dict]) -> dict:
    posts_by_id = {str(post["wpId"]): post for post in posts}
    old_keyword = MISSION["old_keyword"]
    new_keyword = MISSION["new_keyword"]
    assigned = [
        post for post in posts if old_keyword in post.get("secondaryKeywords", [])
    ]
    if len(assigned) != MISSION["before"]:
        raise RuntimeError(
            f"Expected {MISSION['before']} {old_keyword} assignments; found "
            f"{len(assigned)}"
        )
    if any(new_keyword in post.get("secondaryKeywords", []) for post in posts):
        raise RuntimeError(f"{new_keyword} already exists before normalization")

    assigned_ids = {str(post["wpId"]) for post in assigned}
    validate_changes(
        posts_by_id,
        assigned_ids,
        MISSION["remove_ids"],
        MISSION["add_ids"],
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
        if post_id in MISSION["remove_ids"]:
            removed.append(post_record(post))
        else:
            keywords.append(new_keyword)
            retained.append(post_record(post))
        post["secondaryKeywords"] = sorted(set(keywords), key=str.casefold)

    added = []
    for post_id in MISSION["add_ids"]:
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
    expected_after = MISSION["before"] - len(removed) + len(added)
    if old_after != 0 or new_after != expected_after:
        raise RuntimeError(
            "Unexpected Christian Missionary Activity normalization result: "
            f"old={old_after}, new={new_after}, expected={expected_after}"
        )

    return {
        "keyword": new_keyword,
        "renamedFrom": old_keyword,
        "criterion": MISSION["criterion"],
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
    results.append(apply_mission_audit(posts))

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
