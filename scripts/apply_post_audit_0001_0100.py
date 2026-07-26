"""Apply the approved full-text audit for search-index posts 1-100."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"


UPDATES: dict[str, dict[str, list[str]]] = {
    "Jewish Precedents for Jesus Becoming God": {"kw_remove": ["King Saul"]},
    "How Can Monotheists Think a Human Could Become God?": {
        "topic_remove": ["Divine Beings in the Hebrew Bible"],
        "kw_remove": ["Michael", "Methuselah", "Noah", "Old Testament Pseudepigrapha"],
    },
    "2 Clement (one of the “Apostolic Fathers”) in a Nutshell": {
        "kw_remove": ["Paul", "Second Clement"],
    },
    "Christian Justifications for Lying": {
        "kw_remove": ["Augustine", "Forged", "Forgery and Counterforgery"],
    },
    "Ancient Christians Who Wanted to Lie": {
        "kw_remove": ["Forged", "Forgery and Counterforgery"],
        "kw_add": ["Augustine", "Plato"],
    },
    "Evangelicals Who Lie to Promote the(ir) Truth….": {
        "kw_remove": ["Dan Wallace", "Dirk Obbink", "Oxyrhynchus Papyri"],
        "kw_add": ["Scott Carroll", "Brent Nongbri", "Green Collection"],
    },
    "Free Course on … Hell!": {"kw_remove": ["Revelation"]},
    "Jesus the Forager": {
        "kw_remove": ["Pharisees", "Scribes"],
        "kw_add": ["Kingdom of God"],
    },
    "Pro-Roman Jews in First-Century Palestine? Guest Post by Christopher Stanley": {
        "kw_remove": ["Early Judaism"],
        "kw_add": ["Josephus", "Agrippa II", "Pro-Roman Jews", "A Ram for Mars"],
    },
    "Did Jesus Have Wealthy Donors?": {
        "kw_remove": ["Tiberius"],
        "kw_add": ["Tiberias", "Joanna", "Susanna", "Mary Magdalene"],
    },
    "Jesus and the Gospel of John: Some Readers’ Good Questions": {
        "kw_remove": ["John", "Reader Questions"],
        "kw_add": ["John the Apostle", "Tertius", "Ancient Secretaries", "Mike Licona"],
    },
    "Did Paul Have an Exalted View of Himself?": {"kw_remove": ["Jewish Law", "Paul"]},
    "The Parable of the Sower as Advice for Capitalists": {
        "kw_remove": ["Bruce Barton", "Mark"],
        "kw_add": ["Kingdom of God"],
    },
    "The Capitalist Parables of Jesus": {
        "kw_add": ["Parable of the Sower", "Parable of the Talents", "Mark", "Matthew"],
    },
    "Why Not Believe in a God Who is *Not* Active in the World?": {
        "kw_remove": ["Agnosticism", "Free Will and Predestination", "Problem of Evil", "Suffering"],
        "kw_add": ["Deism", "Theism"],
    },
    "How To Figure Out If a Miracle Happened…  Questions from Readers": {
        "topic_add": ["Roman Persecution of Christians"],
        "kw_add": ["Lyon and Vienne", "Eusebius"],
    },
    "More Criticisms of the Criticisms of the Gospel of John (by John! Spong)": {
        "kw_remove": ["Fourth Gospel"],
    },
    "Controversies About the Gospel of John: The Views of John Spong": {
        "kw_remove": ["Fourth Gospel", "Historical Reliability"],
    },
    "Questions on Proving the Resurrection and Sundry Other Things": {
        "kw_remove": ["John the Baptist", "Resurrection"],
    },
    "These Are Weird Parables.  Do They Make Sense?": {"kw_remove": ["Luke"]},
    "Did the Doctrine of Predestination Lead to Capitalism?": {
        "kw_remove": ["Cain"],
        "kw_add": ["Martin Luther"],
    },
    "Predestination!  What do you think?": {
        "kw_remove": ["Augustine", "Church Fathers", "NRSV"],
        "kw_add": ["John Calvin", "Max Weber", "Acts"],
    },
    "Doesn’t Goodness Point to the Existence of God?  And Gospel Perplexities.  Good Readers’ Questions": {
        "topic_add": ["Christology in the Gospels", "Historical Jesus (General)", "Gospel Authorship"],
        "kw_remove": ["Church Fathers", "Problem of Evil"],
    },
    "Did the Glories of Martyrdom Lead to Christian Conversions?": {
        "kw_remove": ["Early Christianity", "Martyrdom"],
    },
    "The Fear of Hell as an Incentive to Convert": {"kw_add": ["Apocalypse of Peter"]},
    "Was Augustine Telling the Truth About Miracles He’d Seen?": {
        "kw_remove": ["Miracles", "Paul", "Resurrection", "Resurrection of Jesus", "Miracles and Conversion"],
        "kw_add": ["City of God"],
    },
    "Biographical Accounts of Early Christian Miracles (Based on Eyewitnesses!)": {
        "kw_remove": ["Miracles", "Trinity", "Miracles and Conversion"],
        "kw_add": ["Sulpicius Severus"],
    },
    "And the Miracles Just Keep on Comin’": {
        "kw_remove": ["Acts", "Church Fathers", "Jesus' Miracle Stories", "Miracles", "Paul", "Miracles and Conversion"],
        "kw_add": ["Acts of John", "Acts of Peter", "Eusebius of Caesarea", "Edessa"],
    },
    "How Could Christian Miracles Convert the Empire if Miracles Don’t Happen?": {
        "kw_remove": ["Early Christianity", "Health Care"],
    },
    "Why Christian Miracles Converted the Empire": {
        "kw_remove": ["Health Care", "Miracles and Conversion"],
    },
    "Superior Health Care as an Explanation for the Spread of Christianity?": {
        "kw_remove": ["Church Fathers"],
        "kw_add": ["Eusebius of Caesarea"],
    },
    "A Modern “Common Sense” About What Made Christianity Attractive to Converts": {
        "kw_remove": ["Constantine", "Miracles", "Salvation"],
        "kw_add": ["Christian Charity", "Christian Community Support"],
    },
    "What an Ancient Enemy of Christianity Said About Why It Was Successful": {
        "kw_remove": ["Gospels"],
        "kw_add": ["The True Word", "1 Corinthians"],
    },
    "How Did Christianity Succeed?  An Older View That Many People Still Have": {
        "kw_remove": ["Roman World"],
    },
    "Do You Know The Golden Ass?   (Is a Mystery Religion like Christianity?)": {
        "kw_add": ["The Golden Ass", "Metamorphoses", "Religious Initiation"],
    },
    "Christianity:  A Weirdly Exclusivist Religion": {
        "kw_remove": ["Conversion"],
        "kw_add": ["Evangelism", "Arthur Darby Nock", "Ramsay MacMullen"],
    },
    "Some Important Readers’ Questions on Some Gospel Head-Scratchers": {
        "topic_add": ["Christology in the Gospels", "Historical Jesus (General)", "Gospel Authorship"],
        "kw_remove": ["Church Fathers", "Gospels", "Original Text"],
        "kw_add": ["John"],
    },
    "How Early Christians Made Converts.  (Tent revivals?)": {
        "kw_add": ["Ramsay MacMullen", "Martin Goodman", "Word of Mouth"],
    },
    "Converting the World:  Why Has Christianity Always Been “Missionary”?": {
        "kw_remove": ["Conversion", "Health Care", "Roman World"],
        "kw_add": ["Evangelism", "Exclusivity", "Word of Mouth"],
    },
    "Jesus and Capitalism:  My Next Book (A Big Change)": {
        "kw_remove": ["Canon Formation", "Debates", "New Testament Canon"],
        "kw_add": ["Max Weber", "Roman Economy", "Jesus' Parables"],
    },
    "The Morality of War": {
        "kw_remove": ["Early Christianity", "Thalassa Journeys"],
        "kw_add": ["Love Thy Stranger", "War", "Thucydides", "Ideology of Dominance", "Peloponnesian War", "Slavery"],
    },
    "Was Jesus the Incarnation of an Angel?  Anniversary Post #13": {
        "kw_remove": ["Christology"],
        "kw_add": ["Charles Gieschen", "Susan Garrett", "Angel of the Lord"],
    },
    "An Amazing Fragment of a Lost Gospel: Anniversary Post #12": {
        "kw_remove": ["Nicodemus", "Resurrection"],
        "kw_add": ["Papyrus Oxyrhynchus 4009", "2 Clement", "Dieter Luhrmann", "Persecution", "Lost Gospel Fragment"],
    },
    "Active Pastors Who Have Lost Their Faith: Anniversary Post #11": {
        "kw_add": ["The Clergy Project", "Clergy", "Pastors", "Loss of Faith"],
    },
    "The Seven Sleepers of Ephesus: Platinum Post by Douglas Wadeson, MD": {
        "topic_remove": ["Martyrdom Traditions (General)"],
        "topic_add": ["Resurrection of the Dead"],
        "kw_remove": ["Mary Magdalene", "Paul", "Resurrection"],
        "kw_add": ["Seven Sleepers of Ephesus", "Quran", "Decius", "Theodosius", "Bodily Resurrection", "Christian Legends"],
    },
    "A Letter Written by Jesus!?  Anniversary Post #10": {
        "kw_remove": ["Abgar", "Church Fathers", "Early Church History", "Eusebius", "Gospels", "John", "Joseph of Arimathea"],
        "kw_add": ["Edessa", "Doctrina Addai", "Thaddaeus", "The Other Gospels", "Jesus' Correspondence", "Egeria"],
    },
    "Anniversary Post #9:  Misquoting Misquoting Jesus": {
        "kw_remove": ["Moses"],
        "kw_add": ["Scribes", "Textual Variants", "Biblical Inerrancy"],
    },
    "May 2026 Platinum Webinar Announcement": {
        "kw_remove": ["Thomas"],
        "kw_add": ["Webinar"],
    },
    "Different Words, VERY Different Theologies, and Understanding Which Words They Were. Readers’ Questions": {
        "topic_add": ["Atonement in Luke-Acts", "Original Text Questions"],
        "kw_remove": ["Paul", "Resurrection"],
        "kw_add": ["Apocalyptic Judaism", "Textual Variants"],
    },
    "Anniversary Post #8:  When Is a Contradiction Not a Contradiction?": {
        "kw_remove": ["Paul"],
        "kw_add": ["Biblical Inerrancy", "Divine Inspiration"],
    },
    "Does God Care What We Wear? A Platinum Post by Douglas Wadeson, MD": {
        "topic_add": ["Sexual and Reproductive Ethics"],
        "kw_remove": ["Moses", "Solomon"],
        "kw_add": ["Quran", "Muhammad", "Religious Clothing", "Modesty", "Head Coverings", "Women", "Lust"],
    },
    "Anniversary Post #7:  Doing a Graduate Degree in Early Christian Studies": {
        "kw_remove": ["Judaism"],
        "kw_add": ["PhD", "Graduate Studies", "Dissertation", "Ancient Languages", "Academic Training"],
    },
    "Anniversary Post #6  Is Mark’s Seemingly Simple Gospel Unsophisticated?": {
        "kw_remove": ["Mark", "Nazareth"],
        "kw_add": ["Literary Artistry", "Temple Curtain", "Roman Centurion", "Baptism of Jesus"],
    },
    "Anniversary Post #5: Why I Was Reluctant to Write The Triumph of Christianity": {
        "kw_remove": ["Roman World"],
        "kw_add": ["Constantine", "Christian Mission", "Pagan Religion", "Christian Apologists", "Book Proposal"],
    },
    "Anniversary Post #4: Why Gospels Matter Even Where They Are Not Historical": {
        "topic_remove": ["Eyewitness Reliability"],
        "topic_add": ["Historical Jesus (General)"],
        "kw_remove": ["Historical Jesus"],
        "kw_add": ["Mnemohistory", "Gospel Memory", "Remembered Jesus", "Jan Assmann"],
    },
    "Anniversary Post #3:  My Response to an Ill-Tempered Richard Carrier": {
        "topic_remove": ["How Jesus Became God"],
        "topic_add": ["Mythicism", "Did Jesus Exist?"],
        "kw_remove": ["How Jesus Became God", "Pilate"],
        "kw_add": ["Richard Carrier", "Philo", "Pilate Inscription", "Roman Sources"],
    },
    "Anniversary Post #2: Why Were the Gospels Written Anonymously?": {
        "kw_remove": ["Authorship", "Mark", "Paul"],
        "kw_add": ["Gospel of Mark", "Gospel of Matthew", "Gospel of Luke", "Gospel of John", "Anonymous Gospels", "Jewish Scripture"],
    },
    "Anniversary Post #1:  Defending Misquoting Jesus": {
        "kw_remove": ["Bart Ehrman"],
        "kw_add": ["Evangelical Critics", "Theological Variants", "Gospel of Mark", "Gospel of Luke"],
    },
    "The Distinctively Jewish Roots of Jesus’ Ethics": {
        "kw_remove": ["Jesus' Teachings", "Nazareth"],
        "kw_add": ["Sirach", "Rabbi Hillel", "Golden Rule", "Jewish Ethics", "Love Thy Stranger"],
    },
    "Understanding the Gospels, Jesus, and the Spread of Christianity: Great Readers’ Questions": {
        "topic_add": ["Memory and Jesus Traditions", "Son of Man"],
        "kw_remove": ["Matthew", "Pontius Pilate", "Son of Man"],
        "kw_add": ["Oral Tradition", "Word of Mouth", "Daniel"],
    },
    "Rethinking Faith Podcast Interview About Love Thy Stranger": {
        "kw_add": ["Love Thy Stranger", "Rethinking Faith Podcast", "Podcast Interview"],
    },
    "Early Christian Reactions to “Heresies” in a Nutshell": {
        "topic_remove": ["Early Church Developments", "Gnostic and Orthodox Conflicts"],
        "kw_remove": ["Early Christianity"],
        "kw_add": ["Marcion", "Ebionites", "Walter Bauer", "Nag Hammadi"],
    },
    "The First Attempts to Wipe Out Christianity": {
        "topic_remove": ["Early Church Developments"],
        "kw_remove": ["Church Fathers", "Persecution"],
        "kw_add": ["Valerian", "Diocletian", "Great Persecution", "Galerius", "Edict of Milan"],
    },
    "When Emperors Became More Involved in Christian Persecutions": {
        "kw_remove": ["Church Fathers", "Papias", "Persecution"],
        "kw_add": ["Marcus Aurelius", "Decius", "Letter of Lyons and Vienne", "Roman Sacrifice", "Libelli"],
    },
    "You’re Invited: The Blog Turns 14": {
        "kw_remove": ["Misquoting Jesus", "Q&A"],
        "kw_add": ["Blog Anniversary", "Live Cocktail Hour", "Zoom Event"],
    },
    "Early Persecutions of Christians, in a Nutshell": {
        "kw_remove": ["Persecution"],
        "kw_add": ["Nero", "Trajan", "Pliny the Younger", "Pagan Worship", "Roman Empire", "Tacitus"],
    },
    "The Rise of Christian Anti-Judaism, in a Nutshell": {
        "kw_remove": ["Debates"],
        "kw_add": ["Messiah", "Jewish Scriptures", "Justin Martyr", "Melito of Sardis", "Marcion", "Constantine", "Antisemitism"],
    },
    "Early Christian Views of Judaism, In a Nutshell": {
        "kw_remove": ["Acts", "Apostolic Fathers"],
        "kw_add": ["Ebionites", "Marcionites", "Hebrews", "Matthew", "Jewish Christianity"],
    },
    "Readers’ Questions on the Accuracy of the Gospels": {
        "topic_add": ["Q Source"],
        "kw_remove": ["Gospel of Thomas", "Q Source"],
        "kw_add": ["Synoptic Problem", "Oral Tradition", "Gospel Legends", "Luke's Prologue", "Ancient Historiography"],
    },
    "The Good Done By Christianity to Our World": {
        "topic_add": ["Charity and Altruism"],
        "kw_add": ["Love Thy Stranger", "Care for Strangers", "Hospitals", "Orphanages", "Social Welfare", "Moral Conscience"],
    },
    "The Dark Side of Christianity: How I (Partially) End My New Book": {
        "topic_add": ["Charity and Altruism"],
        "kw_add": ["Love Thy Stranger", "Altruism", "Social Welfare", "Christian Violence", "Antisemitism", "Crusades", "Inquisition", "Slavery"],
    },
    "Advance Preview–How I Begin My New Book: Love Thy Stranger": {
        "topic_add": ["Jesus' Ethics"],
        "kw_remove": ["Heaven and Hell"],
        "kw_add": ["Love Thy Stranger", "Hebrew Bible", "Jewish Ethics", "Care for Strangers", "Forgiveness", "Hospitals", "Orphanages"],
    },
}


def update_values(values: list[str], remove: list[str], add: list[str], label: str) -> list[str]:
    missing = [value for value in remove if value not in values]
    if missing:
        raise ValueError(f"{label}: expected values are missing: {missing}")
    result = [value for value in values if value not in set(remove)]
    for value in add:
        if value not in result:
            result.append(value)
    if len(result) != len(set(result)):
        raise ValueError(f"{label}: update produced duplicate values")
    return result


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    topic_data = json.loads(TOPICS_PATH.read_text(encoding="utf-8"))
    allowed_topics = {topic["name"] for topic in topic_data["topics"]}
    posts_by_title = {post["title"]: post for post in posts[:100]}

    missing_posts = sorted(set(UPDATES) - set(posts_by_title))
    if missing_posts:
        raise ValueError(f"Audited posts missing from first 100 records: {missing_posts}")

    for title, changes in UPDATES.items():
        post = posts_by_title[title]
        topic_add = changes.get("topic_add", [])
        unknown_topics = sorted(set(topic_add) - allowed_topics)
        if unknown_topics:
            raise ValueError(f"{title}: unknown topic additions: {unknown_topics}")

        post["topics"] = update_values(
            post.get("topics", []),
            changes.get("topic_remove", []),
            topic_add,
            f"{title} topics",
        )
        post["secondaryKeywords"] = update_values(
            post.get("secondaryKeywords", []),
            changes.get("kw_remove", []),
            changes.get("kw_add", []),
            f"{title} secondary keywords",
        )
        if not post["topics"]:
            raise ValueError(f"{title}: update removed every topic")

    POSTS_PATH.write_text(
        json.dumps(posts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Updated {len(UPDATES)} posts in {POSTS_PATH}")


if __name__ == "__main__":
    main()
