"""Record post-topic audit recommendations for posts 2001-2250.

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
NOTES_PATH = ROOT / "data" / "audits" / "post_topic_link_audit_2001_2250_working_notes.md"


def rec(add=(), remove=(), reason=None, description=None):
    return {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
        "description": description,
    }


RECOMMENDATIONS = {
    "21093": rec(
        add=("Son of Man", "Apocalyptic Jesus"),
        reason="The post centers on Jesus' expectation that the Son of Man would judge the earth and situates that expectation within his apocalyptic message.",
    ),
    "21086": rec(
        add=("Son of Man",),
        reason="The post uses Jesus' Son of Man sayings as its sustained case study for deciding what Jesus historically said.",
    ),
    "21054": rec(
        add=("Romans", "Pauline Salvation Models"),
        remove=("Salvation (General)",),
        reason="The post closely analyzes Romans 9-11 and Paul's account of Israel's salvation, making the Pauline topics more precise than general salvation.",
    ),
    "21031": rec(
        remove=("Gnostic and Orthodox Conflicts",),
        reason="The post defines Gnosticism and discusses Gnostic writings but does not focus on conflict with orthodox Christians.",
    ),
    "21009": rec(
        add=("Romans",),
        reason="The post's central question about Paul's use of Israel is developed through Romans 9-11.",
    ),
    "20997": rec(
        add=("Textual Criticism Overview", "Fundamentalism"),
        reason="The post explains textual criticism as a field and gives sustained attention to why conservative and fundamentalist commitments draw scholars to it.",
    ),
    "20990": rec(
        add=("Romans", "Pauline Salvation Models"),
        remove=("Salvation (General)",),
        reason="The post is a sustained analysis of Romans 9-11 and Paul's model for the salvation of Israel, not salvation in general.",
    ),
    "20836": rec(
        add=("Ignore",),
        remove=("Academic Careers and University Life",),
        reason="This is a short administrative invitation for qualified readers to contact Bart, not a substantive discussion of academic careers.",
    ),
    "20782": rec(
        add=("Zealot Hypothesis",),
        reason="The post directly evaluates whether armed disciples support a militant or zealot interpretation of Jesus and his movement.",
    ),
    "20717": rec(
        add=("Biblical Contradictions",),
        reason="The post centers on the irreconcilable differences between Matthew's and Acts' accounts of Judas's death.",
    ),
    "20711": rec(
        add=("Moral Problems in Scripture",),
        reason="The post uses two portrayals of Jesus to reflect centrally on troubling biblical ethics in relation to current crises.",
    ),
    "20266": rec(
        add=("Ignore",),
        remove=("Heaven and Hell",),
        reason="This is an event announcement; the book appears only as the subject of the advertised discussion.",
    ),
    "20284": rec(
        add=("Gospel of John",),
        reason="The guest post substantially evaluates the proposed community behind both the Gospel and letters of John.",
    ),
    "19958": rec(
        add=("Textbooks and Teaching Materials",),
        remove=("Ignore", "Heaven and Hell"),
        reason="Most of the post introduces and explains Bart's three Oxford textbooks; the webinar and Heaven and Hell supply the occasion rather than the sustained subject.",
        description="Announces an Oxford webinar and introduces Bart's three textbooks as resources for studying the New Testament and Bible.",
    ),
    "17833": rec(
        add=("Ignore",),
        remove=("Heaven and Hell",),
        reason="This is a brief humorous publication-day anecdote rather than a substantive discussion of the book.",
    ),
    "17796": rec(
        add=("Original Text Questions",),
        reason="The post substantially evaluates whether the Philippians 2 poem was part of Paul's original letter or a later insertion.",
    ),
    "17780": rec(
        add=("Ignore",),
        remove=("Heaven and Hell",),
        reason="This is a preorder and discount promotion without substantive discussion of the book's subject.",
    ),
    "17776": rec(
        add=("Pastoral Epistles",),
        reason="The post gives sustained attention to the Pastoral Epistles' authorship and restrictions on women.",
    ),
    "17769": rec(
        add=("Original Text Questions",),
        reason="A substantial part of the post examines whether 1 Corinthians 14:34-35 is an interpolation and how that can be determined.",
    ),
    "17749": rec(
        add=("Ignore",),
        remove=("Heaven and Hell",),
        reason="This one-sentence post merely announces a canceled Smithsonian event.",
    ),
    "17737": rec(
        add=("Original Text Questions",),
        reason="The post centrally distinguishes textual variants from the question whether material belonged to an author's original work.",
    ),
    "17708": rec(
        add=("Angelic Christology",),
        reason="The post argues that Philippians 2 presents Jesus as a preexistent angelic or Wisdom figure who became human.",
    ),
    "17699": rec(
        add=("Angelic Christology", "Exaltation Christology"),
        reason="The exposition combines Jesus' preexistent angelic status with his later exaltation after death.",
    ),
    "17688": rec(
        add=("Incarnation Christology", "Angelic Christology"),
        reason="The post rejects an Adam-only reading of Philippians 2 in favor of a preexistent divine or angelic being who became human.",
    ),
    "17665": rec(
        remove=("Heaven and Hell",),
        reason="This is solely a discount promotion for the book; the existing Ignore topic is sufficient.",
    ),
    "17662": rec(
        add=("Biblical Contradictions", "Biblical Inerrancy"),
        reason="The interview repeatedly addresses contradictions, inerrancy, and how critical study changed Bart's view of the Bible.",
    ),
    "17600": rec(
        remove=("Heaven and Hell",),
        reason="This is only a raffle-winner notice; the existing Ignore topic is sufficient.",
    ),
    "17591": rec(
        add=("Apocalyptic Jesus",),
        reason="The historical argument for Jesus' celibacy depends substantially on his apocalyptic ethic and expectation of the coming kingdom.",
    ),
    "17574": rec(
        remove=("Gospel of Jesus' Wife Fragment",),
        reason="The post evaluates whether the historical Jesus was married; the fragment appears only as background to the panel discussion.",
    ),
    "17569": rec(
        remove=("Mary Magdalene in Gnostic Traditions",),
        reason="The post critiques a modern allegorical reading of Joseph and Aseneth; Gnostic traditions about Mary are only contextual background.",
    ),
    "17564": rec(
        remove=("Heaven and Hell",),
        reason="The post is a fundraising raffle announcement and does not discuss the book's contents.",
    ),
    "17561": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Triumph of Christianity",),
        reason="The post offers donated Korean copies to readers and does not discuss the book's historical subject.",
    ),
    "17507": rec(
        remove=("Heaven and Hell",),
        reason="This is an auction update without substantive discussion of the book; the existing Ignore topic is sufficient.",
    ),
    "17505": rec(
        add=("First-Century Mark Fragment",),
        reason="Half of the post comments directly on the First-Century Mark controversy and directs readers to the evidence about it.",
    ),
    "17368": rec(
        add=("Afterlife Journeys",),
        reason="The entire post compares Christian texts that narrate guided journeys through realms of the dead.",
    ),
    "17360": rec(
        add=("Afterlife Journeys",),
        reason="The post introduces a scholarly book project specifically devoted to ancient otherworldly journeys.",
    ),
    "17327": rec(
        add=("Critical Biblical Scholarship",),
        reason="The post centrally explains why a nonbeliever can study the Bible as a historically and culturally important academic subject.",
    ),
    "17310": rec(
        add=("Biblical Contradictions", "Biblical Inerrancy"),
        reason="The guest post explains Gospel differences through ancient compositional practices while defending inspiration and inerrancy.",
    ),
    "17237": rec(
        add=("Apocalyptic Jesus",),
        reason="The historical overview repeatedly identifies Jesus' apocalyptic proclamation of God's imminent kingdom as the heart of his message.",
    ),
    "17217": rec(
        add=("Scholarly Research and Publishing",),
        reason="The post reflects at length on academic book production, publishing incentives, and the glut of scholarly literature.",
    ),
    "17191": rec(
        add=("Theologically Significant Variants",),
        reason="The post's main argument is that some textual variants materially affect the interpretation and theology of New Testament books.",
    ),
    "17185": rec(
        add=("Original Text Questions",),
        reason="A substantial part asks whether surviving manuscripts allow scholars to recover the original wording of New Testament books.",
    ),
    "17005": rec(
        add=("Pastoral Epistles", "Women in Early Christianity"),
        reason="The post centers on 1 Timothy's use of Genesis to require women's silence and submission in Christian communities.",
    ),
    "16994": rec(
        add=("Genesis",),
        reason="The currently untagged post is a sustained interpretation of Genesis 2 as a biblical rationale for women's subordination.",
    ),
    "16931": rec(
        add=("Resurrection Arguments and Apologetics",),
        reason="The post directly evaluates and rejects the apologetic claim that no one would invent women as witnesses to the empty tomb.",
    ),
    "16915": rec(
        add=("Biblical Contradictions", "Biblical Inerrancy"),
        reason="The post's sustained subject is how modern evangelical apologists explain Gospel contradictions while retaining inerrancy.",
    ),
    "16892": rec(
        add=("Visionary Experiences",),
        reason="The post develops the proposal that visions experienced by Peter, Paul, and Mary explain early resurrection belief.",
    ),
    "16873": rec(
        add=("Visionary Experiences",),
        reason="The post uses visionary experiences to explain why some disciples believed Jesus was raised while others doubted.",
    ),
    "16851": rec(
        add=("Original Text Questions",),
        reason="The critique of claims to 99-percent certainty centers on whether scholars can know the original New Testament wording without the originals.",
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def render_notes(entries: list[dict]) -> str:
    lines = [
        "# Post Topic Link Audit 2001-2250 Working Notes",
        "",
        "Canonical post records remain unchanged until the user approves this batch.",
        "",
        "## Progress",
        "",
        "- Audited through post 2250.",
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
    if len(tracker.get("posts", [])) not in {2000, 2250}:
        raise ValueError("Tracker must contain the first 2000 or 2250 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:2000])
    descriptions = [
        item
        for item in tracker.get("pendingDescriptionRecommendations", [])
        if item.get("auditSequence", 0) <= 2000
    ]
    batch_entries = []
    for source_index, post in enumerate(posts[2000:2250], start=2000):
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

    expected_recommendations = set(RECOMMENDATIONS)
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
            "auditScope": "First 2250 canonical search-index posts in current newest-first order",
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
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval; "
        f"batch recommendations: {len(recorded_recommendations)}."
    )


if __name__ == "__main__":
    main()
