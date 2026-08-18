from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Herod the Great": {
        "before": 80,
        "remove_ids": {
            "4602",
            "8350",
            "12844",
            "15723",
            "22610",
            "33124",
            "36902",
            "37067",
            "37837",
            "39278",
            "39869",
            "40440",
            "47084",
            "47139",
        },
        "criterion": (
            "Retain Herod the Great when his reign, actions, family, building "
            "program, historical chronology, or portrayal in Matthew materially "
            "supports the post. Remove references to Herod Antipas and passing "
            "examples, lists, comparisons, and background details."
        ),
    },
    "Jewish Law": {
        "before": 79,
        "remove_ids": {
            "4076",
            "7471",
            "11977",
            "16236",
            "33399",
            "33421",
            "33431",
            "38088",
            "39827",
            "47273",
        },
        "criterion": (
            "Retain Jewish Law when Torah observance, circumcision, Sabbath, "
            "dietary requirements, Jewish burial law, or disputes about the "
            "Law materially support the post. Remove thread references, course "
            "listings, and incidental examples or comparisons."
        ),
    },
    "Roman World": {
        "before": 79,
        "remove_ids": {
            "4957",
            "7896",
            "8018",
            "9129",
            "10590",
            "11777",
            "15251",
            "15738",
            "17477",
            "22178",
            "31849",
            "32879",
            "38645",
        },
        "criterion": (
            "Retain Roman World when Roman political, social, legal, religious, "
            "or cultural context materially supports the post. Remove generic "
            "geographical framing, bibliographic and footnote references, "
            "course organization, and pointers to other posts."
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
