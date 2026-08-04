"""Record recommendations for posts 4251-4390 in the linkage audit.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_4251_4390_working_notes.md"
BATCH_SIZE = 140


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "2755": rec(
        add=("Atonement in Luke-Acts",),
        reason="The post centrally contrasts Mark's atoning interpretation of Jesus' death with Luke's removal of that interpretation.",
    ),
    "2721": rec(
        add=("Redaction Criticism",),
        reason="The post explicitly uses redaction criticism to show how Luke edited Mark's passion narrative and removed Jesus' agony.",
    ),
    "2693": rec(
        add=("Atonement in Luke-Acts",),
        reason="The disputed Last Supper text and its effect on whether Luke presents Jesus' death as an atoning sacrifice are the post's central subjects.",
        description="Explains how a disputed Last Supper passage affects whether Luke presents Jesus' death as an atoning sacrifice.",
    ),
    "2536": rec(
        add=("Forgery and Counterforgery",),
        reason="The post presents the opening anecdote from Forgery and Counterforgery and explains its relevance to the book's study of deception.",
    ),
    "2533": rec(
        add=("Writing and Publishing Process",),
        reason="The post is centrally about checking page proofs and how publication work disrupted a planned writing schedule.",
    ),
    "2512": rec(
        add=("Paul and His Opponents",),
        reason="The post contrasts Acts' harmonious portrait of Paul and the Jerusalem apostles with Paul's account of conflict involving Peter and James's associates.",
        description="Contrasts Acts' portrayal of harmony between Paul and the Jerusalem apostles with Paul's account of conflict involving Peter and James's associates.",
    ),
    "2441": rec(
        add=("Christian Interpretation of Jewish Scripture",),
        reason="The post centrally examines Matthew's Christian interpretation and translation of Isaiah 7 in support of the virgin birth.",
    ),
    "2429": rec(
        add=("Book of Isaiah",),
        reason="The entire post interprets the Suffering Servant in Second Isaiah and considers its later Christian use.",
    ),
    "2385": rec(
        add=("Writing and Publishing Process",),
        reason="The post compares the publishing economics of scholarly books, textbooks, and trade books for authors.",
    ),
    "2311": rec(
        add=("Early Christian Diversity",),
        reason="The post asks why one form of Christianity prevailed among multiple competing forms in the early church.",
    ),
    "2268": rec(
        remove=("Canon Formation",),
        reason="Canon formation is one item in a broad list of seminar sessions rather than a primary or sustained subject of this overview post.",
    ),
    "2263": rec(
        add=("Did Jesus Exist?",),
        reason="This installment is explicitly part of the Witherington discussion of Did Jesus Exist? and addresses the book's historical methods and mythicism arguments.",
    ),
    "2274": rec(
        add=("Did Jesus Exist?",),
        reason="This installment is explicitly part of the Witherington discussion of Did Jesus Exist? and evaluates evidence and methods used in the book.",
    ),
    "2290": rec(
        add=("Did Jesus Exist?",),
        reason="This installment is explicitly part of the Witherington discussion of Did Jesus Exist? and addresses mythicist interpolation arguments.",
    ),
    "2357": rec(
        add=("Did Jesus Exist?",),
        reason="This final installment in the Witherington series discusses resurrection arguments raised in response to Did Jesus Exist?.",
    ),
    "2359": rec(
        add=("Did Jesus Exist?",),
        reason="This alternate final installment in the Witherington series discusses resurrection arguments raised in response to Did Jesus Exist?.",
    ),
    "2103": rec(
        add=("Original Text Questions",),
        reason="The central question is whether miracle stories belonged to the original Gospel texts, evaluated through early manuscript, versional, and patristic evidence.",
    ),
    "2095": rec(
        add=("Apocalyptic Jesus",),
        reason="The post concludes that Jesus went to Jerusalem to proclaim his apocalyptic message and that this proclamation led to his execution.",
    ),
    "1945": rec(
        remove=("Free Will and Predestination",),
        reason="The post specifically critiques free-will explanations for natural suffering; the more precise Free Will Explanations of Suffering topic already captures that focus.",
    ),
    "1715": rec(
        add=("Misquoting Jesus", "Theologically Significant Variants"),
        reason="The post defends Misquoting Jesus against evangelical criticism and centrally argues that textual variants affect theologically important passages.",
    ),
    "26631": rec(
        add=("Source Criticism",),
        reason="The guest post is centrally a source-critical reconstruction of an earlier Jewish apocalypse incorporated into Revelation.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 4251-4390 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited all 140 previously unaudited posts.",
        "- Rechecked the five posts downloaded on August 3, 2026 for over-tagging; all current topic assignments are central and should remain.",
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
    if len(tracker.get("posts", [])) != 4250:
        raise ValueError("Tracker must contain exactly 4250 audited posts")
    if tracker.get("pendingApprovalCount") != 0:
        raise ValueError("Resolve existing pending recommendations before extending")

    audited_ids = {str(entry["wpId"]) for entry in tracker["posts"]}
    batch = [post for post in posts if str(post["wpId"]) not in audited_ids]
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
            "auditScope": "All 4390 posts in the canonical search index",
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
