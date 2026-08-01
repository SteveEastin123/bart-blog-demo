from __future__ import annotations

import argparse
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SEARCH_INDEX = ROOT / "data" / "index" / "ehrman_post_search_index.json"
DEFAULT_TOPICS = ROOT / "data" / "index" / "ehrman_post_topics.json"
DEFAULT_CATEGORIES = ROOT / "data" / "index" / "ehrman_post_categories.json"
DEFAULT_SUBJECT_AREAS = ROOT / "data" / "index" / "ehrman_post_subject_areas.json"
DEFAULT_SUBJECT_AREAS_2 = ROOT / "data" / "index" / "ehrman_post_subject_areas_2.json"
DEFAULT_DESKTOP_OUTPUT = ROOT / "webapp" / "static" / "ehrman-search-methods.svg"
DEFAULT_MOBILE_OUTPUT = ROOT / "webapp" / "static" / "ehrman-search-methods-mobile.svg"


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def count_items(value, key: str) -> int:
    if isinstance(value, list):
        return len(value)
    return len(value.get(key, []))


def collect_counts(args: argparse.Namespace) -> dict[str, int]:
    posts = load_json(args.search_index)
    topics_data = load_json(args.topics)
    topics = topics_data.get("topics", topics_data)
    visible_topics = {
        str(topic.get("name", "")).strip()
        for topic in topics
        if topic.get("displayInBrowser", True) is not False
        and str(topic.get("name", "")).strip()
        and str(topic.get("name", "")).strip() != "Ignore"
    }
    secondary_keywords = {
        str(keyword).strip()
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
        if str(keyword).strip()
    }
    return {
        "posts": len(posts),
        "topics": len(visible_topics),
        "categories": count_items(load_json(args.categories), "categories"),
        "subject_areas": count_items(load_json(args.subject_areas), "subjectAreas"),
        "subject_areas_2": count_items(load_json(args.subject_areas_2), "subjectAreas"),
        "secondary_keywords": len(secondary_keywords),
    }


def fmt(value: int) -> str:
    return f"{value:,}"


def svg_document(width: int, height: int, body: str) -> str:
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-labelledby="title description">
  <title id="title">Ways to find posts on the Bart Ehrman Blog</title>
  <desc id="description">A symmetrical comparison of Keyword Search and Browse Topics. Keyword Search combines topics and secondary keywords into search terms and results. Browse Topics moves from subject areas to categories and topics. Both paths end with posts.</desc>
  <defs>
    <marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth">
      <path d="M0,0 L8,4 L0,8 z" fill="#7d201a"/>
    </marker>
    <style>
      .page {{ fill: #fbf8ef; }}
      .panel {{ fill: #fffdf8; stroke: #d7c9a5; stroke-width: 3; }}
      .box {{ stroke-width: 3; }}
      .process {{ fill: #f7f3e8; stroke: #b4a27a; }}
      .topic {{ fill: #e8f3fa; stroke: #2c7fa9; }}
      .keyword {{ fill: #f1ecf5; stroke: #80678e; }}
      .subject {{ fill: #fae7ad; stroke: #b98720; }}
      .category {{ fill: #fff3c9; stroke: #c09330; }}
      .post {{ fill: #e8f3e5; stroke: #4f944f; }}
      .heading {{ font: 700 48px Arial, Helvetica, sans-serif; fill: #111; }}
      .subheading {{ font: 400 25px Arial, Helvetica, sans-serif; fill: #333; }}
      .label {{ font: 700 30px Arial, Helvetica, sans-serif; fill: #111; }}
      .label-small {{ font: 700 25px Arial, Helvetica, sans-serif; fill: #111; }}
      .count {{ font: 400 23px Arial, Helvetica, sans-serif; fill: #424242; }}
      .note {{ font: 600 22px Arial, Helvetica, sans-serif; fill: #7d201a; }}
      .definition {{ font: 400 23px Arial, Helvetica, sans-serif; fill: #333; }}
      .definition-title {{ font-weight: 700; fill: #111; }}
      .arrow {{ fill: none; stroke: #7d201a; stroke-width: 3; marker-end: url(#arrow); }}
    </style>
  </defs>
  <rect class="page" width="100%" height="100%"/>
{body}
</svg>
'''


def desktop_svg(counts: dict[str, int]) -> str:
    posts = fmt(counts["posts"])
    topics = fmt(counts["topics"])
    keywords = fmt(counts["secondary_keywords"])
    categories = fmt(counts["categories"])
    areas_1 = fmt(counts["subject_areas"])
    areas_2 = fmt(counts["subject_areas_2"])
    body = f'''
  <rect class="panel" x="55" y="55" width="810" height="890" rx="30"/>
  <rect class="panel" x="935" y="55" width="810" height="890" rx="30"/>

  <text class="heading" x="105" y="135">Keyword Search</text>
  <text class="subheading" x="105" y="185">Combine up to four topics or secondary keywords</text>
  <text class="subheading" x="105" y="218">to find posts.</text>

  <text class="heading" x="985" y="135">Browse Topics</text>
  <text class="subheading" x="985" y="185">Explore posts through one of two subject-area</text>
  <text class="subheading" x="985" y="218">structures.</text>

  <rect class="box topic" x="145" y="270" width="315" height="135" rx="20"/>
  <rect class="box keyword" x="460" y="270" width="315" height="135" rx="20"/>
  <text class="label" x="302" y="322" text-anchor="middle">Topics</text>
  <text class="count" x="302" y="362" text-anchor="middle">({topics} topics)</text>
  <text class="label-small" x="617" y="314" text-anchor="middle">Secondary Keywords</text>
  <text class="count" x="617" y="362" text-anchor="middle">({keywords} keywords)</text>

  <rect class="box subject" x="1025" y="270" width="315" height="135" rx="20"/>
  <rect class="box subject" x="1340" y="270" width="315" height="135" rx="20"/>
  <text class="label-small" x="1182" y="322" text-anchor="middle">Browse Topics 1</text>
  <text class="count" x="1182" y="362" text-anchor="middle">({areas_1} subject areas)</text>
  <text class="label-small" x="1497" y="322" text-anchor="middle">Browse Topics 2</text>
  <text class="count" x="1497" y="362" text-anchor="middle">({areas_2} subject areas)</text>

  <path class="arrow" d="M460 405 L460 475"/>
  <path class="arrow" d="M1340 405 L1340 475"/>

  <rect class="box process" x="145" y="475" width="630" height="110" rx="20"/>
  <text class="label" x="460" y="520" text-anchor="middle">Up to Four Search Terms</text>
  <text class="count" x="460" y="558" text-anchor="middle">Topics, secondary keywords, or both</text>

  <rect class="box category" x="1025" y="475" width="630" height="110" rx="20"/>
  <text class="label" x="1340" y="520" text-anchor="middle">Categories</text>
  <text class="count" x="1340" y="558" text-anchor="middle">({categories} categories)</text>

  <path class="arrow" d="M460 585 L460 655"/>
  <path class="arrow" d="M1340 585 L1340 655"/>

  <rect class="box process" x="145" y="655" width="630" height="110" rx="20"/>
  <text class="label" x="460" y="700" text-anchor="middle">Search Results</text>
  <text class="count" x="460" y="738" text-anchor="middle">Ranked, newest first, or oldest first</text>

  <rect class="box topic" x="1025" y="655" width="630" height="110" rx="20"/>
  <text class="label" x="1340" y="700" text-anchor="middle">Topics</text>
  <text class="count" x="1340" y="738" text-anchor="middle">({topics} topics)</text>

  <path class="arrow" d="M460 765 L460 835"/>
  <path class="arrow" d="M1340 765 L1340 835"/>

  <rect class="box post" x="145" y="835" width="630" height="80" rx="20"/>
  <rect class="box post" x="1025" y="835" width="630" height="80" rx="20"/>
  <text class="label" x="460" y="887" text-anchor="middle">Posts ({posts})</text>
  <text class="label" x="1340" y="887" text-anchor="middle">Posts ({posts})</text>

  <text class="note" x="900" y="1005" text-anchor="middle">Topic post lists and search results can be narrowed with additional search terms.</text>

  <rect class="panel" x="55" y="1045" width="1690" height="255" rx="24"/>
  <text class="definition" x="100" y="1105"><tspan class="definition-title">Subject Areas:</tspan><tspan> Broad entry points that organize related categories.</tspan></text>
  <text class="definition" x="100" y="1160"><tspan class="definition-title">Categories:</tspan><tspan> Focused groupings of related topics.</tspan></text>
  <text class="definition" x="100" y="1215"><tspan class="definition-title">Topics:</tspan><tspan> Major subjects covered in posts and used to group related posts.</tspan></text>
  <text class="definition" x="100" y="1270"><tspan class="definition-title">Secondary Keywords:</tspan><tspan> Significant people, texts, places, or supporting ideas discussed in posts.</tspan></text>
'''
    return svg_document(1800, 1360, body)


def mobile_svg(counts: dict[str, int]) -> str:
    posts = fmt(counts["posts"])
    topics = fmt(counts["topics"])
    keywords = fmt(counts["secondary_keywords"])
    categories = fmt(counts["categories"])
    areas_1 = fmt(counts["subject_areas"])
    areas_2 = fmt(counts["subject_areas_2"])
    body = f'''
  <style>
    .heading {{ font-size: 30px; }}
    .subheading {{ font-size: 17px; }}
    .label {{ font-size: 21px; }}
    .label-small {{ font-size: 17px; }}
    .count {{ font-size: 16px; }}
    .note {{ font-size: 15px; }}
    .definition {{ font-size: 15px; }}
  </style>
  <rect class="panel" x="15" y="15" width="350" height="700" rx="18"/>
  <text class="heading" x="35" y="60">Keyword Search</text>
  <text class="subheading" x="35" y="95">Combine up to four topics or secondary</text>
  <text class="subheading" x="35" y="118">keywords to find posts.</text>
  <rect class="box topic" x="35" y="155" width="155" height="100" rx="12"/>
  <rect class="box keyword" x="190" y="155" width="155" height="100" rx="12"/>
  <text class="label" x="112" y="197" text-anchor="middle">Topics</text>
  <text class="count" x="112" y="228" text-anchor="middle">({topics})</text>
  <text class="label-small" x="267" y="190" text-anchor="middle">Secondary</text>
  <text class="label-small" x="267" y="212" text-anchor="middle">Keywords</text>
  <text class="count" x="267" y="238" text-anchor="middle">({keywords})</text>
  <path class="arrow" d="M190 255 L190 305"/>
  <rect class="box process" x="45" y="305" width="290" height="75" rx="12"/>
  <text class="label-small" x="190" y="336" text-anchor="middle">Up to Four Search Terms</text>
  <text class="count" x="190" y="363" text-anchor="middle">Topics, keywords, or both</text>
  <path class="arrow" d="M190 380 L190 430"/>
  <rect class="box process" x="45" y="430" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="461" text-anchor="middle">Search Results</text>
  <text class="count" x="190" y="489" text-anchor="middle">Choose a sort order</text>
  <path class="arrow" d="M190 505 L190 555"/>
  <rect class="box post" x="45" y="555" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="602" text-anchor="middle">Posts ({posts})</text>

  <rect class="panel" x="15" y="745" width="350" height="700" rx="18"/>
  <text class="heading" x="35" y="790">Browse Topics</text>
  <text class="subheading" x="35" y="825">Explore posts through one of two</text>
  <text class="subheading" x="35" y="848">subject-area structures.</text>
  <rect class="box subject" x="35" y="885" width="155" height="100" rx="12"/>
  <rect class="box subject" x="190" y="885" width="155" height="100" rx="12"/>
  <text class="label-small" x="112" y="925" text-anchor="middle">Browse Topics 1</text>
  <text class="count" x="112" y="958" text-anchor="middle">({areas_1} subject areas)</text>
  <text class="label-small" x="267" y="925" text-anchor="middle">Browse Topics 2</text>
  <text class="count" x="267" y="958" text-anchor="middle">({areas_2} subject areas)</text>
  <path class="arrow" d="M190 985 L190 1035"/>
  <rect class="box category" x="45" y="1035" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1066" text-anchor="middle">Categories</text>
  <text class="count" x="190" y="1094" text-anchor="middle">({categories} categories)</text>
  <path class="arrow" d="M190 1110 L190 1160"/>
  <rect class="box topic" x="45" y="1160" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1191" text-anchor="middle">Topics</text>
  <text class="count" x="190" y="1219" text-anchor="middle">({topics} topics)</text>
  <path class="arrow" d="M190 1235 L190 1285"/>
  <rect class="box post" x="45" y="1285" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1332" text-anchor="middle">Posts ({posts})</text>

  <text class="note" x="190" y="1500" text-anchor="middle">Post lists and search results can be</text>
  <text class="note" x="190" y="1522" text-anchor="middle">narrowed with additional search terms.</text>

  <rect class="panel" x="15" y="1570" width="350" height="400" rx="16"/>
  <text class="definition" x="35" y="1615"><tspan class="definition-title">Subject Areas:</tspan></text>
  <text class="definition" x="35" y="1638">Broad entry points that organize</text>
  <text class="definition" x="35" y="1660">related categories.</text>
  <text class="definition" x="35" y="1715"><tspan class="definition-title">Categories:</tspan></text>
  <text class="definition" x="35" y="1738">Focused groupings of related topics.</text>
  <text class="definition" x="35" y="1793"><tspan class="definition-title">Topics:</tspan></text>
  <text class="definition" x="35" y="1816">Major subjects covered in posts and used</text>
  <text class="definition" x="35" y="1838">to group related posts.</text>
  <text class="definition" x="35" y="1893"><tspan class="definition-title">Secondary Keywords:</tspan></text>
  <text class="definition" x="35" y="1916">Significant people, texts, places, or</text>
  <text class="definition" x="35" y="1938">supporting ideas discussed in posts.</text>
'''
    return svg_document(380, 2000, body)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build responsive search-method diagrams from current JSON counts.")
    parser.add_argument("--search-index", type=Path, default=DEFAULT_SEARCH_INDEX)
    parser.add_argument("--topics", type=Path, default=DEFAULT_TOPICS)
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES)
    parser.add_argument("--subject-areas", type=Path, default=DEFAULT_SUBJECT_AREAS)
    parser.add_argument("--subject-areas-2", type=Path, default=DEFAULT_SUBJECT_AREAS_2)
    parser.add_argument("--desktop-output", type=Path, default=DEFAULT_DESKTOP_OUTPUT)
    parser.add_argument("--mobile-output", type=Path, default=DEFAULT_MOBILE_OUTPUT)
    return parser.parse_args()


def build_search_methods_diagrams(args: argparse.Namespace) -> dict[str, int]:
    counts = collect_counts(args)
    args.desktop_output.parent.mkdir(parents=True, exist_ok=True)
    args.mobile_output.parent.mkdir(parents=True, exist_ok=True)
    args.desktop_output.write_text(desktop_svg(counts), encoding="utf-8", newline="\n")
    args.mobile_output.write_text(mobile_svg(counts), encoding="utf-8", newline="\n")
    return counts


def build_search_methods_diagrams_from_paths(
    *,
    search_index: Path = DEFAULT_SEARCH_INDEX,
    topics: Path = DEFAULT_TOPICS,
    categories: Path = DEFAULT_CATEGORIES,
    subject_areas: Path = DEFAULT_SUBJECT_AREAS,
    subject_areas_2: Path = DEFAULT_SUBJECT_AREAS_2,
    desktop_output: Path = DEFAULT_DESKTOP_OUTPUT,
    mobile_output: Path = DEFAULT_MOBILE_OUTPUT,
) -> dict[str, int]:
    return build_search_methods_diagrams(
        argparse.Namespace(
            search_index=search_index,
            topics=topics,
            categories=categories,
            subject_areas=subject_areas,
            subject_areas_2=subject_areas_2,
            desktop_output=desktop_output,
            mobile_output=mobile_output,
        )
    )


def main() -> int:
    args = parse_args()
    counts = build_search_methods_diagrams(args)
    print(f"Built {args.desktop_output}")
    print(f"Built {args.mobile_output}")
    print(
        f"Counts: {counts['posts']} posts, {counts['topics']} topics, "
        f"{counts['secondary_keywords']} secondary keywords, {counts['categories']} categories, "
        f"{counts['subject_areas']} and {counts['subject_areas_2']} subject areas."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
