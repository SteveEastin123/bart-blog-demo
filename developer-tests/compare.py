#!/usr/bin/env python3
"""Compare WordPress search captures without the retired Python/SQLite app."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any


def read_capture(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(value, dict) or value.get("schemaVersion") != 1:
        raise ValueError("Unsupported capture schema")
    for name in ("counts", "dataFingerprint"):
        if not isinstance(value.get(name), dict) or not value[name]:
            raise ValueError(f"Missing {name}")
    if not value["dataFingerprint"].get("sha256"):
        raise ValueError("Missing source-data fingerprint")
    cases, results = value.get("testCases"), value.get("results")
    if not isinstance(cases, list) or not cases or not isinstance(results, list):
        raise ValueError("Capture must contain testCases and results")
    ids = []
    for case in cases:
        if not isinstance(case, dict) or not isinstance(case.get("id"), str) or not case["id"].strip():
            raise ValueError("Invalid case ID")
        ids.append(case["id"].strip())
    if len(ids) != len(set(ids)) or len(results) != len(ids):
        raise ValueError("Duplicate cases or missing results")
    for expected_id, row in zip(ids, results):
        if not isinstance(row, dict) or row.get("id") != expected_id or row.get("ok") is not True:
            raise ValueError(f"Failed, missing, or reordered case: {expected_id}")
        if row.get("operation") not in ("search", "suggest", "browse"):
            raise ValueError(f"Unknown operation: {expected_id}")
    return value


def differences(left: Any, right: Any, path: str = "") -> list[str]:
    """Keep list order significant and report paths, never whole post payloads."""
    if type(left) is not type(right):
        return [f"{path}: type changed"]
    if isinstance(left, dict):
        output = []
        for key in sorted(left.keys() | right.keys()):
            child = f"{path}.{key}" if path else key
            if key not in left or key not in right:
                output.append(f"{child}: field added or removed")
            else:
                output.extend(differences(left[key], right[key], child))
        return output
    if isinstance(left, list):
        output = [] if len(left) == len(right) else [f"{path}: length {len(left)} -> {len(right)}"]
        for index, (old, new) in enumerate(zip(left, right)):
            output.extend(differences(old, new, f"{path}[{index}]"))
        return output
    return [] if left == right else [f"{path}: value changed"]


def compare(baseline: dict[str, Any], actual: dict[str, Any]) -> list[str]:
    # Runtime versions and commit IDs may legitimately change in an upgrade.
    return differences(
        {key: baseline[key] for key in ("schemaVersion", "testCases", "dataFingerprint", "counts", "results")},
        {key: actual[key] for key in ("schemaVersion", "testCases", "dataFingerprint", "counts", "results")},
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("baseline", type=Path)
    parser.add_argument("actual", type=Path)
    args = parser.parse_args()
    try:
        baseline, actual = read_capture(args.baseline), read_capture(args.actual)
        changes = compare(baseline, actual)
    except (OSError, ValueError) as error:
        print(f"INVALID capture: {error}", file=sys.stderr)
        return 2
    if changes:
        print(f"FAIL: {len(changes)} differences. Data changes invalidate a like-for-like comparison.")
        print("\n".join(changes[:30]))
        return 1
    print(f"PASS: {len(actual['results'])} cases; identical data fingerprints, counts, results and ordering.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
