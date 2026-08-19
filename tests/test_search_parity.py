from __future__ import annotations

import io
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import ehrman_demo_data, search_parity
from webapp import app
from webapp.parity import MAX_BATCH_CASES, ParityRequestError, run_batch, source_fingerprints


class SearchParityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        app.ensure_database()

    def call_wsgi(self, payload: object, token: str = "test-token") -> tuple[str, dict[str, str], bytes]:
        body = json.dumps(payload).encode("utf-8")
        environ: dict[str, object] = {
            "PATH_INFO": "/api/parity/batch",
            "QUERY_STRING": "",
            "REQUEST_METHOD": "POST",
            "CONTENT_LENGTH": str(len(body)),
            "CONTENT_TYPE": "application/json",
            "HTTP_X_EHRMAN_PARITY_TOKEN": token,
            "wsgi.input": io.BytesIO(body),
        }
        captured: dict[str, object] = {}

        def start_response(status: str, headers: list[tuple[str, str]]) -> None:
            captured["status"] = status
            captured["headers"] = dict(headers)

        response = b"".join(app.application(environ, start_response))
        return str(captured["status"]), dict(captured["headers"]), response

    def test_url_is_the_stable_sort_tiebreaker(self) -> None:
        rows = [
            {"id": 1, "title": "", "description": "", "date_iso": "2020-01-01", "url": "https://example.test/a"},
            {"id": 2, "title": "", "description": "", "date_iso": "2020-01-01", "url": "https://example.test/b"},
        ]
        ranked = app.sort_scoped_posts(rows, "ranked", [])
        oldest = app.sort_scoped_posts(rows, "oldest", [])
        self.assertEqual([row["url"] for row in ranked], ["https://example.test/b", "https://example.test/a"])
        self.assertEqual([row["url"] for row in oldest], ["https://example.test/a", "https://example.test/b"])

    def test_best_match_uses_title_and_description_boosts(self) -> None:
        rows = [
            {
                "id": 1,
                "title": "A General Post",
                "description": "Explains Paul's understanding of resurrection.",
                "date_iso": "2020-01-01",
                "url": "https://example.test/description",
            },
            {
                "id": 2,
                "title": "A General Post",
                "description": "Explains an unrelated subject.",
                "date_iso": "2021-01-01",
                "url": "https://example.test/no-description-match",
            },
        ]
        ranked = app.sort_scoped_posts(rows, "ranked", ["Paul"], {1: 5, 2: 5})
        self.assertEqual(ranked[0]["id"], 1)
        self.assertEqual(app.title_match_boost("Paul and the Apostles", "Paul"), 4)
        self.assertEqual(app.description_match_boost("A post about Paul.", "Paul"), 2)
        self.assertEqual(app.description_match_boost("A nutshell overview.", "hell"), 0)

    def test_general_topic_qualifier_is_ignored_for_text_boosts(self) -> None:
        rows = [
            {
                "id": 1,
                "title": "Why Gospels Matter Even Where They Are Not Historical",
                "description": "Explains why Gospel stories matter.",
                "date_iso": "2026-04-14",
                "url": "https://example.test/newer",
            },
            {
                "id": 2,
                "title": "Four More Intriguing Topics on the Historical Jesus",
                "description": "Summarizes lectures on the historical Jesus.",
                "date_iso": "2025-10-12",
                "url": "https://example.test/older",
            },
        ]
        term = "Historical Jesus (General)"
        ranked = app.sort_scoped_posts(rows, "ranked", [term], {1: 8, 2: 8})
        self.assertEqual(ranked[0]["id"], 2)
        self.assertEqual(app.title_match_boost(rows[1]["title"], term), 4)
        self.assertEqual(app.description_match_boost(rows[1]["description"], term), 2)

    def test_multiword_terms_use_a_modest_anchor_word_boost(self) -> None:
        term = "Non-Pauline Epistle Authorship"
        self.assertEqual(app.ranking_anchor_token(term), "authorship")
        self.assertEqual(app.ranking_anchor_token("Translation Issues"), "translation")
        self.assertEqual(app.ranking_anchor_token("Paul"), "")
        self.assertEqual(app.title_match_boost("Questions of Authorship", term), 2)
        self.assertEqual(app.description_match_boost("Examines the authorship of Jude.", term), 1)
        self.assertEqual(app.description_match_boost("Discusses post-Pauline theology.", term), 0)
        rows = [
            {
                "id": 1,
                "title": "A Newer Post",
                "description": "Discusses post-Pauline theology.",
                "date_iso": "2025-10-07",
                "url": "https://example.test/newer",
            },
            {
                "id": 2,
                "title": "An Older Post",
                "description": "Examines the authorship of Jude.",
                "date_iso": "2025-09-27",
                "url": "https://example.test/older",
            },
        ]
        ranked = app.sort_scoped_posts(rows, "ranked", [term], {1: 8, 2: 8})
        self.assertEqual(ranked[0]["id"], 2)

    def test_database_uses_refined_topic_and_keyword_weights(self) -> None:
        with app.get_conn() as conn:
            weights = {
                row["kind"]: row["weight"]
                for row in conn.execute(
                    "SELECT kind, MAX(weight) AS weight FROM post_search_terms GROUP BY kind"
                ).fetchall()
            }
        self.assertEqual(weights, {"alias": 6, "secondary": 3, "topic": 6})

    def test_topic_alias_resolves_to_canonical_topic(self) -> None:
        with app.get_conn() as conn:
            alias_modes = app.resolve_term_modes(conn, ["Pericope Adulterae"])
            alias_posts = app.find_post_ids_for_term(
                conn,
                "Pericope Adulterae",
                alias_modes[0],
            )
            canonical_posts = app.find_post_ids_for_term(
                conn,
                "Woman Caught in Adultery",
                "topic",
            )

        self.assertEqual(alias_modes, ["topic"])
        self.assertEqual(set(alias_posts), set(canonical_posts))
        self.assertEqual(len(alias_posts), 10)

        suggestions = json.loads(app.api_keywords({"q": ["pericope"]}).decode("utf-8"))
        alias_suggestion = next(
            suggestion
            for suggestion in suggestions
            if suggestion["normalized"] == "pericope adulterae"
        )
        self.assertEqual(alias_suggestion["label"], "Woman Caught in Adultery")
        self.assertEqual(alias_suggestion["mode"], "topic")
        self.assertEqual(alias_suggestion["postCount"], 10)
        self.assertTrue(alias_suggestion["description"])

        category_suggestions = json.loads(
            app.api_keywords({"category": ["textual-criticism"]}).decode("utf-8")
        )
        self.assertNotIn(
            "pericope adulterae",
            {suggestion["normalized"] for suggestion in category_suggestions},
        )

    def test_standalone_payload_indexes_alias_without_duplicate_keyword(self) -> None:
        topics = ehrman_demo_data.load_topics()
        posts = ehrman_demo_data.load_posts()
        keyword_index = ehrman_demo_data.build_keyword_index(posts, topics)
        suggestions = ehrman_demo_data.build_keyword_suggestions(keyword_index)

        alias_pairs = [
            pair
            for row in keyword_index
            for pair in row[5]
            if pair[1] == "pericope adulterae"
        ]
        alias_keywords = [
            pair
            for row in keyword_index
            for pair in row[6]
            if pair[1] == "pericope adulterae"
        ]
        alias_suggestions = [row for row in suggestions if row[2] == "pericope adulterae"]

        self.assertEqual(len(alias_pairs), 10)
        self.assertTrue(
            all(pair == ["Woman Caught in Adultery", "pericope adulterae", "alias"] for pair in alias_pairs)
        )
        self.assertEqual(alias_keywords, [])
        self.assertEqual(
            alias_suggestions,
            [["Woman Caught in Adultery", 10, "pericope adulterae", "topic", True]],
        )

    def test_search_batch_returns_ordered_urls(self) -> None:
        response = run_batch(
            [
                {
                    "id": "luke-atonement",
                    "operation": "search",
                    "terms": ["Luke", "Atonement"],
                    "sort": "ranked",
                }
            ]
        )
        result = response["results"][0]
        self.assertTrue(result["ok"])
        self.assertGreater(result["resultCount"], 0)
        self.assertEqual(result["resultCount"], len(result["posts"]))
        self.assertEqual(result["posts"][0]["position"], 1)
        self.assertTrue(result["posts"][0]["url"].startswith("https://"))

    def test_scoped_and_suggestion_cases(self) -> None:
        with app.get_conn() as conn:
            category_slug = conn.execute("SELECT slug FROM categories ORDER BY name LIMIT 1").fetchone()[0]
            topic_slug = conn.execute(
                "SELECT slug FROM topics WHERE display_in_browser = 1 ORDER BY name LIMIT 1"
            ).fetchone()[0]
        response = run_batch(
            [
                {
                    "id": "category",
                    "operation": "search",
                    "terms": [],
                    "scope": {"type": "category", "slug": category_slug},
                },
                {
                    "id": "topic",
                    "operation": "search",
                    "terms": [],
                    "scope": {"type": "topic", "slug": topic_slug},
                },
                {"id": "suggest", "operation": "suggest", "query": "wo", "selected": []},
            ]
        )
        self.assertTrue(all(result["ok"] for result in response["results"]))
        self.assertGreater(response["results"][0]["resultCount"], 0)
        self.assertGreater(response["results"][1]["resultCount"], 0)
        self.assertGreater(response["results"][2]["suggestionCount"], 0)

    def test_featured_topics_come_from_topic_metadata(self) -> None:
        expected = [
            "Translation Issues",
            "Scribal Changes",
            "Textual Variants",
            "Biblical Contradictions",
            "Book of Revelation",
            "Heaven and Hell Beliefs",
            "Forgery (General)",
            "Oral Tradition",
            "Conversion",
            "Non-Canonical Gospel Traditions",
            "Canon Formation",
            "Mythicism",
            "Rise of Christianity",
            "Jesus' Teachings",
            "Problem of Evil and Suffering",
            "Gospel Authorship",
            "Eyewitness Reliability",
            "Memory and Jesus Traditions",
            "Gospel Historical Reliability",
            "Resurrection of Jesus",
            "Paul's Knowledge of Jesus",
            "Historical Jesus (General)",
            "Early Christian Diversity",
            "Christology (General)",
            "Pauline Authorship",
        ]
        with app.get_conn() as conn:
            suggestions = app.starter_keyword_suggestions(conn)
        self.assertEqual([suggestion["label"] for suggestion in suggestions], expected)
        self.assertTrue(all(suggestion["postCount"] > 0 for suggestion in suggestions))

    def test_rendered_pages_cache_bust_static_assets(self) -> None:
        page = app.render_page("Test", "").decode("utf-8")
        self.assertIn('/static/styles.css?v=', page)
        self.assertIn('/static/site.js?v=', page)

    def test_keyword_search_category_filter_scopes_results(self) -> None:
        categories = app.keyword_filter_categories()
        category = next(row for row in categories if row["post_count"] > 0)
        posts, clean_terms = app.search_posts([], "ranked", category["slug"])
        self.assertEqual(clean_terms, [])
        self.assertEqual(len(posts), category["post_count"])

        page = app.keyword_results_page({"category": [category["slug"]]}).decode("utf-8")
        self.assertIn('id="keyword-category-filter"', page)
        self.assertIn(
            f'value="{category["slug"]}" data-category-filter',
            page,
        )
        self.assertIn(
            f'aria-selected="true" data-category-option data-value="{category["slug"]}"',
            page,
        )
        self.assertIn(f'Category: {category["name"]}', page)
        self.assertIn('aria-label="Search scope"', page)
        self.assertNotIn('class="keyword-section-title"', page)
        self.assertNotIn('Optionally limit results to one category.', page)
        self.assertNotIn('keyword-category-description', page)

    def test_ambiguous_term_exposes_topic_and_combined_searches(self) -> None:
        suggestions = json.loads(app.api_keywords({"q": ["colossians"]}).decode("utf-8"))
        colossians = {
            suggestion["mode"]: suggestion["postCount"]
            for suggestion in suggestions
            if suggestion["label"] == "Colossians"
        }
        self.assertEqual(colossians, {"topic": 7, "topic-keyword": 23})

        topic_posts, _ = app.search_posts(
            ["Colossians"],
            "ranked",
            term_modes=["topic"],
        )
        combined_posts, _ = app.search_posts(
            ["Colossians"],
            "ranked",
            term_modes=["topic-keyword"],
        )
        self.assertEqual(len(topic_posts), 7)
        self.assertEqual(len(combined_posts), 23)
        self.assertTrue({post["url"] for post in topic_posts}.issubset({post["url"] for post in combined_posts}))

    def test_contextual_suggestions_only_offer_refinements(self) -> None:
        selected_posts, _ = app.search_posts(
            ["Colossians"],
            "ranked",
            term_modes=["topic-keyword"],
        )
        suggestions = json.loads(
            app.api_keywords(
                {
                    "selected": ["Colossians"],
                    "selected-mode": ["topic-keyword"],
                }
            ).decode("utf-8")
        )

        self.assertTrue(suggestions)
        self.assertTrue(
            all(suggestion["postCount"] < len(selected_posts) for suggestion in suggestions)
        )

    def test_keyword_search_page_lists_each_category_once(self) -> None:
        page = app.keyword_search_page().decode("utf-8")
        category = app.keyword_filter_categories()[0]
        self.assertEqual(page.count('data-category-filter'), 1)
        self.assertEqual(
            page.count(' data-category-option data-value='),
            len(app.keyword_filter_categories()) + 1,
        )
        self.assertIn(
            f'data-label="{category["name"]}" '
            f'data-count="{app.pluralize(category["post_count"], "post")}"',
            page,
        )
        self.assertIn('class="category-combobox-option-count"', page)

    def test_browse_snapshot_contains_both_structures(self) -> None:
        result = run_batch([{"id": "browse", "operation": "browse"}])["results"][0]
        self.assertTrue(result["ok"])
        self.assertEqual(len(result["subjectAreas1"]), 10)
        self.assertEqual(len(result["subjectAreas2"]), 9)
        self.assertEqual(len(result["categories"]), 41)
        self.assertGreater(len(result["topics"]), 250)

    def test_source_fingerprint_is_reproducible(self) -> None:
        self.assertEqual(source_fingerprints(), source_fingerprints())
        self.assertEqual(len(source_fingerprints()["sha256"]), 64)

    def test_batch_limit_is_enforced(self) -> None:
        cases = [{"id": f"case-{index}", "operation": "search", "terms": ["Paul"]} for index in range(MAX_BATCH_CASES + 1)]
        with self.assertRaises(ParityRequestError):
            run_batch(cases)

    def test_generate_arguments_do_not_require_capture_only_options(self) -> None:
        args = search_parity.build_parser().parse_args(["generate", "--profile", "smoke"])
        search_parity.validate_args(args)

    def test_standard_profile_is_deterministic_and_contains_500_cases(self) -> None:
        with app.get_conn() as conn:
            first = search_parity.standard_cases(conn, search_parity.DEFAULT_SEED)
            second = search_parity.standard_cases(conn, search_parity.DEFAULT_SEED)
        self.assertEqual(first, second)
        self.assertEqual(len(first), search_parity.STANDARD_CASE_COUNT)
        self.assertEqual(len({case["id"] for case in first}), len(first))
        self.assertEqual({case["operation"] for case in first}, {"browse", "search", "suggest"})

    def test_capture_progress_counts_completed_records(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "capture.jsonl"
            path.write_text(
                '{"recordType":"manifest","schemaVersion":1}\n'
                '{"recordType":"result","id":"one"}\n'
                '{"recordType":"result","id":"two"}\n',
                encoding="utf-8",
            )
            manifest, completed = search_parity.capture_progress(path, "result")
        self.assertEqual(manifest["schemaVersion"], 1)
        self.assertEqual(completed, 2)

    def test_compare_can_allow_an_explicit_known_variance(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            expected = root / "expected.jsonl"
            actual = root / "actual.jsonl"
            report = root / "report.html"
            expected.write_text(
                '{"recordType":"manifest","schemaVersion":1}\n'
                '{"recordType":"result","id":"known","value":1}\n',
                encoding="utf-8",
            )
            actual.write_text(
                '{"recordType":"manifest","schemaVersion":1}\n'
                '{"recordType":"result","id":"known","value":2}\n',
                encoding="utf-8",
            )
            args = search_parity.build_parser().parse_args(
                [
                    "compare",
                    str(expected),
                    str(actual),
                    "--allow-case",
                    "known",
                    "--report",
                    str(report),
                ]
            )
            result = search_parity.compare_command(args)
            report_text = report.read_text(encoding="utf-8")

        self.assertEqual(result, 0)
        self.assertIn("1 approved variances", report_text)

    def test_endpoint_is_disabled_without_token_configuration(self) -> None:
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("EHRMAN_PARITY_TEST_TOKEN", None)
            status, _, _ = self.call_wsgi({"cases": []})
        self.assertEqual(status, "404 Not Found")

    def test_endpoint_rejects_incorrect_token(self) -> None:
        with patch.dict(os.environ, {"EHRMAN_PARITY_TEST_TOKEN": "correct"}):
            status, _, body = self.call_wsgi({"cases": []}, token="incorrect")
        self.assertEqual(status, "403 Forbidden")
        self.assertEqual(json.loads(body)["error"], "Forbidden")

    def test_endpoint_accepts_authenticated_batch(self) -> None:
        payload = {
            "schemaVersion": 1,
            "cases": [{"id": "paul", "operation": "search", "terms": ["Paul"], "sort": "ranked"}],
        }
        with patch.dict(os.environ, {"EHRMAN_PARITY_TEST_TOKEN": "correct"}):
            status, headers, body = self.call_wsgi(payload, token="correct")
        self.assertEqual(status, "200 OK")
        self.assertEqual(headers["Cache-Control"], "no-store")
        self.assertTrue(json.loads(body)["results"][0]["ok"])


if __name__ == "__main__":
    unittest.main()
