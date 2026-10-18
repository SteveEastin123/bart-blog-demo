"""Offline embedding identity, response validation, and resume tests."""

import copy
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

import agent_work
import embed_agent as embed


class AgentEmbeddingTests(unittest.TestCase):
    def setUp(self):
        self.batch = [{"wpId": str(i), "content": f"Title: Post {i}\nSummary: Examines evidence.",
                       "contentHash": embed.sha(str(i)), "sourceQuotes": ["AUDIT ONLY"]} for i in range(2)]
        vector = [1.0] + [0.0] * 511
        self.record = {"requestHash": embed.request_hash(self.batch), "model": embed.MODEL,
                       "response": {"model": embed.MODEL, "usage": {"prompt_tokens": 20, "total_tokens": 20},
                                    "data": [{"index": 1, "embedding": vector}, {"index": 0, "embedding": vector}]}}

    def test_input_excludes_audit_quotes(self):
        payload = embed.payload_for(self.batch)
        self.assertEqual(payload["input"], [r["content"] for r in self.batch])
        self.assertNotIn("AUDIT ONLY", str(payload))

    def test_response_maps_out_of_order_indices(self):
        vectors = embed.validate_response(self.record, self.batch)
        self.assertEqual(set(vectors), {"0", "1"})
        self.assertEqual(vectors["1"]["contentHash"], self.batch[1]["contentHash"])
        self.assertEqual(len(embed.decode_vector(vectors["0"]["embedding"])), 512)

    def test_invalid_vectors_are_rejected(self):
        for vector in ([0.0] * 512, [float("nan")] * 512, [float("inf")] * 512,
                       [0.0] * 511, [True] + [0.0] * 511):
            with self.subTest(vector=vector[:1]), self.assertRaises(ValueError):
                embed.vector_norm(vector)

    def test_wrong_request_model_and_usage_rejected(self):
        for field in ("request", "model", "usage", "response-model"):
            record = copy.deepcopy(self.record)
            if field == "request":
                record["requestHash"] = "wrong"
            elif field == "model":
                record["model"] = "other-model"
            elif field == "response-model":
                record["response"]["model"] = "other-model"
            else:
                record["response"]["usage"]["prompt_tokens"] = 0
            with self.subTest(field=field), self.assertRaises(ValueError):
                embed.validate_response(record, self.batch)

    def test_duplicate_or_missing_indices_rejected(self):
        for indices in ([0, 0], [0], [False, 1], [0, 2]):
            record = copy.deepcopy(self.record)
            record["response"]["data"] = [{"index": i, "embedding": [1.0] + [0.0] * 511} for i in indices]
            with self.subTest(indices=indices), self.assertRaises(ValueError):
                embed.validate_response(record, self.batch)

    def test_resume_uses_receipts_without_more_calls(self):
        with tempfile.TemporaryDirectory() as folder:
            caller = Mock(return_value=self.record)
            first = embed.collect(self.batch, Path(folder), caller)
            caller.assert_called_once()
            caller.reset_mock()
            self.assertEqual(embed.collect(self.batch, Path(folder), caller), first)
            caller.assert_not_called()
            self.assertEqual(embed.collect(self.batch, Path(folder)), first)

    def test_verify_never_fills_missing_responses(self):
        with tempfile.TemporaryDirectory() as folder, self.assertRaises(ValueError):
            embed.collect(self.batch, Path(folder))

    def test_modified_vector_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as folder:
            work = Path(folder)
            embed.collect(self.batch, work, Mock(return_value=self.record))
            path = work / "vectors/0.json"
            changed = agent_work.read(path)
            changed["contentHash"] = "wrong"
            agent_work.save(path, changed)
            with self.assertRaises(ValueError):
                embed.collect(self.batch, work)
            with self.assertRaises(ValueError):
                embed.collect(self.batch, work, Mock(return_value=self.record))

    def test_packed_size_is_checked(self):
        with self.assertRaises(ValueError):
            embed.decode_vector("AA==")


if __name__ == "__main__":
    unittest.main()
