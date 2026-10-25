#!/usr/bin/env python3
"""Build the mandatory, separate developer-test delivery from an explicit allowlist."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import re
import zipfile
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "developer-tests/package-manifest.json"


def payloads(root: Path = ROOT) -> dict[str, bytes]:
    manifest = json.loads((root / "developer-tests/package-manifest.json").read_text(encoding="utf-8"))
    if manifest.get("schemaVersion") != 1 or manifest.get("archiveRoot") != "ehrman-discovery-developer-tests":
        raise ValueError("Unsupported developer-test manifest")
    files = manifest["files"]
    required = {"README.md", "capture.php", "run-regressions.php", "compare.py", "cases/search.json",
                "test-tools/src/Parity_Service.php", "test-tools/src/Parity_REST_Controller.php",
                "test-tools/src/Plugin.php", "test-tools/src/Harness.php",
                "test-tools/ehrman-discovery-test-tools.php", "regressions/plugin-regression-tests.php",
                "regressions/install-data-tests.php"}
    if not required <= files.keys():
        raise ValueError("Required developer testing deliverables are missing from the manifest")
    output: dict[str, bytes] = {}
    for destination, source in files.items():
        for name in (destination, source):
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or "\\" in name or ":" in name:
                raise ValueError(f"Unsafe package path: {name}")
        if not source.startswith(("developer-tests/", "wordpress-production/tests/")):
            raise ValueError(f"Source outside testing allowlist: {source}")
        if not destination.endswith((".md", ".php", ".py", ".json")):
            raise ValueError(f"Unexpected file type: {destination}")
        file = root / source
        if not file.resolve().is_relative_to(root.resolve()) or file.is_symlink():
            raise ValueError(f"Unsafe source file: {source}")
        output[destination] = file.read_bytes()
    # Missing transitive PHP partials must fail packaging, not fail on the recipient's site.
    for name, content in output.items():
        if name.startswith("regressions/") and name.endswith(".php"):
            for dependency in re.findall(rb"require __DIR__ \. '/([^']+)'", content):
                if f"regressions/{dependency.decode()}" not in output:
                    raise ValueError(f"Missing regression dependency: {dependency.decode()}")
    production = root / "wordpress-plugin/ehrman-blog-discovery"
    for file in production.rglob("*.php"):
        if "vendor" not in file.parts and re.search(r"\bParity_(Service|REST_Controller)\b|/parity/batch", file.read_text(encoding="utf-8")):
            raise ValueError(f"Test-only comparison code remains in production: {file}")
    return output


def build(output: Path, baseline_path: Path) -> None:
    files = payloads()
    spec = importlib.util.spec_from_file_location("ehrman_test_compare", ROOT / "developer-tests/compare.py")
    assert spec and spec.loader
    comparator = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(comparator)
    baseline = comparator.read_capture(baseline_path)
    if baseline["testCases"] != json.loads(files["cases/search.json"]):
        raise ValueError("The approved baseline does not match the packaged cases")
    files["baseline.json"] = baseline_path.read_bytes()
    plugin = (ROOT / "wordpress-plugin/ehrman-blog-discovery/ehrman-blog-discovery.php").read_text(encoding="utf-8")
    version = re.search(r"\* Version:\s+(\S+)", plugin).group(1)
    manifest = {
        "schemaVersion": 1,
        "pluginVersion": version,
        "baselineDataFingerprint": baseline["dataFingerprint"]["sha256"],
        "files": {name: {"bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()} for name, data in sorted(files.items())},
    }
    files["MANIFEST.json"] = (json.dumps(manifest, indent=2) + "\n").encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    # Never replace a release bundle silently.
    with zipfile.ZipFile(output, "x", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in sorted(files.items()):
            archive.writestr(f"ehrman-discovery-developer-tests/{name}", data)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None or len(archive.namelist()) != len(files):
            raise ValueError("Archive verification failed")
    print(f"Built and verified {output} ({len(files)} files, plugin {version})")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    if args.check:
        print(f"Developer-test delivery manifest verified: {len(payloads())} required files")
    else:
        if not args.baseline or not args.output:
            parser.error("--baseline and --output are required; capture an approved reference before packaging")
        build(args.output, args.baseline)


if __name__ == "__main__":
    main()
