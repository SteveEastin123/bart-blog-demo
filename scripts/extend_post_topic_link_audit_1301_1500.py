"""Record post-topic audit recommendations for posts 1301-1500.

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


def rec(add=(), remove=(), reason=None, description=None):
    value = {
        "add": list(add),
        "remove": list(remove),
        "reason": reason,
    }
    if description is not None:
        value["description"] = description
    return value


RECOMMENDATIONS = {
    "32678": rec(
        add=("Jesus' Miracle Stories", "Crucifixion of Jesus", "Jesus' Resurrection Appearances"),
        reason=(
            "The proposed Roman-collaboration theory is developed through alleged Roman "
            "staging of Jesus' miracles, crucifixion, and appearances after death."
        ),
    ),
    "32259": rec(
        add=("Biblical Contradictions", "Fundamentalism"),
        reason=(
            "The guest post gives sustained attention to evangelical responses to biblical "
            "differences and describes apologetics moving beyond fundamentalist assumptions."
        ),
    ),
    "32674": rec(
        add=("Roman World",),
        reason=(
            "The entire guest argument proposes that Roman political interests helped create "
            "and shape the early Jesus movement."
        ),
    ),
    "32529": rec(
        add=("Biblical Contradictions",),
        reason=(
            "The post explicitly asks how to discuss contradictions and other historical and "
            "literary problems in the Gospels."
        ),
    ),
    "32502": rec(
        add=("Forgery (General)",),
        reason=(
            "A major section explains forged apostolic writings and the authority they claimed "
            "within debates over the New Testament canon."
        ),
    ),
    "31878": rec(
        add=("Jesus' Birth Narratives", "Proto-Gospel of James"),
        reason=(
            "The argument reconstructs a John infancy source behind Luke and the Proto-Gospel "
            "of James and explains its adaptation into traditions about Jesus' birth."
        ),
    ),
    "32498": rec(
        add=("Jesus' Resurrection Appearances", "Empty Tomb Traditions"),
        reason=(
            "The post's extended examples concern where Jesus appeared after death and how many "
            "women discovered the empty tomb."
        ),
    ),
    "32435": rec(
        remove=("Gospel of Mark",),
        reason=(
            "Mark is only one of several Gospels compared in the discussion and is not a distinct "
            "or sustained subject of the post."
        ),
    ),
    "32450": rec(
        add=("Gnosticism (General)", "Christology (General)"),
        reason=(
            "The post centrally explains separationist and docetic Gnostic understandings of "
            "Christ through the Coptic Apocalypse of Peter."
        ),
    ),
    "32445": rec(
        add=("Christology (General)",),
        reason=(
            "The Coptic Apocalypse's account of who truly suffered at the crucifixion is a central "
            "Christological subject of the post."
        ),
    ),
    "32546": rec(
        add=("Hebrew Bible Composition and Sources",),
        reason=(
            "The entire argument concerns the composition and authorship of Deuteronomy and "
            "Jeremiah's alleged literary response to it."
        ),
    ),
    "32394": rec(
        add=("Petrine Authorship and Forgeries", "Jewish Law and Torah"),
        reason=(
            "The post examines a forged letter attributed to Peter whose central dispute concerns "
            "Paul and whether the law of Moses remains binding."
        ),
    ),
    "32329": rec(
        add=("Gospel Historical Reliability", "Critical Biblical Scholarship"),
        reason=(
            "The post defends critical scholarship's historical and literary evaluation of the "
            "Gospels and directly addresses their reliability."
        ),
    ),
    "31887": rec(
        add=("Romans", "Free Will and Predestination"),
        reason=(
            "The post interprets Romans 9 at length, focusing on divine election, sovereignty, "
            "human response, and judgment."
        ),
    ),
    "32298": rec(
        add=("Textual Criticism Overview",),
        reason=(
            "The central argument explains why manuscript numbers alone cannot establish the "
            "reconstruction or reliability of the New Testament text."
        ),
    ),
    "32267": rec(
        add=("Charity and Altruism",),
        reason=(
            "The post presents Christian hospitals as the institutional development of organized "
            "care for people in need."
        ),
    ),
    "32062": rec(
        add=("Charity and Altruism",),
        reason=(
            "A major section traces almsgiving, mutual support, and organized giving from Jesus "
            "through Paul and later Christians."
        ),
    ),
    "31948": rec(
        add=("Gospel of Mark",),
        reason=(
            "The guest argument is built from sustained close reading of Mark's two Marys and two "
            "figures named James."
        ),
    ),
    "31495": rec(
        add=("Deconversion",),
        remove=("Ignore",),
        reason=(
            "The post introduces a play and support work centered on clergy who have lost faith "
            "while remaining in ministry, making deconversion its substantive subject."
        ),
        description=(
            "Introduces a play and support project centered on clergy who have lost faith while "
            "remaining in ministry."
        ),
    ),
    "31491": rec(
        add=("Writing and Publishing Process",),
        reason=(
            "The post presents a book prospectus and gives sustained attention to the purpose and "
            "role of a prospectus in the publishing process."
        ),
    ),
    "31191": rec(
        add=("Jesus' Resurrection Appearances", "Visionary Experiences"),
        reason=(
            "The historical reconstruction centers on Peter's and Paul's psychologically induced "
            "visions and their role in producing resurrection belief."
        ),
    ),
    "31177": rec(
        add=("Jesus on Wealth and Poverty", "Jesus' Teachings"),
        reason=(
            "Most of the post interprets the rich man and Lazarus and related teachings of Jesus "
            "about wealth, poverty, and salvation."
        ),
    ),
    "30969": rec(
        add=("Early Christian Teachings on Wealth", "Charity and Altruism"),
        reason=(
            "The post uses the Acts of Thomas to explain early Christian teaching on wealth, "
            "giving to the poor, and charitable action."
        ),
    ),
    "30857": rec(
        add=("Charity and Altruism",),
        reason=(
            "The entire post traces the obligation to aid poor people from the Hebrew Bible "
            "through Jesus and early Christianity."
        ),
    ),
    "30223": rec(
        add=("Gospel Historical Reliability",),
        reason=(
            "The entire guest argument assesses whether sayings attributed to Jesus in John are "
            "historically more plausible than those in Mark."
        ),
    ),
    "29861": rec(
        add=("Christian Interpretation of Jewish Scripture",),
        reason=(
            "The post contrasts Isaiah 53 in its original setting with its later Christian "
            "interpretation as a prediction of Jesus' death and resurrection."
        ),
    ),
    "15929": rec(
        add=("Christian Anti-Judaism",),
        reason=(
            "A major part explains how the Barabbas tradition shifted responsibility for Jesus' "
            "death from Pilate to the Jewish people."
        ),
    ),
    "29856": rec(
        add=("Jesus' Resurrection Appearances", "Rise of Christianity"),
        reason=(
            "The central argument is that reported appearances generated resurrection belief and "
            "that this belief launched Christianity."
        ),
    ),
    "15168": rec(
        add=("Gospel of Matthew", "Gospel of John"),
        reason=(
            "The post gives sustained, parallel explanations of the traditional names assigned "
            "to all four canonical Gospels; Matthew and John are as central as Mark and Luke."
        ),
    ),
    "30220": rec(
        add=("Gospel Historical Reliability",),
        reason=(
            "The entire post compares John's chronology with the Synoptics to argue that John's "
            "account may be more historically credible."
        ),
    ),
    "11520": rec(
        add=("Rise of Christianity", "Peter the Apostle"),
        reason=(
            "The post compares candidates for the founder of Christianity, gives Peter a major "
            "dedicated section, and centrally addresses Christian origins."
        ),
    ),
    "29928": rec(
        add=("Mary Magdalene in Gnostic Traditions",),
        remove=("Non-Canonical Gospel Traditions",),
        reason=(
            "The Greater Questions of Mary and its portrayal of Mary Magdalene are examined at "
            "length, making the broad noncanonical-Gospel label redundant."
        ),
    ),
    "29925": rec(
        add=("Mary Magdalene in Gnostic Traditions",),
        remove=("Non-Canonical Gospel Traditions",),
        reason=(
            "The Greater Questions of Mary and its portrayal of Mary Magdalene are examined at "
            "length, making the broad noncanonical-Gospel label redundant."
        ),
    ),
    "29782": rec(
        add=("Jesus' Teachings",),
        reason=(
            "The post repeatedly contrasts Revelation's vision of vengeance and domination with "
            "Jesus' teachings on service, love, humility, and care for others."
        ),
    ),
    "3448": rec(
        add=("Jesus' Birth Narratives",),
        reason=(
            "Matthew's infancy narrative supplies nearly all the fulfillment citations and "
            "examples examined in the post."
        ),
    ),
    "29776": rec(
        add=("Book of Revelation",),
        reason=(
            "Revelation's ideology of vengeance and dominance is one of the two principal subjects "
            "of the comparison with Jesus and Roman society."
        ),
    ),
    "12391": rec(
        add=("Deconversion", "Apocalyptic Jesus"),
        remove=("Eternal Punishment",),
        reason=(
            "The post's central personal argument is that the author cannot follow Jesus because "
            "he rejects Jesus' apocalyptic location of ultimate meaning beyond this world; eternal "
            "punishment is only one element in the background explanation."
        ),
        description=(
            "Explains why Bart no longer identifies as Christian, focusing on disagreement with "
            "Jesus' apocalyptic expectation that ultimate meaning lies beyond this world."
        ),
    ),
    "29888": rec(
        add=("Blog Updates and Fundraising",),
        remove=("Ignore",),
        reason=(
            "The anniversary lottery is explicitly a blog fundraiser whose proceeds support "
            "disaster relief in Ukraine."
        ),
        description=(
            "Announces a blog-anniversary lottery fundraiser for disaster relief in Ukraine."
        ),
    ),
    "29298": rec(
        add=("Biblical Inerrancy",),
        reason=(
            "The post centrally distinguishes inspiration, inerrancy, and textual preservation "
            "while correcting an account of the author's changing beliefs."
        ),
    ),
    "29185": rec(
        add=("Modern Forgery Claims",),
        reason=(
            "The entire post exposes a modern fabrication presented as an ancient eyewitness "
            "account of Jesus' crucifixion."
        ),
    ),
    "29381": rec(
        add=("Gospel of Luke",),
        remove=("Canonical Gospels (General)",),
        reason=(
            "The guest post specifically compares Matthew and Luke and argues that Luke rewrote "
            "Matthew's nativity account; the broad canonical-Gospels topic is redundant."
        ),
    ),
    "28781": rec(
        add=("Original Text Questions",),
        reason=(
            "The entire post asks whether scholars could identify a newly discovered manuscript "
            "as the original text rather than merely date it."
        ),
    ),
}


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def main() -> None:
    posts = load_json(POSTS_PATH)
    topics_data = load_json(TOPICS_PATH)
    tracker = load_json(TRACKER_PATH)
    if not isinstance(posts, list):
        raise TypeError("Unexpected post index shape")
    if not isinstance(topics_data, dict) or not isinstance(tracker, dict):
        raise TypeError("Unexpected topic or tracker shape")
    if len(tracker.get("posts", [])) not in {1300, 1500}:
        raise ValueError("Tracker must contain the first 1300 or 1500 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:1300])
    for source_index, post in enumerate(posts[1300:1500], start=1300):
        sequence = source_index + 1
        wp_id = str(post["wpId"])
        original = list(post.get("topics", []))
        recommendation = RECOMMENDATIONS.get(
            wp_id, {"add": [], "remove": [], "reason": None}
        )
        added = list(recommendation["add"])
        removed = list(recommendation["remove"])
        description = recommendation.get("description")

        unknown = (set(original) | set(added) | set(removed)) - valid_topics
        if unknown:
            raise ValueError(f"Unknown topics for {wp_id}: {sorted(unknown)}")
        if not set(removed).issubset(original):
            raise ValueError(f"Cannot remove absent topic from {wp_id}: {removed}")
        if set(added) & set(original):
            raise ValueError(f"Cannot add existing topic to {wp_id}: {added}")

        recommended = [topic for topic in original if topic not in removed]
        recommended.extend(topic for topic in added if topic not in recommended)
        changed = bool(added or removed or description is not None)
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
        if description is not None:
            entry["descriptionBefore"] = post.get("description")
            entry["descriptionRecommended"] = description
        entries.append(entry)

    expected_recommendations = set(RECOMMENDATIONS)
    recorded_recommendations = {
        entry["wpId"] for entry in entries[1300:] if entry["status"] == "pending_approval"
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
            "auditScope": (
                "First 1500 canonical search-index posts in current newest-first order"
            ),
            "reviewedPostCount": len(entries),
            "noChangeCount": sum(
                entry["status"] == "reviewed_no_change" for entry in entries
            ),
            "pendingApprovalCount": sum(
                entry["status"] == "pending_approval" for entry in entries
            ),
            "appliedChangeCount": sum(entry["status"] == "applied" for entry in entries),
        }
    )
    TRACKER_PATH.write_text(
        json.dumps(tracker, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(
        f"Recorded {len(entries)} reviewed posts: "
        f"{tracker['noChangeCount']} no change, "
        f"{tracker['appliedChangeCount']} applied, "
        f"{tracker['pendingApprovalCount']} pending approval."
    )


if __name__ == "__main__":
    main()
