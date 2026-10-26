import sys
from pathlib import Path
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
from build_search_methods_diagram import desktop_svg, mobile_svg
from check_wordpress_landing import check_svg, check_live, post_range, source_counts, read_local_url


class LandingCountTests(unittest.TestCase):
    counts = dict(posts=2, topics=295, categories=41, subject_areas=10,
                  subject_areas_2=9, secondary_keywords=902)

    def test_both_layouts(self):
        for builder in (desktop_svg, mobile_svg):
            check_svg(builder(self.counts), self.counts)

    def test_stale_missing_and_duplicate_counts(self):
        svg = desktop_svg(self.counts)
        for changed in (svg.replace('902 keywords', '901 keywords'),
                        svg.replace('902 keywords', ''),
                        svg.replace('</svg>', '<text>902 keywords</text></svg>')):
            with self.assertRaises(ValueError):
                check_svg(changed, self.counts)

    def test_hidden_ignore_topic_is_not_counted(self):
        root = Path(__file__).resolve().parents[2] / 'data/index'
        counts = source_counts(root)
        import json
        topics = json.loads((root / 'ehrman_post_topics.json').read_text(encoding='utf-8'))['topics']
        expected = sum(topic.get('displayInBrowser', True) is not False and topic['name'] != 'Ignore' for topic in topics)
        self.assertEqual(counts['topics'], expected)

    def test_range_is_sorted_and_formatted(self):
        self.assertEqual(post_range([{'dateText': 'October 11, 2026'}, {'dateText': 'April 3, 2012'}]),
                         'Posts from April 3, 2012 through October 11, 2026 (2 posts)')
        with self.assertRaises(ValueError):
            post_range([])
        self.assertIn('February 22, 2017', post_range([{'dateText': '2017-02-22T07:03:41-05:00'}]))

    def test_live_assets_and_range(self):
        html = '<p class="ehrman-demo-meta">Expected range</p><img src="/ehrman-search-methods.svg?ver=1"><source srcset="/ehrman-search-methods-mobile.svg?ver=1">'
        with patch('check_wordpress_landing.read_local_url', side_effect=[html, desktop_svg(self.counts), mobile_svg(self.counts)]) as read:
            check_live('http://localhost:1234', self.counts, 'Expected range')
            self.assertEqual(read.call_count, 3)
        for page in (html.replace('Expected range', 'Stale range'), html.replace('srcset', 'data-srcset'),
                     html.replace('/ehrman-search-methods.svg', 'https://example.com/ehrman-search-methods.svg')):
            with patch('check_wordpress_landing.read_local_url', side_effect=[page, desktop_svg(self.counts)]), self.assertRaises(ValueError):
                check_live('http://localhost:1234', self.counts, 'Expected range')

    def test_remote_url_is_rejected_before_network(self):
        with self.assertRaises(ValueError):
            read_local_url('https://example.com/')


if __name__ == '__main__':
    unittest.main()
