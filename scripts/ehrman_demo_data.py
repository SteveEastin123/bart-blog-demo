from __future__ import annotations

import json
import re
from collections import OrderedDict
from datetime import datetime
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
DEFAULT_CATEGORY_GROUPS_PATH = ROOT / "data" / "index" / "ehrman_post_category_groups.json"
DEFAULT_SEARCH_INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
DEFAULT_THEMES_PATH = ROOT / "data" / "index" / "ehrman_post_themes.json"
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


def load_category_groups(path: Path = DEFAULT_CATEGORY_GROUPS_PATH) -> list[dict[str, Any]]:
    data = read_json(path)
    category_groups = data.get("categoryGroups") if isinstance(data, dict) else data
    if not isinstance(category_groups, list):
        raise ValueError(f"{path} must contain a categoryGroups list")
    return category_groups


def load_posts(path: Path = DEFAULT_SEARCH_INDEX_PATH) -> list[dict[str, Any]]:
    posts = read_json(path)
    if not isinstance(posts, list):
        raise ValueError(f"{path} must contain a list of posts")
    return posts


def load_themes(path: Path = DEFAULT_THEMES_PATH) -> list[dict[str, Any]]:
    data = read_json(path)
    themes = data.get("themes") if isinstance(data, dict) else data
    if not isinstance(themes, list):
        raise ValueError(f"{path} must contain a themes list")
    return themes


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


def build_articles_by_theme(posts: list[dict[str, Any]]) -> OrderedDict[str, list[dict[str, str]]]:
    articles_by_theme: OrderedDict[str, list[dict[str, str]]] = OrderedDict()
    seen_by_theme: dict[str, set[str]] = {}

    for post in posts:
        title = clean_string(post.get("title", ""))
        url = clean_string(post.get("url", ""))
        if not title or not url:
            continue

        article = {"title": title, "url": url}
        for theme in unique_strings(post.get("themes", [])):
            articles_by_theme.setdefault(theme, [])
            seen_by_theme.setdefault(theme, set())
            if url in seen_by_theme[theme]:
                continue
            seen_by_theme[theme].add(url)
            articles_by_theme[theme].append(article)

    return articles_by_theme


def build_demo_data(
    categories: list[dict[str, Any]],
    themes: list[dict[str, Any]],
    posts: list[dict[str, Any]],
    category_groups: list[dict[str, Any]] | None = None,
) -> OrderedDict[str, Any]:
    themes_by_category: dict[str, list[str]] = {}
    theme_descriptions: OrderedDict[str, str] = OrderedDict()
    for theme in themes:
        if theme.get("displayInBrowser", True) is False:
            continue
        name = clean_string(theme.get("name", ""))
        if not name:
            continue
        theme_descriptions[name] = clean_string(theme.get("description", ""))
        for category_name in unique_strings(theme.get("categories", [])):
            themes_by_category.setdefault(category_name, []).append(name)

    demo_categories: list[OrderedDict[str, Any]] = []
    for category in categories:
        name = clean_string(category.get("name", ""))
        demo_category: OrderedDict[str, Any] = OrderedDict()
        demo_category["name"] = name
        demo_category["themes"] = sorted(
            unique_strings(themes_by_category.get(name, [])),
            key=str.casefold,
        )
        demo_category["description"] = clean_string(category.get("description", ""))
        demo_categories.append(demo_category)

    articles_by_theme = build_articles_by_theme(posts)
    for category in demo_categories:
        for theme in category["themes"]:
            articles_by_theme.setdefault(theme, [])

    demo_category_groups: list[OrderedDict[str, Any]] = []
    for category_group in category_groups or []:
        name = clean_string(category_group.get("name", ""))
        if not name:
            continue
        demo_category_group: OrderedDict[str, Any] = OrderedDict()
        demo_category_group["name"] = name
        demo_category_group["description"] = clean_string(category_group.get("description", ""))
        demo_category_group["categories"] = unique_strings(category_group.get("categories", []))
        demo_category_groups.append(demo_category_group)

    payload: OrderedDict[str, Any] = OrderedDict()
    payload["categories"] = demo_categories
    payload["categoryGroups"] = demo_category_groups
    payload["themeDescriptions"] = theme_descriptions
    payload["articlesByTheme"] = articles_by_theme
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
            keyword_pairs(post.get("themes", [])),
            keyword_pairs(post.get("secondaryKeywords", [])),
            description,
        ])
    return keyword_index


def build_keyword_suggestions(keyword_index: list[list[Any]]) -> list[list[Any]]:
    suggestions: dict[str, dict[str, Any]] = {}

    for article_index, row in enumerate(keyword_index):
        seen_in_article: set[str] = set()
        for label, normalized in row[5] + row[6]:
            if not normalized or normalized in seen_in_article:
                continue
            seen_in_article.add(normalized)
            suggestion = suggestions.setdefault(
                normalized,
                {"label": label, "articleIndexes": set()},
            )
            suggestion["articleIndexes"].add(article_index)

    return [
        [suggestion["label"], len(suggestion["articleIndexes"]), normalized]
        for normalized, suggestion in sorted(
            suggestions.items(),
            key=lambda item: (str(item[1]["label"]).casefold(), item[0]),
        )
    ]


def build_demo_payloads(
    categories: list[dict[str, Any]],
    themes: list[dict[str, Any]],
    posts: list[dict[str, Any]],
    category_groups: list[dict[str, Any]] | None = None,
) -> tuple[OrderedDict[str, Any], list[list[Any]], list[list[Any]]]:
    demo_data = build_demo_data(categories, themes, posts, category_groups)
    keyword_index = build_keyword_index(posts)
    keyword_suggestions = build_keyword_suggestions(keyword_index)
    return demo_data, keyword_index, keyword_suggestions


def dumps_pretty(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=6)


def dumps_compact(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
