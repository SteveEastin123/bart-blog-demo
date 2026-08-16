"""Apply the approved secondary-keyword vocabulary merges."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENT_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

NORMALIZE = {
    "Papyrus Egerton 2": "Papyrus Egerton",
    "Christian Forgery": "Forgery",
    "Canonization": "Canon Formation",
    "Four Gospels": "Canonical Gospels",
    "Bible Translation": "Translation",
    "Apocryphal Gospels": "Non-Canonical Gospels",
    "Jewish Scripture": "Hebrew Bible",
    "Burial of Jesus": "Burial",
    "Crucifixion of Jesus": "Crucifixion",
    "Princeton Seminary": "Princeton Theological Seminary",
    "Schweitzer": "Albert Schweitzer",
    "Eusebius": "Eusebius of Caesarea",
    "Erasmus": "Desiderius Erasmus",
    "Abgar": "King Abgar",
    "Jesus' Wife": "Gospel of Jesus' Wife",
    "Nag Hammadi Discovery": "Nag Hammadi",
    "Eyewitness": "Eyewitness Testimony",
    "Mike Licona": "Michael Licona",
    "Joseph Ratzinger": "Pope Benedict XVI",
    "Octavian": "Augustus Caesar",
    "Emperor Worship": "Imperial Cult",
    "Mythicists": "Mythicism",
    "Marcionites": "Marcionism",
    "Infancy Narratives": "Jesus' Birth Narratives",
    "Patristics": "Patristic Evidence",
    "Translation Process": "Translation",
    "Ancient Jewish Afterlife Beliefs": "Afterlife",
    "Literary-Historical Method": "Historical Method",
    "Herod the Great": "Herod",
    "Loss of Faith": "Deconversion",
    "Gospel Contradictions": "Biblical Discrepancies",
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
    normalized = 0
    deduplicated = 0
    topic_duplicates = 0

    for post in posts:
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            replacement = NORMALIZE.get(keyword, keyword)
            if replacement != keyword:
                normalized += 1
            updated.append(replacement)

        unique = normalized_unique(updated)
        deduplicated += len(updated) - len(unique)
        topic_keys = {
            str(topic).casefold().strip() for topic in post.get("topics", [])
        }
        filtered = [
            keyword
            for keyword in unique
            if keyword.casefold().strip() not in topic_keys
        ]
        topic_duplicates += len(unique) - len(filtered)
        post["secondaryKeywords"] = filtered

    retirement = json.loads(RETIREMENT_PATH.read_text(encoding="utf-8"))
    retirement["keywords"] = sorted(
        set(retirement.get("keywords", [])).union(NORMALIZE), key=str.casefold
    )

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    RETIREMENT_PATH.write_text(
        json.dumps(retirement, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Normalized assignments: {normalized}")
    print(f"Deduplicated assignments: {deduplicated}")
    print(f"Removed topic duplicates: {topic_duplicates}")


if __name__ == "__main__":
    main()
