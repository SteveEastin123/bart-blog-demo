"""Record post-topic audit recommendations for posts 2851-3150.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_2851_3150_working_notes.md"


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "12756": rec(
        add=("Jewish Apocalypticism",),
        reason="The full post explains the ancient Jewish apocalyptic worldview associated with Jesus and Paul before considering its modern reinterpretation.",
    ),
    "12709": rec(
        add=("Scholarly Research and Publishing",),
        reason="The post is devoted to conceiving, editing, and publishing two editions of a major scholarly reference work.",
    ),
    "12675": rec(
        add=("Acts", "Textual Variants"),
        reason="The post substantially compares Judas's death in Matthew, Acts, and Papias, then explains manuscript variants and evangelical responses to them.",
        description="Compares accounts of Judas's death in Matthew, Acts, and Papias, then explains how evangelicals approach New Testament textual variants.",
    ),
    "12667": rec(
        add=("Gospel of Mark", "Gospel of Luke", "Biblical Contradictions"),
        reason="The entire post contrasts Mark's and Luke's Passion narratives and argues that their portrayals cannot simply be combined.",
    ),
    "12638": rec(
        add=("Heaven and Hell", "Writing and Publishing Process"),
        reason="The publication update discusses both the strategy for publishing Triumph of Christianity and active research and planning for the afterlife book.",
    ),
    "12576": rec(
        add=("Biblical Inerrancy",),
        reason="The post centers on abandoning biblical inerrancy while continuing to identify as a Christian.",
    ),
    "12563": rec(
        add=("Genesis", "Pentateuch", "Hebrew Bible Composition and Sources"),
        reason="The post gives a sustained treatment of contradictions and source tensions in Genesis and the Pentateuch.",
    ),
    "12447": rec(
        remove=("Eternal Punishment",),
        reason="The post explicitly explains that Sheol is a common realm of the dead rather than a place of eternal punishment or reward.",
        description="Explains Sheol as the Hebrew Bible's realm of the dead, not a place of reward or eternal punishment.",
    ),
    "12434": rec(
        add=("Jesus' Resurrection Appearances",),
        reason="A substantial portion of the post analyzes resurrection-appearance traditions and claims that the risen Jesus physically interacted with followers.",
    ),
    "12420": rec(
        add=("Christology in the Gospels",),
        reason="The first major section closely compares how Mark and John portray Jesus' divine identity.",
    ),
    "12397": rec(
        add=("Heaven and Hell",),
        reason="The post lays out the planned content, historical questions, and organizing argument of Bart's book on the afterlife.",
    ),
    "12381": rec(
        add=("Heaven and Hell",),
        reason="The post describes research for the proposed afterlife book and develops its central historical questions.",
    ),
    "12334": rec(
        add=("Textual Criticism Overview",),
        reason="The post gives an extended history and explanation of Greek New Testament editions, textual witnesses, variants, and the original-text problem.",
    ),
    "12326": rec(
        add=("Scribal Changes", "Textual Variants"),
        reason="The entire post responds to questions about manuscript differences and the effects of scribal alteration on the New Testament text.",
    ),
    "12255": rec(
        add=("Canon Formation",),
        reason="A central purpose of the post is to correct the claim that Nicaea determined the biblical canon and to explain the canon's chronology.",
    ),
    "12149": rec(
        add=("Personal Reflections", "Problem of Evil and Suffering"),
        reason="The post is a sustained personal reflection on Christian faith, suffering, moral values, and the incarnation story.",
        description="Reflects on suffering, estrangement from Christian faith, and the ethical values still found meaningful in the Christmas story.",
    ),
    "12107": rec(
        add=("Miracle Stories in Non-Canonical Texts",),
        reason="The post retells and interprets numerous childhood miracles attributed to Jesus in the Infancy Gospel of Thomas.",
    ),
    "12004": rec(
        add=("Peter the Apostle", "Mythicism", "Paul's Knowledge of Jesus"),
        reason="The post uses Paul's acquaintance with Peter, John, and James as sustained evidence against mythicist claims and for Paul's knowledge of the historical Jesus.",
    ),
    "12002": rec(
        add=("Mythicism",),
        reason="The post arises directly from the mythicist debate and argues against the claim that Paul knew nothing of a historical Jesus.",
    ),
    "11992": rec(
        add=("Mythicism",),
        reason="The post explicitly argues that multiple Gospel sources provide evidence against the claim that Jesus was invented.",
    ),
    "11989": rec(
        add=("Gospel Historical Reliability",),
        reason="The post evaluates multiple independent Gospel sources as historical evidence for Jesus' existence.",
    ),
    "11981": rec(
        add=("Jesus' Ethics",),
        reason="After explaining textual-critical manuscript quality, the post gives a sustained argument that social justice was central to Jesus' message.",
        description="Explains how textual critics identify the best manuscripts and argues that social justice was central to Jesus' message.",
    ),
    "11935": rec(
        add=("Heaven and Hell",),
        reason="The entire post develops the concept, title, scope, and questions for Bart's proposed book on the afterlife.",
    ),
    "11929": rec(
        add=("Visionary Experiences", "Jesus' Resurrection Appearances"),
        reason="The post's reconstruction of resurrection belief centers on Peter's and Paul's visions of Jesus after his death.",
    ),
    "11921": rec(
        add=("Jesus' Resurrection Appearances", "Visionary Experiences"),
        reason="A substantial section analyzes doubt, recognition, and visionary experience within traditions of Jesus' resurrection appearances.",
    ),
    "11893": rec(
        add=("Historical Study and Theology",),
        reason="The entire post defines the proper relationship between theological belief and historical analysis.",
    ),
    "11823": rec(
        add=("Textual Criticism Overview", "Autobiographical Posts"),
        reason="The post is an autobiographical account of Bart's self-training in textual criticism and subsequent study with Bruce Metzger.",
    ),
    "11777": rec(
        add=("Historical Study and Theology",),
        reason="The post explains the difference between historical or descriptive approaches to New Testament theology and confessional theological interpretation.",
    ),
    "11763": rec(
        add=("Historical Study and Theology",),
        reason="The full post distinguishes exegesis and historical description from theology and contemporary application.",
    ),
    "11734": rec(
        add=("Gospel Historical Reliability", "Textual Criticism Overview"),
        reason="A substantial section explicitly distinguishes reconstructing John's text from evaluating the Gospel's historical accuracy.",
    ),
    "11669": rec(
        add=("Conversion",),
        reason="The post narrates Constantine's rise and the Battle of the Milvian Bridge as the setting for his reported conversion.",
    ),
    "11640": rec(
        add=("Atonement (General)",),
        reason="One full section evaluates whether Jesus' words at the Last Supper present his death as an atoning sacrifice.",
    ),
    "11615": rec(
        add=("Comparative Ancient Evidence",),
        reason="The post substantially compares the flood stories in Gilgamesh and Atrahasis with the Genesis account.",
    ),
    "11543": rec(
        description="Shares an unusual video explaining apocalypticism, briefly touching on Jesus and modern end-time prediction.",
    ),
    "11595": rec(
        add=("Pentateuch", "Hebrew Bible Historical Reliability"),
        reason="The post uses archaeological evidence concerning the Philistines and Beersheba to test Pentateuchal chronology and Mosaic authorship.",
    ),
    "11589": rec(
        add=("Pentateuch", "Hebrew Bible Composition and Sources"),
        reason="The post argues from literary tensions in Genesis 1-3 that the creation accounts derive from multiple sources.",
    ),
    "11576": rec(
        add=("Rise of Christianity",),
        reason="The entire post models conversion rates and population growth in the early Christian movement.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 2851-3150 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited through post 3150.",
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
        lines.append(f"- Reason: {entry['reason']}")
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) not in {2850, 3150}:
        raise ValueError("Tracker must contain the first 2850 or 3150 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:2850])
    descriptions = [
        item
        for item in tracker.get("pendingDescriptionRecommendations", [])
        if item.get("auditSequence", 0) <= 2850
    ]
    batch_entries = []
    for source_index, post in enumerate(posts[2850:3150], start=2850):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id,
            {"add": [], "remove": [], "reason": None, "description": None},
        )
        added = list(recommendation["add"])
        removed = list(recommendation["remove"])

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

    expected_recommendations = {
        wp_id
        for wp_id, recommendation in RECOMMENDATIONS.items()
        if recommendation["add"] or recommendation["remove"]
    }
    recorded_recommendations = {
        entry["wpId"] for entry in batch_entries if entry["status"] == "pending_approval"
    }
    if recorded_recommendations != expected_recommendations:
        raise ValueError(
            "Recommendation mismatch: "
            f"expected {sorted(expected_recommendations)}, "
            f"found {sorted(recorded_recommendations)}"
        )

    tracker["posts"] = entries
    tracker.update(
        {
            "updatedAt": date.today().isoformat(),
            "auditScope": "First 3150 canonical search-index posts in current newest-first order",
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
    print(
        f"Audited {len(batch_entries)} posts; "
        f"recorded {len(recorded_recommendations)} recommendations."
    )


if __name__ == "__main__":
    main()
