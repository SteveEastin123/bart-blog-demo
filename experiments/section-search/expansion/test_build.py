"""Offline checks for section coverage, receipt integrity, and immutable output."""

import base64
import copy
import json
from pathlib import Path
import tempfile
import unittest

import build as b


class ExpansionTests(unittest.TestCase):
    def rows(self, text):
        return list(b.corpus.chunks(text, b.TARGET))

    def test_coverage_for_varied_lengths(self):
        for length in (1, 99, 100, 239, 240, 299, 300, 301, 360, 361, 1000, 8000):
            text = " ".join("word" + str(i) for i in range(length))
            b.validate_chunks(text, self.rows(text))

    def test_paragraphs_and_whitespace(self):
        text = "\n\n".join("  ".join("w" + str(i) for i in range(n, n + 113)) for n in range(0, 1130, 113))
        b.validate_chunks(text, self.rows(text))

    def test_reject_missing_tail(self):
        text = "word " * 1000
        with self.assertRaises(ValueError):
            b.validate_chunks(text, self.rows(text)[:-1])

    def test_reject_changed_passage(self):
        text = "word " * 1000
        rows = self.rows(text)
        rows[0]["text"] = "Changed"
        with self.assertRaises(ValueError):
            b.validate_chunks(text, rows)

    def test_reject_bad_overlap(self):
        text = "word " * 1000
        rows = self.rows(text)
        rows[1]["startWord"] += 1
        rows[1]["text"] = " ".join(rows[1]["text"].split()[1:])
        with self.assertRaises(ValueError):
            b.validate_chunks(text, rows)

    def test_reject_empty_text(self):
        with self.assertRaises(ValueError):
            b.validate_chunks("", [])

    def test_vector_round_trip(self):
        value = [1.0] + [0.0] * 511
        encoded = base64.b64encode(b.vector_bytes(value)).decode("ascii")
        self.assertEqual(encoded, b.decode_vector(encoded))

    def test_reject_invalid_vectors(self):
        for value in ([0.0] * 512, [1.0] * 512, [1.0] * 511,
                      [float("nan")] + [0.0] * 511, [float("inf")] + [0.0] * 511,
                      [True] + [0.0] * 511, ["1"] + [0.0] * 511):
            with self.subTest(value=str(value[:1])):
                with self.assertRaises(ValueError):
                    b.vector_bytes(value)

    def test_reject_invalid_encoded_vectors(self):
        for value in ("!!!", base64.b64encode(b"short").decode("ascii")):
            with self.assertRaises(ValueError):
                b.decode_vector(value)

    def receipt(self):
        rows = [{"hash": b.corpus.digest(value), "input": value} for value in ("First", "Second")]
        receipt = {"requestHash": b.request_hash(b.payload(rows)), "model": b.MODEL,
                   "response": {"model": b.MODEL,
                                "data": [{"index": i, "embedding": [1.0] + [0.0] * 511} for i in (1, 0)],
                                "usage": {"prompt_tokens": 5, "total_tokens": 5}}}
        return rows, receipt

    def test_out_of_order_response_indices(self):
        rows, receipt = self.receipt()
        vectors, tokens = b.checked_receipt(receipt, rows)
        self.assertEqual(set(vectors), {r["hash"] for r in rows})
        self.assertEqual(tokens, 5)

    def test_reject_wrong_model(self):
        rows, receipt = self.receipt()
        receipt["response"]["model"] = "text-embedding-3-large"
        with self.assertRaises(ValueError):
            b.checked_receipt(receipt, rows)

    def test_reject_duplicate_response_indices(self):
        rows, receipt = self.receipt()
        receipt["response"]["data"][0]["index"] = 0
        with self.assertRaises(ValueError):
            b.checked_receipt(receipt, rows)

    def test_reject_wrong_request_hash(self):
        rows, receipt = self.receipt()
        rows.reverse()
        with self.assertRaises(ValueError):
            b.checked_receipt(receipt, rows)

    def test_reject_invalid_usage(self):
        rows, original = self.receipt()
        for usage in ({"prompt_tokens": 0, "total_tokens": 0},
                      {"prompt_tokens": 5, "total_tokens": 6},
                      {"prompt_tokens": True, "total_tokens": True}):
            receipt = copy.deepcopy(original)
            receipt["response"]["usage"] = usage
            with self.assertRaises(ValueError):
                b.checked_receipt(receipt, rows)

    def test_frozen_output_is_idempotent(self):
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / "frozen.json"
            b.save(path, {"a": 1})
            b.save(path, {"a": 1})
            self.assertEqual(json.loads(path.read_text()), {"a": 1})
            with self.assertRaises(ValueError):
                b.save(path, {"a": 2})

    def test_gzip_is_deterministic(self):
        with tempfile.TemporaryDirectory() as name:
            path = Path(name) / "rows.jsonl.gz"
            b.gzip_rows(path, [{"a": 1}])
            b.gzip_rows(path, [{"a": 1}])

    def test_payload_uses_512_and_exact_input(self):
        rows, _ = self.receipt()
        body = b.payload(rows)
        self.assertEqual(body["dimensions"], 512)
        self.assertEqual(body["input"], [r["input"] for r in rows])
        self.assertEqual(body["model"], "text-embedding-3-small")


if __name__ == "__main__":
    unittest.main()
