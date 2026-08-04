"""Apply the approved description-only recommendation for audit sequence 3119."""

from __future__ import annotations

import json
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
WP_ID = "11543"
BEFORE = "Shares an unusual video related to apocalypticism and the book of Revelation."
AFTER = "Shares an unusual video explaining apocalypticism, briefly touching on Jesus and modern end-time prediction."


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def main() -> None:
    posts = load_json(POSTS_PATH)
    tracker = load_json(TRACKER_PATH)
    post = next(post for post in posts if str(post["wpId"]) == WP_ID)
    if post.get("description") != BEFORE:
        raise ValueError("Canonical description changed unexpectedly for 11543")
    post["description"] = AFTER

    entry = next(
        entry for entry in tracker["posts"] if entry["auditSequence"] == 3119
    )
    if str(entry["wpId"]) != WP_ID:
        raise ValueError("Audit sequence 3119 no longer identifies wpId 11543")
    entry["descriptionAppliedAt"] = date.today().isoformat()

    write_json(POSTS_PATH, posts)
    write_json(TRACKER_PATH, tracker)
    print("Applied the description-only recommendation for audit sequence 3119.")


if __name__ == "__main__":
    main()
