from __future__ import annotations

import argparse
import json
import re
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CATEGORIES_PATH = ROOT / "data" / "index" / "ehrman_post_categories.json"
DEFAULT_CATEGORY_GROUPS_PATH = ROOT / "data" / "index" / "ehrman_post_category_groups.json"
DEFAULT_SEARCH_INDEX_PATH = ROOT / "data" / "index" / "ehrman_post_search_index.json"
DEFAULT_TOPICS_PATH = ROOT / "data" / "index" / "ehrman_post_topics.json"
DEFAULT_DB_PATH = ROOT / "webapp" / "data" / "ehrman_search.db"


SCHEMA = """
PRAGMA foreign_keys = ON;

CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT ''
);

CREATE TABLE category_groups (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT ''
);

CREATE TABLE topics (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    display_in_browser INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE posts (
    id INTEGER PRIMARY KEY,
    wp_id TEXT,
    title TEXT NOT NULL,
    url TEXT NOT NULL UNIQUE,
    author TEXT NOT NULL DEFAULT '',
    date_text TEXT NOT NULL DEFAULT '',
    date_iso TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT ''
);

CREATE TABLE keywords (
    id INTEGER PRIMARY KEY,
    label TEXT NOT NULL,
    normalized TEXT NOT NULL UNIQUE
);

CREATE TABLE topic_categories (
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (topic_id, category_id)
);

CREATE TABLE category_group_categories (
    category_group_id INTEGER NOT NULL REFERENCES category_groups(id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (category_group_id, category_id)
);

CREATE TABLE post_topics (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, topic_id)
);

CREATE TABLE post_keywords (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    keyword_id INTEGER NOT NULL REFERENCES keywords(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, keyword_id)
);

CREATE TABLE post_search_terms (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    normalized TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('topic', 'secondary')),
    weight INTEGER NOT NULL,
    PRIMARY KEY (post_id, normalized, kind)
);

CREATE INDEX idx_categories_name ON categories(name COLLATE NOCASE);
CREATE INDEX idx_categories_slug ON categories(slug);
CREATE INDEX idx_category_groups_name ON category_groups(name COLLATE NOCASE);
CREATE INDEX idx_category_groups_slug ON category_groups(slug);
CREATE INDEX idx_topics_name ON topics(name COLLATE NOCASE);
CREATE INDEX idx_topics_slug ON topics(slug);
CREATE INDEX idx_posts_date ON posts(date_iso DESC, id DESC);
CREATE INDEX idx_posts_title ON posts(title COLLATE NOCASE);
CREATE INDEX idx_keywords_normalized ON keywords(normalized);
CREATE INDEX idx_post_keywords_keyword_post ON post_keywords(keyword_id, post_id);
CREATE INDEX idx_post_topics_topic_post ON post_topics(topic_id, post_id);
CREATE INDEX idx_topic_categories_category_topic ON topic_categories(category_id, topic_id);
CREATE INDEX idx_topic_categories_category_position ON topic_categories(category_id, position, topic_id);
CREATE INDEX idx_category_group_categories_group_position ON category_group_categories(category_group_id, position);
CREATE INDEX idx_category_group_categories_category_group ON category_group_categories(category_id, category_group_id);
CREATE INDEX idx_search_terms_normalized_post ON post_search_terms(normalized, post_id);
CREATE INDEX idx_search_terms_label ON post_search_terms(label COLLATE NOCASE);
"""


def clean_string(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def read_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def normalize_keyword(value: Any) -> str:
    text = clean_string(value).lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9]+", " ", text).strip()
    return re.sub(r"\s+", " ", text)


def slugify(value: Any) -> str:
    normalized = normalize_keyword(value)
    return normalized.replace(" ", "-") or "item"


def unique_slug(value: Any, used_slugs: set[str]) -> str:
    base_slug = slugify(value)
    slug = base_slug
    suffix = 2
    while slug in used_slugs:
        slug = f"{base_slug}-{suffix}"
        suffix += 1
    used_slugs.add(slug)
    return slug


def unique_strings(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    seen: set[str] = set()
    unique: list[str] = []
    for raw_value in values:
        value = clean_string(raw_value)
        if not value:
            continue
        key = value.casefold()
        if key in seen:
            continue
        seen.add(key)
        unique.append(value)
    return unique


def parse_date_value(date_text: Any) -> datetime | None:
    text = clean_string(date_text)
    if not text:
        return None
    try:
        return datetime.strptime(text, "%B %d, %Y")
    except ValueError:
        pass
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return None


def date_sort_value(date_text: Any) -> str:
    parsed = parse_date_value(date_text)
    return parsed.strftime("%Y-%m-%d") if parsed else ""


def display_date_text(date_text: Any) -> str:
    text = clean_string(date_text)
    if "T" not in text:
        return text
    parsed = parse_date_value(text)
    if not parsed:
        return text
    return f"{parsed.strftime('%B')} {parsed.day}, {parsed.year}"


def load_categories(path: Path) -> list[dict[str, Any]]:
    data = read_json(path)
    categories = data.get("categories") if isinstance(data, dict) else data
    if not isinstance(categories, list):
        raise ValueError(f"{path} must contain a categories list")
    return categories


def load_category_groups(path: Path) -> list[dict[str, Any]]:
    data = read_json(path)
    category_groups = data.get("categoryGroups") if isinstance(data, dict) else data
    if not isinstance(category_groups, list):
        raise ValueError(f"{path} must contain a categoryGroups list")
    return category_groups


def load_topics(path: Path) -> list[dict[str, Any]]:
    data = read_json(path)
    topics = data.get("topics") if isinstance(data, dict) else data
    if not isinstance(topics, list):
        raise ValueError(f"{path} must contain a topics list")
    return topics


def load_posts(path: Path) -> list[dict[str, Any]]:
    data = read_json(path)
    if not isinstance(data, list):
        raise ValueError(f"{path} must contain a posts list")
    return data


def insert_keyword(conn: sqlite3.Connection, label: str) -> int | None:
    normalized = normalize_keyword(label)
    if not normalized:
        return None
    conn.execute(
        """
        INSERT INTO keywords(label, normalized)
        VALUES (?, ?)
        ON CONFLICT(normalized) DO UPDATE SET label = excluded.label
        """,
        (label, normalized),
    )
    row = conn.execute("SELECT id FROM keywords WHERE normalized = ?", (normalized,)).fetchone()
    return int(row[0]) if row else None


def build_database(
    db_path: Path = DEFAULT_DB_PATH,
    categories_path: Path = DEFAULT_CATEGORIES_PATH,
    category_groups_path: Path = DEFAULT_CATEGORY_GROUPS_PATH,
    topics_path: Path = DEFAULT_TOPICS_PATH,
    search_index_path: Path = DEFAULT_SEARCH_INDEX_PATH,
) -> dict[str, int]:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = db_path.with_suffix(db_path.suffix + ".tmp")
    if tmp_path.exists():
        tmp_path.unlink()

    categories = load_categories(categories_path)
    category_groups = load_category_groups(category_groups_path)
    topics = load_topics(topics_path)
    posts = load_posts(search_index_path)

    conn = sqlite3.connect(tmp_path)
    conn.row_factory = sqlite3.Row
    try:
        conn.executescript(SCHEMA)

        used_category_slugs: set[str] = set()
        for category in categories:
            name = clean_string(category.get("name"))
            if not name:
                continue
            conn.execute(
                "INSERT INTO categories(name, slug, description) VALUES (?, ?, ?)",
                (name, unique_slug(name, used_category_slugs), clean_string(category.get("description"))),
            )

        used_topic_slugs: set[str] = set()
        for topic in topics:
            name = clean_string(topic.get("name"))
            if not name:
                continue
            conn.execute(
                """
                INSERT INTO topics(name, slug, description, display_in_browser)
                VALUES (?, ?, ?, ?)
                """,
                (
                    name,
                    unique_slug(name, used_topic_slugs),
                    clean_string(topic.get("description")),
                    0 if topic.get("displayInBrowser") is False else 1,
                ),
            )

        category_ids = {
            row["name"]: row["id"]
            for row in conn.execute("SELECT id, name FROM categories").fetchall()
        }
        category_topic_positions = {
            clean_string(category.get("name")): {
                topic_name: position
                for position, topic_name in enumerate(unique_strings(category.get("topicOrder")), start=1)
            }
            for category in categories
        }

        used_category_group_slugs: set[str] = set()
        for category_group in category_groups:
            name = clean_string(category_group.get("name"))
            if not name:
                continue
            conn.execute(
                "INSERT INTO category_groups(name, slug, description) VALUES (?, ?, ?)",
                (name, unique_slug(name, used_category_group_slugs), clean_string(category_group.get("description"))),
            )

        category_group_ids = {
            row["name"]: row["id"]
            for row in conn.execute("SELECT id, name FROM category_groups").fetchall()
        }
        for category_group in category_groups:
            group_name = clean_string(category_group.get("name"))
            group_id = category_group_ids.get(group_name)
            if not group_id:
                continue
            for position, category_name in enumerate(unique_strings(category_group.get("categories")), start=1):
                category_id = category_ids.get(category_name)
                if not category_id:
                    continue
                conn.execute(
                    """
                    INSERT OR IGNORE INTO category_group_categories(category_group_id, category_id, position)
                    VALUES (?, ?, ?)
                    """,
                    (group_id, category_id, position),
                )

        topic_ids = {
            row["name"]: row["id"]
            for row in conn.execute("SELECT id, name FROM topics").fetchall()
        }

        for topic in topics:
            topic_name = clean_string(topic.get("name"))
            topic_id = topic_ids.get(topic_name)
            if not topic_id:
                continue
            for category_name in unique_strings(topic.get("categories")):
                category_id = category_ids.get(category_name)
                if category_id:
                    position = category_topic_positions.get(category_name, {}).get(topic_name, 0)
                    conn.execute(
                        """
                        INSERT OR IGNORE INTO topic_categories(topic_id, category_id, position)
                        VALUES (?, ?, ?)
                        """,
                        (topic_id, category_id, position),
                    )

        for post in posts:
            title = clean_string(post.get("title"))
            url = clean_string(post.get("url"))
            if not title or not url:
                continue
            date_text = display_date_text(post.get("dateText"))
            date_iso = clean_string(post.get("dateSort")) or date_sort_value(date_text)
            cur = conn.execute(
                """
                INSERT INTO posts(wp_id, title, url, author, date_text, date_iso, description)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    clean_string(post.get("wpId")),
                    title,
                    url,
                    clean_string(post.get("author")),
                    date_text,
                    date_iso,
                    clean_string(post.get("description")),
                ),
            )
            post_id = int(cur.lastrowid)

            for topic_name in unique_strings(post.get("topics")):
                topic_id = topic_ids.get(topic_name)
                if not topic_id:
                    continue
                normalized = normalize_keyword(topic_name)
                conn.execute(
                    "INSERT OR IGNORE INTO post_topics(post_id, topic_id) VALUES (?, ?)",
                    (post_id, topic_id),
                )
                conn.execute(
                    """
                    INSERT OR IGNORE INTO post_search_terms(post_id, label, normalized, kind, weight)
                    VALUES (?, ?, ?, 'topic', 5)
                    """,
                    (post_id, topic_name, normalized),
                )

            for keyword in unique_strings(post.get("secondaryKeywords")):
                keyword_id = insert_keyword(conn, keyword)
                if not keyword_id:
                    continue
                normalized = normalize_keyword(keyword)
                conn.execute(
                    "INSERT OR IGNORE INTO post_keywords(post_id, keyword_id) VALUES (?, ?)",
                    (post_id, keyword_id),
                )
                conn.execute(
                    """
                    INSERT OR IGNORE INTO post_search_terms(post_id, label, normalized, kind, weight)
                    VALUES (?, ?, ?, 'secondary', 3)
                    """,
                    (post_id, keyword, normalized),
                )

        conn.commit()
    finally:
        conn.close()

    if db_path.exists():
        db_path.unlink()
    tmp_path.replace(db_path)

    with sqlite3.connect(db_path) as check_conn:
        counts = {
            "posts": check_conn.execute("SELECT COUNT(*) FROM posts").fetchone()[0],
            "category_groups": check_conn.execute("SELECT COUNT(*) FROM category_groups").fetchone()[0],
            "categories": check_conn.execute("SELECT COUNT(*) FROM categories").fetchone()[0],
            "topics": check_conn.execute("SELECT COUNT(*) FROM topics").fetchone()[0],
            "keywords": check_conn.execute("SELECT COUNT(*) FROM keywords").fetchone()[0],
            "search_terms": check_conn.execute("SELECT COUNT(*) FROM post_search_terms").fetchone()[0],
        }
    return counts


def main() -> int:
    parser = argparse.ArgumentParser(description="Build the SQLite database for the Ehrman search web app.")
    parser.add_argument("--db", type=Path, default=DEFAULT_DB_PATH)
    parser.add_argument("--categories", type=Path, default=DEFAULT_CATEGORIES_PATH)
    parser.add_argument("--category-groups", type=Path, default=DEFAULT_CATEGORY_GROUPS_PATH)
    parser.add_argument("--topics", type=Path, default=DEFAULT_TOPICS_PATH)
    parser.add_argument("--search-index", type=Path, default=DEFAULT_SEARCH_INDEX_PATH)
    args = parser.parse_args()

    counts = build_database(args.db, args.categories, args.category_groups, args.topics, args.search_index)
    print(f"Built {args.db}")
    print(
        "Imported "
        f"{counts['posts']:,} posts, "
        f"{counts['category_groups']:,} subject areas, "
        f"{counts['categories']:,} categories, "
        f"{counts['topics']:,} topics, "
        f"{counts['keywords']:,} secondary keywords, "
        f"{counts['search_terms']:,} searchable post terms."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
