"""Record post-topic audit recommendations for audit sequence 3151-3400.

This extends the audit tracker only. It does not alter canonical post topics,
descriptions, the standalone demo, or SQLite data.
"""

from __future__ import annotations

import json
from datetime import date
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
TRACKER_PATH = ROOT / "data" / "audits" / "ehrman_post_topic_link_audit_tracker.json"
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_3151_3400_working_notes.md"
LAST_AUDITED_WP_ID = "11560"
BATCH_SIZE = 250


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "11549": rec(
        add=("Gospel Historical Reliability",),
        reason="The full post evaluates whether John's sayings, signs, and characters are historically reliable, making Gospel reliability a sustained central issue.",
        description="Evaluates John Shelby Spong's claims about the authorship, sayings, signs, and historical characters of the Gospel of John.",
    ),
    "11529": rec(
        add=("Rise of Christianity",),
        reason="A major section explains why the early Christian movement persisted and grew after its expected apocalypse did not occur.",
        description="Explains early Christianity's persistence through cognitive dissonance and considers whether Jesus' apocalyptic outlook indicates mental illness.",
    ),
    "11302": rec(
        add=("Public Debates",),
        reason="One of the post's three substantial reader questions is devoted to debate preparation, experience, and effective debating.",
    ),
    "9303": rec(
        add=("Historical Jesus (General)", "Gospel of Mark"),
        reason="The post devotes one major section to unresolved questions about the historical Jesus and another to whether Mark used Paul's letters.",
    ),
    "9220": rec(
        add=("Acts",),
        reason="The full post repeatedly uses speeches in Acts to evaluate whether Luke-Acts presents a consistent Christology.",
    ),
    "9118": rec(
        add=("Christology (General)",),
        reason="The post gives a sustained account of competing early Christologies and explains why Christological disputes shaped the book's textual study.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 3151-3400 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited through audit sequence 3400.",
        "- Canonical post records remain unchanged pending approval.",
        "- Posts not listed below currently require no topic changes.",
        "",
        "## Proposed Changes",
        "",
    ]
    for entry in entries:
        if entry["status"] != "pending_approval":
            continue
        lines.extend(
            [
                f"### Post {entry['auditSequence']} | wpId {entry['wpId']}",
                "",
                f"Title: {entry['title']}",
                "",
            ]
        )
        if entry["topicsRemoved"]:
            lines.append(f"- Remove: {'; '.join(entry['topicsRemoved'])}")
        if entry["topicsAdded"]:
            lines.append(f"- Add: {'; '.join(entry['topicsAdded'])}")
        lines.extend((f"- Reason: {entry['reason']}", ""))
    return "\n".join(lines).rstrip() + "\n"


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) != 3150:
        raise ValueError("Tracker must contain the first 3150 audited posts")
    if str(tracker["posts"][-1]["wpId"]) != LAST_AUDITED_WP_ID:
        raise ValueError("Tracker's last stable post does not match the expected boundary")

    anchor_index = next(
        index for index, post in enumerate(posts) if str(post["wpId"]) == LAST_AUDITED_WP_ID
    )
    batch = posts[anchor_index + 1 : anchor_index + 1 + BATCH_SIZE]
    if len(batch) != BATCH_SIZE:
        raise ValueError(f"Expected {BATCH_SIZE} posts after the stable boundary")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"])
    descriptions = list(tracker.get("pendingDescriptionRecommendations", []))
    batch_entries = []
    for offset, post in enumerate(batch, start=1):
        sequence = 3150 + offset
        source_index = anchor_index + offset
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(wp_id, rec())
        added = recommendation["add"]
        removed = recommendation["remove"]

        unknown = (set(original) | set(added) | set(removed)) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        if not set(removed).issubset(original):
            raise ValueError(f"Cannot remove absent topic from {wp_id}: {removed}")
        if set(added) & set(original):
            raise ValueError(f"Cannot add existing topic to {wp_id}: {added}")

        recommended = [topic for topic in original if topic not in removed]
        recommended.extend(topic for topic in added if topic not in recommended)
        changed = bool(added or removed)
        entry = {
            "auditSequence": sequence,
            "sourceIndex": source_index,
            "wpId": wp_id,
            "dateText": post.get("dateText"),
            "title": post["title"],
            "status": "pending_approval" if changed else "reviewed_no_change",
            "topicsBefore": original,
            "topicsRecommended": recommended,
            "topicsAdded": added,
            "topicsRemoved": removed,
            "reason": recommendation["reason"],
        }
        entries.append(entry)
        batch_entries.append(entry)
        if recommendation["description"]:
            descriptions.append(
                {
                    "auditSequence": sequence,
                    "wpId": wp_id,
                    "title": post["title"],
                    "descriptionBefore": post.get("description"),
                    "descriptionRecommended": recommendation["description"],
                }
            )

    expected = set(RECOMMENDATIONS)
    recorded = {
        entry["wpId"]
        for entry in batch_entries
        if entry["status"] == "pending_approval"
    }
    if recorded != expected:
        raise ValueError(f"Recommendation mismatch: expected {sorted(expected)}, found {sorted(recorded)}")

    tracker["posts"] = entries
    tracker.update(
        {
            "updatedAt": date.today().isoformat(),
            "auditScope": "First 3400 stable search-index posts in newest-first audit order",
            "reviewedPostCount": len(entries),
            "noChangeCount": sum(entry["status"] == "reviewed_no_change" for entry in entries),
            "pendingApprovalCount": sum(entry["status"] == "pending_approval" for entry in entries),
            "appliedChangeCount": sum(entry["status"] == "applied" for entry in entries),
            "pendingDescriptionRecommendations": descriptions,
        }
    )
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    NOTES_PATH.write_text(render_notes(batch_entries), encoding="utf-8")
    print(f"Audited {len(batch_entries)} posts; recorded {len(recorded)} recommendations.")


if __name__ == "__main__":
    main()
