from __future__ import annotations

import argparse
from pathlib import Path

from ehrman_demo_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_DEMO_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_THEMES_PATH,
    build_demo_payloads,
    dumps_compact,
    dumps_pretty,
    load_categories,
    load_posts,
    load_themes,
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
    themes_path: Path,
    search_index_path: Path,
) -> tuple[str, dict[str, int]]:
    categories = load_categories(categories_path)
    themes = load_themes(themes_path)
    posts = load_posts(search_index_path)
    demo_data, keyword_index, keyword_suggestions = build_demo_payloads(categories, themes, posts)

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

    linked_themes = {
        theme.get("name", "").strip()
        for theme in themes
        if theme.get("displayInBrowser", True) is not False and isinstance(theme.get("name"), str) and theme.get("name").strip()
    }
    stats = {
        "posts": len(posts),
        "categories": len(categories),
        "linked_themes": len(linked_themes),
        "keyword_suggestions": len(keyword_suggestions),
    }
    return html, stats


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Rebuild the self-contained Ehrman search demo HTML from category, theme, and search-index JSON."
    )
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES_PATH)
    parser.add_argument("--themes", type=Path, default=DEFAULT_THEMES_PATH)
    parser.add_argument("--search-index", "--keywords", dest="search_index", type=Path, default=DEFAULT_SEARCH_INDEX_PATH)
    parser.add_argument("--template", type=Path, default=DEFAULT_DEMO_PATH)
    parser.add_argument("--output", type=Path, default=DEFAULT_DEMO_PATH)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    template_html = args.template.read_text(encoding="utf-8")
    output_html, stats = build_demo_html(template_html, args.categories, args.themes, args.search_index)
    args.output.write_text(output_html, encoding="utf-8", newline="\n")
    size_bytes = args.output.stat().st_size
    print(f"Built {args.output}")
    print(
        "Embedded "
        f"{stats['posts']} posts, "
        f"{stats['categories']} categories, "
        f"{stats['linked_themes']} linked themes, "
        f"{stats['keyword_suggestions']} keyword suggestions."
    )
    print(f"HTML size: {size_bytes:,} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
