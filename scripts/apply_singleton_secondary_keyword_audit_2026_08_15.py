#!/usr/bin/env python3
"""Apply the approved August 2026 secondary-keyword audit."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
POSTS_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
RETIREMENTS_PATH = (
    ROOT / "data" / "index" / "ehrman_secondary_keyword_retirement_candidates.json"
)

REMOVE = {
    "Ancient Text Translation",
    "Apostate",
    "Ben Hur",
    "Christological Controversy",
    "Coptic Church",
    "Divine Inspiration",
    "Doktor Vater",
    "Gideon",
    "Greco-Roman Religious Culture",
    "Faith Transitions",
    "Handing Over of Pilate",
    "Holocaust",
    "Justification",
    "Manuscript Discoveries",
    "Oxford University Press",
    "Pastoral Epistles",
    "Pauline Forgeries",
    "Political Reform",
    "Quail Ridge Books",
    "Pontifex Maximus",
    "Roman Polytheism",
    "Roman Sources",
    "Ramtha",
    "Sabbath",
    "Sayings of Jesus",
    "Shepherd of Hermas",
    "Swedish Podcast",
    "The Law",
    "The Fourth Gospel",
    "The Other Gospels",
    "Trial of Jesus",
    "Women in Early Christianity",
    "Youtube Video",
    "Zealot Hypothesis",
}

REPLACE = {
    "Alexandrian Text": "Alexandrian Text-Type",
    "Anaphora Pilati": "Report of Pilate (Anaphora Pilati)",
    "Antiochus Epiphanes": "Antiochus IV Epiphanes",
    "Babylon": "Babylonian Exile",
    "Bultmann": "Rudolf Bultmann",
    "Bayes Theorem": "Bayes' Theorem",
    "Bible and Homosexuality": "Bible and Same-Sex Relations",
    "C S Lewis": "C. S. Lewis",
    "Carlo Martini": "Carlo Maria Martini",
    "Christological Issues": "Christology",
    "Christmas Star": "Star of Bethlehem",
    "Codex Leningradensis": "Leningrad Codex",
    "Convent of St Catherine": "St. Catherine's Monastery",
    "Constantines Conversion": "Constantine's Conversion",
    "Council of Nicea": "Council of Nicaea",
    "Damascus": "Road to Damascus",
    "Demetrius": "Demetrius Poliorcetes",
    "Destruction of Jerusalem": "Babylonian Exile",
    "Deutero Canonical Books": "Deuterocanonical Books",
    "Deutero Pauline Letters": "Deutero-Pauline Letters",
    "E Randolph Richards": "E. Randolph Richards",
    "Freedom From Religion": "Freedom From Religion Foundation",
    "G A Wells": "G. A. Wells",
    "Gods Problem": "God's Problem",
    "Greek Bible": "Greek New Testament",
    "Harper Collins": "HarperCollins",
    "History of the Bible": "History of the King James Bible",
    "J Z Smith": "J. Z. Smith",
    "James Mcgrath": "James McGrath",
    "Jehovahs Witnesses": "Jehovah's Witnesses",
    "Jesus Resurrection": "Resurrection of Jesus",
    "Jesus Wife": "Jesus' Wife",
    "John J Collins": "John J. Collins",
    "Letter of Lyons and Vienne": "Martyrs of Lyons and Vienne",
    "Literary Historical Method": "Literary-Historical Method",
    "Lords Prayer": "Lord's Prayer",
    "Licona": "Michael Licona",
    "Lyon and Vienne": "Martyrs of Lyons and Vienne",
    "N T Wright": "N. T. Wright",
    "NT Manuscripts": "New Testament Manuscripts",
    "Nicaea": "Council of Nicaea",
    "Ossuaries": "Jewish Ossuaries",
    "P46": "Papyrus 46 (P46)",
    "P Egerton 2": "Papyrus Egerton 2",
    "P.Oxy. 4009": "Papyrus Oxyrhynchus 4009",
    "Paradosis Pilati": "Handing Over of Pilate (Paradosis Pilati)",
    "Phillip H Wiebe": "Phillip H. Wiebe",
    "Peter J Williams": "Peter J. Williams",
    "Passion of the Christ": "The Passion of the Christ",
    "Pliny": "Pliny the Younger",
    "Richard G Swinburne": "Richard G. Swinburne",
    "Signs": "Johannine Signs",
    "St Catherine's Monastery": "St. Catherine's Monastery",
    "Temptations of Christ": "Temptation of Jesus",
    "Temptations of Jesus": "Temptation of Jesus",
    "The Invention of Afterlife": "The Invention of the Afterlife",
    "The Greater Questions of Mary": "Greater Questions of Mary",
    "The Gospel of Basilides": "Gospel of Basilides",
    "The Twelve": "Twelve Disciples",
    "Teaching Company": "The Great Courses (Teaching Company)",
    "Tischendorf": "Constantin von Tischendorf",
    "Tim Mcgrew": "Tim McGrew",
    "UNC": "UNC-Chapel Hill",
    "We Passages": "Acts “We” Passages",
    "Wrede": "Wilhelm Wrede",
    "King James": "King James Bible",
}

RESTORE_BY_POST = {
    "2455": {"Wisdom": "Sophia"},
}

REMOVE_BY_POST = {
    "13404": {"Scribal Changes"},
    "16167": {"Modern Forgery Claims"},
    "15505": {"Historical Study and Theology"},
    "34541": {"Masada"},
    "8803": {"Signs"},
}

SOURCE_MAX_COUNTS = {
    "Antiochus Epiphanes": 2,
    "Christological Issues": 2,
    "Christmas Star": 2,
    "Constantines Conversion": 2,
    "P46": 2,
    "UNC": 2,
    "Faith Transitions": 2,
    "Gods Problem": 2,
    "Greek Bible": 2,
    "History of the Bible": 2,
    "Jesus Resurrection": 2,
    "Jesus Wife": 2,
    "Manuscript Discoveries": 2,
    "Peter J Williams": 2,
    "Quail Ridge Books": 2,
    "The Fourth Gospel": 2,
    "The Other Gospels": 2,
    "Youtube Video": 2,
    "Ancient Text Translation": 2,
    "Bible and Homosexuality": 2,
    "C S Lewis": 2,
    "Literary Historical Method": 2,
    "NT Manuscripts": 2,
    "Oxford University Press": 2,
    "Signs": 2,
    "St Catherine's Monastery": 2,
    "Teaching Company": 2,
    "Freedom From Religion": 2,
    "The Greater Questions of Mary": 2,
    "The Law": 2,
    "Tim Mcgrew": 2,
}


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def keyword_counts(posts: list[dict]) -> Counter[str]:
    return Counter(
        keyword
        for post in posts
        for keyword in dict.fromkeys(post.get("secondaryKeywords") or [])
    )


def main() -> int:
    posts = load_json(POSTS_PATH)
    before = keyword_counts(posts)
    audited_sources = REMOVE | set(REPLACE)
    unexpected = {
        keyword: before[keyword]
        for keyword in audited_sources
        if before[keyword] > SOURCE_MAX_COUNTS.get(keyword, 1)
    }
    if unexpected:
        raise ValueError(f"Expected every audited source keyword at most once; found {unexpected}")

    changed_posts = 0
    for post in posts:
        original = post.get("secondaryKeywords") or []
        post_restorations = RESTORE_BY_POST.get(str(post.get("wpId")), {})
        post_removals = REMOVE_BY_POST.get(str(post.get("wpId")), {})
        revised: list[str] = []
        for keyword in original:
            if keyword in REMOVE or keyword in post_removals:
                continue
            normalized = REPLACE.get(keyword, keyword)
            normalized = post_restorations.get(normalized, normalized)
            if normalized not in revised:
                revised.append(normalized)
        if revised != original:
            post["secondaryKeywords"] = revised
            changed_posts += 1

    retirements = load_json(RETIREMENTS_PATH)
    retired = list(retirements.get("keywords") or [])
    retired_set = set(retired)
    retired.extend(sorted(audited_sources - retired_set, key=str.casefold))
    normalized_targets = set(REPLACE.values())
    restored_targets = {
        target
        for replacements in RESTORE_BY_POST.values()
        for target in replacements.values()
    }
    retirements["keywords"] = [
        keyword
        for keyword in retired
        if keyword not in normalized_targets and keyword not in restored_targets
    ]

    after = keyword_counts(posts)
    still_present = sorted(keyword for keyword in audited_sources if after[keyword])
    if still_present:
        raise ValueError(f"Retired keyword variants remain in the index: {still_present}")
    for target in set(REPLACE.values()):
        if not after[target]:
            raise ValueError(f"Normalized keyword is missing: {target}")
    for target in restored_targets:
        if not after[target]:
            raise ValueError(f"Restored keyword is missing: {target}")
    for wp_id, removed_keywords in REMOVE_BY_POST.items():
        post = next(post for post in posts if str(post.get("wpId")) == wp_id)
        remaining = set(post.get("secondaryKeywords") or []) & removed_keywords
        if remaining:
            raise ValueError(f"Post-specific keyword removals remain on {wp_id}: {remaining}")

    write_json(POSTS_PATH, posts)
    write_json(RETIREMENTS_PATH, retirements)
    print(f"Updated {changed_posts} posts.")
    print(f"Retired {len(REMOVE)} secondary keywords.")
    print(f"Normalized {len(REPLACE)} keyword variants.")
    print(f"Unique secondary keywords: {len(after):,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
