"""Check that plugin documentation is shipped and matches the sharing copies."""

import json
from html.parser import HTMLParser
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
PLUGIN_DOCS = ROOT / "wordpress-plugin/ehrman-blog-discovery/docs"


class GuideParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = set()
        self.links = []
        self.images = []
        self.heading_levels = []

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if "id" in values:
            self.ids.add(values["id"])
        if tag == "a":
            self.links.append(values.get("href", ""))
        if tag == "img":
            self.images.append(values.get("src", ""))
        if tag in ("h1", "h2", "h3", "h4"):
            self.heading_levels.append(int(tag[1]))


class PublisherGuideTests(unittest.TestCase):
    def test_packaged_copies_match(self):
        for path in (ROOT / "docs/user-guide/ehrman_blog_publisher_guide.html",
                     ROOT / "output/pdf/ehrman_blog_publisher_guide.pdf"):
            with self.subTest(format=path.suffix):
                self.assertEqual(path.read_bytes(), (PLUGIN_DOCS / path.name).read_bytes())
        self.assertTrue((PLUGIN_DOCS / "ehrman_blog_publisher_guide.pdf").read_bytes().startswith(b"%PDF-"))

    def test_guide_is_self_contained_and_has_current_navigation(self):
        source = json.loads((ROOT / "docs/user-guide/guide.json").read_text(encoding="utf-8"))
        html = (PLUGIN_DOCS / "ehrman_blog_publisher_guide.html").read_text(encoding="utf-8")
        self.assertIn("<title>Ehrman Blog Publisher Guide</title>", html)
        self.assertNotIn("Settings and Status", html)
        self.assertIn("Blog Search > Settings", html)
        self.assertIn("Blog Search > Info &amp; Documentation", html)
        self.assertIn("Download PDF", html)
        self.assertIn('aria-label="Quick Guide"', html)
        self.assertIn("What Is Search Metadata?", html)
        self.assertIn('aria-label="Table of Contents"', html)
        self.assertIn("Back to contents", html)
        parser = GuideParser()
        parser.feed(html)
        expected_ids = {"contents"}
        expected_contents = []
        previous = None
        for page in source["pages"]:
            expected_ids.add(page["id"])
            if page["section"] != previous:
                expected_ids.add("section-" + page["id"])
                expected_contents.append("#section-" + page["id"])
            if page["title"] != page["section"]:
                expected_contents.append("#" + page["id"])
            for block in page["blocks"]:
                if "id" in block:
                    expected_ids.add(block["id"])
                    expected_contents.append("#" + block["id"])
            previous = page["section"]
        self.assertEqual(expected_ids, parser.ids)
        self.assertTrue(parser.links)
        self.assertEqual(21, len(expected_contents))
        self.assertEqual(expected_contents, parser.links[:21])
        self.assertEqual(["#publish", "#manual", "#manage", "#analytics", "#settings", "#system-status"], parser.links[21:27])
        for link in parser.links:
            self.assertTrue(link.startswith("#") and link[1:] in parser.ids, link)
        self.assertTrue(parser.images)
        self.assertTrue(all(image.startswith("data:image/") for image in parser.images))
        self.assertEqual(6, len(set(page["section"] for page in source["pages"])))
        self.assertEqual(1, parser.heading_levels.count(1))
        self.assertEqual(7, parser.heading_levels.count(2))
        self.assertEqual("1. Introduction", source["pages"][0]["title"])
        for previous, current in zip(parser.heading_levels, parser.heading_levels[1:]):
            self.assertLessEqual(current, previous + 1, "Heading levels must not be skipped")

    def test_section_and_subsection_numbering(self):
        source = json.loads((ROOT / "docs/user-guide/guide.json").read_text(encoding="utf-8"))
        groups = {}
        for page in source["pages"]:
            titles = groups.setdefault(page["section"], [])
            if page["title"] != page["section"]:
                titles.append(page["title"])
            titles.extend(block["text"] for block in page["blocks"] if "id" in block)
        self.assertEqual([2, 2, 2, 4, 3, 2], [len(titles) for titles in groups.values()])
        for section_number, (section, titles) in enumerate(groups.items(), 1):
            self.assertTrue(section.startswith(f"{section_number}. "), section)
            for subsection_number, title in enumerate(titles, 1):
                self.assertTrue(title.startswith(f"{section_number}.{subsection_number} "), title)


if __name__ == "__main__":
    unittest.main()
