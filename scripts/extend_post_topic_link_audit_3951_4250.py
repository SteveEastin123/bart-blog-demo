"""Record recommendations for posts 3951-4250 in the linkage audit.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_3951_4250_working_notes.md"
BATCH_SIZE = 300


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "4792": rec(
        add=("Courses and Teaching",),
        reason="The post presents a complete doctoral seminar syllabus, assignments, readings, and teaching plan for early Christian apocrypha.",
    ),
    "4513": rec(
        add=("Early Christian Diversity", "Gnostic and Orthodox Conflicts"),
        reason="The post centrally evaluates whether Egyptian Christianity began with Gnostic or other non-orthodox forms before orthodox Christianity became dominant.",
        description="Examines whether early Egyptian Christianity was initially orthodox or represented by Gnostic and other competing forms.",
    ),
    "4591": rec(
        add=("Women in Early Christianity",),
        reason="The post moves beyond Paul's congregations to sustained discussion of women's leadership and opposition to it across early Christian communities.",
    ),
    "4282": rec(
        add=("Pentateuch",),
        reason="The post is entirely about the discovery, contents, dating, and significance of a complete medieval Torah scroll.",
    ),
    "4285": rec(
        add=("Historical Study and Theology",),
        reason="The post centrally asks how Christians reconcile historical conclusions about an apocalyptic Jesus with theological belief.",
    ),
    "4163": rec(
        add=("Christian Anti-Judaism",),
        reason="The post directly examines how belief in Jesus as God intensified Christian accusations that Jews had killed God.",
    ),
    "4171": rec(
        add=("Archaeology and Material Evidence",),
        reason="The post substantially discusses excavated remains at Caesarea and the Pilate inscription as material historical evidence.",
    ),
    "4139": rec(
        add=("Academic Careers and University Life",),
        reason="The post compares liberal-arts colleges with a major research university, including class size, faculty work, and student relationships.",
    ),
    "4007": rec(
        add=("Visionary Experiences",),
        reason="The post is a sustained examination of reported modern visions of Jesus and their relevance to resurrection appearances.",
    ),
    "3927": rec(
        add=("Moral Philosophy",),
        reason="The post centrally explains why moral conduct and concern for others do not require belief in God or an afterlife.",
    ),
    "3898": rec(
        add=("Exaltation Christology", "How Jesus Became God"),
        reason="The post contrasts exaltation with incarnation Christology while explaining a major change in the argument of How Jesus Became God.",
    ),
    "3868": rec(
        add=("Incarnation Christology",),
        reason="The post centrally connects John's incarnate Logos with Jewish traditions about personified divine Wisdom.",
    ),
    "3821": rec(
        add=("Scribal Changes", "Textual Variants"),
        reason="The post evaluates competing forms of Luke 3:22 and explains why an anti-adoptionist scribe likely changed the wording.",
    ),
    "3809": rec(
        add=("Acts",),
        reason="The post analyzes exaltation Christology specifically in Peter's and Paul's speeches in Acts.",
    ),
    "3589": rec(
        add=("Modern End-Times Interpretation",),
        reason="The post compares modern failed end-time predictions such as Y2K with the chronology in the Letter of Barnabas.",
    ),
    "3723": rec(
        add=("Historical Study and Theology",),
        reason="The guest post centrally describes integrating historical-critical study of the Bible with continuing Christian faith.",
    ),
    "3675": rec(
        add=("Jesus' Ethics",),
        reason="The post is a sustained methodological reflection on interpreting Jesus' ethical teaching in ancient and modern contexts.",
    ),
    "3415": rec(
        add=("Jesus' Birth Narratives", "Personal Reflections"),
        reason="The post combines substantial discussion of Gospel birth traditions with a personal reflection on appreciating Christmas as an agnostic.",
    ),
    "3371": rec(
        add=("Media Coverage and Reviews",),
        reason="The post reviews Pope Benedict's infancy-narrative book and evaluates the media coverage surrounding it.",
    ),
    "3064": rec(
        add=("Writing and Publishing Process",),
        reason="The post centrally explains the extensive research and selection involved in producing serious books and textbooks.",
        description="Explains why strong books require extensive background research, using resurrection research and a planned textbook as examples.",
    ),
    "3020": rec(
        add=("Jesus and Women",),
        remove=("Women in Early Christianity",),
        reason="The post concerns women in Gospel traditions about Jesus' empty tomb, not women's roles or leadership in early Christian communities.",
    ),
    "2935": rec(
        add=("Resurrection of Jesus", "Visionary Experiences"),
        reason="The post gives sustained treatment to Ludemann's reconstruction of resurrection belief as arising from visions of Jesus.",
    ),
    "2852": rec(
        add=("Historical Study and Theology",),
        reason="The post directly explains how historical conclusions may affect theology while theological commitments should not determine historical analysis.",
    ),
    "2792": rec(
        add=("Historical Study and Theology",),
        reason="The post centrally examines the relationship between loss of faith, critical scholarship, historical conclusions, and theological belief.",
    ),
    "2784": rec(
        add=("Life After Death (General)",),
        reason="The post surveys annihilation, disembodied existence, and bodily resurrection as competing conceptions of life after death.",
    ),
    "2776": rec(
        add=("Comparative Ancient Evidence", "Historical Methods (General)"),
        reason="The post explains contextual historical interpretation and uses Apollonius of Tyana as an extended ancient comparison for Jesus traditions.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 3951-4250 Working Notes",
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
    if len(tracker.get("posts", [])) != 3950:
        raise ValueError("Tracker must contain exactly 3950 audited posts")
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
            "auditScope": "First 4250 stable audited posts in newest-first selection order",
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
