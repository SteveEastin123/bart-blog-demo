"""Record recommendations for posts 3651-3950 in the linkage audit.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_3651_3950_working_notes.md"
BATCH_SIZE = 300


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "7706": rec(
        add=("Media Interviews and Videos",),
        reason="The post presents an embedded public Yale lecture on Christ as a divine man.",
    ),
    "7699": rec(
        add=("Media Interviews and Videos",),
        reason="The post presents an embedded public Yale lecture on early views of Christ.",
    ),
    "7661": rec(
        add=("Media Interviews and Videos",),
        reason="The post shares a public Freedom From Religion Foundation video lecture about agnosticism and religion.",
    ),
    "7654": rec(
        add=("Courses and Teaching",),
        reason="The post presents and explains a doctoral seminar syllabus on the Apostolic Fathers.",
    ),
    "7648": rec(
        add=("Christian Interpretation of Jewish Scripture",),
        reason="The full post centrally explains methods early Christians used to interpret Jewish Scripture.",
    ),
    "7585": rec(
        add=("Courses and Teaching",),
        reason="The post announces and describes the content and production of a Great Courses lecture series.",
    ),
    "7411": rec(
        add=("Gnosticism (General)",),
        reason="The post gives sustained treatment to Thomasine, miscellaneous, and difficult-to-classify Gnostic traditions.",
    ),
    "7122": rec(
        add=("General-Audience Books", "Public-Facing Scholarship"),
        remove=("Media Coverage and Reviews",),
        reason="Media attention is introductory context; the post is centrally about why scholars write popular books to disseminate knowledge.",
        description="Explains why scholars write and sell books for general readers as a way to disseminate knowledge.",
    ),
    "7002": rec(
        add=("Historical Methods (General)",),
        reason="The post explicitly explains and applies the comparative method to the Gospel of Luke.",
    ),
    "6958": rec(
        add=("Scribal Changes", "Textual Variants"),
        reason="The post centrally explains that Mark's snake-handling passage belongs to a later scribal ending rather than the original Gospel.",
    ),
    "6919": rec(
        add=("Pauline Textual Issues",),
        reason="The post examines manuscript transmission and the proposed scribal insertion of references to Jesus in Paul's letters.",
    ),
    "6762": rec(
        add=("Apocalyptic Jesus", "Jesus' Family Traditions"),
        remove=("Jesus and Women",),
        reason="The post argues that Jesus was unmarried on the basis of his apocalyptic ethics; women are not a sustained subject.",
    ),
    "6703": rec(
        remove=("Gospel of Jesus' Wife Fragment",),
        reason="The fragment appears only as background about a participant; the post itself evaluates the historical question of Jesus' marital status.",
    ),
    "6546": rec(
        add=("Apocalyptic Jesus",),
        reason="The post's central argument is that Jesus' ministry and proclamation were apocalyptic.",
    ),
    "6371": rec(
        add=("Resurrection of Jesus",),
        reason="The post centrally contrasts Gnostic and proto-orthodox understandings of Jesus' resurrection body.",
    ),
    "6349": rec(
        add=("Historical Methods (General)",),
        reason="The post centrally asks whether historians can reconstruct the past when memories are distorted.",
    ),
    "6145": rec(
        add=("Methods for Studying the Historical Jesus",),
        reason="The post explains multiple attestation as a method for evaluating traditions about Jesus.",
    ),
    "5506": rec(
        add=("New Testament Manuscripts",),
        reason="The post introduces and evaluates a newly identified Coptic manuscript of the Gospel of John.",
    ),
    "5084": rec(
        add=("Philippians",),
        reason="The post offers sustained exegesis of Philippians 1:21-24 using comparative ancient evidence.",
    ),
    "4906": rec(
        add=("Media Coverage and Reviews",),
        reason="The post is a detailed public critique and review of Bill O'Reilly's Killing Jesus.",
    ),
    "4900": rec(
        add=("Media Coverage and Reviews",),
        reason="The post introduces and evaluates Bill O'Reilly's Killing Jesus for a general audience.",
    ),
    "4806": rec(
        add=("Jewish Christian Gospels",),
        reason="The post centrally interprets the Gospel of the Ebionites' account of John the Baptist's diet.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 3651-3950 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited 300 previously unaudited posts.",
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
    if len(tracker.get("posts", [])) != 3650:
        raise ValueError("Tracker must contain exactly 3650 audited posts")
    if tracker.get("pendingApprovalCount") != 0:
        raise ValueError("Resolve existing pending recommendations before extending")

    audited_ids = {str(entry["wpId"]) for entry in tracker["posts"]}
    batch = [post for post in posts if str(post["wpId"]) not in audited_ids][:BATCH_SIZE]
    if len(batch) != BATCH_SIZE:
        raise ValueError(f"Expected {BATCH_SIZE} unaudited posts, found {len(batch)}")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"])
    descriptions = list(tracker.get("pendingDescriptionRecommendations", []))
    batch_entries = []
    source_indexes = {str(post["wpId"]): index for index, post in enumerate(posts)}

    for offset, post in enumerate(batch, start=1):
        sequence = len(tracker["posts"]) + offset
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
            "sourceIndex": source_indexes[wp_id],
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
        raise ValueError(
            f"Recommendation mismatch: expected {sorted(expected)}, found {sorted(recorded)}"
        )

    tracker["posts"] = entries
    tracker.update(
        {
            "updatedAt": date.today().isoformat(),
            "auditScope": "First 3950 stable audited posts in newest-first selection order",
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
