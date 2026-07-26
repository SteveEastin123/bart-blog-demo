"""Validate and print recommendations for the full-text audit of posts 101-2100.

This script is intentionally read-only. It records the recommendations for user
review without changing the search index, standalone demo, or SQLite database.
"""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"


UPDATES: dict[int, dict[str, list[str]]] = {
    108: {"topic_remove": ["Gospel of Mark"], "topic_add": ["Jewish Law and Torah"]},
    109: {"kw_remove": ["Genesis", "Hebrew Bible", "Old Testament", "Old Testament Apocrypha", "Revelation"]},
    110: {"kw_remove": ["Judas Iscariot"]},
    113: {"kw_remove": ["Gospel Writers as Interpreters"]},
    121: {"kw_remove": ["Barnabas"]},
    132: {"kw_remove": ["Barnabas", "Didache", "Polycarp"]},
    139: {"topic_add": ["Scribal Changes", "Textual Variants"]},
    140: {"kw_remove": ["John", "Mark", "Resurrection"]},
    171: {"kw_add": ["Christmas"]},
    181: {"kw_add": ["Consciousness"]},
    187: {"kw_add": ["Bethlehem"]},
    189: {"kw_add": ["Aramaic", "Greek"]},
    195: {"kw_remove": ["Q Source"]},
    198: {"topic_remove": ["Early Christian Teachings on Wealth"]},
    200: {"topic_add": ["Letter of Barnabas"]},
    219: {"kw_remove": ["Church Fathers"]},
    240: {"kw_remove": ["Church Fathers"]},
    266: {"kw_remove": ["Rebekah"]},
    299: {"kw_remove": ["Mary", "Mary Magdalene"]},
    300: {"kw_remove": ["Mary", "Mary Magdalene"]},
    311: {"kw_remove": ["Mary", "Mary Magdalene"]},
    313: {"kw_remove": ["Mary", "Mary Magdalene", "Peter"]},
    315: {"kw_remove": ["James"]},
    414: {"kw_remove": ["James"]},
    429: {"kw_remove": ["Trinity"]},
    469: {"kw_remove": ["Historical Jesus"]},
    474: {"kw_remove": ["Martin Luther"]},
    484: {"kw_remove": ["Rebekah"]},
    489: {"topic_add": ["Gospel of Mark"]},
    510: {
        "kw_remove": ["Church Fathers", "Forgiveness"],
        "kw_add": ["Aristotle", "Moral Philosophy", "Happiness"],
    },
    516: {"kw_remove": ["Church Fathers", "Paul"]},
    598: {"kw_remove": ["Paul"]},
    603: {"kw_remove": ["Paul"]},
    612: {"kw_remove": ["Church Fathers"]},
    630: {"topic_add": ["Apocalyptic Jesus"]},
    645: {"topic_add": ["Didymus the Blind"]},
    648: {"kw_remove": ["Acts"]},
    667: {"kw_remove": ["3 John"]},
    669: {"kw_remove": ["2 John"]},
    680: {
        "topic_remove": ["Bible Translations (General)", "Translation Issues"],
        "topic_add": ["Textual Criticism Methods", "Original Text Questions"],
    },
    699: {"kw_remove": ["Gospel of Matthew", "Paul"]},
    715: {"kw_remove": ["Mary Magdalene"]},
    737: {"kw_remove": ["Charity"]},
    747: {"topic_add": ["Pauline Forgeries"]},
    757: {"kw_remove": ["Son of Man"]},
    768: {"kw_remove": ["Bart Ehrman", "Judges"]},
    774: {"topic_add": ["Apocalyptic Jesus"]},
    787: {"kw_remove": ["Scholarship"]},
    813: {"kw_remove": ["Gospel of Thomas", "Paul", "Thomas"]},
    835: {"kw_remove": ["Scholarship"]},
    888: {"kw_remove": ["Church Fathers"]},
    899: {"kw_remove": ["Elisha", "Ishmael", "Jonah", "Peter"]},
    924: {"kw_remove": ["Scholarship"]},
    929: {"kw_remove": ["Rebekah"]},
    931: {"kw_remove": ["Church Fathers"]},
    954: {"kw_remove": ["Julian"]},
    981: {"kw_remove": ["Acts"]},
    991: {"kw_remove": ["Church Fathers", "Paul"]},
    1023: {"kw_remove": ["Rebekah"]},
    1059: {"kw_remove": ["Q Source"]},
    1092: {"kw_remove": ["James", "Lectures"]},
    1135: {"kw_remove": ["Church Fathers", "James", "Mary", "Peter", "Son of God"]},
    1182: {"kw_remove": ["Church Fathers"]},
    1184: {"kw_remove": ["James"]},
    1199: {"kw_remove": ["Church Fathers"], "kw_add": ["Consciousness"]},
    1233: {"topic_add": ["Church Fathers as Textual Evidence"]},
    1234: {"kw_remove": ["Church Fathers"], "kw_add": ["Augustine"]},
    1245: {
        "topic_add": ["Armageddon"],
        "kw_remove": ["Church Fathers"],
        "kw_add": ["Augustine"],
    },
    1254: {"kw_remove": ["Church Fathers"]},
    1287: {"kw_remove": ["Annas"]},
    1300: {"topic_remove": ["Miracle Traditions (General)"], "topic_add": ["Roman World"]},
    1309: {"kw_remove": ["James"]},
    1393: {"kw_remove": ["Daniel"]},
    1469: {
        "topic_remove": ["Early Christian Writings"],
        "topic_add": ["Textual Criticism Overview"],
        "kw_remove": ["Early Christian Writings"],
    },
    1487: {"kw_remove": ["Rebekah"]},
    1512: {"kw_remove": ["Daniel"]},
    1521: {"kw_remove": ["Rebekah"]},
    1542: {"kw_remove": ["Rebekah"]},
    1582: {"kw_remove": ["Early Christianity"]},
    1612: {"kw_remove": ["Church Fathers", "Didymus the Blind", "Early Christian Writings", "Gospels", "Origen"]},
    1625: {"kw_remove": ["Christmas", "Paul"]},
    1662: {"kw_remove": ["Memory"]},
    1715: {"kw_remove": ["Church Fathers"], "kw_add": ["Didymus the Blind"]},
    1754: {"topic_add": ["Paul's Churches and Communities"]},
    1767: {"kw_remove": ["Noah"]},
    1772: {"topic_remove": ["Judas Iscariot"], "topic_add": ["Jesus' Passion Narratives"]},
    1775: {"topic_remove": ["Judas Iscariot"]},
    1795: {"kw_remove": ["Christology (General)"]},
    1883: {
        "topic_remove": ["Development of the Trinity"],
        "topic_add": ["Divine Beings in the Hebrew Bible"],
        "kw_remove": ["Paul"],
    },
    1910: {"kw_remove": ["Rebekah"]},
    2030: {"topic_add": ["Hebrew Bible Manuscripts"]},
    2082: {"topic_add": ["Textual Variants"]},
}


def main() -> None:
    posts = json.loads(POSTS_PATH.read_text(encoding="utf-8"))
    allowed_topics = {
        topic["name"]
        for topic in json.loads(TOPICS_PATH.read_text(encoding="utf-8"))["topics"]
    }
    for index, changes in UPDATES.items():
        if not 100 <= index < 2100:
            raise ValueError(f"Index outside audited range: {index}")
        post = posts[index]
        for field, key in (("topics", "topic_remove"), ("secondaryKeywords", "kw_remove")):
            missing = sorted(set(changes.get(key, [])) - set(post.get(field, [])))
            if missing:
                raise ValueError(f"{index} {post['title']}: missing {field}: {missing}")
        unknown = sorted(set(changes.get("topic_add", [])) - allowed_topics)
        if unknown:
            raise ValueError(f"{index} {post['title']}: unknown topics: {unknown}")
        print(json.dumps({"index": index, "title": post["title"], **changes}, ensure_ascii=False))
    print(f"Validated {len(UPDATES)} substantive post recommendations.")


if __name__ == "__main__":
    main()
