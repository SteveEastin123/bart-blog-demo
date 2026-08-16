"""Correct the initial Herod pass by treating 'King Herod' as ambiguous."""

from __future__ import annotations

import json
from pathlib import Path

from disambiguate_person_name_keywords_2026_08_16 import (
    AUDIT_PATH,
    INDEX_PATH,
    RAW_PATH,
    classify_herod,
)


CORRECTION_PATH = (
    Path(__file__).resolve().parents[1]
    / "data"
    / "audits"
    / "herod_name_disambiguation_correction_2026_08_16.json"
)


def main() -> None:
    posts = json.loads(INDEX_PATH.read_text(encoding="utf-8"))
    posts_by_id = {str(post["wpId"]): post for post in posts}
    initial_audit = json.loads(AUDIT_PATH.read_text(encoding="utf-8"))
    records = initial_audit["details"]["Herod"]["resolved"]
    target_ids = {record["wpId"] for record in records}
    raw_text = {}
    with RAW_PATH.open(encoding="utf-8") as source:
        for line in source:
            raw_post = json.loads(line)
            wp_id = str(raw_post.get("wpId", ""))
            if wp_id in target_ids:
                raw_text[wp_id] = raw_post.get("text", "")

    corrected = []
    now_ambiguous = []
    for record in records:
        wp_id = record["wpId"]
        post = posts_by_id[wp_id]
        for added_label in record["addedKeywords"]:
            post["secondaryKeywords"] = [
                value
                for value in post.get("secondaryKeywords", [])
                if value != added_label
            ]

        labels = classify_herod(set(post.get("topics", [])), raw_text[wp_id])
        if not labels:
            if "Herod" not in post["secondaryKeywords"]:
                post["secondaryKeywords"].append("Herod")
            now_ambiguous.append({"wpId": wp_id, "title": post["title"]})
            continue

        added = []
        for label in sorted(labels):
            if label not in post.get("topics", []) and label not in post["secondaryKeywords"]:
                post["secondaryKeywords"].append(label)
                added.append(label)
        corrected.append({"wpId": wp_id, "title": post["title"], "labels": added})

    INDEX_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    report = {
        "reclassified": len(corrected),
        "restoredAsAmbiguousHerod": len(now_ambiguous),
        "correctedPosts": corrected,
        "ambiguousPosts": now_ambiguous,
    }
    CORRECTION_PATH.write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(json.dumps({key: report[key] for key in report if not key.endswith("Posts")}, indent=2))


if __name__ == "__main__":
    main()
