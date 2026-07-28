from __future__ import annotations

import argparse
import gzip
import hashlib
import html
import itertools
import json
import os
import random
import sqlite3
import sys
from contextlib import contextmanager
from itertools import zip_longest
from pathlib import Path
from typing import Any, Iterable, Iterator, TextIO
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from webapp.import_data import DEFAULT_DB_PATH, normalize_keyword  # noqa: E402
from webapp.parity import MAX_BATCH_CASES, run_batch  # noqa: E402


DEFAULT_SEED = 20260728
DEFAULT_CASES_PATH = ROOT / "tests" / "parity" / "artifacts" / "cases.jsonl"
DEFAULT_BASELINE_PATH = ROOT / "tests" / "parity" / "artifacts" / "baseline.jsonl.gz"
DEFAULT_DIGEST_PATH = ROOT / "tests" / "parity" / "artifacts" / "baseline-digests.jsonl"
DEFAULT_REPORT_PATH = ROOT / "tests" / "parity" / "artifacts" / "comparison-report.html"


REGRESSION_SEARCHES = (
    ("Luke", "Atonement"),
    ("Luke", "Atonement", "Curtain"),
    ("Hell",),
    ("In a Nutshell Series",),
    ("Nazareth", "Jesus' Birth Narratives"),
    ("Q Source",),
    ("Women", "Paul"),
    ("Textual Criticism", "Bruce Metzger"),
    ("Colossians", "Raised Up"),
)


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def semantic_digest(value: Any) -> str:
    return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


@contextmanager
def text_reader(path: Path) -> Iterator[TextIO]:
    if path.suffix == ".gz":
        with gzip.open(path, "rt", encoding="utf-8") as handle:
            yield handle
    else:
        with path.open("r", encoding="utf-8") as handle:
            yield handle


@contextmanager
def text_writer(path: Path) -> Iterator[TextIO]:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.suffix == ".gz":
        with gzip.open(path, "wt", encoding="utf-8", newline="\n") as handle:
            yield handle
    else:
        with path.open("w", encoding="utf-8", newline="\n") as handle:
            yield handle


def write_json_line(handle: TextIO, value: Any) -> None:
    handle.write(json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n")


def read_json_lines(path: Path) -> Iterator[dict[str, Any]]:
    with text_reader(path) as handle:
        for line_number, line in enumerate(handle, start=1):
            if not line.strip():
                continue
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"{path}:{line_number} must contain a JSON object")
            yield value


def chunks(values: Iterable[dict[str, Any]], size: int) -> Iterator[list[dict[str, Any]]]:
    batch: list[dict[str, Any]] = []
    for value in values:
        batch.append(value)
        if len(batch) == size:
            yield batch
            batch = []
    if batch:
        yield batch


def term_catalog(conn: sqlite3.Connection) -> tuple[list[str], dict[str, str]]:
    rows = conn.execute(
        """
        SELECT
            normalized,
            COALESCE(
                MIN(CASE WHEN kind = 'topic' THEN label END),
                MIN(label)
            ) AS label
        FROM post_search_terms
        WHERE normalized <> 'ignore'
        GROUP BY normalized
        ORDER BY normalized
        """
    ).fetchall()
    normalized_terms = [str(row[0]) for row in rows]
    labels = {str(row[0]): str(row[1]) for row in rows}
    return normalized_terms, labels


def terms_by_post(conn: sqlite3.Connection) -> dict[int, set[str]]:
    values: dict[int, set[str]] = {}
    for post_id, normalized in conn.execute(
        """
        SELECT DISTINCT post_id, normalized
        FROM post_search_terms
        WHERE normalized <> 'ignore'
        ORDER BY post_id, normalized
        """
    ):
        values.setdefault(int(post_id), set()).add(str(normalized))
    return values


def autocomplete_prefixes(normalized_terms: list[str]) -> list[str]:
    prefixes: set[str] = set()
    for term in normalized_terms:
        for word in term.split():
            prefixes.update(word[:index] for index in range(1, len(word) + 1))
    return sorted(prefixes)


def sampled_combinations(
    values_by_post: dict[int, set[str]],
    length: int,
    limit: int,
    seed: int,
) -> list[tuple[str, ...]]:
    combinations: set[tuple[str, ...]] = set()
    for values in values_by_post.values():
        combinations.update(itertools.combinations(sorted(values), length))
    ordered = sorted(combinations)
    if len(ordered) <= limit:
        return ordered
    indexes = sorted(random.Random(seed + length).sample(range(len(ordered)), limit))
    return [ordered[index] for index in indexes]


def case_builder() -> tuple[list[dict[str, Any]], Any]:
    cases: list[dict[str, Any]] = []
    counter = 0

    def add(prefix: str, operation: str, **fields: Any) -> None:
        nonlocal counter
        counter += 1
        cases.append({"id": f"{prefix}-{counter:06d}", "operation": operation, **fields})

    return cases, add


def smoke_cases(conn: sqlite3.Connection) -> list[dict[str, Any]]:
    cases, add = case_builder()
    for terms in REGRESSION_SEARCHES:
        add("regression-search", "search", terms=list(terms), sort="ranked")
    add("sort-newest", "search", terms=["Paul"], sort="newest")
    add("sort-oldest", "search", terms=["Paul"], sort="oldest")
    add("invalid-sort", "search", terms=["Paul"], sort="unexpected")
    add("duplicate-term", "search", terms=["Paul", "paul"], sort="ranked")
    add("ampersand", "search", terms=["Heaven & Hell"], sort="ranked")
    add("suggest-starter", "suggest", query="", selected=[])
    add("suggest-prefix", "suggest", query="wo", selected=[])
    add("suggest-selected", "suggest", query="at", selected=["Luke"])

    category_rows = conn.execute("SELECT slug FROM categories ORDER BY name COLLATE NOCASE LIMIT 2").fetchall()
    for row in category_rows:
        slug = str(row[0])
        add("category-search", "search", terms=[], sort="ranked", scope={"type": "category", "slug": slug})
        add("category-suggest", "suggest", query="", selected=[], categorySlug=slug)

    topic_rows = conn.execute(
        "SELECT slug FROM topics WHERE display_in_browser = 1 ORDER BY name COLLATE NOCASE LIMIT 2"
    ).fetchall()
    for row in topic_rows:
        slug = str(row[0])
        add("topic-search", "search", terms=[], sort="ranked", scope={"type": "topic", "slug": slug})
        add("topic-suggest", "suggest", query="", selected=[], topicSlug=slug)
    add("browse", "browse")
    return cases


def full_cases(
    conn: sqlite3.Connection,
    seed: int,
    triple_sample: int,
    quadruple_sample: int,
    random_pair_sample: int,
) -> list[dict[str, Any]]:
    cases, add = case_builder()
    normalized_terms, labels = term_catalog(conn)
    values_by_post = terms_by_post(conn)

    for normalized in normalized_terms:
        label = labels[normalized]
        for sort in ("ranked", "newest", "oldest"):
            add(f"single-{sort}", "search", terms=[label], sort=sort)

    cooccurring_pairs: set[tuple[str, str]] = set()
    for values in values_by_post.values():
        cooccurring_pairs.update(itertools.combinations(sorted(values), 2))
    for first, second in sorted(cooccurring_pairs):
        add("pair-ranked", "search", terms=[labels[first], labels[second]], sort="ranked")

    triples = sampled_combinations(values_by_post, 3, triple_sample, seed)
    for values in triples:
        add("triple-ranked", "search", terms=[labels[value] for value in values], sort="ranked")

    quadruples = sampled_combinations(values_by_post, 4, quadruple_sample, seed)
    for values in quadruples:
        add("quadruple-ranked", "search", terms=[labels[value] for value in values], sort="ranked")

    pair_population = list(itertools.combinations(normalized_terms, 2))
    sample_size = min(random_pair_sample, len(pair_population))
    for first, second in random.Random(seed).sample(pair_population, sample_size):
        add("random-pair", "search", terms=[labels[first], labels[second]], sort="ranked")

    reversed_pairs = sorted(cooccurring_pairs)[:: max(1, len(cooccurring_pairs) // 1000)]
    for first, second in reversed_pairs[:1000]:
        add("pair-reversed", "search", terms=[labels[second], labels[first]], sort="ranked")

    for terms in REGRESSION_SEARCHES:
        if all(normalize_keyword(term) in labels for term in terms):
            add("regression-search", "search", terms=list(terms), sort="ranked")

    for prefix in autocomplete_prefixes(normalized_terms):
        add("suggest-prefix", "suggest", query=prefix, selected=[])
    for normalized in normalized_terms:
        add("suggest-selected-empty", "suggest", query="", selected=[labels[normalized]])
    for first, second in sorted(cooccurring_pairs):
        add("suggest-pair", "suggest", query=labels[second], selected=[labels[first]])

    category_rows = conn.execute("SELECT slug FROM categories ORDER BY name COLLATE NOCASE").fetchall()
    for row in category_rows:
        slug = str(row[0])
        for sort in ("ranked", "newest", "oldest"):
            add("category-search", "search", terms=[], sort=sort, scope={"type": "category", "slug": slug})
        add("category-suggest", "suggest", query="", selected=[], categorySlug=slug)

    topic_rows = conn.execute(
        "SELECT slug FROM topics WHERE display_in_browser = 1 ORDER BY name COLLATE NOCASE"
    ).fetchall()
    for row in topic_rows:
        slug = str(row[0])
        for sort in ("ranked", "newest", "oldest"):
            add("topic-search", "search", terms=[], sort=sort, scope={"type": "topic", "slug": slug})
        add("topic-suggest", "suggest", query="", selected=[], topicSlug=slug)

    add("browse", "browse")
    return cases


def generate_command(args: argparse.Namespace) -> int:
    conn = sqlite3.connect(args.db)
    try:
        if args.profile == "smoke":
            cases = smoke_cases(conn)
        else:
            cases = full_cases(
                conn,
                args.seed,
                args.triple_sample,
                args.quadruple_sample,
                args.random_pair_sample,
            )
    finally:
        conn.close()
    with text_writer(args.output) as handle:
        for case in cases:
            write_json_line(handle, case)
    print(f"Generated {len(cases):,} {args.profile} parity cases at {args.output}")
    return 0


def remote_batch(base_url: str, token: str, cases: list[dict[str, Any]], timeout: int) -> dict[str, Any]:
    url = base_url.rstrip("/") + "/api/parity/batch"
    payload = json.dumps({"schemaVersion": 1, "cases": cases}, ensure_ascii=False).encode("utf-8")
    request = Request(
        url,
        data=payload,
        method="POST",
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "X-Ehrman-Parity-Token": token,
        },
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Parity endpoint returned HTTP {exc.code}: {detail}") from exc
    except URLError as exc:
        raise RuntimeError(f"Unable to reach parity endpoint: {exc}") from exc


def comparable_manifest(response: dict[str, Any]) -> dict[str, Any]:
    return {
        "schemaVersion": response.get("schemaVersion"),
        "dataFingerprint": response.get("dataFingerprint"),
        "counts": response.get("counts"),
    }


def digest_record(result: dict[str, Any]) -> dict[str, Any]:
    summary = {
        "recordType": "digest",
        "id": result.get("id", ""),
        "ok": bool(result.get("ok")),
        "sha256": semantic_digest(result),
    }
    if "resultCount" in result:
        summary["resultCount"] = result["resultCount"]
    if "suggestionCount" in result:
        summary["suggestionCount"] = result["suggestionCount"]
    return summary


def capture_command(args: argparse.Namespace) -> int:
    token = ""
    if args.base_url:
        token = os.environ.get(args.token_env, "")
        if not token:
            raise RuntimeError(f"Set {args.token_env} before capturing a remote baseline")

    first_manifest: dict[str, Any] | None = None
    total = 0
    case_records: Iterable[dict[str, Any]] = read_json_lines(args.cases)
    if args.offset or args.limit is not None:
        stop = None if args.limit is None else args.offset + args.limit
        case_records = itertools.islice(case_records, args.offset, stop)

    with text_writer(args.output) as output, text_writer(args.digests) as digests:
        for batch in chunks(case_records, args.batch_size):
            response = (
                remote_batch(args.base_url, token, batch, args.timeout)
                if args.base_url
                else run_batch(batch)
            )
            current_manifest = {key: value for key, value in response.items() if key != "results"}
            if first_manifest is None:
                first_manifest = current_manifest
                write_json_line(output, {"recordType": "manifest", **current_manifest})
                write_json_line(digests, {"recordType": "manifest", **current_manifest})
            elif comparable_manifest(current_manifest) != comparable_manifest(first_manifest):
                raise RuntimeError("Manifest changed while capturing baseline")

            results = response.get("results")
            if not isinstance(results, list) or len(results) != len(batch):
                raise RuntimeError("Parity endpoint returned an unexpected number of results")
            for result in results:
                write_json_line(output, {"recordType": "result", **result})
                write_json_line(digests, digest_record(result))
                total += 1
    print(f"Captured {total:,} parity results at {args.output}")
    print(f"Wrote result digests to {args.digests}")
    return 0


def comparable_manifest_record(record: dict[str, Any]) -> dict[str, Any]:
    return {
        "schemaVersion": record.get("schemaVersion"),
        "dataFingerprint": record.get("dataFingerprint"),
        "counts": record.get("counts"),
    }


def comparison_html(
    expected_path: Path,
    actual_path: Path,
    compared: int,
    mismatches: list[dict[str, Any]],
) -> str:
    status = "PASS" if not mismatches else "FAIL"
    rows = "".join(
        "<tr>"
        f"<td>{html.escape(str(item.get('id', '')))}</td>"
        f"<td><pre>{html.escape(json.dumps(item.get('expected'), ensure_ascii=False, indent=2)[:4000])}</pre></td>"
        f"<td><pre>{html.escape(json.dumps(item.get('actual'), ensure_ascii=False, indent=2)[:4000])}</pre></td>"
        "</tr>"
        for item in mismatches[:100]
    )
    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Search Parity Comparison</title>
  <style>
    body {{ font-family: Arial, sans-serif; margin: 2rem; color: #222; }}
    table {{ border-collapse: collapse; width: 100%; }}
    th, td {{ border: 1px solid #bbb; padding: .5rem; vertical-align: top; }}
    th {{ background: #f3f3f3; }}
    pre {{ white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }}
  </style>
</head>
<body>
  <h1>Search Parity Comparison: {status}</h1>
  <p>Expected: {html.escape(str(expected_path))}</p>
  <p>Actual: {html.escape(str(actual_path))}</p>
  <p>Compared {compared:,} results; found {len(mismatches):,} differences.</p>
  <table>
    <thead><tr><th>Case</th><th>Expected</th><th>Actual</th></tr></thead>
    <tbody>{rows}</tbody>
  </table>
</body>
</html>
"""


def compare_command(args: argparse.Namespace) -> int:
    expected_records = read_json_lines(args.expected)
    actual_records = read_json_lines(args.actual)
    expected_manifest = next(expected_records, None)
    actual_manifest = next(actual_records, None)
    mismatches: list[dict[str, Any]] = []

    if expected_manifest is None or actual_manifest is None:
        raise RuntimeError("Both comparison files must contain a manifest")
    if comparable_manifest_record(expected_manifest) != comparable_manifest_record(actual_manifest):
        mismatches.append({"id": "manifest", "expected": expected_manifest, "actual": actual_manifest})

    compared = 0
    for expected, actual in zip_longest(expected_records, actual_records):
        compared += 1
        if expected != actual:
            mismatches.append(
                {
                    "id": (expected or actual or {}).get("id", f"record-{compared}"),
                    "expected": expected,
                    "actual": actual,
                }
            )
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(
        comparison_html(args.expected, args.actual, compared, mismatches),
        encoding="utf-8",
    )
    print(f"Compared {compared:,} results; found {len(mismatches):,} differences")
    print(f"Wrote comparison report to {args.report}")
    return 1 if mismatches else 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Generate, capture, and compare Python/PHP search parity cases.")
    subparsers = parser.add_subparsers(dest="command", required=True)

    generate = subparsers.add_parser("generate", help="Generate deterministic parity cases")
    generate.add_argument("--profile", choices=("smoke", "full"), default="smoke")
    generate.add_argument("--db", type=Path, default=DEFAULT_DB_PATH)
    generate.add_argument("--output", type=Path, default=DEFAULT_CASES_PATH)
    generate.add_argument("--seed", type=int, default=DEFAULT_SEED)
    generate.add_argument("--triple-sample", type=int, default=5000)
    generate.add_argument("--quadruple-sample", type=int, default=2000)
    generate.add_argument("--random-pair-sample", type=int, default=5000)
    generate.set_defaults(func=generate_command)

    capture = subparsers.add_parser("capture", help="Capture local or remote parity results")
    capture.add_argument("--cases", type=Path, default=DEFAULT_CASES_PATH)
    capture.add_argument("--output", type=Path, default=DEFAULT_BASELINE_PATH)
    capture.add_argument("--digests", type=Path, default=DEFAULT_DIGEST_PATH)
    capture.add_argument("--base-url", default="", help="Remote base URL; omit to run locally")
    capture.add_argument("--token-env", default="EHRMAN_PARITY_TEST_TOKEN")
    capture.add_argument("--batch-size", type=int, default=MAX_BATCH_CASES)
    capture.add_argument("--offset", type=int, default=0)
    capture.add_argument("--limit", type=int)
    capture.add_argument("--timeout", type=int, default=120)
    capture.set_defaults(func=capture_command)

    compare = subparsers.add_parser("compare", help="Compare two captured parity files")
    compare.add_argument("expected", type=Path)
    compare.add_argument("actual", type=Path)
    compare.add_argument("--report", type=Path, default=DEFAULT_REPORT_PATH)
    compare.set_defaults(func=compare_command)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    if getattr(args, "batch_size", 1) < 1 or getattr(args, "batch_size", 1) > MAX_BATCH_CASES:
        raise SystemExit(f"--batch-size must be between 1 and {MAX_BATCH_CASES}")
    if getattr(args, "offset", 0) < 0 or getattr(args, "limit", 0) is not None and args.limit < 1:
        raise SystemExit("--offset must be non-negative and --limit must be positive")
    try:
        return int(args.func(args))
    except (OSError, ValueError, RuntimeError, sqlite3.Error) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
