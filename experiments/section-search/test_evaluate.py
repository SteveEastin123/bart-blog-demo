"""Metric checks run without WordPress or paid services."""

import unittest

from evaluate import confidence_interval, metrics, verify_evidence
from corpus import HERE, PRIVATE, read


class MetricsTests(unittest.TestCase):
    def test_completed_report_integrity(self):
        if not (HERE / "results.json").exists():
            self.skipTest("The evaluation has not been run yet")
        results = read(HERE / "results.json")
        sample = read(HERE / "sample.json")
        self.assertEqual(len(results["questions"]), 50)
        self.assertEqual(results["sampleHash"], sample["sampleHash"])
        self.assertEqual(results["unverifiedEvidence"], [])
        for question in results["questions"]:
            self.assertEqual(set(question["versions"]), {"baseline", "300", "400", "500"})
            grades = {g["wpId"]: g["grade"] for g in question["grades"]}
            for mode, version in question["versions"].items():
                self.assertEqual(len(version["usage"]), 2)
                for post in version["posts"]:
                    self.assertEqual(post["grade"], grades[post["wpId"]])
                raw = PRIVATE / "runs" / f"q{question['id']:02}-{mode}.json"
                if raw.exists():
                    run = read(raw)
                    self.assertEqual(run["sampleHash"], sample["sampleHash"])
                    self.assertFalse(run["cacheHit"])
                    self.assertFalse(run["evidence"])
                    self.assertEqual(run["question"], question["question"])

    def test_evidence_checks_ignore_wrappers_not_words(self):
        self.assertTrue(verify_evidence('"There are good reasons for this."', 'There are good reasons for this.')[0])
        self.assertTrue(verify_evidence('"There are good reasons ... making the case more persuasive."',
                                        'There are good reasons for this, making the case more persuasive.')[0])
        self.assertFalse(verify_evidence('There are bad reasons for this.', 'There are good reasons for this.')[0])
        self.assertFalse(verify_evidence('making the case more persuasive ... There are good reasons',
                                         'There are good reasons for this, making the case more persuasive.')[0])

    def run_data(self, ids):
        return {"posts": [{"wpId": value} for value in ids], "submissionToResultsSeconds": 2.0,
                "costUsd": .01, "broaderFallback": False}

    def test_missing_slots_are_not_relevant(self):
        result = metrics(self.run_data(["a", "b"]), {"a": 3, "b": 2})
        self.assertEqual(result["precisionAt5"], .4)
        self.assertEqual(result["precisionAt10"], .2)
        self.assertEqual(result["displayedPrecisionAt10"], 1)
        self.assertEqual(result["ndcgAt10"], 1)

    def test_wrong_order_reduces_ndcg(self):
        result = metrics(self.run_data(["b", "a"]), {"a": 3, "b": 1})
        self.assertGreater(result["ndcgAt10"], 0)
        self.assertLess(result["ndcgAt10"], 1)

    def test_empty_and_irrelevant(self):
        self.assertEqual(metrics(self.run_data([]), {})["ndcgAt10"], 0)
        self.assertEqual(metrics(self.run_data(["a"]), {"a": 0})["ndcgAt10"], 0)

    def test_top_ten_only(self):
        grades = {str(i): 3 for i in range(11)}
        result = metrics(self.run_data(list(grades)), grades)
        self.assertEqual(result["returnedTop10"], 10)
        self.assertEqual(result["precisionAt10"], 1)

    def test_bootstrap_pairing_and_reproducibility(self):
        self.assertEqual(confidence_interval([0] * 50), {"mean": 0, "lower95": 0, "upper95": 0})
        result = confidence_interval([-.1, .1] * 25)
        self.assertLess(result["lower95"], 0)
        self.assertGreater(result["upper95"], 0)
        self.assertEqual(result, confidence_interval([-.1, .1] * 25))


if __name__ == "__main__":
    unittest.main()
