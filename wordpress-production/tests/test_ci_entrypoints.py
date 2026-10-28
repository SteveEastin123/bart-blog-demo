"""Exercise the shell and Compose boundaries used by GitHub Actions."""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


PRODUCTION = Path(__file__).resolve().parents[1]


def run_like_actions(script):
    quoted = str(script).replace("'", "''")
    # Match Actions' PowerShell error handling and last-native-exit-code footer.
    command = (
        "$ErrorActionPreference = 'Stop'; "
        f"& '{quoted}'; "
        "if (Test-Path variable:LASTEXITCODE) { exit $LASTEXITCODE }"
    )
    return subprocess.run(
        ["pwsh", "-NoProfile", "-NonInteractive", "-Command", command],
        capture_output=True, text=True, timeout=60,
    )


class CiEntrypointTests(unittest.TestCase):
    def test_expected_failures_do_not_fail_the_ci_step(self):
        result = run_like_actions(PRODUCTION / "tests/regression-runner-tests.ps1")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("Database comparison:", result.stdout)

    def test_real_assertion_failure_still_fails_the_ci_step(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "tests").mkdir()
            for name in ("regression-report.psm1", "compare-database-snapshots.ps1"):
                shutil.copyfile(PRODUCTION / name, root / name)
            source = (PRODUCTION / "tests/regression-runner-tests.ps1").read_text(encoding="utf-8")
            assertion = "Assert-Runner ($success.status -eq 'passed')"
            self.assertEqual(source.count(assertion), 1)
            script = root / "tests/regression-runner-tests.ps1"
            script.write_text(source.replace(assertion, "Assert-Runner $false"), encoding="utf-8")
            result = run_like_actions(script)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("Successful commands were not reported as passed.", result.stderr)

    def test_mariadb_projects_reuse_the_built_wordpress_image(self):
        images = []
        for project in ("ehrman-ci-build-check", "ehrman-ci-isolated-check"):
            result = subprocess.run(
                ["docker", "compose", "-p", project, "-f", str(PRODUCTION / "compose.local.yaml"),
                 "config", "--format", "json"],
                capture_output=True, text=True, timeout=30,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            services = json.loads(result.stdout)["services"]
            self.assertEqual(set(services), {"wordpress", "database"})
            self.assertTrue(services["database"]["image"].startswith("mariadb:"))
            service = services["wordpress"]
            self.assertIn("build", service)
            images.append(service.get("image"))
        self.assertEqual(images, ["ehrman-wordpress-production-test-wordpress"] * 2)


if __name__ == "__main__":
    unittest.main()
