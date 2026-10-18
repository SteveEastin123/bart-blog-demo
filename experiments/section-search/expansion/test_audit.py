"""Offline tampering tests using a tiny package isolated from the live run."""

from contextlib import ExitStack, redirect_stdout, redirect_stderr
import copy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import audit as a


class AuditTests(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.root = Path(self.stack.enter_context(tempfile.TemporaryDirectory()))
        section = self.root / "experiments/section-search"
        for name, value in {"ROOT": self.root, "SECTION": section,
                            "WORK": section / "private" / a.b.RUN,
                            "PUBLIC": section / "runs" / a.b.RUN}.items():
            self.stack.enter_context(patch.object(a.b, name, value))
        self.network = self.stack.enter_context(patch.object(a.b.api, "call", side_effect=AssertionError("No API")))
        self.http = self.stack.enter_context(patch.object(a.b.api.urllib.request, "urlopen",
                                                       side_effect=AssertionError("No HTTP")))
        self.stack.enter_context(patch.object(a.b, "pilot_vectors", side_effect=dict))
        for name in (*a.AUDITORS, *a.GENERATORS, a.PILOT_PATH):
            a.b.frozen_bytes(self.root / name, name.encode("ascii"))

        text = " ".join("word" + str(i) for i in range(100))
        post = {"wpId": "123", "title": "Fixture", "url": "https://example.invalid/fixture",
                "textHash": a.b.corpus.digest(text), "wordCount": 100}
        self.stack.enter_context(patch.object(a.b.corpus, "source_posts",
                                             return_value=([{**post, "text": text}], {})))
        value = "Title: Fixture\nPassage: " + text
        row = {"wpId": "123", "size": 300, "section": 0, "startWord": 0, "endWord": 100,
               "text": text, "input": value, "hash": a.b.corpus.digest(value)}
        a.b.save(a.b.WORK / "prepared.json", {"posts": [post], "sections": [row], "excludedPostIds": {}})
        manifest = {
            "run": a.b.RUN, "model": a.b.MODEL, "dimensions": 512, "targetWords": 300,
            "overlapWords": 60, "batchSize": 32, "remainingPostCount": 1, "sectionCount": 1,
            "uniqueInputs": 1, "newEmbeddingInputs": 1, "reusedPilotInputs": 0,
            "preparedHash": a.b.digest(a.b.WORK / "prepared.json"),
            "preservedFiles": {a.PILOT_PATH: a.b.digest(self.root / a.PILOT_PATH)},
            "generatorFiles": {name: a.b.digest(self.root / name) for name in a.GENERATORS},
        }
        a.b.save(a.b.PUBLIC / "manifest.json", manifest)
        request_hash = a.b.request_hash(a.b.payload([row]))
        self.receipt_path = a.b.WORK / "embedding-cache" / (request_hash + ".json")
        a.b.save(self.receipt_path, {
            "label": a.b.RUN, "model": a.b.MODEL, "requestHash": request_hash,
            "response": {"model": a.b.MODEL, "usage": {"prompt_tokens": 5, "total_tokens": 5},
                         "data": [{"index": 0, "embedding": [1.0] + [0.0] * 511}]},
        })
        with redirect_stdout(io.StringIO()):
            a.b.package()
        self.path = a.b.WORK / "package/manifest.json"
        self.record = a.read_json(self.path)

    def write_json(self, path, value):
        path.write_text(json.dumps(value), encoding="utf-8")

    def reject_record(self, record):
        self.write_json(self.path, record)
        with self.assertRaises(ValueError):
            a.check_manifest()

    def test_complete_offline_audit_and_idempotent_report(self):
        report = a.audit_package()
        self.assertEqual(report, a.audit_package())
        self.assertEqual(report, a.read_json(a.b.PUBLIC / "validation.json"))
        self.assertEqual(report["auditorFiles"], a.auditor_hashes())
        self.assertEqual(report["packageManifestSha256"], a.b.digest(self.path))
        self.assertEqual(report["usageSha256"], a.b.digest(a.b.PUBLIC / "usage.json"))
        self.assertEqual(report["resultsSha256"], a.b.digest(a.b.PUBLIC / "results.json"))
        self.assertIs(report["receiptsAndOutputsVerified"], True)
        self.assertIs(report["apiCallsAllowed"], False)
        self.network.assert_not_called()
        self.http.assert_not_called()

    def test_missing_or_extra_manifest_fields(self):
        for key in self.record:
            with self.subTest(missing=key):
                record = copy.deepcopy(self.record)
                del record[key]
                self.reject_record(record)
        self.reject_record({**self.record, "unexpected": True})

    def test_wrong_scalar_fields(self):
        changes = {"version": 2, "run": "other-run", "model": "text-embedding-3-large",
                   "dimensions": 1536, "targetWords": 400, "overlapWords": 80,
                   "encoding": "base64 big-endian float32", "postCount": 2, "sectionCount": 2,
                   "existingPilotExcluded": False, "requiresExplicitIntegration": False,
                   "manifestSha256": "0" * 64, "preparedSha256": "0" * 64}
        for key, value in changes.items():
            with self.subTest(field=key):
                self.reject_record({**self.record, key: value})

    def test_scalar_type_confusion(self):
        for key in ("version", "dimensions", "targetWords", "overlapWords", "postCount", "sectionCount",
                    "existingPilotExcluded", "requiresExplicitIntegration"):
            for value in (str(self.record[key]), float(self.record[key]), None):
                with self.subTest(field=key, value=value):
                    self.reject_record({**self.record, key: value})
        self.reject_record({**self.record, "existingPilotExcluded": 1})
        self.reject_record({**self.record, "requiresExplicitIntegration": 1})
        self.reject_record({**self.record, "postCount": True})

    def test_exact_declared_file_set(self):
        for files in ({}, {"posts.jsonl.gz": self.record["files"]["posts.jsonl.gz"]},
                      {**self.record["files"], "../escape": {"sha256": "0" * 64, "bytes": 1}}):
            with self.subTest(files=list(files)):
                self.reject_record({**self.record, "files": files})

    def test_file_metadata(self):
        for name in a.DATA_FILES:
            for field, value in (("sha256", "0" * 64), ("bytes", 0), ("bytes", -1),
                                 ("bytes", True), ("bytes", float(self.record["files"][name]["bytes"]))):
                with self.subTest(file=name, field=field, value=value):
                    record = copy.deepcopy(self.record)
                    record["files"][name][field] = value
                    self.reject_record(record)
            for field in ("sha256", "bytes"):
                record = copy.deepcopy(self.record)
                del record["files"][name][field]
                self.reject_record(record)
            record = copy.deepcopy(self.record)
            record["files"][name]["extra"] = True
            self.reject_record(record)

    def test_pilot_provenance(self):
        for pilot in ({}, {"path": "../other", "sha256": self.record["pilotPackage"]["sha256"]},
                      {"path": a.PILOT_PATH, "sha256": "0" * 64},
                      {**self.record["pilotPackage"], "extra": True}):
            with self.subTest(pilot=pilot):
                self.reject_record({**self.record, "pilotPackage": pilot})

    def test_missing_package_file(self):
        (a.b.WORK / "package/posts.jsonl.gz").unlink()
        with self.assertRaisesRegex(ValueError, "file set"):
            a.check_manifest()

    def test_unexpected_physical_file(self):
        (a.b.WORK / "package/extra.partial").write_bytes(b"incomplete")
        with self.assertRaisesRegex(ValueError, "file set"):
            a.check_manifest()

    def test_missing_package_never_builds_or_calls(self):
        with patch.object(a.b, "WORK", self.root / "missing"), patch.object(a.b, "verify") as verify:
            with self.assertRaisesRegex(ValueError, "Completed package"):
                a.audit_package()
            verify.assert_not_called()
        self.network.assert_not_called()

    def test_missing_receipt_never_calls_api_or_writes_success(self):
        self.receipt_path.unlink()
        with self.assertRaisesRegex(ValueError, "Missing saved receipt"):
            a.audit_package()
        self.assertFalse((a.b.PUBLIC / "validation.json").exists())
        self.network.assert_not_called()

    def test_malformed_receipt_rejected(self):
        receipt = a.read_json(self.receipt_path)
        receipt["response"]["data"][0]["embedding"] = [1.0]
        self.write_json(self.receipt_path, receipt)
        with self.assertRaisesRegex(ValueError, "dimensions"):
            a.audit_package()
        self.assertFalse((a.b.PUBLIC / "validation.json").exists())

    def test_package_data_tampering_rejected(self):
        with (a.b.WORK / "package/posts.jsonl.gz").open("ab") as handle:
            handle.write(b"tampered")
        with self.assertRaises(ValueError):
            a.audit_package()

    def test_frozen_generator_or_pilot_change_rejected(self):
        for name in (*a.GENERATORS, a.PILOT_PATH):
            path = self.root / name
            original = path.read_bytes()
            path.write_bytes(b"changed")
            with self.subTest(path=name), self.assertRaisesRegex(ValueError, "changed"):
                a.check_manifest()
            path.write_bytes(original)

    def test_prepared_data_change_rejected(self):
        (a.b.WORK / "prepared.json").write_bytes(b"{}")
        with self.assertRaisesRegex(ValueError, "preparedHash"):
            a.check_manifest()

    def test_duplicate_and_nonfinite_json_rejected(self):
        for text in ('{"version":1,"version":1}', '{"version":NaN}', '{"version":Infinity}'):
            self.path.write_text(text, encoding="utf-8")
            with self.subTest(text=text), self.assertRaises(ValueError):
                a.check_manifest()

    def test_network_guard_blocks_both_entry_points(self):
        for action in (lambda: a.b.api.call("embeddings", {}, "forbidden"),
                       lambda: a.b.api.urllib.request.urlopen("https://example.invalid")):
            with patch.object(a.b, "verify", side_effect=action):
                with self.assertRaisesRegex(RuntimeError, "forbids"):
                    a.audit_package()
        self.network.assert_not_called()
        self.http.assert_not_called()
        self.assertFalse((a.b.PUBLIC / "validation.json").exists())

    def test_verify_failure_does_not_write_success(self):
        with patch.object(a.b, "verify", side_effect=ValueError("Invalid outputs")):
            with self.assertRaisesRegex(ValueError, "Invalid outputs"):
                a.audit_package()
        self.assertFalse((a.b.PUBLIC / "validation.json").exists())

    def test_changed_manifest_during_verification_rejected(self):
        def change():
            self.write_json(self.path, {**self.record, "dimensions": 1536})

        with patch.object(a.b, "verify", side_effect=change):
            with self.assertRaises(ValueError):
                a.audit_package()
        self.assertFalse((a.b.PUBLIC / "validation.json").exists())

    def test_auditor_change_cannot_overwrite_attestation(self):
        a.audit_package()
        path = a.b.PUBLIC / "validation.json"
        before = path.read_bytes()
        (self.root / a.AUDITORS[0]).write_bytes(b"new auditor version")
        with self.assertRaisesRegex(ValueError, "Refusing to overwrite"):
            a.audit_package()
        self.assertEqual(path.read_bytes(), before)

    def test_cli_requires_offline_and_emits_success_marker(self):
        with redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            a.main([])
        output = io.StringIO()
        with redirect_stdout(output):
            a.main(["--offline"])
        self.assertEqual(output.getvalue().strip(), a.SUCCESS_MARKER + " " + str(a.b.PUBLIC / "validation.json"))


if __name__ == "__main__":
    unittest.main()
