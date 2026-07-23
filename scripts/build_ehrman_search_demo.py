from __future__ import annotations

import argparse
import base64
import mimetypes
from pathlib import Path

from ehrman_demo_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_SUBJECT_AREAS_PATH,
    DEFAULT_DEMO_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_TOPICS_PATH,
    build_demo_payloads,
    dumps_compact,
    dumps_pretty,
    load_categories,
    load_subject_areas,
    load_posts,
    load_topics,
)


DATA_START = "const DATA ="
KEYWORD_INDEX_START = "    const KEYWORD_INDEX ="
KEYWORD_SUGGESTIONS_START = "    const KEYWORD_SUGGESTIONS ="
MAX_SUGGESTIONS_START = "    const MAX_KEYWORD_SUGGESTIONS"
SEARCH_METHODS_IMAGE_START = "<!-- SEARCH_METHODS_IMAGE_START -->"
SEARCH_METHODS_IMAGE_END = "<!-- SEARCH_METHODS_IMAGE_END -->"
DEFAULT_SEARCH_METHODS_IMAGE = Path(__file__).resolve().parents[1] / "webapp" / "static" / "ehrman-search-methods.png"


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
    subject_areas_path: Path,
    topics_path: Path,
    search_index_path: Path,
    search_methods_image_path: Path,
) -> tuple[str, dict[str, int]]:
    categories = load_categories(categories_path)
    subject_areas = load_subject_areas(subject_areas_path)
    topics = load_topics(topics_path)
    posts = load_posts(search_index_path)
    demo_data, keyword_index, keyword_suggestions = build_demo_payloads(categories, topics, posts, subject_areas)

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
    while SEARCH_METHODS_IMAGE_END + SEARCH_METHODS_IMAGE_END in html:
        html = html.replace(SEARCH_METHODS_IMAGE_END + SEARCH_METHODS_IMAGE_END, SEARCH_METHODS_IMAGE_END)
    image_mime = mimetypes.guess_type(search_methods_image_path.name)[0] or "image/png"
    image_data = base64.b64encode(search_methods_image_path.read_bytes()).decode("ascii")
    image_markup = (
        f'{SEARCH_METHODS_IMAGE_START}'
        f'<img class="search-methods-image" src="data:{image_mime};base64,{image_data}" '
        'alt="Diagram comparing Browse by Topic with Keyword Search">'
    )
    html = replace_block(html, SEARCH_METHODS_IMAGE_START, SEARCH_METHODS_IMAGE_END, image_markup)

    linked_topics = {
        topic.get("name", "").strip()
        for topic in topics
        if topic.get("displayInBrowser", True) is not False and isinstance(topic.get("name"), str) and topic.get("name").strip()
    }
    stats = {
        "posts": len(posts),
        "categories": len(categories),
        "subject_areas": len(subject_areas),
        "linked_topics": len(linked_topics),
        "keyword_suggestions": len(keyword_suggestions),
    }
    return html, stats


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Rebuild the self-contained Ehrman search demo HTML from category, topic, and search-index JSON."
    )
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES_PATH)
    parser.add_argument("--subject-areas", type=Path, default=DEFAULT_SUBJECT_AREAS_PATH)
    parser.add_argument("--topics", type=Path, default=DEFAULT_TOPICS_PATH)
    parser.add_argument("--search-index", "--keywords", dest="search_index", type=Path, default=DEFAULT_SEARCH_INDEX_PATH)
    parser.add_argument("--search-methods-image", type=Path, default=DEFAULT_SEARCH_METHODS_IMAGE)
    parser.add_argument("--template", type=Path, default=DEFAULT_DEMO_PATH)
    parser.add_argument("--output", type=Path, default=DEFAULT_DEMO_PATH)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    template_html = args.template.read_text(encoding="utf-8")
    output_html, stats = build_demo_html(
        template_html,
        args.categories,
        args.subject_areas,
        args.topics,
        args.search_index,
        args.search_methods_image,
    )
    args.output.write_text(output_html, encoding="utf-8", newline="\n")
    size_bytes = args.output.stat().st_size
    print(f"Built {args.output}")
    print(
        "Embedded "
        f"{stats['posts']} posts, "
        f"{stats['subject_areas']} subject areas, "
        f"{stats['categories']} categories, "
        f"{stats['linked_topics']} linked topics, "
        f"{stats['keyword_suggestions']} keyword suggestions."
    )
    print(f"HTML size: {size_bytes:,} bytes")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
