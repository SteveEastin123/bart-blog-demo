from __future__ import annotations

import json
import re
from collections import OrderedDict
from datetime import datetime
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
DEFAULT_SUBJECT_AREAS_PATH = ROOT / "data" / "index" / "ehrman_post_subject_areas.json"
DEFAULT_SEARCH_INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
DEFAULT_TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
DEFAULT_DEMO_PATH = ROOT / "ehrman_search_demo.html"


def clean_string(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def read_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def load_categories(path: Path = DEFAULT_CATEGORIES_PATH) -> list[dict[str, Any]]:
    data = read_json(path)
    categories = data.get("categories") if isinstance(data, dict) else data
    if not isinstance(categories, list):
        raise ValueError(f"{path} must contain a categories list")
    return categories


def load_subject_areas(path: Path = DEFAULT_SUBJECT_AREAS_PATH) -> list[dict[str, Any]]:
    data = read_json(path)
    subject_areas = data.get("subjectAreas") if isinstance(data, dict) else data
    if not isinstance(subject_areas, list):
        raise ValueError(f"{path} must contain a subjectAreas list")
    return subject_areas


def load_posts(path: Path = DEFAULT_SEARCH_INDEX_PATH) -> list[dict[str, Any]]:
    posts = read_json(path)
    if not isinstance(posts, list):
        raise ValueError(f"{path} must contain a list of posts")
    return posts


def load_topics(path: Path = DEFAULT_TOPICS_PATH) -> list[dict[str, Any]]:
    data = read_json(path)
    topics = data.get("topics") if isinstance(data, dict) else data
    if not isinstance(topics, list):
        raise ValueError(f"{path} must contain a topics list")
    return topics


def normalize_keyword(value: Any) -> str:
    text = clean_string(value).lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text).strip()
    return re.sub(r"\s+", " ", text)


def unique_strings(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    seen: set[str] = set()
    unique: list[str] = []
    for raw_value in values:
        value = clean_string(raw_value)
        if not value:
            continue
        key = value.casefold()
        if key in seen:
            continue
        seen.add(key)
        unique.append(value)
    return unique


def keyword_pairs(values: Any) -> list[list[str]]:
    pairs: list[list[str]] = []
    seen: set[str] = set()
    for value in unique_strings(values):
        normalized = normalize_keyword(value)
        if not normalized or normalized in seen:
            continue
        seen.add(normalized)
        pairs.append([value, normalized])
    return pairs


def parse_date_value(date_text: Any) -> datetime | None:
    text = clean_string(date_text)
    if not text:
        return None
    try:
        return datetime.strptime(text, "%B %d, %Y")
    except ValueError:
        pass
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return None


def date_sort_value(date_text: Any) -> str:
    parsed = parse_date_value(date_text)
    return parsed.strftime("%Y-%m-%d") if parsed else ""


def display_date_text(date_text: Any) -> str:
    text = clean_string(date_text)
    if "T" not in text:
        return text
    parsed = parse_date_value(text)
    if not parsed:
        return text
    return f"{parsed.strftime('%B')} {parsed.day}, {parsed.year}"


def build_posts_by_topic(
    posts: list[dict[str, Any]],
    allowed_topics: set[str] | None = None,
) -> OrderedDict[str, list[dict[str, str]]]:
    posts_by_topic: OrderedDict[str, list[dict[str, str]]] = OrderedDict()
    seen_by_topic: dict[str, set[str]] = {}

    for post in posts:
        title = clean_string(post.get("title", ""))
        url = clean_string(post.get("url", ""))
        if not title or not url:
            continue

        article = {"title": title, "url": url}
        for topic in unique_strings(post.get("topics", [])):
            if allowed_topics is not None and topic not in allowed_topics:
                continue
            posts_by_topic.setdefault(topic, [])
            seen_by_topic.setdefault(topic, set())
            if url in seen_by_topic[topic]:
                continue
            seen_by_topic[topic].add(url)
            posts_by_topic[topic].append(article)

    return posts_by_topic


def build_demo_data(
    categories: list[dict[str, Any]],
    topics: list[dict[str, Any]],
    posts: list[dict[str, Any]],
    subject_areas: list[dict[str, Any]] | None = None,
) -> OrderedDict[str, Any]:
    topics_by_category: dict[str, list[str]] = {}
    topic_descriptions: OrderedDict[str, str] = OrderedDict()
    for topic in topics:
        if topic.get("displayInBrowser", True) is False:
            continue
        name = clean_string(topic.get("name", ""))
        if not name:
            continue
        topic_descriptions[name] = clean_string(topic.get("description", ""))
        for category_name in unique_strings(topic.get("categories", [])):
            topics_by_category.setdefault(category_name, []).append(name)

    demo_categories: list[OrderedDict[str, Any]] = []
    for category in categories:
        name = clean_string(category.get("name", ""))
        topic_order = {
            topic_name: index
            for index, topic_name in enumerate(unique_strings(category.get("topicOrder", [])))
        }
        demo_category: OrderedDict[str, Any] = OrderedDict()
        demo_category["name"] = name
        demo_category["topics"] = sorted(
            unique_strings(topics_by_category.get(name, [])),
            key=lambda topic_name: (
                0 if topic_name in topic_order else 1,
                topic_order.get(topic_name, 0),
                topic_name.casefold(),
            ),
        )
        demo_category["description"] = clean_string(category.get("description", ""))
        demo_categories.append(demo_category)

    posts_by_topic = build_posts_by_topic(posts, set(topic_descriptions))
    for category in demo_categories:
        for topic in category["topics"]:
            posts_by_topic.setdefault(topic, [])

    demo_subject_areas: list[OrderedDict[str, Any]] = []
    for subject_area in subject_areas or []:
        name = clean_string(subject_area.get("name", ""))
        if not name:
            continue
        demo_subject_area: OrderedDict[str, Any] = OrderedDict()
        demo_subject_area["name"] = name
        demo_subject_area["description"] = clean_string(subject_area.get("description", ""))
        demo_subject_area["categories"] = unique_strings(subject_area.get("categories", []))
        demo_subject_areas.append(demo_subject_area)

    payload: OrderedDict[str, Any] = OrderedDict()
    payload["categories"] = demo_categories
    payload["subjectAreas"] = demo_subject_areas
    payload["topicDescriptions"] = topic_descriptions
    payload["articlesByTopic"] = posts_by_topic
    return payload


def build_keyword_index(posts: list[dict[str, Any]]) -> list[list[Any]]:
    keyword_index: list[list[Any]] = []
    for post in posts:
        title = clean_string(post.get("title", ""))
        url = clean_string(post.get("url", ""))
        if not title or not url:
            continue
        date_text = display_date_text(post.get("dateText", ""))
        date_sort = clean_string(post.get("dateSort", "")) or date_sort_value(post.get("dateText", ""))
        author = clean_string(post.get("author", ""))
        description = clean_string(post.get("description", ""))
        keyword_index.append([
            title,
            url,
            date_text,
            date_sort,
            author,
            keyword_pairs(post.get("topics", [])),
            keyword_pairs(post.get("secondaryKeywords", [])),
            description,
        ])
    return keyword_index


def build_keyword_suggestions(keyword_index: list[list[Any]]) -> list[list[Any]]:
    suggestions: dict[str, dict[str, Any]] = {}

    for article_index, row in enumerate(keyword_index):
        seen_in_article: set[str] = set()
        for is_topic, terms in ((True, row[5]), (False, row[6])):
            for label, normalized in terms:
                if not normalized:
                    continue
                suggestion = suggestions.setdefault(
                    normalized,
                    {"label": label, "articleIndexes": set(), "isTopic": False},
                )
                if is_topic:
                    suggestion["label"] = label
                    suggestion["isTopic"] = True
                if normalized in seen_in_article:
                    continue
                seen_in_article.add(normalized)
                suggestion["articleIndexes"].add(article_index)

    return [
        [
            suggestion["label"],
            len(suggestion["articleIndexes"]),
            normalized,
            suggestion["isTopic"],
        ]
        for normalized, suggestion in sorted(
            suggestions.items(),
            key=lambda item: (str(item[1]["label"]).casefold(), item[0]),
        )
    ]


def build_demo_payloads(
    categories: list[dict[str, Any]],
    topics: list[dict[str, Any]],
    posts: list[dict[str, Any]],
    subject_areas: list[dict[str, Any]] | None = None,
) -> tuple[OrderedDict[str, Any], list[list[Any]], list[list[Any]]]:
    demo_data = build_demo_data(categories, topics, posts, subject_areas)
    keyword_index = build_keyword_index(posts)
    keyword_suggestions = build_keyword_suggestions(keyword_index)
    return demo_data, keyword_index, keyword_suggestions


def dumps_pretty(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=6)


def dumps_compact(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
