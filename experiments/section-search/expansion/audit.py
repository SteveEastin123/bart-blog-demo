"""Independently audit a completed expansion package without API or runtime access."""

import argparse
from contextlib import redirect_stdout
import io
import json
import sys
from unittest.mock import patch

sys.dont_write_bytecode = True
import build as b


PILOT_PATH = "experiments/section-search/private/runtime/sections-300.jsonl.gz"
EXPANSION_PATH = "experiments/section-search/expansion/"
AUDITORS = (EXPANSION_PATH + "audit.py", EXPANSION_PATH + "test_audit.py")
GENERATORS = (EXPANSION_PATH + "build.py", EXPANSION_PATH + "test_build.py")
DATA_FILES = ("posts.jsonl.gz", "sections-300-512.jsonl.gz")
SUCCESS_MARKER = "EXPANSION_AUDIT_OK"


def exact(actual, expected, location):
    """JSON equality must not treat booleans or floats as integer metadata."""
    b.require(type(actual) is type(expected), "Wrong type: " + location)
    if isinstance(expected, dict):
        b.require(actual.keys() == expected.keys(), "Wrong fields: " + location)
        for key, value in expected.items():
            exact(actual[key], value, location + "." + key)
    else:
        b.require(actual == expected, "Wrong value: " + location)


def read_json(path):
    def unique_fields(pairs):
        result = {}
        for key, value in pairs:
            b.require(key not in result, "Duplicate JSON field: " + key)
            result[key] = value
        return result

    def invalid_constant(value):
        raise ValueError("Nonfinite JSON value: " + value)

    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique_fields,
                      parse_constant=invalid_constant)


def auditor_hashes():
    return {name: b.digest(b.ROOT / name) for name in AUDITORS}


def check_manifest():
    folder = b.WORK / "package"
    b.require(folder.is_dir(), "Completed package is required; audit does not build it")
    entries = list(folder.iterdir())
    b.require({p.name for p in entries} == {"manifest.json", *DATA_FILES}, "Wrong package file set")
    b.require(all(p.is_file() and not p.is_symlink() for p in entries), "Package must contain regular files")

    manifest = read_json(b.PUBLIC / "manifest.json")
    for key, value in {"run": b.RUN, "model": b.MODEL, "dimensions": b.DIMENSIONS,
                       "targetWords": b.TARGET, "overlapWords": b.OVERLAP,
                       "batchSize": b.BATCH}.items():
        exact(manifest[key], value, "build." + key)
    for key in ("remainingPostCount", "sectionCount"):
        b.require(type(manifest[key]) is int and manifest[key] > 0, "Invalid count: " + key)
    b.require(manifest["sectionCount"] >= manifest["remainingPostCount"], "Fewer sections than posts")
    b.require(set(manifest["generatorFiles"]) == set(GENERATORS), "Wrong frozen generator set")
    b.preserve(manifest)

    prepared_hash = b.digest(b.WORK / "prepared.json")
    exact(manifest["preparedHash"], prepared_hash, "build.preparedHash")
    pilot_hash = b.digest(b.ROOT / PILOT_PATH)
    exact(manifest["preservedFiles"].get(PILOT_PATH), pilot_hash, "build.preservedPilotHash")
    expected = {
        "version": 1, "run": b.RUN, "model": b.MODEL, "dimensions": b.DIMENSIONS,
        "targetWords": b.TARGET, "overlapWords": b.OVERLAP,
        "encoding": "base64 little-endian float32",
        "postCount": manifest["remainingPostCount"], "sectionCount": manifest["sectionCount"],
        "files": {name: {"sha256": b.digest(folder / name), "bytes": (folder / name).stat().st_size}
                  for name in DATA_FILES},
        "manifestSha256": b.digest(b.PUBLIC / "manifest.json"), "preparedSha256": prepared_hash,
        "existingPilotExcluded": True, "requiresExplicitIntegration": True,
        "pilotPackage": {"path": PILOT_PATH, "sha256": pilot_hash},
    }
    exact(read_json(folder / "manifest.json"), expected, "package")
    return manifest, expected


def no_network(*args, **kwargs):
    raise RuntimeError("Independent audit forbids API and network calls")


def audit_package():
    # These guards are local to this process; the live generator is not affected.
    with patch.object(b.api, "call", no_network), patch.object(b.api.urllib.request, "urlopen", no_network):
        manifest, record = check_manifest()
        auditors = auditor_hashes()
        package_hash = b.digest(b.WORK / "package/manifest.json")
        usage_hash = b.digest(b.PUBLIC / "usage.json")
        # The frozen verifier replays every receipt and checks all package rows.
        with redirect_stdout(io.StringIO()):
            b.verify()
        after_manifest, after_record = check_manifest()
        exact(after_manifest, manifest, "build after verification")
        exact(after_record, record, "package after verification")
        exact(b.digest(b.WORK / "package/manifest.json"), package_hash, "package manifest hash")
        exact(b.digest(b.PUBLIC / "usage.json"), usage_hash, "usage hash")
        exact(auditor_hashes(), auditors, "auditor hashes")
        report = {
            "version": 1, "run": b.RUN, "status": "passed", "mode": "offline",
            "marker": SUCCESS_MARKER, "apiCallsAllowed": False,
            "completeManifestContractVerified": True, "receiptsAndOutputsVerified": True,
            "priorFilesUnchanged": True, "installedOrImported": False, "productionChanged": False,
            "auditorFiles": auditors, "generatorFiles": manifest["generatorFiles"],
            "manifestSha256": record["manifestSha256"], "packageManifestSha256": package_hash,
            "preparedSha256": record["preparedSha256"], "pilotPackage": record["pilotPackage"],
            "postCount": record["postCount"], "sectionCount": record["sectionCount"],
            "files": record["files"], "usageSha256": usage_hash,
            "resultsSha256": b.digest(b.PUBLIC / "results.json"),
        }
        b.save(b.PUBLIC / "validation.json", report)
    return report


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--offline", action="store_true", required=True,
                        help="Audit a completed package using saved receipts only")
    parser.parse_args(argv)
    audit_package()
    print(SUCCESS_MARKER + " " + str(b.PUBLIC / "validation.json"))


if __name__ == "__main__":
    main()
