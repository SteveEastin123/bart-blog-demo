"""Record post-topic audit recommendations for posts 701-900.

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

RECOMMENDATIONS = {
    "38963": {
        "add": ["Historical Jesus (General)"],
        "remove": [],
        "reason": (
            "The entire post evaluates the historical plausibility of Gospel "
            "crowds, Jesus' labor and travel, and possible contact with Sepphoris."
        ),
    },
    "38934": {
        "add": ["Textual Variants"],
        "remove": [],
        "reason": (
            "A substantial central section explains agrapha preserved as scribal "
            "additions or variant readings and gives multiple manuscript examples."
        ),
    },
    "38930": {
        "add": ["Atonement in Luke-Acts"],
        "remove": [],
        "reason": (
            "Much of the post contrasts Paul's atonement theology with Luke's "
            "forgiveness-and-repentance model."
        ),
        "description": (
            "Compares Paul's speeches in Acts with his letters, especially their "
            "different understandings of Jesus' death and salvation."
        ),
    },
    "38868": {
        "add": ["Sexual and Reproductive Ethics"],
        "remove": [],
        "reason": (
            "The entire post centers on abstinence, childbirth, gender, procreation, "
            "and interpretation of sayings in the Gospel of the Egyptians."
        ),
    },
    "38778": {
        "add": ["How Jesus Became God"],
        "remove": [],
        "reason": (
            "The sustained example is Robert Barron's review and characterization "
            "of How Jesus Became God."
        ),
    },
    "38802": {
        "add": ["Early Christian Diversity"],
        "remove": [],
        "reason": (
            "The post argues that Paul was one Christian voice among many and "
            "stresses alternative forms of Christianity within and beyond his churches."
        ),
    },
    "38752": {
        "add": ["Public Debates"],
        "remove": [],
        "reason": (
            "The post is explicitly the negative rebuttal in a staged classroom "
            "debate over the historical reliability of Acts."
        ),
    },
    "38713": {
        "add": ["Public Debates"],
        "remove": [],
        "reason": (
            "The post is explicitly the negative case in a staged classroom debate "
            "over the historical reliability of Acts."
        ),
    },
    "38718": {
        "add": ["Public Debates"],
        "remove": [],
        "reason": (
            "The post is explicitly the affirmative case in a staged classroom "
            "debate over the historical reliability of Acts."
        ),
    },
    "38564": {
        "add": ["Historical Methods (General)"],
        "remove": [],
        "reason": (
            "The post's central question is how historians distinguish historical "
            "fact from legendary accounts across canonical and non-canonical Paul traditions."
        ),
    },
    "38411": {
        "add": ["Gospel of Luke"],
        "remove": [],
        "reason": (
            "Matthew and Luke's use of Mark is the central Synoptic example, and "
            "Luke is as sustained a subject as the already assigned Matthew topic."
        ),
    },
    "38300": {
        "add": ["Moral Problems in Scripture"],
        "remove": [],
        "reason": (
            "The entire post critiques attempts to make troubling Pauline gender "
            "passages morally benign."
        ),
    },
    "38221": {
        "add": ["Women in Early Christianity"],
        "remove": [],
        "reason": (
            "A major sustained section centers on Paul's teachings about women and "
            "modern efforts to reinterpret them."
        ),
    },
    "38151": {
        "add": ["Jesus' Ethics"],
        "remove": [],
        "reason": (
            "A major section contrasts Jesus' teaching on forgiveness with "
            "Greco-Roman practice and later Christian atonement and penance."
        ),
    },
    "38142": {
        "add": ["Jesus' Ethics"],
        "remove": [],
        "reason": (
            "Much of the post outlines Jesus' distinctive moral teaching on love, "
            "altruism, and care for outsiders."
        ),
    },
    "37948": {
        "add": [],
        "remove": ["Social Class in Antiquity"],
        "reason": (
            "Slavery is one supporting lexical example; the sustained subject is "
            "translation and the NRSV."
        ),
    },
    "37959": {
        "add": ["Charity and Altruism"],
        "remove": [],
        "reason": (
            "The post's central argument is giving to strangers, the poor, and "
            "enemies as the ethical meaning of Christmas."
        ),
    },
    "37854": {
        "add": ["Early Christian Orthodoxy and Heresy"],
        "remove": [],
        "reason": (
            "The post centrally explains how proto-orthodox Christians interpreted "
            "texts and alternative narratives to counter adoptionist and docetic readings."
        ),
    },
    "37850": {
        "add": [
            "Canon Formation",
            "Proto-Gospel of James",
            "Adoptionist Christology",
        ],
        "remove": ["Non-Canonical Gospel Traditions"],
        "reason": (
            "The post explains canon placement and the Proto-Gospel of James as "
            "strategies for steering Luke away from an adoptionist reading. The "
            "specific apocryphal Gospel topic is more precise than the general one."
        ),
    },
    "37845": {
        "add": ["Gospel of Luke", "Textual Variants"],
        "remove": [],
        "reason": (
            "The post is a sustained analysis of Luke's textual history, including "
            "the addition of its infancy chapters and christological scribal changes."
        ),
    },
    "37841": {
        "add": ["Gospel of Luke", "Textual Variants"],
        "remove": [],
        "reason": (
            "The post reconstructs an early form of Luke through manuscript and "
            "patristic evidence for Luke 3:22 and the possible absence of chapters 1-2."
        ),
    },
    "37837": {
        "add": ["Adoptionist Christology", "Original Text Questions"],
        "remove": [],
        "reason": (
            "The post asks whether an earlier edition of Luke lacked chapters 1-2 "
            "and develops the adoptionist implications of that possibility."
        ),
    },
    "37690": {
        "add": ["Armageddon"],
        "remove": [],
        "reason": (
            "The post is explicitly an excerpt from Armageddon explaining the rise "
            "of dispensational premillennialism."
        ),
    },
    "37686": {
        "add": ["Armageddon"],
        "remove": [],
        "reason": (
            "The post is explicitly an excerpt from Armageddon on Christian Zionism "
            "and futuristic readings of Revelation."
        ),
    },
    "37661": {
        "add": ["Apocalyptic Jesus"],
        "remove": [],
        "reason": (
            "A sustained part of the argument interprets Jesus' miracles as signs "
            "that the Kingdom of God and the apocalypse were near."
        ),
    },
    "37655": {
        "add": ["Christology in the Gospels", "Apocalyptic Jesus"],
        "remove": [],
        "reason": (
            "The post explains that miracle stories identify Jesus as God's powerful "
            "Son and present his deeds as signs of the approaching Kingdom."
        ),
    },
    "37649": {
        "add": ["Jesus Before the Gospels"],
        "remove": [],
        "reason": (
            "The post explicitly introduces and reproduces the treatment of Jesus' "
            "miracles from Jesus Before the Gospels."
        ),
    },
    "37644": {
        "add": ["New Testament Manuscripts"],
        "remove": [],
        "reason": (
            "The post's argument depends throughout on early papyri, versions, "
            "manuscript relationships, and patristic witnesses to Gospel miracle stories."
        ),
    },
    "37523": {
        "add": ["Comparative Ancient Evidence", "Jesus' Miracle Stories"],
        "remove": [],
        "reason": (
            "The entire post compares Vespasian's reported healings with closely "
            "similar Gospel miracle stories about Jesus."
        ),
    },
    "37676": {
        "add": ["Virgin Birth"],
        "remove": [],
        "reason": (
            "The announced course and its detailed outline centrally evaluate New "
            "Testament evidence for and against Jesus' virgin birth."
        ),
    },
    "37577": {
        "add": ["Early Christian Diversity"],
        "remove": [],
        "reason": (
            "The post's principal conclusion is that geographically separated groups "
            "of Jesus' earliest followers preserved different understandings and traditions."
        ),
    },
    "37572": {
        "add": ["Paul and His Opponents"],
        "remove": [],
        "reason": (
            "The post uses Paul's disputes with rival missionaries and his own "
            "churches as the principal evidence for earliest Christian division."
        ),
    },
    "37497": {
        "add": [],
        "remove": ["Gnostic and Orthodox Conflicts"],
        "reason": (
            "The post uses discoveries of Gnostic texts as evidence for early "
            "Christian diversity, but it does not centrally examine conflicts between "
            "Gnostic and orthodox groups."
        ),
    },
    "37529": {
        "add": ["Incarnation Christology"],
        "remove": [],
        "reason": (
            "The post centrally tests the doctrine that Jesus was fully human and "
            "fully divine against the biological implications of a virgin birth."
        ),
    },
    "37411": {
        "add": ["Gnosticism (General)"],
        "remove": [],
        "reason": (
            "The post surveys Thomasine Christians and then broadens into the "
            "classification of other Gnostic and Gnostic-like traditions."
        ),
    },
    "37502": {
        "add": ["Courses and Teaching", "Original Text Questions"],
        "remove": [],
        "reason": (
            "The post announces a course whose central subjects include scribal "
            "change and whether the original New Testament text can be recovered."
        ),
    },
    "37367": {
        "add": ["Sethian Gnostics"],
        "remove": [],
        "reason": (
            "The post presents and explains the Gospel of Judas's specifically "
            "Sethian myth of the divine realm and creation."
        ),
    },
    "37312": {
        "add": ["Gnosticism (General)"],
        "remove": [],
        "reason": (
            "Roughly half the post summarizes beliefs shared across Gnostic groups "
            "before turning to the more specific Sethian system."
        ),
    },
    "37291": {
        "add": ["Historical Methods (General)"],
        "remove": [],
        "reason": (
            "The entire response explains how historians use contradictions, "
            "plausibility, and corroborating evidence when assessing an event's gist."
        ),
    },
    "37257": {
        "add": ["Dating Ancient Texts"],
        "remove": [],
        "reason": (
            "A substantial question and response explain how regnal, consular, "
            "indiction, and paleographic evidence date the Nag Hammadi codices."
        ),
    },
    "37095": {
        "add": ["Historical Methods (General)"],
        "remove": [],
        "reason": (
            "The post centrally distinguishes literary interpretation from historical "
            "reconstruction and explains why the two require different methods."
        ),
    },
    "37091": {
        "add": ["How Jesus Became God"],
        "remove": [],
        "reason": (
            "The post explicitly reproduces the book's argument that Roman adoption "
            "helps explain the high status granted to an adopted Son of God."
        ),
    },
    "37087": {
        "add": ["Gospel of Luke", "Original Text Questions"],
        "remove": ["John the Baptist"],
        "reason": (
            "The post centrally reconstructs an earlier edition of Luke and its "
            "adoptionist possibilities. John the Baptist supplies the narrative "
            "starting point but is not a sustained subject."
        ),
    },
    "37067": {
        "add": ["Original Text Questions"],
        "remove": ["John the Baptist"],
        "reason": (
            "The post centrally asks whether Luke originally circulated without "
            "chapters 1-2. John the Baptist marks the proposed opening but is not a "
            "major subject in his own right."
        ),
    },
    "37078": {
        "add": ["Source Criticism", "Original Text Questions"],
        "remove": [],
        "reason": (
            "The post is a sustained inquiry into fluid Gospel editions, lost written "
            "and oral sources, and what those possibilities mean for source relationships."
        ),
    },
    "36268": {
        "add": ["Virgin Birth"],
        "remove": [],
        "reason": (
            "The miraculous birth is one of four explicitly developed puzzles and "
            "receives its own substantial Islamic interpretation."
        ),
        "description": (
            "Offers an Islamic interpretation of Jesus' crucifixion, mission, "
            "ascension and return, and miraculous birth."
        ),
    },
    "37046": {
        "add": ["Source Criticism"],
        "remove": [],
        "reason": (
            "The entire post evaluates competing models for which written Gospels "
            "and other sources later Gospel authors used."
        ),
    },
    "36693": {
        "add": ["Pauline Salvation Models"],
        "remove": [],
        "reason": (
            "The post centrally argues that Paul originated the interpretation of "
            "Jesus' death as an atoning sacrifice required for salvation."
        ),
    },
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
    if len(tracker.get("posts", [])) not in {700, 900}:
        raise ValueError("Tracker must contain the first 700 or 900 posts")

    valid_topics = {topic["name"] for topic in topics_data["topics"]}
    entries = list(tracker["posts"][:700])
    for source_index, post in enumerate(posts[700:900], start=700):
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
        entry["wpId"] for entry in entries[700:] if entry["status"] == "pending_approval"
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
                "First 900 canonical search-index posts in current newest-first order"
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
