"""Apply the approved audit of the second 50 higher-frequency keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE_ALL = {"New Testament"}

REMOVE_BY_KEYWORD = {
    "1 Peter": {"47012"},
    "2 Timothy": {"46973", "47123", "1715"},
    "Amos": {"48332"},
    "Colossians": {"46973", "47246", "47123"},
    "Didache": {"39578", "14403"},
    "Didymus the Blind": {"23125", "3723"},
    "Documentary Hypothesis": {"15268"},
    "Gabriel": {"34994", "4177"},
    "Gospel of Mary": {"35188", "16606", "12262", "7688"},
    "Infancy Gospel of Thomas": {
        "35188",
        "35182",
        "21260",
        "21255",
        "16606",
        "7688",
        "2268",
    },
    "King Saul": {"40440", "32678", "29846", "17642"},
    "New Testament Manuscripts": {"12543", "10984", "9462", "2103"},
    "Oral Tradition": {"41046", "10301", "8753", "7669"},
    "Pentateuch": {"11652"},
    "Poverty": {"40011", "27875"},
    "Repentance": {"34088"},
    "Synoptic Problem": {"47178", "4519"},
    "Tertullian": {"49051", "35188"},
    "Thecla": {"35188"},
    "Titus": {"46973", "47123"},
    "Trinity": {
        "32879",
        "24586",
        "24528",
        "17079",
        "15780",
        "12571",
        "12270",
        "9039",
        "7206",
        "6587",
        "4007",
        "3648",
    },
}

NORMALIZE = {
    "Scribal Activity": "Scribal Practices",
    "Scribal Tendencies": "Scribal Practices",
    "Biblical Translation": "Bible Translation",
    "Bible Translations": "Bible Translation",
    "King James Bible": "King James Version",
    "NRSV": "New Revised Standard Version (NRSV)",
}

RETIRED = set(NORMALIZE).union(REMOVE_ALL)


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
    topic_duplicates = 0

    for post in posts:
        post_id = str(post["wpId"])
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if keyword in REMOVE_ALL or post_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)

        unique = normalized_unique(updated)
        topic_keys = {topic.casefold().strip() for topic in post.get("topics", [])}
        filtered = [
            keyword
            for keyword in unique
            if keyword.casefold().strip() not in topic_keys
        ]
        topic_duplicates += len(unique) - len(filtered)
        post["secondaryKeywords"] = filtered

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
    print(f"Removed topic duplicates after normalization: {topic_duplicates}")


if __name__ == "__main__":
    main()
