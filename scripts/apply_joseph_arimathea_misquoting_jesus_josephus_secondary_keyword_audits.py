from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"


AUDITS = {
    "Joseph of Arimathea": {
        "before": 77,
        "remove_ids": {
            "4042",
            "4893",
            "6647",
            "7031",
            "12319",
            "13189",
            "15672",
            "17525",
            "21311",
            "25205",
            "34912",
            "40554",
            "47109",
        },
        "criterion": (
            "Retain Joseph of Arimathea when his role in Jesus' burial, the "
            "empty-tomb and resurrection traditions, historical reconstruction, "
            "literary portrayal, or later legends materially supports the post. "
            "Remove narrative transitions, list items, cross-references, and "
            "brief illustrative mentions."
        ),
    },
    "Misquoting Jesus": {
        "before": 77,
        "remove_ids": {
            "2282",
            "2385",
            "3151",
            "7101",
            "7155",
            "8244",
            "8639",
            "9047",
            "9518",
            "9956",
            "11560",
            "11845",
            "13330",
            "14766",
            "17619",
            "17662",
            "20704",
            "33021",
            "34837",
            "37502",
        },
        "criterion": (
            "Retain Misquoting Jesus when the book or podcast, its arguments, "
            "reception, composition, title, excerpts, or direct application "
            "materially supports the post. Remove author credentials, reading "
            "lists, event listings, title analogies, and passing cross-references."
        ),
    },
    "Josephus": {
        "before": 76,
        "remove_ids": {
            "2228",
            "3073",
            "6277",
            "6563",
            "7563",
            "11992",
            "15012",
            "15820",
            "16907",
            "17237",
        },
        "criterion": (
            "Retain Josephus when his writings, testimony, biography, historical "
            "claims, or use as comparative evidence materially supports the post. "
            "Remove source-list mentions, question framing, event listings, "
            "generic comparisons, and isolated references."
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
