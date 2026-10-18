"""Read-only integrity and pricing checks for the frozen evidence run."""

import unittest

from corpus import HERE, PRIVATE, read
from evidence import ARMS, PUBLIC, WORK, fingerprint, usage_cost


class EvidenceTests(unittest.TestCase):
    def test_cost_accounts_for_cache(self):
        self.assertAlmostEqual(usage_cost({"input_tokens": 1000, "input_tokens_details": {"cached_tokens": 500},
                                          "output_tokens": 100}), .0008625)

    def test_frozen_inputs(self):
        if not (PUBLIC / "manifest.json").exists():
            self.skipTest("Run not prepared")
        manifest = read(PUBLIC / "manifest.json")
        self.assertEqual(len(manifest["files"]), 50)
        self.assertEqual(manifest["originalResultsHash"], fingerprint(HERE / "results.json"))
        self.assertEqual(manifest["originalQuestionsHash"], fingerprint(HERE / "questions.json"))
        self.assertEqual(manifest["corpusHash"], fingerprint(PRIVATE / "corpus.json"))
        for name, digest in manifest["files"].items():
            path = WORK / "fixtures" / name
            self.assertEqual(fingerprint(path), digest)
            row = read(path)
            self.assertEqual(len(row["candidates"]), 100)
            self.assertEqual(len({p["wpId"] for p in row["candidates"]}), 100)
            self.assertTrue(all(p["passage"] for p in row["candidates"]))

    def test_completed_result_integrity(self):
        if not (PUBLIC / "results.json").exists():
            self.skipTest("Run not yet complete")
        result = read(PUBLIC / "results.json")
        self.assertEqual(len(result["questions"]), 50)
        self.assertEqual(sum(result["comparison"][k] for k in ("passageWins", "summaryWins", "ties")), 50)
        for question in result["questions"]:
            left, right = [question["versions"][arm] for arm in ARMS]
            self.assertEqual(left["candidateHash"], right["candidateHash"])
            self.assertEqual(left["promptHash"], right["promptHash"])
            self.assertTrue(all(g["evidenceVerified"] for g in question["grades"]))
            self.assertGreater(right["usage"]["input_tokens"], left["usage"]["input_tokens"])
            for arm in ARMS:
                saved = read(WORK / "reviews" / f"q{question['id']:02}-{arm}.json")
                self.assertFalse(saved["cacheHit"])
                self.assertEqual(saved["evidence"], arm == "passage")


if __name__ == "__main__":
    unittest.main()
