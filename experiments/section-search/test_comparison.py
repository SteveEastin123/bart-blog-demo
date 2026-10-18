import copy
import unittest

import comparison
from corpus import read
from evaluate import metrics


class ComparisonTests(unittest.TestCase):
    def test_questions(self):
        rows = read(comparison.PUBLIC / "questions.json")["questions"]
        self.assertEqual([q["id"] for q in rows], list(range(1, 51)))
        self.assertEqual(len({q["question"].casefold() for q in rows}), 50)
        self.assertEqual(sum(q["group"] == "representative" for q in rows), 40)
        self.assertEqual(sum(q["group"] == "challenge" for q in rows), 10)

    def test_nested_question_extraction(self):
        self.assertEqual(list(comparison.questions_in({"questions": [{"question": "one"}, {"question": "two"}]})), ["one", "two"])

    def test_frozen_fixture_bytes(self):
        manifest = comparison.integrity()
        public = comparison.PUBLIC / "questions.json"
        fixture = comparison.WORK / "fixtures/questions.json"
        self.assertEqual(read(public), read(fixture))
        self.assertEqual(comparison.fingerprint(public), manifest["questionsHash"])
        self.assertEqual(comparison.fingerprint(fixture), manifest["fixtureQuestionsHash"])

    def test_run_guards(self):
        question = {"id": 1, "question": "test"}
        manifest = {"sampleHash": "frozen", "embeddingModel": "text-embedding-3-small", "reviewerModel": "gpt-5.4-mini"}
        result = {"run": comparison.RUN, "questionId": 1, "question": "test", "arm": "ask-ai-2", "sampleHash": "frozen",
                  "cacheHit": False, "controlled": True, "evidence": False, "candidates": [{"wpId": str(i)} for i in range(100)],
                  "costUsd": .011, "usage": [{"model": "text-embedding-3-small", "estimated_cost_usd": .001},
                                              {"model": "gpt-5.4-mini-2026-03-17", "estimated_cost_usd": .01}]}
        comparison.validate_run(result, question, "ask-ai-2", manifest)
        for key, value in [("cacheHit", True), ("evidence", True), ("sampleHash", "changed"), ("costUsd", .01), ("controlled", False)]:
            changed = copy.deepcopy(result)
            changed[key] = value
            with self.assertRaises(ValueError):
                comparison.validate_run(changed, question, "ask-ai-2", manifest)

    def test_pooled_metrics_and_missing_slots(self):
        run = {"posts": [{"wpId": "one"}], "submissionToResultsSeconds": 2, "costUsd": .01, "broaderFallback": False}
        score = metrics(run, {"one": 3, "two": 2})
        self.assertEqual(score["precisionAt5"], .2)
        self.assertEqual(score["precisionAt10"], .1)
        self.assertEqual(score["displayedPrecisionAt10"], 1)
        self.assertLess(score["ndcgAt10"], 1)

    def test_evidence_repair_is_explicit_and_source_verified(self):
        original = {"grade": 2, "evidence": "wrong source", "reason": "original"}
        repair = {"requestHash": "hash", "wpId": "one", "originalEvidence": "wrong source",
                  "evidence": "source phrase", "reason": "checked"}
        fixed = comparison.repair_evidence(original, "hash", "one", "A source phrase exists.", [repair])
        self.assertEqual(fixed["grade"], 2)
        self.assertEqual(fixed["originalJudgment"], original)
        self.assertEqual(original["evidence"], "wrong source")
        self.assertEqual(comparison.repair_evidence(original, "other", "one", "text", [repair]), original)
        with self.assertRaises(ValueError):
            comparison.repair_evidence(original, "hash", "one", "no quotation here", [repair])
        with self.assertRaises(ValueError):
            comparison.repair_evidence({**original, "evidence": "changed"}, "hash", "one", "source phrase", [repair])


if __name__ == "__main__":
    unittest.main()
