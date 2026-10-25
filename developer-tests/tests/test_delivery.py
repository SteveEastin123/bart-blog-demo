import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


compare = module("test_compare", ROOT / "developer-tests/compare.py")
packager = module("test_packager", ROOT / "scripts/package_wordpress_tests.py")


def capture():
    cases = json.loads((ROOT / "developer-tests/cases/search.json").read_text())
    return {
        "schemaVersion": 1, "testCases": cases,
        "dataFingerprint": {"sha256": "fixture"}, "counts": {"posts": 2},
        "results": [{"id": case["id"], "operation": case["operation"], "ok": True,
                     "posts": [{"id": 1}, {"id": 2}]} for case in cases],
    }


class DeveloperDeliveryTests(unittest.TestCase):
    def test_equal_results_and_different_runtimes(self):
        a, b = capture(), capture()
        b.update(runtime={"php": "8.3"}, commit="new-commit")
        self.assertEqual(compare.compare(a, b), [])

    def test_order_counts_fingerprints_and_cases_are_significant(self):
        original = capture()
        changes = [lambda b: b["results"][0]["posts"].reverse(),
                   lambda b: b["counts"].update(posts=3),
                   lambda b: b["dataFingerprint"].update(sha256="different"),
                   lambda b: b["testCases"][0].update(terms=["different"])]
        for mutate in changes:
            candidate = copy.deepcopy(original)
            mutate(candidate)
            self.assertTrue(compare.compare(original, candidate))

    def test_invalid_incomplete_failed_and_duplicate_cases(self):
        invalid = [{}, {"schemaVersion": 2}, capture(), capture(), capture(), capture()]
        invalid[2]["results"] = []
        invalid[3]["results"][0]["ok"] = False
        invalid[4]["testCases"][1]["id"] = invalid[4]["testCases"][0]["id"]
        invalid[5]["results"].reverse()
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "capture.json"
            for value in invalid:
                file.write_text(json.dumps(value))
                with self.assertRaises(ValueError):
                    compare.read_capture(file)

    def test_standalone_cli_exit_codes(self):
        with tempfile.TemporaryDirectory() as directory:
            baseline, actual = Path(directory) / "base.json", Path(directory) / "actual.json"
            baseline.write_text(json.dumps(capture()))
            actual.write_bytes(baseline.read_bytes())
            command = [sys.executable, "-B", str(ROOT / "developer-tests/compare.py"), str(baseline), str(actual)]
            self.assertEqual(subprocess.run(command, capture_output=True).returncode, 0)
            changed = capture()
            changed["counts"]["posts"] = 3
            actual.write_text(json.dumps(changed))
            self.assertEqual(subprocess.run(command, capture_output=True).returncode, 1)
            actual.write_text("invalid")
            self.assertEqual(subprocess.run(command, capture_output=True).returncode, 2)

    def test_packaged_delivery_and_no_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            baseline, output = Path(directory) / "baseline.json", Path(directory) / "bundle.zip"
            baseline.write_text(json.dumps(capture()))
            packager.build(output, baseline)
            with zipfile.ZipFile(output) as archive:
                names = archive.namelist()
                self.assertIn("ehrman-discovery-developer-tests/baseline.json", names)
                self.assertIn("ehrman-discovery-developer-tests/regressions/search-page-tests.php", names)
                self.assertIn("ehrman-discovery-developer-tests/regressions/install-data-tests.php", names)
                self.assertFalse(any("reviewer" in name or "experiments" in name or "data/raw" in name for name in names))
                archive.extractall(Path(directory) / "unpacked")
            unpacked = Path(directory) / "unpacked/ehrman-discovery-developer-tests"
            result = subprocess.run([sys.executable, "-B", str(unpacked / "compare.py"),
                                     str(unpacked / "baseline.json"), str(unpacked / "baseline.json")], capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            with self.assertRaises(FileExistsError):
                packager.build(output, baseline)

    def test_missing_required_file_fails_packaging(self):
        original = Path.read_bytes
        def missing(file):
            if file.name == "Parity_Service.php":
                raise FileNotFoundError("fixture missing")
            return original(file)
        with patch.object(Path, "read_bytes", missing):
            with self.assertRaises(FileNotFoundError):
                packager.payloads()

    def test_baseline_case_mismatch_fails_packaging(self):
        with tempfile.TemporaryDirectory() as directory:
            value = capture()
            value["testCases"][0]["terms"] = ["other"]
            baseline = Path(directory) / "baseline.json"
            baseline.write_text(json.dumps(value))
            with self.assertRaises(ValueError):
                packager.build(Path(directory) / "bad.zip", baseline)


if __name__ == "__main__":
    unittest.main()
