from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Gospel of Peter": {
        "before": 84,
        "remove_ids": {
            "2059",
            "2240",
            "2263",
            "2274",
            "2551",
            "3101",
            "4049",
            "4056",
            "4711",
            "5149",
            "6145",
            "6402",
            "6578",
            "7688",
            "8269",
            "8417",
            "8634",
            "10892",
            "12107",
            "12262",
            "12391",
            "12642",
            "15446",
            "15624",
            "15643",
            "15647",
            "15649",
            "15674",
            "16606",
            "16715",
            "17169",
            "20507",
            "21106",
            "21223",
            "21274",
            "21355",
            "22087",
            "25436",
            "25517",
            "26382",
            "26387",
            "32394",
            "32441",
            "33028",
            "33135",
            "35188",
            "35192",
            "35261",
            "35410",
            "37415",
            "38596",
            "38752",
            "40016",
        },
        "add_ids": {
            "3069",
            "8857",
            "11959",
            "33902",
            "47727",
            "50098",
        },
        "criterion": (
            "Retain or add when the Gospel of Peter is a meaningful source, "
            "case study, text under analysis, or sustained supporting example. "
            "Remove passing comparisons, generic lists of non-canonical works, "
            "syllabus and reading-list mentions, prior-thread references, and "
            "brief examples that do not materially advance the post's argument."
        ),
    },
    "Pauline Letters": {
        "before": 84,
        "remove_ids": {
            "2551",
            "8571",
            "8626",
            "8821",
            "10312",
            "11748",
            "12739",
            "15818",
            "17551",
            "26011",
            "46934",
            "47188",
            "47215",
            "48295",
            "48954",
        },
        "add_ids": {
            "2492",
            "4519",
            "6919",
            "9022",
            "9303",
            "12118",
            "14674",
            "14690",
            "15151",
            "22271",
            "25893",
            "29621",
            "33349",
            "35689",
            "35878",
            "37572",
            "39286",
            "39578",
            "39593",
            "39723",
            "40576",
            "46994",
            "47001",
            "47123",
            "47131",
            "47155",
            "47164",
            "47169",
            "47189",
            "47197",
            "47208",
            "47565",
            "49897",
        },
        "criterion": (
            "Retain or add when Paul's letters as a corpus, an individual "
            "Pauline letter, its composition or textual history, or evidence "
            "drawn substantially from the letters is important to the post. "
            "Remove incidental course titles, source lists, comparisons, "
            "prior-thread references, and examples that do not materially "
            "contribute to the post's discussion."
        ),
    },
}


def post_record(post: dict) -> dict:
    return {"wpId": str(post["wpId"]), "title": post["title"]}


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
