from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"

AUDITS = {
    "Eve": {
        "before": 79,
        "retained_ids": {
            "3858",
            "3917",
            "4335",
            "4613",
            "4618",
            "8220",
            "10321",
            "11589",
            "12560",
            "12584",
            "12749",
            "14860",
            "16639",
            "17005",
            "17041",
            "17688",
            "17776",
            "23752",
            "24163",
            "25653",
            "28353",
            "34490",
            "38171",
            "38868",
            "39548",
            "39555",
            "47268",
        },
        "criterion": (
            "Retain when Eve or the Genesis account involving her materially "
            "supports the post's discussion of creation, women and subordination, "
            "original sin, sexuality, suffering, divine appearances, or "
            "Christ-and-Adam interpretation. Remove genealogical shorthand, "
            "passing examples and analogies, generic belief lists, and references "
            "that merely identify Eve as Seth's or Cain's mother."
        ),
    },
    "Jeremiah": {
        "before": 86,
        "retained_ids": {
            "3448",
            "4234",
            "4325",
            "4329",
            "4523",
            "6555",
            "9497",
            "9513",
            "11645",
            "12190",
            "12744",
            "15217",
            "15469",
            "15559",
            "15562",
            "15694",
            "16633",
            "20776",
            "20862",
            "20867",
            "21009",
            "22643",
            "25459",
            "26782",
            "27340",
            "27498",
            "27777",
            "28045",
            "28726",
            "31887",
            "32546",
            "33023",
            "33100",
            "33421",
            "34327",
            "35685",
            "37690",
            "39865",
            "40859",
            "40864",
            "47027",
            "47043",
            "47159",
            "47269",
            "48388",
            "48695",
            "48795",
            "50364",
        },
        "criterion": (
            "Retain when Jeremiah's writings, prophetic activity, textual history, "
            "Temple criticism, restoration prophecies, or influence on later "
            "interpretation receives meaningful discussion. Remove names found "
            "only in book and prophet lists, passing comparisons, isolated "
            "technical examples, and unrelated geographic citations."
        ),
    },
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
    missing_retained = sorted(config["retained_ids"] - assigned_ids, key=int)
    if missing_retained:
        raise RuntimeError(
            f"Expected retained {keyword} posts without keyword: "
            f"{missing_retained}"
        )

    retained = []
    removed = []
    for post in assigned:
        record = {"wpId": str(post["wpId"]), "title": post["title"]}
        if str(post["wpId"]) in config["retained_ids"]:
            retained.append(record)
            continue
        post["secondaryKeywords"] = [
            value
            for value in post.get("secondaryKeywords", [])
            if value != keyword
        ]
        removed.append(record)

    return {
        "keyword": keyword,
        "criterion": config["criterion"],
        "before": len(assigned),
        "retained": len(retained),
        "removed": len(removed),
        "added": 0,
        "retainedPosts": retained,
        "removedPosts": removed,
        "addedPosts": [],
    }


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
        audit_path = AUDITS_DIR / (
            result["keyword"].casefold().replace(" ", "_")
            + "_secondary_keyword_audit.json"
        )
        audit_path.write_text(
            json.dumps(result, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        print(
            f"{result['keyword']}: {result['before']} -> "
            f"{result['retained']} posts "
            f"({result['removed']} removed)"
        )


if __name__ == "__main__":
    main()
