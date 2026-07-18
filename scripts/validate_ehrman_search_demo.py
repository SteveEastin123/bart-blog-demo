from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

from ehrman_demo_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_CATEGORY_GROUPS_PATH,
    DEFAULT_DEMO_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_TOPICS_PATH,
    build_demo_payloads,
    clean_string,
    date_sort_value,
    load_categories,
    load_category_groups,
    load_posts,
    load_topics,
    normalize_keyword,
    unique_strings,
)


DATA_START = "const DATA ="
KEYWORD_INDEX_START = "    const KEYWORD_INDEX ="
KEYWORD_SUGGESTIONS_START = "    const KEYWORD_SUGGESTIONS"
MAX_SUGGESTIONS_START = "    const MAX_KEYWORD_SUGGESTIONS"


def extract_js_json_constant(html: str, start_marker: str, end_marker: str) -> Any:
    start = html.find(start_marker)
    if start < 0:
        raise ValueError(f"Could not find start marker: {start_marker}")
    equals = html.find("=", start)
    if equals < 0:
        raise ValueError(f"Could not find '=' after marker: {start_marker}")
    end = html.find(end_marker, equals)
    if end < 0:
        raise ValueError(f"Could not find end marker after {start_marker}: {end_marker}")
    raw_value = html[equals + 1:end].strip()
    if raw_value.endswith(";"):
        raw_value = raw_value[:-1].strip()
    return json.loads(raw_value)


def require_string(record: dict[str, Any], field: str, label: str, errors: list[str]) -> str:
    value = clean_string(record.get(field, ""))
    if not value:
        errors.append(f"{label} is missing required field: {field}")
    return value


def has_case_duplicates(values: list[str]) -> bool:
    return len({value.casefold() for value in values}) != len(values)


def sorted_casefold(values: list[str]) -> list[str]:
    return sorted(values, key=lambda value: value.casefold())


def validate_categories(
    categories: list[dict[str, Any]],
    errors: list[str],
    warnings: list[str],
) -> set[str]:
    category_names: list[str] = []

    for index, category in enumerate(categories, start=1):
        label = f"category #{index}"
        name = require_string(category, "name", label, errors)
        if name:
            category_names.append(name)
            label = f"category {name!r}"

        description = str(category.get("description", "")).strip()
        if not description:
            errors.append(f"{label} is missing a description")

        if "topics" in category:
            errors.append(f"{label} should not contain a topics field; use ehrman_post_topics.json")

    if has_case_duplicates(category_names):
        errors.append("Category names include duplicates that differ only by case")
    if category_names != sorted_casefold(category_names):
        warnings.append("Category names are not alphabetical")

    return set(category_names)


def validate_category_groups(
    category_groups: list[dict[str, Any]],
    category_names: set[str],
    errors: list[str],
    warnings: list[str],
) -> set[str]:
    group_names: list[str] = []
    linked_categories: list[str] = []

    for index, category_group in enumerate(category_groups, start=1):
        label = f"category group #{index}"
        name = require_string(category_group, "name", label, errors)
        if name:
            group_names.append(name)
            label = f"category group {name!r}"

        description = clean_string(category_group.get("description", ""))
        if not description:
            errors.append(f"{label} is missing a description")
        elif "\n" in description or "\r" in description:
            errors.append(f"{label} description contains a line break")
        elif not description.endswith("."):
            warnings.append(f"{label} description does not end with a period")

        categories = unique_strings(category_group.get("categories", []))
        if not isinstance(category_group.get("categories", []), list):
            errors.append(f"{label} has a non-list categories field")
        if not categories:
            errors.append(f"{label} has no linked categories")
        for category_name in categories:
            if category_name not in category_names:
                errors.append(f"{label} links unknown category {category_name!r}")
            linked_categories.append(category_name)

    if has_case_duplicates(group_names):
        errors.append("Category group names include duplicates that differ only by case")

    normalized_counts: dict[str, int] = {}
    category_name_by_key = {category.casefold(): category for category in category_names}
    for category_name in linked_categories:
        key = category_name.casefold()
        normalized_counts[key] = normalized_counts.get(key, 0) + 1

    duplicated_categories = sorted(
        category_name_by_key.get(key, key)
        for key, count in normalized_counts.items()
        if count > 1
    )
    if duplicated_categories:
        errors.append("Categories linked to more than one category group: " + ", ".join(duplicated_categories))

    missing_categories = sorted(
        category
        for category in category_names
        if normalized_counts.get(category.casefold(), 0) == 0
    )
    if missing_categories:
        errors.append("Categories missing from category groups: " + ", ".join(missing_categories))

    return {name for name in group_names if name}


def validate_topics(
    topics: list[dict[str, Any]],
    category_names: set[str],
    post_topic_counts: dict[str, int],
    all_post_topics: set[str],
    errors: list[str],
    warnings: list[str],
) -> set[str]:
    topic_names: list[str] = []
    linked_topics: set[str] = set()

    for index, topic in enumerate(topics, start=1):
        label = f"topic #{index}"
        name = require_string(topic, "name", label, errors)
        if name:
            topic_names.append(name)
            label = f"topic {name!r}"

        description = clean_string(topic.get("description", ""))
        if not description:
            errors.append(f"{label} is missing a description")
        elif "\n" in description or "\r" in description:
            errors.append(f"{label} description contains a line break")
        elif not description.endswith("."):
            warnings.append(f"{label} description does not end with a period")

        categories = unique_strings(topic.get("categories", []))
        if not isinstance(topic.get("categories", []), list):
            errors.append(f"{label} has a non-list categories field")
        if name != "Ignore" and topic.get("displayInBrowser", True) is not False and not categories:
            warnings.append(f"{label} has no linked categories")
        if categories != sorted_casefold(categories):
            warnings.append(f"{label} categories are not alphabetical")
        for category_name in categories:
            if category_name not in category_names:
                errors.append(f"{label} links unknown category {category_name!r}")

        if name:
            linked_topics.add(name)
            if name != "Ignore" and post_topic_counts.get(name, 0) == 0:
                errors.append(f"{label} exists, but no post uses that topic")

    if has_case_duplicates(topic_names):
        errors.append("Topic names include duplicates that differ only by case")
    if topic_names != sorted_casefold(topic_names):
        warnings.append("Topic names are not alphabetical")

    missing_topic_metadata = sorted(all_post_topics - linked_topics, key=str.casefold)
    if missing_topic_metadata:
        errors.append("Topics used by posts but missing from ehrman_post_topics.json: " + ", ".join(missing_topic_metadata))

    return linked_topics


def validate_posts(
    posts: list[dict[str, Any]],
    errors: list[str],
    warnings: list[str],
) -> tuple[dict[str, int], set[str]]:
    urls: set[str] = set()
    titles: set[str] = set()
    post_topic_counts: dict[str, int] = {}
    all_post_topics: set[str] = set()

    for index, post in enumerate(posts, start=1):
        title = require_string(post, "title", f"post #{index}", errors)
        url = require_string(post, "url", f"post #{index}", errors)
        date_text = clean_string(post.get("dateText", ""))
        author = clean_string(post.get("author", ""))
        description = clean_string(post.get("description", ""))

        if url:
            if url in urls:
                errors.append(f"Duplicate post URL: {url}")
            urls.add(url)
        if title:
            titles.add(title)
        if not date_text:
            warnings.append(f"Post {title!r} is missing dateText")
        elif not date_sort_value(date_text):
            errors.append(f"Post {title!r} has an unparseable dateText: {date_text!r}")
        if not author:
            warnings.append(f"Post {title!r} is missing author")
        if not description:
            errors.append(f"Post {title!r} is missing description")
        elif "\n" in description or "\r" in description:
            errors.append(f"Post {title!r} description contains a line break")
        elif len(description) > 360:
            warnings.append(f"Post {title!r} has a long description ({len(description)} characters)")

        topics = unique_strings(post.get("topics", []))
        secondary_keywords = unique_strings(post.get("secondaryKeywords", []))
        if not isinstance(post.get("topics", []), list):
            errors.append(f"Post {title!r} has a non-list topics field")
        if not isinstance(post.get("secondaryKeywords", []), list):
            errors.append(f"Post {title!r} has a non-list secondaryKeywords field")
        if not topics:
            errors.append(f"Post {title!r} has no topics")
        if len(topics) != len(post.get("topics", [])):
            errors.append(f"Post {title!r} has duplicate or blank topics")
        if len(secondary_keywords) != len(post.get("secondaryKeywords", [])):
            errors.append(f"Post {title!r} has duplicate or blank secondary keywords")

        for keyword in topics + secondary_keywords:
            if not normalize_keyword(keyword):
                errors.append(f"Post {title!r} has keyword that normalizes to an empty value: {keyword!r}")

        for topic in topics:
            all_post_topics.add(topic)
            post_topic_counts[topic] = post_topic_counts.get(topic, 0) + 1

    if len(titles) < len(posts):
        warnings.append("Some post titles are duplicated; URLs remain the unique post key")

    return post_topic_counts, all_post_topics


def validate_html(
    html_path: Path,
    categories: list[dict[str, Any]],
    category_groups: list[dict[str, Any]],
    topics: list[dict[str, Any]],
    posts: list[dict[str, Any]],
    errors: list[str],
) -> None:
    if not html_path.exists():
        errors.append(f"Demo HTML does not exist: {html_path}")
        return

    html = html_path.read_text(encoding="utf-8")
    try:
        embedded_data = extract_js_json_constant(
            html,
            DATA_START,
            "\n\n" + KEYWORD_INDEX_START,
        )
        embedded_keyword_index = extract_js_json_constant(
            html,
            KEYWORD_INDEX_START,
            "\n\n" + KEYWORD_SUGGESTIONS_START,
        )
        embedded_keyword_suggestions = extract_js_json_constant(
            html,
            KEYWORD_SUGGESTIONS_START,
            "\n\n" + MAX_SUGGESTIONS_START,
        )
    except (ValueError, json.JSONDecodeError) as exc:
        errors.append(f"Could not parse embedded demo data from {html_path}: {exc}")
        return

    expected_data, expected_keyword_index, expected_keyword_suggestions = build_demo_payloads(categories, topics, posts, category_groups)
    if embedded_data != expected_data:
        errors.append("Embedded DATA in the HTML is stale; run scripts/build_ehrman_search_demo.py")
    if embedded_keyword_index != expected_keyword_index:
        errors.append("Embedded KEYWORD_INDEX in the HTML is stale; run scripts/build_ehrman_search_demo.py")
    if embedded_keyword_suggestions != expected_keyword_suggestions:
        errors.append("Embedded KEYWORD_SUGGESTIONS in the HTML is stale; run scripts/build_ehrman_search_demo.py")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Validate the Ehrman search demo JSON files and generated standalone HTML."
    )
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES_PATH)
    parser.add_argument("--category-groups", type=Path, default=DEFAULT_CATEGORY_GROUPS_PATH)
    parser.add_argument("--topics", type=Path, default=DEFAULT_TOPICS_PATH)
    parser.add_argument("--search-index", "--keywords", dest="search_index", type=Path, default=DEFAULT_SEARCH_INDEX_PATH)
    parser.add_argument("--html", type=Path, default=DEFAULT_DEMO_PATH)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    errors: list[str] = []
    warnings: list[str] = []

    try:
        categories = load_categories(args.categories)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load categories JSON: {exc}")
        return 1

    try:
        category_groups = load_category_groups(args.category_groups)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load category groups JSON: {exc}")
        return 1

    try:
        posts = load_posts(args.search_index)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load search-index JSON: {exc}")
        return 1

    try:
        topics = load_topics(args.topics)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load topics JSON: {exc}")
        return 1

    post_topic_counts, all_post_topics = validate_posts(posts, errors, warnings)
    category_names = validate_categories(categories, errors, warnings)
    category_group_names = validate_category_groups(category_groups, category_names, errors, warnings)
    linked_topics = validate_topics(topics, category_names, post_topic_counts, all_post_topics, errors, warnings)
    unlinked_topics = sorted(
        topic
        for topic in all_post_topics
        if topic != "Ignore"
        and not any(
            clean_string(topic_record.get("name", "")) == topic
            and unique_strings(topic_record.get("categories", []))
            for topic_record in topics
        )
    )
    if unlinked_topics:
        warnings.append("Topics used by posts but not linked to a category: " + ", ".join(unlinked_topics))

    validate_html(args.html, categories, category_groups, topics, posts, errors)

    if errors:
        print("Validation failed:")
        for error in errors:
            print(f"- {error}")
        if warnings:
            print("\nWarnings:")
            for warning in warnings:
                print(f"- {warning}")
        return 1

    print("Validation passed.")
    print(f"Posts: {len(posts):,}")
    print(f"Category groups: {len(category_group_names):,}")
    print(f"Categories: {len(categories):,}")
    print(f"Unique post topics: {len(all_post_topics):,}")
    print(f"Topic metadata records: {len(linked_topics):,}")
    if warnings:
        print("Warnings:")
        for warning in warnings:
            print(f"- {warning}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
