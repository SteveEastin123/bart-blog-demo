from __future__ import annotations

import io
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import search_parity
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
            {"id": 1, "title": "", "date_iso": "2020-01-01", "url": "https://example.test/a"},
            {"id": 2, "title": "", "date_iso": "2020-01-01", "url": "https://example.test/b"},
        ]
        ranked = app.sort_scoped_posts(rows, "ranked", [])
        oldest = app.sort_scoped_posts(rows, "oldest", [])
        self.assertEqual([row["url"] for row in ranked], ["https://example.test/b", "https://example.test/a"])
        self.assertEqual([row["url"] for row in oldest], ["https://example.test/a", "https://example.test/b"])

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
