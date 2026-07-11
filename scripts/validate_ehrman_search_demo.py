from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

from ehrman_demo_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_DEMO_PATH,
    DEFAULT_KEYWORDS_PATH,
    DEFAULT_THEMES_PATH,
    build_demo_payloads,
    clean_string,
    date_sort_value,
    load_categories,
    load_posts,
    load_themes,
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

        if "themes" in category:
            errors.append(f"{label} should not contain a themes field; use ehrman_themes.json")

    if has_case_duplicates(category_names):
        errors.append("Category names include duplicates that differ only by case")
    if category_names != sorted_casefold(category_names):
        warnings.append("Category names are not alphabetical")

    return set(category_names)


def validate_themes(
    themes: list[dict[str, Any]],
    category_names: set[str],
    post_theme_counts: dict[str, int],
    all_post_themes: set[str],
    errors: list[str],
    warnings: list[str],
) -> set[str]:
    theme_names: list[str] = []
    linked_themes: set[str] = set()

    for index, theme in enumerate(themes, start=1):
        label = f"theme #{index}"
        name = require_string(theme, "name", label, errors)
        if name:
            theme_names.append(name)
            label = f"theme {name!r}"

        description = clean_string(theme.get("description", ""))
        if not description:
            errors.append(f"{label} is missing a description")
        elif "\n" in description or "\r" in description:
            errors.append(f"{label} description contains a line break")
        elif not description.endswith("."):
            warnings.append(f"{label} description does not end with a period")

        categories = unique_strings(theme.get("categories", []))
        if not isinstance(theme.get("categories", []), list):
            errors.append(f"{label} has a non-list categories field")
        if name != "Ignore" and theme.get("displayInBrowser", True) is not False and not categories:
            errors.append(f"{label} has no linked categories")
        if categories != sorted_casefold(categories):
            warnings.append(f"{label} categories are not alphabetical")
        for category_name in categories:
            if category_name not in category_names:
                errors.append(f"{label} links unknown category {category_name!r}")

        if name:
            linked_themes.add(name)
            if name != "Ignore" and post_theme_counts.get(name, 0) == 0:
                errors.append(f"{label} exists, but no post uses that theme")

    if has_case_duplicates(theme_names):
        errors.append("Theme names include duplicates that differ only by case")
    if theme_names != sorted_casefold(theme_names):
        warnings.append("Theme names are not alphabetical")

    missing_theme_metadata = sorted(all_post_themes - linked_themes, key=str.casefold)
    if missing_theme_metadata:
        errors.append("Themes used by posts but missing from ehrman_themes.json: " + ", ".join(missing_theme_metadata))

    return linked_themes


def validate_posts(
    posts: list[dict[str, Any]],
    errors: list[str],
    warnings: list[str],
) -> tuple[dict[str, int], set[str]]:
    urls: set[str] = set()
    titles: set[str] = set()
    post_theme_counts: dict[str, int] = {}
    all_post_themes: set[str] = set()

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

        themes = unique_strings(post.get("themes", []))
        secondary_keywords = unique_strings(post.get("secondaryKeywords", []))
        if not isinstance(post.get("themes", []), list):
            errors.append(f"Post {title!r} has a non-list themes field")
        if not isinstance(post.get("secondaryKeywords", []), list):
            errors.append(f"Post {title!r} has a non-list secondaryKeywords field")
        if not themes:
            errors.append(f"Post {title!r} has no themes")
        if len(themes) != len(post.get("themes", [])):
            errors.append(f"Post {title!r} has duplicate or blank themes")
        if len(secondary_keywords) != len(post.get("secondaryKeywords", [])):
            errors.append(f"Post {title!r} has duplicate or blank secondary keywords")

        for keyword in themes + secondary_keywords:
            if not normalize_keyword(keyword):
                errors.append(f"Post {title!r} has keyword that normalizes to an empty value: {keyword!r}")

        for theme in themes:
            all_post_themes.add(theme)
            post_theme_counts[theme] = post_theme_counts.get(theme, 0) + 1

    if len(titles) < len(posts):
        warnings.append("Some post titles are duplicated; URLs remain the unique post key")

    return post_theme_counts, all_post_themes


def validate_html(
    html_path: Path,
    categories: list[dict[str, Any]],
    themes: list[dict[str, Any]],
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

    expected_data, expected_keyword_index, expected_keyword_suggestions = build_demo_payloads(categories, themes, posts)
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
    parser.add_argument("--themes", type=Path, default=DEFAULT_THEMES_PATH)
    parser.add_argument("--keywords", type=Path, default=DEFAULT_KEYWORDS_PATH)
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
        posts = load_posts(args.keywords)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load keywords JSON: {exc}")
        return 1

    try:
        themes = load_themes(args.themes)
    except Exception as exc:  # noqa: BLE001
        print(f"Validation failed: could not load themes JSON: {exc}")
        return 1

    post_theme_counts, all_post_themes = validate_posts(posts, errors, warnings)
    category_names = validate_categories(categories, errors, warnings)
    linked_themes = validate_themes(themes, category_names, post_theme_counts, all_post_themes, errors, warnings)
    unlinked_themes = sorted(
        theme
        for theme in all_post_themes
        if theme != "Ignore"
        and not any(
            clean_string(theme_record.get("name", "")) == theme
            and unique_strings(theme_record.get("categories", []))
            for theme_record in themes
        )
    )
    if unlinked_themes:
        errors.append("Themes used by posts but not linked to a category: " + ", ".join(unlinked_themes))

    validate_html(args.html, categories, themes, posts, errors)

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
    print(f"Categories: {len(categories):,}")
    print(f"Unique post themes: {len(all_post_themes):,}")
    print(f"Theme metadata records: {len(linked_themes):,}")
    if warnings:
        print("Warnings:")
        for warning in warnings:
            print(f"- {warning}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
