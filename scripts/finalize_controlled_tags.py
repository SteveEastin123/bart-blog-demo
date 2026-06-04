import csv
import json
from pathlib import Path

ROOT = Path(r"C:\Users\charl\OneDrive\Documents\Bart Ehrman Blog")
ANALYSIS_DIR = ROOT / "data" / "analysis"
INDEX_DIR = ROOT / "data" / "index"

DRAFT_TAXONOMY_MD = ANALYSIS_DIR / "draft_tag_taxonomy.md"
DRAFT_TAXONOMY_JSON = ANALYSIS_DIR / "draft_tag_taxonomy.json"
DRAFT_TAGGED_JSONL = INDEX_DIR / "posts_with_draft_controlled_tags.jsonl"

FINAL_TAXONOMY_MD = ANALYSIS_DIR / "controlled_tag_taxonomy.md"
FINAL_TAXONOMY_JSON = ANALYSIS_DIR / "controlled_tag_taxonomy.json"
FINAL_TAGGED_JSONL = INDEX_DIR / "posts_with_controlled_tags.jsonl"
FINAL_TAGGED_CSV = INDEX_DIR / "posts_with_controlled_tags.csv"
FINAL_COUNTS_CSV = ANALYSIS_DIR / "controlled_tag_counts.csv"


def load_jsonl(path):
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def main():
    FINAL_TAXONOMY_MD.write_text(
        DRAFT_TAXONOMY_MD.read_text(encoding="utf-8").replace("Draft Controlled Tag Taxonomy", "Controlled Tag Taxonomy"),
        encoding="utf-8",
    )
    FINAL_TAXONOMY_JSON.write_text(DRAFT_TAXONOMY_JSON.read_text(encoding="utf-8"), encoding="utf-8")

    draft_rows = load_jsonl(DRAFT_TAGGED_JSONL)
    final_rows = []
    counts = {}
    for row in draft_rows:
        controlled_tags = row.get("controlledTagsDraft", [])
        for tag in controlled_tags:
            counts[tag] = counts.get(tag, 0) + 1
        final = {
            "title": row.get("title", ""),
            "url": row.get("url", ""),
            "date": row.get("date", ""),
            "author": row.get("author", ""),
            "wordCount": row.get("wordCount", 0),
            "legacyCategories": row.get("legacyCategories", []),
            "legacyTags": row.get("legacyTags", []),
            "controlledTags": controlled_tags,
            "controlledTagGroups": row.get("controlledTagGroupsDraft", {}),
            "needsTagReview": not bool(controlled_tags),
        }
        final_rows.append(final)

    with FINAL_TAGGED_JSONL.open("w", encoding="utf-8", newline="") as handle:
        for row in final_rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")

    with FINAL_TAGGED_CSV.open("w", encoding="utf-8", newline="") as handle:
        fieldnames = [
            "title",
            "url",
            "date",
            "wordCount",
            "legacyCategories",
            "legacyTags",
            "controlledTags",
            "needsTagReview",
        ]
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in final_rows:
            writer.writerow({
                "title": row["title"],
                "url": row["url"],
                "date": row["date"],
                "wordCount": row["wordCount"],
                "legacyCategories": "; ".join(row["legacyCategories"]),
                "legacyTags": "; ".join(row["legacyTags"]),
                "controlledTags": "; ".join(row["controlledTags"]),
                "needsTagReview": row["needsTagReview"],
            })

    with FINAL_COUNTS_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["controlled_tag", "count"])
        for tag, count in sorted(counts.items(), key=lambda item: (-item[1], item[0])):
            writer.writerow([tag, count])

    print(json.dumps({
        "posts": len(final_rows),
        "taggedPosts": sum(1 for row in final_rows if row["controlledTags"]),
        "needsTagReview": sum(1 for row in final_rows if row["needsTagReview"]),
        "taxonomyTags": len(json.loads(FINAL_TAXONOMY_JSON.read_text(encoding="utf-8"))),
        "outputs": [
            str(FINAL_TAXONOMY_MD),
            str(FINAL_TAXONOMY_JSON),
            str(FINAL_TAGGED_JSONL),
            str(FINAL_TAGGED_CSV),
            str(FINAL_COUNTS_CSV),
        ],
    }, indent=2))


if __name__ == "__main__":
    main()
