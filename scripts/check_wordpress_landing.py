"""Read-only checks for canonical counts in local and served landing-page assets."""
from __future__ import annotations

import argparse
from collections import Counter
from datetime import date
from html.parser import HTMLParser
import http.client
import json
from pathlib import Path
import re
from urllib.parse import urljoin, urlsplit
import xml.etree.ElementTree as ET

from build_search_methods_diagram import collect_counts
from ehrman_demo_data import parse_date_value

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ("ehrman-search-methods.svg", "ehrman-search-methods-mobile.svg")


def source_counts(index: Path) -> dict[str, int]:
    return collect_counts(argparse.Namespace(
        search_index=index / "ehrman_post_search_index.json",
        topics=index / "ehrman_post_topics.json",
        categories=index / "ehrman_post_categories.json",
        subject_areas=index / "ehrman_post_subject_areas.json",
        subject_areas_2=index / "ehrman_post_subject_areas_2.json",
    ))


def check_svg(svg: str, counts: dict[str, int]) -> None:
    expected = Counter([
        f"{counts['subject_areas']:,} subject areas",
        f"{counts['subject_areas_2']:,} subject areas",
        f"{counts['categories']:,} categories",
        f"{counts['topics']:,} topics", f"{counts['topics']:,} topics",
        f"{counts['secondary_keywords']:,} keywords",
    ])
    labels = [" ".join("".join(node.itertext()).split())
              for node in ET.fromstring(svg).iter()
              if node.tag.rsplit('}', 1)[-1] == 'text']
    actual = Counter(label for label in labels if re.match(r"^\d[\d,]*\s+(topics|keywords|categories|subject areas|posts)$", label))
    if actual != expected:
        raise ValueError(f"Stale or missing diagram counts: expected {dict(expected)}, found {dict(actual)}")


def post_range(posts: list[dict]) -> str:
    parsed = [parse_date_value(post['dateText']) for post in posts]
    if any(value is None for value in parsed):
        raise ValueError('A canonical post date could not be parsed.')
    dates = [value.date() for value in parsed]
    if not dates:
        raise ValueError('The canonical post collection is empty.')
    def label(value: date) -> str:
        return f"{value.strftime('%B')} {value.day}, {value.year}"
    return f"Posts from {label(min(dates))} through {label(max(dates))} ({len(posts):,} posts)"


class LandingPage(HTMLParser):
    def __init__(self):
        super().__init__()
        self.assets: dict[str, str] = {}
        self.summary: list[str] = []
        self.in_summary = False

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'p' and 'ehrman-demo-meta' in values.get('class', '').split():
            self.in_summary = True
        url = values.get('src' if tag == 'img' else 'srcset' if tag == 'source' else '')
        if url:
            name = Path(urlsplit(url).path).name
            if name in ASSETS:
                self.assets[name] = url

    def handle_endtag(self, tag):
        if tag == 'p':
            self.in_summary = False

    def handle_data(self, data):
        if self.in_summary:
            self.summary.append(data)


def read_local_url(url: str) -> str:
    target = urlsplit(url)
    if target.scheme != 'http' or target.hostname not in ('localhost', '127.0.0.1') or target.username or target.password:
        raise ValueError('Landing checks require a local HTTP URL.')
    connection = http.client.HTTPConnection(target.hostname, target.port or 80, timeout=15)
    try:
        connection.request('GET', (target.path or '/') + ('?' + target.query if target.query else ''))
        response = connection.getresponse()
        if response.status != 200:
            raise ValueError(f'Landing resource returned HTTP {response.status}; redirects are not followed.')
        return response.read().decode('utf-8-sig')
    finally:
        connection.close()


def check_live(base: str, counts: dict[str, int], expected_summary: str) -> None:
    origin = urlsplit(base)
    if origin.path not in ('', '/') or origin.query or origin.fragment:
        raise ValueError('Supply a local origin, not a page URL.')
    page = LandingPage()
    page.feed(read_local_url(base))
    if ' '.join(''.join(page.summary).split()) != expected_summary:
        raise ValueError(f'Landing post dates/count do not match: expected {expected_summary}')
    for name in ASSETS:
        if name not in page.assets:
            raise ValueError(f'Landing page does not reference {name}')
        url = urljoin(base, page.assets[name])
        target = urlsplit(url)
        if (target.scheme, target.netloc) != (origin.scheme, origin.netloc):
            raise ValueError('Landing diagram must be served by the tested local origin.')
        check_svg(read_local_url(url), counts)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--index', type=Path, default=ROOT / 'data/index')
    parser.add_argument('--assets', type=Path, default=ROOT / 'wordpress-theme/ehrman-discovery-demo/assets/images')
    parser.add_argument('--base-url', help='Also verify the served diagrams and database-backed post range.')
    args = parser.parse_args()
    try:
        counts = source_counts(args.index)
        posts = json.loads((args.index / 'ehrman_post_search_index.json').read_text(encoding='utf-8'))
        for name in ASSETS:
            check_svg((args.assets / name).read_text(encoding='utf-8'), counts)
        summary = post_range(posts)
        if args.base_url:
            check_live(args.base_url, counts, summary)
        print(json.dumps({'status': 'passed', 'counts': counts, 'post_range': summary, 'live_checked': bool(args.base_url)}))
        return 0
    except (ValueError, OSError, ET.ParseError, http.client.HTTPException) as error:
        print(f'FAIL: {error}')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
