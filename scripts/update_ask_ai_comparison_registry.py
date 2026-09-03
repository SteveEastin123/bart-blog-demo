"""Build the cumulative Ask AI 1 vs. Ask AI 2 benchmark registry."""

from __future__ import annotations

import json
import re
from datetime import date
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
EVALUATION_DIR = ROOT / "data" / "evaluations"
DOCS_DIR = ROOT / "docs"
REGISTRY_PATH = EVALUATION_DIR / "ask_ai1_vs_ask_ai2_run_registry.json"
HISTORY_PATH = DOCS_DIR / "ask_ai1_vs_ask_ai2_run_history.md"
DETAIL_PATTERN = "ask_ai1_vs_ask_ai2_*full_text_evaluation*.json"
METRICS = (
    "precisionAt5",
    "precisionAt10",
    "averageGradeAt5",
    "averageGradeAt10",
    "recallAt10",
    "ndcgAt10",
)


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def run_number(path: Path) -> int:
    match = re.search(r"_run(\d+)$", path.stem)
    return int(match.group(1)) if match else 1


def run_date(path: Path) -> str:
    match = re.search(r"(\d{4}-\d{2}-\d{2})", path.stem)
    if not match:
        raise ValueError(f"Evaluation filename has no ISO date: {path.name}")
    return match.group(1)


def question_file(path: Path, number: int) -> str | None:
    if number == 1:
        candidate = EVALUATION_DIR / "ask_ai2_vs_ask_ai3_new_questions_2026-08-29.json"
    else:
        candidate = EVALUATION_DIR / path.name.replace(
            "_full_text_evaluation", "_questions"
        )
    return relative(candidate) if candidate.exists() else None


def compact_run(path: Path) -> dict[str, Any]:
    payload = load_json(path)
    summary = payload["summary"]
    methodology = payload["methodology"]
    number = run_number(path)
    report = DOCS_DIR / f"{path.stem}.md"
    run = {
        "runNumber": number,
        "runId": path.stem,
        "date": run_date(path),
        "questionSet": methodology["questionSet"],
        "questionCount": int(summary["questions"]),
        "questionFile": question_file(path, number),
        "detailFile": relative(path),
        "reportFile": relative(report) if report.exists() else None,
        "wins": summary["wins"],
        "metrics": {
            "askAi1": {key: summary["askAi1"][key] for key in METRICS},
            "askAi2": {key: summary["askAi2"][key] for key in METRICS},
        },
        "resultCoverage": summary["resultCoverage"],
        "meanTopTenOverlap": summary["meanTopTenOverlap"],
        "latencySeconds": summary["latency"],
        "retrievalCostUsd": {
            "askAi1": {
                "total": summary["usage"]["askAi1"]["totalCostUsd"],
                "averagePerQuestion": summary["usage"]["askAi1"]["averageCostUsd"],
            },
            "askAi2": {
                "total": summary["usage"]["askAi2"]["totalCostUsd"],
                "averagePerQuestion": summary["usage"]["askAi2"]["averageCostUsd"],
            },
        },
        "grading": summary["grading"],
        "benchmarkExecutionOverhead": summary.get(
            "benchmarkExecutionOverhead",
            {
                "estimatedCostUsd": 0.0,
                "note": "Not separately recorded for this run.",
            },
        ),
    }
    return run


def weighted_average(runs: list[dict[str, Any]], method: str, metric: str) -> float:
    questions = sum(run["questionCount"] for run in runs)
    total = sum(
        run["metrics"][method][metric] * run["questionCount"] for run in runs
    )
    return round(total / questions, 4) if questions else 0.0


def cumulative_summary(runs: list[dict[str, Any]]) -> dict[str, Any]:
    questions = sum(run["questionCount"] for run in runs)
    costs: dict[str, dict[str, float]] = {}
    for method in ("askAi1", "askAi2"):
        total = sum(run["retrievalCostUsd"][method]["total"] for run in runs)
        costs[method] = {
            "total": round(total, 8),
            "averagePerQuestion": round(total / questions, 8) if questions else 0.0,
        }

    return {
        "runs": len(runs),
        "questions": questions,
        "wins": {
            "askAi1": sum(run["wins"]["askAi1"] for run in runs),
            "askAi2": sum(run["wins"]["askAi2"] for run in runs),
            "ties": sum(run["wins"]["ties"] for run in runs),
        },
        "weightedMeanMetrics": {
            method: {
                metric: weighted_average(runs, method, metric) for metric in METRICS
            }
            for method in ("askAi1", "askAi2")
        },
        "retrievalCostUsd": costs,
        "gradingCostUsd": round(
            sum(run["grading"]["estimatedCostUsd"] for run in runs), 8
        ),
        "benchmarkExecutionOverheadCostUsd": round(
            sum(
                run["benchmarkExecutionOverhead"].get("estimatedCostUsd", 0)
                for run in runs
            ),
            8,
        ),
    }


def format_percent(value: float) -> str:
    return f"{value * 100:.1f}%"


def format_cents(value: float) -> str:
    return f"{value * 100:.3f} cents"


def write_history(registry: dict[str, Any]) -> None:
    cumulative = registry["cumulative"]
    lines = [
        "# Ask AI 1 vs. Ask AI 2 Benchmark History",
        "",
        f"Last updated: {registry['updated']}",
        "",
        "Each run uses blinded relevance grading from complete local post text. Grades 2 and 3 count as relevant, and an nDCG@10 difference of 0.03 or less is a tie.",
        "",
        "## Runs",
        "",
        "| Run | Questions | Ask AI 1 wins | Ask AI 2 wins | Ties | AI 1 nDCG@10 | AI 2 nDCG@10 | AI 1 cost/question | AI 2 cost/question |",
        "|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for run in registry["runs"]:
        report = run["reportFile"] or run["detailFile"]
        lines.append(
            f"| [{run['runNumber']}](../{report}) | {run['questionCount']} | "
            f"{run['wins']['askAi1']} | {run['wins']['askAi2']} | {run['wins']['ties']} | "
            f"{run['metrics']['askAi1']['ndcgAt10']:.4f} | {run['metrics']['askAi2']['ndcgAt10']:.4f} | "
            f"{format_cents(run['retrievalCostUsd']['askAi1']['averagePerQuestion'])} | "
            f"{format_cents(run['retrievalCostUsd']['askAi2']['averagePerQuestion'])} |"
        )

    lines.extend(
        [
            "",
            "## Cumulative",
            "",
            f"Across {cumulative['runs']} runs and {cumulative['questions']} questions, Ask AI 1 won {cumulative['wins']['askAi1']}, Ask AI 2 won {cumulative['wins']['askAi2']}, and {cumulative['wins']['ties']} were ties.",
            "",
            "| Weighted mean metric | Ask AI 1 | Ask AI 2 |",
            "|---|---:|---:|",
            f"| Precision@5 | {format_percent(cumulative['weightedMeanMetrics']['askAi1']['precisionAt5'])} | {format_percent(cumulative['weightedMeanMetrics']['askAi2']['precisionAt5'])} |",
            f"| Precision@10 | {format_percent(cumulative['weightedMeanMetrics']['askAi1']['precisionAt10'])} | {format_percent(cumulative['weightedMeanMetrics']['askAi2']['precisionAt10'])} |",
            f"| Recall@10 within judged pool | {format_percent(cumulative['weightedMeanMetrics']['askAi1']['recallAt10'])} | {format_percent(cumulative['weightedMeanMetrics']['askAi2']['recallAt10'])} |",
            f"| nDCG@10 | {cumulative['weightedMeanMetrics']['askAi1']['ndcgAt10']:.4f} | {cumulative['weightedMeanMetrics']['askAi2']['ndcgAt10']:.4f} |",
            "",
            f"Average measured retrieval cost per question across all runs was {format_cents(cumulative['retrievalCostUsd']['askAi1']['averagePerQuestion'])} for Ask AI 1 and {format_cents(cumulative['retrievalCostUsd']['askAi2']['averagePerQuestion'])} for Ask AI 2. Blinded evaluation cost is tracked separately.",
            "",
            f"Separately recorded benchmark retry overhead totals ${cumulative['benchmarkExecutionOverheadCostUsd']:.4f}. This is excluded from normal per-question cost.",
        ]
    )
    HISTORY_PATH.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    paths = sorted(
        EVALUATION_DIR.glob(DETAIL_PATTERN),
        key=lambda path: (run_date(path), run_number(path), path.name),
    )
    if not paths:
        raise SystemExit("No Ask AI 1 vs. Ask AI 2 evaluation files found.")

    runs = [compact_run(path) for path in paths]
    registry = {
        "version": 1,
        "updated": date.today().isoformat(),
        "benchmark": "Ask AI 1 vs. Ask AI 2 full-text relevance",
        "protocol": {
            "judgedDepth": 10,
            "relevantGrades": [2, 3],
            "tieThresholdNdcg": 0.03,
            "blinded": True,
            "source": "Complete local post text",
        },
        "cumulative": cumulative_summary(runs),
        "runs": runs,
    }
    REGISTRY_PATH.write_text(
        json.dumps(registry, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    write_history(registry)
    print(f"Recorded {len(runs)} runs and {registry['cumulative']['questions']} questions.")
    print(f"Registry: {REGISTRY_PATH}")
    print(f"History: {HISTORY_PATH}")


if __name__ == "__main__":
    main()
