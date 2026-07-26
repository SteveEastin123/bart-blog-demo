"""Generate compact, full-text-supported packets for manual post-label audits."""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RAW_PATH = ROOT / "data" / "raw" / "posts.jsonl"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
STOP_WORDS = {
    "a", "an", "and", "as", "at", "by", "for", "from", "in", "into", "of",
    "on", "or", "the", "to", "with",
}


def normalize(value: str) -> str:
    value = re.sub(r"\s*\(general\)\s*", " ", value, flags=re.IGNORECASE)
    return " ".join(re.findall(r"[a-z0-9]+", value.casefold().replace("’", "'")))


def phrase_count(needle: str, haystack: str) -> int:
    if not needle:
        return 0
    return len(re.findall(rf"(?<![a-z0-9]){re.escape(needle)}(?![a-z0-9])", haystack))


def significant_tokens(value: str) -> set[str]:
    return {token for token in normalize(value).split() if token not in STOP_WORDS}


def load_raw_posts() -> dict[str, dict[str, Any]]:
    with RAW_PATH.open(encoding="utf-8") as handle:
        return {str(post.get("wpId")): post for post in map(json.loads, handle)}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--start", type=int, required=True)
    parser.add_argument("--count", type=int, default=100)
    parser.add_argument(
        "--compact",
        action="store_true",
        help="Print only the fields needed for an initial manual screening pass",
    )
    args = parser.parse_args()

    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    raw_posts = load_raw_posts()
    topic_data = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    topic_names = [topic["name"] for topic in topic_data if topic["name"] != "Ignore"]
    keyword_usage = Counter(
        keyword for post in posts for keyword in post.get("secondaryKeywords", [])
    )
    keyword_names = [keyword for keyword, count in keyword_usage.items() if count >= 2]

    for index, post in enumerate(posts[args.start : args.start + args.count], args.start):
        raw = raw_posts.get(str(post.get("wpId")), {})
        title = normalize(post.get("title", ""))
        description = normalize(post.get("description", ""))
        full_text = normalize(raw.get("text", ""))
        heading = " ".join((title, description))
        combined = " ".join((heading, full_text))
        current_topics = post.get("topics", [])
        current_keywords = post.get("secondaryKeywords", [])
        current_normalized = {
            normalize(value) for value in current_topics + current_keywords
        }

        weak_topics = []
        heading_tokens = significant_tokens(heading)
        for topic in current_topics:
            topic_key = normalize(topic)
            overlap = significant_tokens(topic) & heading_tokens
            if topic != "Ignore" and not overlap and phrase_count(topic_key, full_text) == 0:
                weak_topics.append(topic)

        weak_keywords = []
        for keyword in current_keywords:
            keyword_key = normalize(keyword)
            count = phrase_count(keyword_key, full_text)
            in_heading = keyword_key in heading
            if count == 0 or (count == 1 and not in_heading):
                weak_keywords.append({"name": keyword, "count": count})

        topic_additions = []
        for topic in topic_names:
            topic_key = normalize(topic)
            if topic_key in current_normalized or len(topic_key) < 4:
                continue
            in_title = phrase_count(topic_key, title) > 0
            in_description = phrase_count(topic_key, description) > 0
            if not in_title and not in_description:
                continue
            count = phrase_count(topic_key, full_text)
            if in_title or count >= 2:
                topic_additions.append({"name": topic, "count": count})

        keyword_additions = []
        for keyword in keyword_names:
            keyword_key = normalize(keyword)
            if keyword_key in current_normalized or len(keyword_key) < 3:
                continue
            in_title = phrase_count(keyword_key, title) > 0
            in_description = phrase_count(keyword_key, description) > 0
            if not in_title and not in_description:
                continue
            count = phrase_count(keyword_key, full_text)
            if in_title or count >= 2:
                keyword_additions.append(
                    {"name": keyword, "count": count, "usage": keyword_usage[keyword]}
                )

        packet = {
                    "index": index,
                    "wpId": post.get("wpId"),
                    "title": post.get("title"),
                    "description": post.get("description"),
                    "topics": current_topics,
                    "secondaryKeywords": current_keywords,
        }
        if not args.compact:
            packet.update(
                {
                    "weakTopics": weak_topics,
                    "weakKeywords": weak_keywords,
                    "possibleTopicAdditions": topic_additions,
                    "possibleKeywordAdditions": keyword_additions,
                    "wordCount": len(full_text.split()),
                }
            )
        print(
            json.dumps(
                packet,
                ensure_ascii=False,
                separators=(",", ":") if args.compact else None,
            )
        )


if __name__ == "__main__":
    main()
