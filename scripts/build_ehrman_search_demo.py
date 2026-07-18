from __future__ import annotations

import argparse
from pathlib import Path

from ehrman_demo_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_CATEGORY_GROUPS_PATH,
    DEFAULT_DEMO_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_TOPICS_PATH,
    build_demo_payloads,
    dumps_compact,
    dumps_pretty,
    load_categories,
    load_category_groups,
    load_posts,
    load_topics,
)


DATA_START = "const DATA ="
KEYWORD_INDEX_START = "    const KEYWORD_INDEX ="
KEYWORD_SUGGESTIONS_START = "    const KEYWORD_SUGGESTIONS ="
MAX_SUGGESTIONS_START = "    const MAX_KEYWORD_SUGGESTIONS"


def replace_block(html: str, start_marker: str, end_marker: str, replacement: str) -> str:
    start = html.find(start_marker)
    if start < 0:
        raise ValueError(f"Could not find start marker: {start_marker}")
    end = html.find(end_marker, start)
    if end < 0:
        raise ValueError(f"Could not find end marker after {start_marker}: {end_marker}")
    return html[:start] + replacement + html[end:]


def build_demo_html(
    template_html: str,
    categories_path: Path,
    category_groups_path: Path,
    topics_path: Path,
    search_index_path: Path,
) -> tuple[str, dict[str, int]]:
    categories = load_categories(categories_path)
    category_groups = load_category_groups(category_groups_path)
    topics = load_topics(topics_path)
    posts = load_posts(search_index_path)
    demo_data, keyword_index, keyword_suggestions = build_demo_payloads(categories, topics, posts, category_groups)

    html = replace_block(
        template_html,
        DATA_START,
        "\n\n" + KEYWORD_INDEX_START,
        f"{DATA_START} {dumps_pretty(demo_data)};\n",
    )
    html = replace_block(
        html,
        KEYWORD_INDEX_START,
        "\n\n" + KEYWORD_SUGGESTIONS_START,
        f"{KEYWORD_INDEX_START} {dumps_compact(keyword_index)};\n",
    )
    html = replace_block(
        html,
        KEYWORD_SUGGESTIONS_START,
        "\n\n" + MAX_SUGGESTIONS_START,
        f"{KEYWORD_SUGGESTIONS_START} {dumps_compact(keyword_suggestions)};\n",
    )

    linked_topics = {
        topic.get("name", "").strip()
        for topic in topics
        if topic.get("displayInBrowser", True) is not False and isinstance(topic.get("name"), str) and topic.get("name").strip()
    }
    stats = {
        "posts": len(posts),
        "categories": len(categories),
        "category_groups": len(category_groups),
        "linked_topics": len(linked_topics),
        "keyword_suggestions": len(keyword_suggestions),
    }
    return html, stats


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Rebuild the self-contained Ehrman search demo HTML from category, topic, and search-index JSON."
    )
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES_PATH)
    parser.add_argument("--category-groups", type=Path, default=DEFAULT_CATEGORY_GROUPS_PATH)
    parser.add_argument("--topics", type=Path, default=DEFAULT_TOPICS_PATH)
    parser.add_argument("--search-index", "--keywords", dest="search_index", type=Path, default=DEFAULT_SEARCH_INDEX_PATH)
    parser.add_argument("--template", type=Path, default=DEFAULT_DEMO_PATH)
    parser.add_argument("--output", type=Path, default=DEFAULT_DEMO_PATH)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    template_html = args.template.read_text(encoding="utf-8")
    output_html, stats = build_demo_html(template_html, args.categories, args.category_groups, args.topics, args.search_index)
    args.output.write_text(output_html, encoding="utf-8", newline="\n")
    size_bytes = args.output.stat().st_size
    print(f"Built {args.output}")
    print(
        "Embedded "
        f"{stats['posts']} posts, "
        f"{stats['category_groups']} category groups, "
        f"{stats['categories']} categories, "
        f"{stats['linked_topics']} linked topics, "
        f"{stats['keyword_suggestions']} keyword suggestions."
    )
    print(f"HTML size: {size_bytes:,} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
