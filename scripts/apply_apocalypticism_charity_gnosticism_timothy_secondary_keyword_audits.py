"""Apply the approved Apocalypticism, Charity, Gnosticism, and Timothy audits."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


def id_set(values: str) -> set[str]:
    """Return a whitespace-delimited collection of WordPress IDs as a set."""
    return set(values.split())


AUDITS = {
    "Apocalypticism": {
        "before": 59,
        "remove_ids": id_set("12749 28517 28663"),
        "add_ids": id_set(
            """
            2095 3657 3713 3758 6537 6541 6762 7484 7491 8513 12391
            12813 12826 13211 13323 15466 15614 15791 17237 17591 21093
            21139 21159 25886 25923 26033 26526 26782 27285 27340 27343
            27397 27402 27746 28726 28907 28939 28977 28981 35313 35324
            35679 35685 36022 36569 37655 37661 38137 38328 38334 38340
            38348 39995 41351 47060 47640 47645 49985 50249 50273 50288
            50290 50317 50329 50367
            """
        ),
        "criterion": (
            "Use Apocalypticism when Jewish or early Christian apocalyptic "
            "worldviews, Jesus' apocalyptic message, end-time expectations, "
            "apocalyptic judgment, or the historical development of those "
            "beliefs are a major or meaningful supporting subject. Exclude "
            "autobiographical analogies and posts that mention apocalypticism "
            "only to introduce a separate discussion of biblical prophecy."
        ),
    },
    "Charity": {
        "before": 58,
        "remove_ids": id_set(
            """
            2079 2380 4689 6439 8874 12516 13192 14952 14971 15288
            15981 16383 16425 16851 17095 17149 17159 29846 32319 32323
            34837 38151 47004
            """
        ),
        "add_ids": id_set(
            """
            2254 4633 7645 7683 7906 8385 8942 9173 9448 13781 15053
            16040 17457 20486 21022 22447 22720 22919 23938 24074 26018
            27091 30458 30857 30969 30972 31491 32062 32065 32068 32264
            32735 32817 32820 33559 37959 38132 38142 38146 39844 39850
            41484 41505 47029 47031 47684 48160 48737 48919 49334 49337
            49339 49516
            """
        ),
        "criterion": (
            "Use Charity when charitable giving, altruism, aid to people in "
            "need, Christian teachings or institutions of charity, or the "
            "blog's charitable fundraising is a major or meaningful "
            "supporting subject. Exclude membership boilerplate, passing "
            "biographical or publishing references, and unrelated uses such "
            "as intellectual charity."
        ),
    },
    "Gnosticism": {
        "before": 58,
        "remove_ids": id_set("2059 2797"),
        "add_ids": id_set("4513 8950 29925 40302 47007 47009 48295"),
        "criterion": (
            "Use Gnosticism when Gnostic beliefs, texts, movements, "
            "Christologies, opponents, or conflicts with proto-orthodox "
            "Christians are a major or meaningful supporting subject. "
            "Exclude references that merely identify a scholar's specialty "
            "or list Gnosticism among several academic fields or courses. "
            "Do not duplicate the equivalent Gnosticism (General) topic."
        ),
    },
    "Timothy": {
        "before": 58,
        "remove_ids": id_set(
            """
            1715 8334 8599 16117 16656 17041 17064 35561 38583 46943
            46973 47123 47236
            """
        ),
        "add_ids": id_set(
            """
            5075 8128 8344 10885 11765 16251 16668 16678 25712 27835
            29032 38713 39555 41256 47196 47224 47256 48797
            """
        ),
        "criterion": (
            "Use Timothy for meaningful discussion of Paul's coworker "
            "Timothy or the New Testament letters 1 and 2 Timothy, including "
            "their contents, authorship, use in historical arguments, and "
            "role in the canon. Exclude passing lists and unrelated modern "
            "people whose given name is Timothy."
        ),
    },
}


def post_record(post: dict) -> dict:
    """Return the stable audit fields for one post."""
    return {
        "wpId": str(post["wpId"]),
        "title": post["title"],
        "topics": post.get("topics", []),
    }


def validate_audit(posts: list[dict], keyword: str, config: dict) -> None:
    """Validate the expected state before modifying any assignments."""
    posts_by_id = {str(post["wpId"]): post for post in posts}
    assigned_ids = {
        str(post["wpId"])
        for post in posts
        if keyword in post.get("secondaryKeywords", [])
    }
    if len(assigned_ids) != config["before"]:
        raise RuntimeError(
            f"Expected {config['before']} {keyword} assignments; "
            f"found {len(assigned_ids)}"
        )

    remove_ids = config["remove_ids"]
    add_ids = config["add_ids"]
    missing_posts = sorted((remove_ids | add_ids) - posts_by_id.keys(), key=int)
    missing_removals = sorted(remove_ids - assigned_ids, key=int)
    existing_additions = sorted(add_ids & assigned_ids, key=int)
    exact_topic_duplicates = sorted(
        post_id
        for post_id in add_ids
        if keyword in posts_by_id[post_id].get("topics", [])
    )
    if missing_posts:
        raise RuntimeError(f"Unknown post IDs in {keyword} audit: {missing_posts}")
    if missing_removals:
        raise RuntimeError(
            f"Expected removable {keyword} assignments not found: "
            f"{missing_removals}"
        )
    if existing_additions:
        raise RuntimeError(
            f"Expected new {keyword} assignments already present: "
            f"{existing_additions}"
        )
    if exact_topic_duplicates:
        raise RuntimeError(
            f"Refusing exact topic/keyword duplication for {keyword}: "
            f"{exact_topic_duplicates}"
        )


def apply_audit(posts: list[dict], keyword: str, config: dict) -> dict:
    """Apply one validated keyword audit and return its audit record."""
    validate_audit(posts, keyword, config)
    posts_by_id = {str(post["wpId"]): post for post in posts}
    assigned_before = [
        post for post in posts if keyword in post.get("secondaryKeywords", [])
    ]

    removed = []
    for post_id in config["remove_ids"]:
        post = posts_by_id[post_id]
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

    removed_ids = config["remove_ids"]
    retained = [
        post_record(post)
        for post in assigned_before
        if str(post["wpId"]) not in removed_ids
    ]
    after = sum(
        keyword in post.get("secondaryKeywords", []) for post in posts
    )
    expected_after = config["before"] - len(removed) + len(added)
    if after != expected_after:
        raise RuntimeError(
            f"Expected {expected_after} {keyword} assignments after audit; "
            f"found {after}"
        )

    return {
        "keyword": keyword,
        "criterion": config["criterion"],
        "before": config["before"],
        "after": after,
        "retained": len(retained),
        "removed": len(removed),
        "added": len(added),
        "retainedPosts": sorted(retained, key=lambda item: int(item["wpId"])),
        "removedPosts": sorted(removed, key=lambda item: int(item["wpId"])),
        "addedPosts": sorted(added, key=lambda item: int(item["wpId"])),
    }


def audit_filename(keyword: str) -> str:
    """Return the repository's standard audit filename for a keyword."""
    return keyword.casefold().replace(" ", "_") + "_secondary_keyword_audit.json"


def main() -> None:
    """Apply all four audits and save the updated index and audit evidence."""
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
