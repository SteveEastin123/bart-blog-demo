"""Apply the approved audit of the final higher-frequency keywords."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"

CONCEPT_TOPIC_EQUIVALENTS = {
    "Historical Jesus": "Historical Jesus (General)",
    "Early Christianity": "Early Christianity (General)",
}

REMOVE_BY_KEYWORD_AND_TITLE = {
    "Gospels": {
        "Paul’s Own (and Only) Gospel",
        "The Core of Paul’s Gospel",
        "Paul’s Gospel Message",
    }
}


def validate_targets(posts: list[dict[str, object]]) -> None:
    """Ensure every title-based removal resolves to one matching assignment."""
    for keyword, titles in REMOVE_BY_KEYWORD_AND_TITLE.items():
        for title in titles:
            matches = [
                post
                for post in posts
                if post.get("title") == title
                and keyword in post.get("secondaryKeywords", [])
            ]
            if len(matches) != 1:
                raise ValueError(
                    f"Expected one {keyword!r} assignment for {title!r}; "
                    f"found {len(matches)}"
                )


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    validate_targets(posts)
    concept_duplicates = 0
    selective_removals = 0

    for post in posts:
        title = str(post["title"])
        topics = {str(topic) for topic in post.get("topics", [])}
        updated: list[str] = []
        for keyword in post.get("secondaryKeywords", []):
            if CONCEPT_TOPIC_EQUIVALENTS.get(keyword) in topics:
                concept_duplicates += 1
                continue
            if title in REMOVE_BY_KEYWORD_AND_TITLE.get(keyword, set()):
                selective_removals += 1
                continue
            updated.append(keyword)
        post["secondaryKeywords"] = updated

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"Removed conceptual duplicates: {concept_duplicates}")
    print(f"Removed selective assignments: {selective_removals}")


if __name__ == "__main__":
    main()
