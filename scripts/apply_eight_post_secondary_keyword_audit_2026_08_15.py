"""Apply the approved audit of secondary keywords used by eight posts."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"

REMOVE_BY_KEYWORD = {
    "Aaron": {"40864", "15559"},
    "Abel": {"48013", "15392", "33532"},
    "Abgar": {"15680", "8405"},
    "Ananias": {"46975"},
    "Gospel of Jesus' Wife": {"14882", "7319", "7315"},
    "Julian": {"25276"},
    "Marcion": {"38600", "11977"},
    "Papias": {"15392", "8251"},
    "Psalms": {"48695", "48687", "12239"},
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

    for post in posts:
        post_id = str(post["wpId"])
        updated = []
        for keyword in post.get("secondaryKeywords", []):
            if post_id in REMOVE_BY_KEYWORD.get(keyword, set()):
                removed += 1
                continue
            updated.append(keyword)

        topic_keys = {topic.casefold().strip() for topic in post.get("topics", [])}
        post["secondaryKeywords"] = [
            keyword
            for keyword in normalized_unique(updated)
            if keyword.casefold().strip() not in topic_keys
        ]

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Removed assignments: {removed}")


if __name__ == "__main__":
    main()
