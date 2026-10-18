"""Offline identity and accounting tests for the paired summary experiment."""

import copy
import unittest

import compare_agent as compare
from evaluate import metrics


class ComparisonTests(unittest.TestCase):
    def setUp(self):
        self.question = {"id": 1, "question": "A fixed question?"}
        self.manifest = {"sampleHash": "sample", "promptHash": "prompt", "settingsHash": "settings",
                         "embeddingModel": "text-embedding-3-small", "reviewerModel": "gpt-5.4-mini"}
        usage = [{"model": "text-embedding-3-small", "input_tokens": 20, "cached_input_tokens": 0, "output_tokens": 0},
                 {"model": "gpt-5.4-mini-2026-03-17", "input_tokens": 1000, "cached_input_tokens": 100, "output_tokens": 100}]
        for row in usage:
            row["estimated_cost_usd"] = compare.phase_cost(row)
        self.run = {"run": compare.RUN, "questionId": 1, "question": self.question["question"], "arm": "ask-ai-2",
                    "sampleHash": "sample", "sampleCount": 500, "cacheHit": False, "broaderFallback": False,
                    "promptHash": "prompt", "settingsHash": "settings", "usage": usage,
                    "costUsd": sum(u["estimated_cost_usd"] for u in usage), "submissionToResultsSeconds": 5.0,
                    "candidates": [{"wpId": str(i)} for i in range(100)], "posts": [{"wpId": "0"}]}

    def test_valid_search(self):
        compare.validate_run(self.run, self.question, "ask-ai-2", self.manifest)

    def test_changed_or_cached_configuration_rejected(self):
        for field, value in (("question", "different"), ("sampleCount", 499), ("cacheHit", True),
                             ("promptHash", "other"), ("settingsHash", "other"), ("costUsd", 0),
                             ("submissionToResultsSeconds", 0)):
            changed = copy.deepcopy(self.run)
            changed[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                compare.validate_run(changed, self.question, "ask-ai-2", self.manifest)

    def test_missing_phase_rejected(self):
        self.run["usage"].pop()
        with self.assertRaises(ValueError):
            compare.validate_run(self.run, self.question, "ask-ai-2", self.manifest)

    def test_duplicate_or_unretrieved_posts_rejected(self):
        for posts in ([{"wpId": "0"}, {"wpId": "0"}], [{"wpId": "999"}]):
            self.run["posts"] = posts
            with self.assertRaises(ValueError):
                compare.validate_run(self.run, self.question, "ask-ai-2", self.manifest)

    def test_wrong_summary_rejected(self):
        self.run["candidates"] = [{"wpId": "0", "title": "Title", "summary": "New"}]
        self.run["posts"] = []
        with self.assertRaises(ValueError):
            compare.validate_metadata(self.run, {"0": {"title": "Title", "searchSummary": "Old"}}, {})

    def test_fixed_denominators_and_common_ideal(self):
        scores = metrics(self.run, {"0": 3, "1": 3})
        self.assertEqual(scores["precisionAt5"], .2)
        self.assertEqual(scores["precisionAt10"], .1)
        self.assertLess(scores["ndcgAt10"], 1)


if __name__ == "__main__":
    unittest.main()
