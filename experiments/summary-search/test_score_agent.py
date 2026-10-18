"""Offline tests for full-post evidence and common-pool grading."""

import copy
import unittest

import score_agent as score


class GradingTests(unittest.TestCase):
    def setUp(self):
        self.source = "Paul argues that resurrection is bodily. This is a substantial section."
        self.row = {"wpId": "1", "grade": 3, "reason": "Directly answers the question.",
                    "evidence": "resurrection is bodily", "textHash": "hash"}
        self.question = {"id": 1, "question": "How does Paul describe resurrection?"}

    def test_positive_grade_needs_exact_evidence(self):
        score.check_grade(self.row, self.source, strict=True)
        for phrase in ("", "resurrection is not bodily"):
            changed = dict(self.row, evidence=phrase)
            with self.assertRaises(ValueError):
                score.check_grade(changed, self.source, strict=True)

    def test_irrelevant_can_have_no_evidence(self):
        score.check_grade(dict(self.row, grade=0, evidence=""), self.source, strict=True)

    def test_fractional_or_boolean_grades_rejected(self):
        for grade in (1.5, True, 4):
            with self.assertRaises(ValueError):
                score.check_grade(dict(self.row, grade=grade), self.source)

    def test_pool_includes_both_final_and_candidate_results(self):
        runs = {"a": {"posts": [{"wpId": "1"}], "candidates": [{"wpId": "2"}]},
                "b": {"posts": [{"wpId": "1"}], "candidates": [{"wpId": "3"}]}}
        self.assertEqual(score.pooled_ids(runs), {"1", "2", "3"})

    def test_pool_identity_ignores_order_not_question(self):
        self.assertEqual(score.pool_identity(self.question, ["2", "1"]), score.pool_identity(self.question, ["1", "2"]))
        self.assertNotEqual(score.pool_identity(self.question, ["1"]), score.pool_identity({**self.question, "question": "Other?"}, ["1"]))

    def test_duplicate_and_changed_source_rejected(self):
        judged = {**self.question, "poolHash": score.pool_identity(self.question, {"1"}), "grades": [self.row]}
        corpus = {"1": {"text": self.source, "textHash": "hash"}}
        score.validate_grades(judged, self.question, {"1"}, corpus)
        duplicate = copy.deepcopy(judged)
        duplicate["grades"].append(self.row)
        with self.assertRaises(ValueError):
            score.validate_grades(duplicate, self.question, {"1"}, corpus)
        corpus["1"]["textHash"] = "changed"
        with self.assertRaises(ValueError):
            score.validate_grades(judged, self.question, {"1"}, corpus)


if __name__ == "__main__":
    unittest.main()
