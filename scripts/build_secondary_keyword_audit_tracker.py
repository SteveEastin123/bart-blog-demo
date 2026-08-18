from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
AUDITS_DIR = ROOT / "data" / "audits"
TRACKER_PATH = AUDITS_DIR / "ehrman_secondary_keyword_audit_tracker.json"


def frequency_band(count: int) -> str:
    if count >= 100:
        return "100+"
    if count >= 25:
        return "25-99"
    if count >= 5:
        return "5-24"
    return "1-4"


def documented_audits() -> dict[str, list[str]]:
    evidence: dict[str, list[str]] = {}
    for path in sorted(AUDITS_DIR.glob("*secondary_keyword_audit.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        keyword = data.get("keyword")
        if isinstance(keyword, str):
            evidence.setdefault(keyword, []).append(path.name)
        for audited_keyword in data.get("auditedKeywords", []):
            if isinstance(audited_keyword, str):
                evidence.setdefault(audited_keyword, []).append(path.name)

    combined_path = AUDITS_DIR / "1_corinthians_nazareth_keyword_audit_2026_08_17.json"
    if combined_path.exists():
        data = json.loads(combined_path.read_text(encoding="utf-8"))
        for keyword in data.get("scope", []):
            evidence.setdefault(str(keyword), []).append(combined_path.name)
    return evidence


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    counts = Counter(
        keyword
        for post in posts
        for keyword in post.get("secondaryKeywords", [])
    )
    evidence = documented_audits()

    entries = []
    for rank, (keyword, count) in enumerate(
        sorted(counts.items(), key=lambda item: (-item[1], item[0].casefold())),
        start=1,
    ):
        files = evidence.get(keyword, [])
        entries.append(
            {
                "keyword": keyword,
                "postCount": count,
                "frequencyRank": rank,
                "frequencyBand": frequency_band(count),
                "status": "audited_documented" if files else "pending",
                "auditEvidence": files,
            }
        )

    status_counts = Counter(entry["status"] for entry in entries)
    band_counts = Counter(entry["frequencyBand"] for entry in entries)
    tracker = {
        "generatedFrom": str(INDEX_PATH.relative_to(ROOT)).replace("\\", "/"),
        "purpose": (
            "Tracks full-text secondary-keyword audits. Pending means that no "
            "keyword-specific audit record was found; it does not erase earlier "
            "informal or batch cleanup work."
        ),
        "summary": {
            "totalCurrentKeywords": len(entries),
            "statusCounts": dict(sorted(status_counts.items())),
            "frequencyBandCounts": {
                band: band_counts.get(band, 0)
                for band in ("100+", "25-99", "5-24", "1-4")
            },
        },
        "keywords": entries,
    }
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(tracker["summary"], indent=2))


if __name__ == "__main__":
    main()
