"""Apply the approved audit of secondary keywords used by seven posts."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_BY_KEYWORD = {
    "Agnosticism": {"40339", "7360"},
    "Apocrypha": {"12262"},
    "Aramaic": {"12363"},
    "Esther": {"48691", "48687", "12248", "12239"},
    "Hosea": {"35354"},
    "Miracles and Conversion": {
        "38658",
        "21403",
        "15607",
        "15602",
        "15573",
        "11330",
        "10649",
    },
    "Rich and Poor in Afterlife": {
        "31177",
        "31173",
        "30969",
        "15541",
        "15539",
        "12924",
        "12914",
    },
    "Samson": {"37909", "33575", "17569", "11791", "8545", "8232"},
    "Syllabus": {"6422"},
}

NORMALIZE = {
    "Marc Goodacre": "Mark Goodacre",
    "Apocrypha": "Christian Apocrypha",
}

RETIRED = {
    "Apocrypha",
    "Miracles and Conversion",
    "Rich and Poor in Afterlife",
}


def normalized_unique(values: list[str]) -> list[str]:
    """Return values in original order with case-insensitive duplicates removed."""
    result: list[str] = []
    seen: set[str] = set()
    for value in values:
        key = value.casefold().strip()
        if key and key not in seen:
            seen.add(key)
            result.append(value)
    return result


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    removed = 0
    normalized = 0

    for post in posts:
        post_id = str(post["wpId"])
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if post_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)
        topic_keys = {topic.casefold().strip() for topic in post.get("topics", [])}
        post["secondaryKeywords"] = [
            keyword
            for keyword in normalized_unique(updated)
            if keyword.casefold().strip() not in topic_keys
        ]

    retirement = json.loads(RETIREMENT_PATH.read_text(encoding="utf-8"))
    retirement["keywords"] = sorted(
        set(retirement.get("keywords", [])).union(RETIRED), key=str.casefold
    )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENT_PATH.write_text(
        json.dumps(retirement, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Removed assignments: {removed}")
    print(f"Normalized assignments: {normalized}")


if __name__ == "__main__":
    main()
