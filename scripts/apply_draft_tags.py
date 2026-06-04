import csv
import json
import re
from collections import Counter
from pathlib import Path

ROOT = Path(r"C:\Users\charl\OneDrive\Documents\Bart Ehrman Blog")
POSTS_PATH = ROOT / "data" / "raw" / "posts.jsonl"
TAXONOMY_PATH = ROOT / "data" / "analysis" / "draft_tag_taxonomy.json"
OUT_JSONL = ROOT / "data" / "index" / "posts_with_draft_controlled_tags.jsonl"
OUT_CSV = ROOT / "data" / "index" / "posts_with_draft_controlled_tags.csv"
OUT_COUNTS = ROOT / "data" / "analysis" / "draft_controlled_tag_counts.csv"


RULES = {
    "historical-jesus": {
        "category": [r"historical-jesus", r"mythicism"],
        "tag": [r"historical-jesus", r"the-historical-jesus", r"mythicism", r"mythicists", r"messiah", r"crucifixion"],
        "title": [r"\bjesus\b", r"\bcrucifixion\b", r"\bson of man\b", r"\bmessiah\b", r"\bmythic"],
        "text": [r"\bhistorical Jesus\b", r"\bJesus' death\b", r"\bJesus was crucified\b", r"\bJesus existed\b"],
    },
    "canonical-gospels": {
        "category": [r"canonical-gospels", r"revelation-of-john"],
        "tag": [r"canonical-gospels", r"gospel-of-", r"\bmatthew\b", r"\bmark\b", r"\bluke\b", r"\bjohn\b", r"\bq\b"],
        "title": [r"\bgospel", r"\bmatthew\b", r"\bmark\b", r"\bluke\b", r"\bjohn\b", r"\bsynoptic\b"],
        "text": [r"\bGospel of (Matthew|Mark|Luke|John)\b", r"\bSynoptic\b"],
    },
    "paul-and-letters": {
        "category": [r"paul-and-his-letters", r"catholic-epistles"],
        "tag": [r"\bpaul\b", r"pauline", r"philippians", r"romans", r"corinthians", r"galatians", r"thessalonians"],
        "title": [r"\bpaul\b", r"\bpauline\b", r"\bphilippians\b", r"\bromans\b", r"\bcorinthians\b", r"\bgalatians\b"],
        "text": [r"\bPaul\b", r"\bPauline\b", r"\b1 Corinthians\b", r"\bRomans\b"],
    },
    "acts-and-revelation": {
        "category": [r"acts-of-the-apostles", r"revelation-of-john"],
        "tag": [r"\bacts\b", r"\brevelation\b", r"apocalypticism"],
        "title": [r"\bacts\b", r"\brevelation\b", r"\bapocalypse\b", r"\bapocalyptic"],
        "text": [r"\bbook of Acts\b", r"\bRevelation\b", r"\bApocalypse\b"],
    },
    "hebrew-bible-judaism": {
        "category": [r"hebrew-bible-old-testament", r"early-judaism", r"jews-and-christians"],
        "tag": [r"hebrew-bible", r"old-testament", r"\bjob\b", r"\btorah\b", r"\bjewish\b", r"\bjudaism\b"],
        "title": [r"\bhebrew bible\b", r"\bold testament\b", r"\bjew", r"\bjudaism\b", r"\btorah\b", r"\bbible\b"],
        "text": [r"\bSecond Temple\b", r"\bHebrew Bible\b", r"\bOld Testament\b", r"\bJewish\b", r"\bJudaism\b"],
    },
    "early-christianity": {
        "category": [r"history-of-christianity", r"early-christian-writings", r"proto-orthodox-writers", r"fourth-century-christianity"],
        "tag": [r"apostolic-fathers", r"rise-of-christianity", r"proto-orthodox", r"church-fathers"],
        "title": [r"\bearly christian", r"\bchristian origins\b", r"\bapostolic fathers\b", r"\bpapias\b", r"\bbarnabas\b"],
        "text": [r"\bearly Christians?\b", r"\bApostolic Fathers\b", r"\bchurch fathers\b", r"\bPapias\b", r"\bEpistle of Barnabas\b"],
    },
    "christian-apocrypha": {
        "category": [r"early-christian-apocrypha"],
        "tag": [r"christian-apocrypha", r"gospel-of-jesus-wife", r"gnosticism", r"gospel-of-thomas", r"gospel-of-peter"],
        "title": [r"\bapocryph", r"\blost gospel", r"\bgnostic", r"\bgospel of thomas\b", r"\bgospel of peter\b", r"\bgospel of mary\b"],
        "text": [r"\bapocryph", r"\bnon-canonical\b", r"\bGospel of Thomas\b", r"\bGospel of Peter\b", r"\bGnostic"],
    },
    "greco-roman-world": {
        "category": [r"greco-roman", r"constantine"],
        "tag": [r"roman-empire", r"constantine", r"pagan", r"mystery-religion"],
        "title": [r"\broman\b", r"\bpagan\b", r"\bconstantine\b", r"\bempire\b"],
        "text": [r"\bRoman Empire\b", r"\bpagan\b", r"\bGreco-Roman\b", r"\bConstantine\b"],
    },
    "church-history": {
        "category": [r"history-of-christianity", r"fourth-century-christianity", r"constantine"],
        "tag": [r"constantine", r"reformation", r"augustine", r"council"],
        "title": [r"\bconstantine\b", r"\baugustine\b", r"\breformation\b", r"\bcouncil\b", r"\bpredestination\b", r"\bcalvin\b"],
        "text": [r"\bAugustine\b", r"\bConstantine\b", r"\bReformation\b", r"\bcouncil\b", r"\bpredestination\b", r"\bCalvin\b"],
    },
    "resurrection": {
        "category": [],
        "tag": [r"resurrection", r"burial", r"empty-tomb"],
        "title": [r"\bresurrection\b", r"\bempty tomb\b", r"\braised\b"],
        "text": [r"\bresurrection\b", r"\bempty tomb\b", r"\braised from the dead\b"],
    },
    "afterlife": {
        "category": [r"afterlife"],
        "tag": [r"afterlife", r"\bhell\b", r"\bheaven\b"],
        "title": [r"\bafterlife\b", r"\bheaven\b", r"\bhell\b", r"\bsalvation\b"],
        "text": [r"\bafterlife\b", r"\bheaven\b", r"\bhell\b", r"\beternal life\b", r"\bsalvation\b"],
    },
    "christology": {
        "category": [r"early-christian-doctrine"],
        "tag": [r"christology", r"adoptionist-christology", r"how-jesus-became-god"],
        "title": [r"\bchristology\b", r"\bjesus became god\b", r"\bdivine\b", r"\bson of god\b"],
        "text": [r"\bChristology\b", r"\bdivine Christ\b", r"\bSon of God\b", r"\bJesus became God\b"],
    },
    "suffering-and-evil": {
        "category": [],
        "tag": [r"suffering", r"theodicy", r"problem-of-evil", r"evil"],
        "title": [r"\bsuffering\b", r"\bevil\b", r"\btheodicy\b"],
        "text": [r"\bproblem of evil\b", r"\bsuffering\b", r"\btheodicy\b"],
    },
    "miracles": {
        "category": [],
        "tag": [r"miracle", r"exorcism", r"healing"],
        "title": [r"\bmiracle", r"\bexorcism", r"\bhealing"],
        "text": [r"\bmiracles?\b", r"\bexorcism\b", r"\bhealing\b"],
    },
    "martyrdom-persecution": {
        "category": [],
        "tag": [r"martyr", r"persecution"],
        "title": [r"\bmartyr", r"\bpersecution\b"],
        "text": [r"\bmartyrs?\b", r"\bpersecution\b"],
    },
    "heresy-orthodoxy": {
        "category": [r"heresy-and-orthodoxy"],
        "tag": [r"heresy", r"orthodoxy", r"gnosticism", r"proto-orthodox"],
        "title": [r"\bheresy\b", r"\borthodoxy\b", r"\bgnostic"],
        "text": [r"\bheresy\b", r"\borthodoxy\b", r"\bGnostic"],
    },
    "canon-and-scripture": {
        "category": [],
        "tag": [r"canon", r"canonical", r"biblical-inerrancy", r"scripture"],
        "title": [r"\bcanon\b", r"\bscripture\b", r"\binerrancy\b", r"\bbible\b", r"\bchapters and verses\b", r"\bcontradictions?\b", r"\bmistakes?\b"],
        "text": [r"\bcanon\b", r"\bscripture\b", r"\binerrancy\b", r"\binspired\b", r"\bBible\b", r"\bchapters and verses\b", r"\bcontradictions?\b"],
    },
    "women-gender-sexuality": {
        "category": [r"women-in-early-christianity", r"sex-and-sexuality"],
        "tag": [r"women", r"gender", r"sexual", r"mary-magdalene"],
        "title": [r"\bwomen\b", r"\bgender\b", r"\bsexual", r"\bmary magdalene\b"],
        "text": [r"\bwomen\b", r"\bgender\b", r"\bsexual", r"\bMary Magdalene\b"],
    },
    "spread-of-christianity": {
        "category": [r"spread-christianity"],
        "tag": [r"rise-of-christianity", r"triumph-of-christianity", r"conversion"],
        "title": [r"\bspread\b", r"\bconvert", r"\btriumph of christianity\b", r"\bsucceed"],
        "text": [r"\bspread of Christianity\b", r"\bconversion\b", r"\bconvert"],
    },
    "mythicism": {
        "category": [r"mythicism"],
        "tag": [r"mythicism", r"mythicists"],
        "title": [r"\bmythic"],
        "text": [r"\bmythicism\b", r"\bmythicists?\b"],
    },
    "faith-doubt-belief": {
        "category": [r"reflections-ruminations"],
        "tag": [r"agnosticism", r"fundamentalism", r"biblical-inerrancy", r"faith", r"doubt"],
        "title": [r"\bfaith\b", r"\bdoubt\b", r"\bagnostic", r"\bfundamental", r"\bpredestination\b", r"\bgod\b", r"\bmormon"],
        "text": [r"\bfaith\b", r"\bdoubt\b", r"\bagnostic", r"\bfundamentalism\b", r"\bpredestination\b", r"\bMormon"],
    },
    "textual-criticism": {
        "category": [r"new-testament-manuscripts"],
        "tag": [r"textual-criticism", r"textual-variants", r"scribal", r"manuscripts", r"original-text"],
        "title": [r"\btextual\b", r"\bmanuscript", r"\bscrib", r"\boriginal text\b"],
        "text": [r"\btextual criticism\b", r"\bmanuscripts?\b", r"\bscribes?\b", r"\bvariants?\b"],
    },
    "translation": {
        "category": [],
        "tag": [r"biblical-translation", r"king-james-bible", r"nrsv"],
        "title": [r"\btranslation\b", r"\btranslate", r"\bking james\b", r"\bnrsv\b"],
        "text": [r"\btranslation\b", r"\bGreek\b", r"\bHebrew\b", r"\bNRSV\b", r"\bKing James\b"],
    },
    "authorship-forgery": {
        "category": [r"forgery-in-antiquity"],
        "tag": [r"forgery", r"authorship", r"forgery-and-counterforgery"],
        "title": [r"\bforg", r"\bauthor", r"\bwho wrote\b"],
        "text": [r"\bforgery\b", r"\bforged\b", r"\bpseudonymous\b", r"\bauthorship\b"],
    },
    "historical-method": {
        "category": [r"history-of-biblical-scholarship"],
        "tag": [r"historical-criticism", r"historicity", r"professional-scholarship"],
        "title": [r"\bhistorical method\b", r"\bhistoricity\b", r"\bevidence\b", r"\btrustworthy\b", r"\bdirect access\b"],
        "text": [r"\bhistorical method\b", r"\bhistorical criticism\b", r"\bevidence\b", r"\bcriteria\b", r"\btrustworthy\b", r"\bdirect access\b"],
    },
    "memory-oral-tradition": {
        "category": [r"memory-studies"],
        "tag": [r"memory", r"oral-traditions", r"jesus-before-the-gospels"],
        "title": [r"\bmemory\b", r"\boral tradition\b"],
        "text": [r"\bmemory\b", r"\boral tradition\b", r"\beyewitness"],
    },
    "archaeology-material-culture": {
        "category": [],
        "tag": [r"archaeology", r"inscription", r"skeletal"],
        "title": [r"\barchaeolog", r"\binscription", r"\bskeletal", r"\bartifact"],
        "text": [r"\barchaeolog", r"\binscriptions?\b", r"\bartifacts?\b", r"\bskeletal remains\b"],
    },
    "reader-questions": {
        "category": [r"readers-questions"],
        "tag": [r"reader", r"mailbag"],
        "title": [r"\breader", r"\bmailbag\b", r"\bquestions?\b"],
        "text": [r"\bQUESTION:\b", r"\bRESPONSE:\b"],
    },
    "book-discussion": {
        "category": [r"book-discussions"],
        "tag": [r"textbook", r"how-jesus-became-god", r"triumph-of-christianity", r"misquoting-jesus"],
        "title": [r"\bbook\b", r"\bmy book\b"],
        "text": [r"\bmy book\b", r"\bbook\b", r"\bpublished\b", r"\bpublisher\b"],
    },
    "personal-autobiographical": {
        "category": [r"barts-biography", r"reflections-ruminations"],
        "tag": [r"autobiographical", r"agnosticism", r"moody", r"bruce-metzger"],
        "title": [r"\bautobiographical\b", r"\bmy life\b", r"\bmy career\b", r"\bpersonal\b"],
        "text": [r"\bwhen I was\b", r"\bmy life\b", r"\bMoody Bible Institute\b", r"\bPrinceton\b"],
    },
    "teaching-scholarship": {
        "category": [r"teaching-christianity", r"christianity-in-classroom", r"history-of-biblical-scholarship"],
        "tag": [r"pedagogy", r"professional-scholarship", r"teaching", r"tenure"],
        "title": [r"\bteaching\b", r"\bclassroom\b", r"\btenure\b", r"\bscholar", r"\blecture\b", r"\bwork habits\b", r"\bproductivity\b"],
        "text": [r"\bteaching\b", r"\bclassroom\b", r"\bscholarship\b", r"\btenure\b", r"\blecture\b", r"\bproductivity\b"],
    },
    "debates-and-critics": {
        "category": [r"barts-debates", r"barts-critics"],
        "tag": [r"debate", r"craig-evans", r"dan-wallace", r"reza-aslan"],
        "title": [r"\bdebate\b", r"\bcritic", r"\bpublic debates\b"],
        "text": [r"\bdebate\b", r"\bcritics?\b"],
    },
    "media-video": {
        "category": [r"video-media"],
        "tag": [r"video", r"c-span", r"interview", r"podcast"],
        "title": [r"\bvideo\b", r"\binterview\b", r"\bpodcast\b", r"\bcourse\b", r"\bdocumentary\b"],
        "text": [r"\bvideo\b", r"\binterview\b", r"\bpodcast\b", r"\bGreat Courses\b", r"\bdocumentary\b"],
    },
    "blog-community": {
        "category": [r"platinums"],
        "tag": [r"platinum", r"gold"],
        "title": [r"\bgold\b", r"\bplatinum\b", r"\bwebinar\b", r"\bblog dinner\b", r"\bvote\b", r"\bq&a\b", r"\bblog\b", r"\bcruise\b", r"\btrip\b", r"\bannual appeal\b", r"\bsponsor a stranger\b", r"\bfavorite post\b", r"\byou'?re invited\b"],
        "text": [r"\bGold\b", r"\bPlatinum\b", r"\bwebinar\b", r"\bblog dinner\b", r"\bmembership\b", r"\bannual appeal\b", r"\bSponsor a Stranger\b", r"\bcharit"],
    },
    "news-current-events": {
        "category": [r"religion-in-the-news"],
        "tag": [r"religion-in-the-news"],
        "title": [r"\bnews\b", r"\btoday\b"],
        "text": [r"\bin the news\b", r"\bcurrent events\b"],
    },
}

WEIGHTS = {"category": 7, "tag": 6, "title": 5, "text": 1}
THRESHOLDS = {"content_area": 5, "theme": 5, "method": 5, "format": 5}
GROUP_LIMITS = {"content_area": 3, "theme": 5, "method": 3, "format": 2}


def compile_rules():
    return {
        tag: {
            field: [re.compile(pattern, re.I) for pattern in patterns]
            for field, patterns in field_rules.items()
        }
        for tag, field_rules in RULES.items()
    }


def load_jsonl(path):
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def score_post(post, compiled):
    fields = {
        "category": " ".join(post.get("categories") or []),
        "tag": " ".join(post.get("tags") or []),
        "title": post.get("title", ""),
        "text": post.get("text", "")[:6000],
    }
    scores = {}
    for tag, field_rules in compiled.items():
        score = 0
        reasons = []
        for field, patterns in field_rules.items():
            matches = sum(1 for pattern in patterns if pattern.search(fields[field]))
            if matches:
                score += WEIGHTS[field] * matches
                reasons.append(field)
        if score:
            scores[tag] = {"score": score, "reasons": reasons}
    return scores


def select_tags(scores, taxonomy_by_tag):
    grouped = {}
    for tag, info in scores.items():
        group = taxonomy_by_tag[tag]["group"]
        if info["score"] >= THRESHOLDS[group]:
            grouped.setdefault(group, []).append((tag, info["score"]))

    selected_by_group = {}
    for group, items in grouped.items():
        items.sort(key=lambda item: (-item[1], item[0]))
        selected_by_group[group] = [tag for tag, _score in items[: GROUP_LIMITS[group]]]

    selected = []
    for group in ["content_area", "theme", "method", "format"]:
        selected.extend(selected_by_group.get(group, []))
    return selected, selected_by_group


def main():
    posts = load_jsonl(POSTS_PATH)
    taxonomy = json.loads(TAXONOMY_PATH.read_text(encoding="utf-8"))
    taxonomy_by_tag = {row["tag"]: row for row in taxonomy}
    compiled = compile_rules()
    counts = Counter()
    rows = []

    with OUT_JSONL.open("w", encoding="utf-8", newline="") as jsonl:
        for post in posts:
            scores = score_post(post, compiled)
            selected, selected_by_group = select_tags(scores, taxonomy_by_tag)
            counts.update(selected)
            row = {
                "title": post.get("title", ""),
                "url": post.get("url", ""),
                "date": post.get("dateText", ""),
                "author": post.get("author", ""),
                "wordCount": post.get("wordCount", 0),
                "legacyCategories": post.get("categories", []),
                "legacyTags": post.get("tags", []),
                "controlledTagsDraft": selected,
                "controlledTagGroupsDraft": selected_by_group,
                "tagScoresDraft": scores,
            }
            rows.append(row)
            jsonl.write(json.dumps(row, ensure_ascii=False) + "\n")

    with OUT_CSV.open("w", encoding="utf-8", newline="") as handle:
        fieldnames = ["title", "url", "date", "wordCount", "legacyCategories", "legacyTags", "controlledTagsDraft"]
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow({
                "title": row["title"],
                "url": row["url"],
                "date": row["date"],
                "wordCount": row["wordCount"],
                "legacyCategories": "; ".join(row["legacyCategories"]),
                "legacyTags": "; ".join(row["legacyTags"]),
                "controlledTagsDraft": "; ".join(row["controlledTagsDraft"]),
            })

    with OUT_COUNTS.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["controlled_tag_draft", "count"])
        writer.writerows(counts.most_common())

    print(json.dumps({
        "posts": len(posts),
        "taggedPosts": sum(1 for row in rows if row["controlledTagsDraft"]),
        "untaggedPosts": sum(1 for row in rows if not row["controlledTagsDraft"]),
        "tagCount": len(counts),
        "outputs": [str(OUT_JSONL), str(OUT_CSV), str(OUT_COUNTS)],
    }, indent=2))


if __name__ == "__main__":
    main()
