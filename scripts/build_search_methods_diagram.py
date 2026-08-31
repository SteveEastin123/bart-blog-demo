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
    <filter id="shadow" x="-10%" y="-10%" width="120%" height="130%">
      <feDropShadow dx="0" dy="4" stdDeviation="7" flood-color="#392d20" flood-opacity="0.10"/>
    </filter>
    <marker id="arrow" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto" markerUnits="userSpaceOnUse">
      <path d="M0,0 L9,4.5 L0,9 z" fill="#7d201a"/>
    </marker>
    <style>
      .page {{ fill: #f6f3ed; }}
      .panel {{ fill: #fffefa; stroke: #d8cfbe; stroke-width: 2; filter: url(#shadow); }}
      .box {{ stroke-width: 2; }}
      .process {{ fill: #fffefa; stroke: #bcae91; }}
      .topic {{ fill: #edf6fb; stroke: #3982a5; }}
      .keyword {{ fill: #f5f0f7; stroke: #876f93; }}
      .subject {{ fill: #fff3cf; stroke: #bb8b29; }}
      .category {{ fill: #fff9e9; stroke: #c39a42; }}
      .post {{ fill: #edf7eb; stroke: #559657; }}
      .heading {{ font: 700 44px Arial, Helvetica, sans-serif; fill: #6f1d18; }}
      .subheading {{ font: 400 24px Arial, Helvetica, sans-serif; fill: #45413c; }}
      .stage {{ font: 700 15px Arial, Helvetica, sans-serif; letter-spacing: 1.5px; fill: #766c5f; }}
      .label {{ font: 700 29px Arial, Helvetica, sans-serif; fill: #171512; }}
      .label-small {{ font: 700 24px Arial, Helvetica, sans-serif; fill: #171512; }}
      .count {{ font: 400 22px Arial, Helvetica, sans-serif; fill: #57514a; }}
      .note {{ font: 600 21px Arial, Helvetica, sans-serif; fill: #7d201a; }}
      .definition-title {{ font: 700 26px Arial, Helvetica, sans-serif; fill: #6f1d18; }}
      .definition {{ font: 400 22px Arial, Helvetica, sans-serif; fill: #45413c; }}
      .arrow {{ fill: none; stroke: #7d201a; stroke-width: 3; marker-end: url(#arrow); }}
      .accent {{ stroke: #7d201a; stroke-width: 5; stroke-linecap: round; }}
      .divider {{ stroke: #ddd4c4; stroke-width: 1.5; }}
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
  <rect class="panel" x="60" y="45" width="800" height="865" rx="16"/>
  <rect class="panel" x="940" y="45" width="800" height="865" rx="16"/>

  <text class="heading" x="105" y="115">Keyword Search</text>
  <line class="accent" x1="105" y1="143" x2="205" y2="143"/>
  <text class="subheading" x="105" y="185">Combine up to four topics or secondary keywords</text>
  <text class="subheading" x="105" y="217">to find relevant posts.</text>

  <text class="heading" x="985" y="115">Browse Topics</text>
  <line class="accent" x1="985" y1="143" x2="1085" y2="143"/>
  <text class="subheading" x="985" y="185">Explore posts through one of two subject-area</text>
  <text class="subheading" x="985" y="217">structures.</text>

  <text class="stage" x="145" y="258">START WITH</text>
  <text class="stage" x="1025" y="258">START WITH</text>

  <rect class="box topic" x="145" y="275" width="315" height="120" rx="12"/>
  <rect class="box keyword" x="460" y="275" width="315" height="120" rx="12"/>
  <text class="label" x="302" y="323" text-anchor="middle">Topics</text>
  <text class="count" x="302" y="361" text-anchor="middle">{topics} topics</text>
  <text class="label" x="617" y="323" text-anchor="middle">Secondary Keywords</text>
  <text class="count" x="617" y="361" text-anchor="middle">{keywords} keywords</text>

  <rect class="box subject" x="1025" y="275" width="315" height="120" rx="12"/>
  <rect class="box subject" x="1340" y="275" width="315" height="120" rx="12"/>
  <text class="label" x="1182" y="323" text-anchor="middle">Browse Topics 1</text>
  <text class="count" x="1182" y="361" text-anchor="middle">{areas_1} subject areas</text>
  <text class="label" x="1497" y="323" text-anchor="middle">Browse Topics 2</text>
  <text class="count" x="1497" y="361" text-anchor="middle">{areas_2} subject areas</text>

  <path class="arrow" d="M460 395 L460 529"/>
  <path class="arrow" d="M1340 395 L1340 448"/>

  <rect class="box process" x="145" y="529" width="630" height="105" rx="12"/>
  <text class="label" x="460" y="571" text-anchor="middle">Up to Four Search Terms</text>
  <text class="count" x="460" y="608" text-anchor="middle">Topics, secondary keywords, or both</text>

  <rect class="box category" x="1025" y="448" width="630" height="105" rx="12"/>
  <text class="label" x="1340" y="490" text-anchor="middle">Categories</text>
  <text class="count" x="1340" y="527" text-anchor="middle">{categories} categories</text>

  <path class="arrow" d="M1340 553 L1340 610"/>

  <rect class="box topic" x="1025" y="610" width="630" height="105" rx="12"/>
  <text class="label" x="1340" y="652" text-anchor="middle">Topics</text>
  <text class="count" x="1340" y="689" text-anchor="middle">{topics} topics</text>

  <path class="arrow" d="M460 634 L460 772"/>
  <path class="arrow" d="M1340 715 L1340 772"/>

  <rect class="box post" x="145" y="772" width="630" height="88" rx="12"/>
  <rect class="box post" x="1025" y="772" width="630" height="88" rx="12"/>
  <text class="label" x="460" y="826" text-anchor="middle">Posts ({posts})</text>
  <text class="label" x="1340" y="826" text-anchor="middle">Posts ({posts})</text>

  <text class="note" x="900" y="970" text-anchor="middle">Topic post lists and search results can be narrowed with additional search terms.</text>

  <rect class="panel" x="60" y="1015" width="1680" height="245" rx="16"/>
  <line class="divider" x1="480" y1="1050" x2="480" y2="1225"/>
  <line class="divider" x1="900" y1="1050" x2="900" y2="1225"/>
  <line class="divider" x1="1320" y1="1050" x2="1320" y2="1225"/>

  <text class="definition-title" x="90" y="1075">Subject Areas:</text>
  <text class="definition" x="90" y="1120">Broad entry points that</text>
  <text class="definition" x="90" y="1152">organize related categories.</text>

  <text class="definition-title" x="510" y="1075">Categories:</text>
  <text class="definition" x="510" y="1120">Focused groupings of</text>
  <text class="definition" x="510" y="1152">related topics.</text>

  <text class="definition-title" x="930" y="1075">Topics:</text>
  <text class="definition" x="930" y="1120">Major subjects covered</text>
  <text class="definition" x="930" y="1152">in posts and used to group</text>
  <text class="definition" x="930" y="1184">related posts.</text>

  <text class="definition-title" x="1350" y="1075">Secondary Keywords:</text>
  <text class="definition" x="1350" y="1120">Important people, texts,</text>
  <text class="definition" x="1350" y="1152">places, or supporting ideas</text>
  <text class="definition" x="1350" y="1184">discussed in posts.</text>
'''
    return svg_document(1800, 1320, body)


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
    .stage {{ font-size: 11px; letter-spacing: 1px; }}
    .label {{ font-size: 21px; }}
    .label-small {{ font-size: 17px; }}
    .label-fit {{ font-size: 18px; }}
    .count {{ font-size: 16px; }}
    .note {{ font-size: 15px; }}
    .definition {{ font-size: 18px; }}
    .definition-title {{ font-size: 20px; }}
    .accent {{ stroke-width: 4; }}
  </style>
  <rect class="panel" x="15" y="15" width="350" height="700" rx="12"/>
  <text class="heading" x="35" y="60">Keyword Search</text>
  <line class="accent" x1="35" y1="74" x2="95" y2="74"/>
  <text class="subheading" x="35" y="95">Combine up to four topics or secondary</text>
  <text class="subheading" x="35" y="118">keywords to find relevant posts.</text>
  <text class="stage" x="35" y="145">START WITH</text>
  <rect class="box topic" x="35" y="155" width="115" height="100" rx="12"/>
  <rect class="box keyword" x="150" y="155" width="195" height="100" rx="12"/>
  <text class="label" x="92" y="197" text-anchor="middle">Topics</text>
  <text class="count" x="92" y="228" text-anchor="middle">{topics} topics</text>
  <text class="label-small label-fit" x="247" y="197" text-anchor="middle">Secondary Keywords</text>
  <text class="count" x="247" y="228" text-anchor="middle">{keywords} keywords</text>
  <path class="arrow" d="M190 255 L190 350"/>
  <rect class="box process" x="45" y="350" width="290" height="75" rx="12"/>
  <text class="label-small" x="190" y="381" text-anchor="middle">Up to Four Search Terms</text>
  <text class="count" x="190" y="408" text-anchor="middle">Topics, keywords, or both</text>
  <path class="arrow" d="M190 425 L190 555"/>
  <rect class="box post" x="45" y="555" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="602" text-anchor="middle">Posts ({posts})</text>

  <rect class="panel" x="15" y="745" width="350" height="700" rx="12"/>
  <text class="heading" x="35" y="790">Browse Topics</text>
  <line class="accent" x1="35" y1="804" x2="95" y2="804"/>
  <text class="subheading" x="35" y="825">Explore posts through one of two</text>
  <text class="subheading" x="35" y="848">subject-area structures.</text>
  <text class="stage" x="35" y="875">START WITH</text>
  <rect class="box subject" x="35" y="885" width="155" height="100" rx="12"/>
  <rect class="box subject" x="190" y="885" width="155" height="100" rx="12"/>
  <text class="label-small label-fit" x="112" y="925" text-anchor="middle">Browse Topics 1</text>
  <text class="count" x="112" y="958" text-anchor="middle">{areas_1} subject areas</text>
  <text class="label-small label-fit" x="267" y="925" text-anchor="middle">Browse Topics 2</text>
  <text class="count" x="267" y="958" text-anchor="middle">{areas_2} subject areas</text>
  <path class="arrow" d="M190 985 L190 1035"/>
  <rect class="box category" x="45" y="1035" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1066" text-anchor="middle">Categories</text>
  <text class="count" x="190" y="1094" text-anchor="middle">{categories} categories</text>
  <path class="arrow" d="M190 1110 L190 1160"/>
  <rect class="box topic" x="45" y="1160" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1191" text-anchor="middle">Topics</text>
  <text class="count" x="190" y="1219" text-anchor="middle">{topics} topics</text>
  <path class="arrow" d="M190 1235 L190 1285"/>
  <rect class="box post" x="45" y="1285" width="290" height="75" rx="12"/>
  <text class="label" x="190" y="1332" text-anchor="middle">Posts ({posts})</text>

  <text class="note" x="190" y="1500" text-anchor="middle">Post lists and search results can be</text>
  <text class="note" x="190" y="1522" text-anchor="middle">narrowed with additional search terms.</text>

  <rect class="panel" x="15" y="1570" width="350" height="465" rx="12"/>
  <text class="definition" x="35" y="1610"><tspan class="definition-title">Subject Areas:</tspan></text>
  <text class="definition" x="35" y="1640">Broad entry points that organize</text>
  <text class="definition" x="35" y="1665">related categories.</text>
  <text class="definition" x="35" y="1715"><tspan class="definition-title">Categories:</tspan></text>
  <text class="definition" x="35" y="1745">Focused groupings of related topics.</text>
  <text class="definition" x="35" y="1820"><tspan class="definition-title">Topics:</tspan></text>
  <text class="definition" x="35" y="1850">Major subjects covered in posts and</text>
  <text class="definition" x="35" y="1875">used to group related posts.</text>
  <text class="definition" x="35" y="1950"><tspan class="definition-title">Secondary Keywords:</tspan></text>
  <text class="definition" x="35" y="1980">Important people, texts, places, or</text>
  <text class="definition" x="35" y="2005">supporting ideas discussed in posts.</text>
'''
    return svg_document(380, 2055, body)


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
