import csv
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(r"C:\Users\charl\OneDrive\Documents\Bart Ehrman Blog")
POSTS_PATH = ROOT / "data" / "raw" / "posts.jsonl"
OUT_DIR = ROOT / "data" / "analysis"

THEME_RULES = {
    "historical-jesus": r"\bhistorical jesus\b|\bjesus\b|\bson of man\b|\bmessiah\b|\bchrist\b|\bcrucifixion\b",
    "gospels": r"\bgospels?\b|\bmatthew\b|\bmark\b|\bluke\b|\bjohn\b|\bsynoptic\b",
    "paul": r"\bpaul\b|\bpauline\b|\bromans\b|\bcorinthians\b|\bgalatians\b|\bphilippians\b|\bthessalonians\b",
    "new-testament": r"\bnew testament\b|\bacts\b|\brevelation\b|\bepistles?\b|\bcanon\b",
    "hebrew-bible-judaism": r"\bhebrew bible\b|\bold testament\b|\btorah\b|\bjewish\b|\bjudaism\b|\bpharisee\b|\bsadducee\b",
    "early-christianity": r"\bearly christian\b|\bchristian origins\b|\bproto-orthodox\b|\bapostolic fathers\b|\bchurch fathers\b",
    "christian-apocrypha": r"\bapocryph|\bnon-canonical\b|\bgospel of thomas\b|\bgospel of peter\b|\bgospel of mary\b",
    "textual-criticism-manuscripts": r"\btextual criticism\b|\bmanuscripts?\b|\bscribes?\b|\bcopyists?\b|\bvariants?\b|\binterpolation\b",
    "authorship-forgery": r"\bforger|\bforged\b|\bpseudonymous\b|\bauthorship\b|\bwho wrote\b",
    "resurrection-afterlife": r"\bresurrection\b|\bempty tomb\b|\bafterlife\b|\bheaven\b|\bhell\b|\beternal\b|\bsalvation\b",
    "miracles-martyrdom": r"\bmiracles?\b|\bexorcism\b|\bhealing\b|\bmartyrs?\b|\bpersecution\b",
    "christology": r"\bchristology\b|\bdivine christ\b|\bincarnation\b|\btrinity\b|\bson of god\b",
    "roman-world-paganism": r"\broman\b|\bempire\b|\bpagan\b|\bgentile\b|\bconstantine\b|\bgreco-roman\b",
    "women-gender-sexuality": r"\bwomen\b|\bwoman\b|\bgender\b|\bsexual\b|\bmary magdalene\b|\bfemale\b",
    "suffering-evil-theodicy": r"\bsuffering\b|\bevil\b|\btheodicy\b|\bpain\b|\bproblem of evil\b",
    "books-publication-media": r"\bbook\b|\bpublication\b|\binterview\b|\bdebate\b|\blecture\b|\bcourse\b|\bpodcast\b|\bvideo\b",
    "reader-questions": r"\breader'?s questions\b|\bquestion:\b|\bmailbag\b|\bq&a\b",
    "blog-community": r"\bwebinar\b|\bgold\b|\bplatinum\b|\bannouncement\b|\bblog dinner\b|\bvote\b|\bmembership\b",
    "personal-autobiographical": r"\bautobiographical\b|\bpersonal\b|\bmy life\b|\bmoody\b|\bprinceton\b|\bcareer\b|\bteaching\b",
}


def load_posts():
    return [
        json.loads(line)
        for line in POSTS_PATH.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]


def norm(value):
    return re.sub(r"(^-|-$)", "", re.sub(r"[^a-z0-9]+", "-", value.lower()))


def top_examples(posts, tag, limit=8):
    pattern = re.compile(THEME_RULES[tag], re.I)
    scored = []
    for post in posts:
        haystack = f"{post.get('title','')}\n{' '.join(post.get('categories') or [])}\n{' '.join(post.get('tags') or [])}\n{post.get('text','')[:4000]}"
        if not pattern.search(haystack):
            continue
        score = 0
        score += 8 if pattern.search(post.get("title", "")) else 0
        score += 4 if pattern.search(" ".join(post.get("categories") or [])) else 0
        score += 3 if pattern.search(" ".join(post.get("tags") or [])) else 0
        score += len(pattern.findall(post.get("text", "")[:4000]))
        scored.append((score, post))
    scored.sort(key=lambda item: (-item[0], item[1].get("dateText", "")))
    return [
        {
            "title": post.get("title", ""),
            "url": post.get("url", ""),
            "date": post.get("dateText", ""),
            "categories": post.get("categories", []),
        }
        for _, post in scored[:limit]
    ]


def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    posts = load_posts()

    category_counts = Counter(category for post in posts for category in post.get("categories", []))
    original_tag_counts = Counter(tag for post in posts for tag in post.get("tags", []))
    candidate_tag_counts = Counter(tag for post in posts for tag in post.get("candidateTags", []))

    theme_counts = {}
    theme_examples = {}
    for tag, raw_pattern in THEME_RULES.items():
        pattern = re.compile(raw_pattern, re.I)
        count = 0
        for post in posts:
            haystack = f"{post.get('title','')}\n{' '.join(post.get('categories') or [])}\n{' '.join(post.get('tags') or [])}\n{post.get('text','')[:5000]}"
            if pattern.search(haystack):
                count += 1
        theme_counts[tag] = count
        theme_examples[tag] = top_examples(posts, tag)

    report = {
        "postCount": len(posts),
        "totalWords": sum(post.get("wordCount", 0) for post in posts),
        "categoryCounts": category_counts.most_common(),
        "originalTagCountsTop200": original_tag_counts.most_common(200),
        "candidateTagCounts": candidate_tag_counts.most_common(),
        "themeCounts": sorted(theme_counts.items(), key=lambda item: item[1], reverse=True),
        "themeExamples": theme_examples,
    }
    (OUT_DIR / "tag_signal_report.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    with (OUT_DIR / "existing_categories.csv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["category", "count"])
        writer.writerows(category_counts.most_common())

    with (OUT_DIR / "existing_tags_top200.csv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["tag", "count"])
        writer.writerows(original_tag_counts.most_common(200))

    with (OUT_DIR / "draft_theme_counts.csv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["draft_tag", "matched_posts"])
        writer.writerows(sorted(theme_counts.items(), key=lambda item: item[1], reverse=True))

    print(json.dumps({
        "posts": len(posts),
        "categories": len(category_counts),
        "originalTags": len(original_tag_counts),
        "draftThemes": len(theme_counts),
        "outDir": str(OUT_DIR),
    }, indent=2))


if __name__ == "__main__":
    main()
