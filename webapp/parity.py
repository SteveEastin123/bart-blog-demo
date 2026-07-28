from __future__ import annotations

import hashlib
import json
import os
import platform
import sqlite3
from functools import lru_cache
from pathlib import Path
from typing import Any

from . import app
from .import_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_SUBJECT_AREAS_2_PATH,
    DEFAULT_SUBJECT_AREAS_PATH,
    DEFAULT_TOPICS_PATH,
    ROOT,
    normalize_keyword,
)


SCHEMA_VERSION = 1
MAX_BATCH_CASES = 200
MAX_SELECTED_TERMS = 4
SOURCE_PATHS = (
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_TOPICS_PATH,
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_SUBJECT_AREAS_PATH,
    DEFAULT_SUBJECT_AREAS_2_PATH,
)


class ParityRequestError(ValueError):
    pass


def _relative_path(path: Path) -> str:
    try:
        return path.resolve().relative_to(ROOT.resolve()).as_posix()
    except ValueError:
        return path.resolve().as_posix()


@lru_cache(maxsize=4)
def _fingerprints_for_signature(
    signature: tuple[tuple[str, int, int], ...],
) -> dict[str, Any]:
    combined = hashlib.sha256()
    files: list[dict[str, Any]] = []
    for raw_path, _, _ in signature:
        path = Path(raw_path)
        data = path.read_bytes()
        digest = hashlib.sha256(data).hexdigest()
        name = _relative_path(path)
        combined.update(name.encode("utf-8"))
        combined.update(b"\0")
        combined.update(digest.encode("ascii"))
        combined.update(b"\0")
        files.append({"path": name, "sha256": digest, "bytes": len(data)})
    return {"sha256": combined.hexdigest(), "files": files}


def source_fingerprints(paths: tuple[Path, ...] = SOURCE_PATHS) -> dict[str, Any]:
    signature = tuple(
        (str(path.resolve()), path.stat().st_mtime_ns, path.stat().st_size)
        for path in paths
    )
    return _fingerprints_for_signature(signature)


def database_counts(conn: sqlite3.Connection) -> dict[str, int]:
    queries = {
        "posts": "SELECT COUNT(*) FROM posts",
        "subjectAreas1": "SELECT COUNT(*) FROM subject_areas",
        "subjectAreas2": "SELECT COUNT(*) FROM subject_areas_2",
        "categories": "SELECT COUNT(*) FROM categories",
        "topics": "SELECT COUNT(*) FROM topics",
        "secondaryKeywords": "SELECT COUNT(*) FROM keywords",
        "searchTerms": "SELECT COUNT(*) FROM post_search_terms",
    }
    return {name: int(conn.execute(sql).fetchone()[0]) for name, sql in queries.items()}


def manifest() -> dict[str, Any]:
    with app.get_conn() as conn:
        counts = database_counts(conn)
    return {
        "schemaVersion": SCHEMA_VERSION,
        "implementation": "python",
        "commit": os.environ.get("RENDER_GIT_COMMIT", ""),
        "dataFingerprint": source_fingerprints(),
        "runtime": {
            "python": platform.python_version(),
            "sqlite": sqlite3.sqlite_version,
        },
        "counts": counts,
    }


def _clean_string(value: Any) -> str:
    return str(value).strip() if value is not None else ""


def _string_list(value: Any, field: str, maximum: int | None = None) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list) or any(not isinstance(item, str) for item in value):
        raise ParityRequestError(f"{field} must be a list of strings")
    values = app.unique_terms(value)
    if maximum is not None and len(values) > maximum:
        raise ParityRequestError(f"{field} supports at most {maximum} unique values")
    return values


def _normalized_sort(value: Any) -> str:
    sort = _clean_string(value)
    return sort if sort in {"ranked", "newest", "oldest"} else "ranked"


def _serialize_posts(rows: list[sqlite3.Row]) -> list[dict[str, Any]]:
    return [
        {
            "position": position,
            "url": row["url"],
            "wpId": row["wp_id"] or "",
            "title": row["title"],
            "dateIso": row["date_iso"],
        }
        for position, row in enumerate(rows, start=1)
    ]


def _search_case(case: dict[str, Any]) -> dict[str, Any]:
    terms = _string_list(case.get("terms"), "terms", MAX_SELECTED_TERMS)
    sort = _normalized_sort(case.get("sort"))
    scope = case.get("scope") or {"type": "global"}
    if not isinstance(scope, dict):
        raise ParityRequestError("scope must be an object")
    scope_type = _clean_string(scope.get("type")) or "global"
    scope_slug = _clean_string(scope.get("slug"))

    if scope_type == "global":
        rows, clean_terms = app.search_posts(terms, sort)
        display_terms = clean_terms
    elif scope_type == "category":
        if not scope_slug:
            raise ParityRequestError("category scope requires a slug")
        with app.get_conn() as conn:
            category = conn.execute("SELECT * FROM categories WHERE slug = ?", (scope_slug,)).fetchone()
            if not category:
                raise ParityRequestError(f"unknown category slug: {scope_slug}")
            rows, clean_terms = app.search_category_posts(conn, category, terms, sort)
        display_terms = clean_terms
    elif scope_type == "topic":
        if not scope_slug:
            raise ParityRequestError("topic scope requires a slug")
        with app.get_conn() as conn:
            topic = conn.execute("SELECT * FROM topics WHERE slug = ?", (scope_slug,)).fetchone()
            if not topic:
                raise ParityRequestError(f"unknown topic slug: {scope_slug}")
            rows, clean_terms, display_terms = app.search_topic_posts(conn, topic, terms, sort)
    else:
        raise ParityRequestError(f"unknown search scope: {scope_type}")

    return {
        "operation": "search",
        "terms": clean_terms,
        "displayTerms": display_terms,
        "sort": sort,
        "scope": {"type": scope_type, "slug": scope_slug},
        "resultCount": len(rows),
        "posts": _serialize_posts(rows),
    }


def _suggest_case(case: dict[str, Any]) -> dict[str, Any]:
    selected = _string_list(case.get("selected"), "selected", MAX_SELECTED_TERMS)
    query_text = _clean_string(case.get("query"))
    category_slug = _clean_string(case.get("categorySlug"))
    topic_slug = _clean_string(case.get("topicSlug"))
    query: dict[str, list[str]] = {"q": [query_text]}
    if selected:
        query["selected"] = selected
    if category_slug:
        query["category"] = [category_slug]
    if topic_slug:
        query["topic"] = [topic_slug]
    suggestions = json.loads(app.api_keywords(query).decode("utf-8"))
    return {
        "operation": "suggest",
        "query": query_text,
        "normalizedQuery": normalize_keyword(query_text),
        "selected": selected,
        "categorySlug": category_slug,
        "topicSlug": topic_slug,
        "suggestionCount": len(suggestions),
        "suggestions": suggestions,
    }


def _subject_area_records(
    conn: sqlite3.Connection,
    area_table: str,
    link_table: str,
) -> list[dict[str, Any]]:
    areas = conn.execute(f"SELECT * FROM {area_table} ORDER BY id").fetchall()
    records: list[dict[str, Any]] = []
    for area in areas:
        categories = conn.execute(
            f"""
            SELECT
                c.name,
                c.slug,
                c.description,
                COUNT(DISTINCT tc.topic_id) AS topic_count,
                COUNT(DISTINCT pt.post_id) AS post_count
            FROM {link_table} sac
            JOIN categories c ON c.id = sac.category_id
            LEFT JOIN topic_categories tc ON tc.category_id = c.id
            LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id
            WHERE sac.subject_area_id = ?
            GROUP BY c.id
            ORDER BY sac.position, c.name COLLATE NOCASE
            """,
            (area["id"],),
        ).fetchall()
        records.append(
            {
                "name": area["name"],
                "slug": area["slug"],
                "description": area["description"],
                "categoryCount": len(categories),
                "topicCount": len(
                    {
                        int(row[0])
                        for row in conn.execute(
                            f"""
                            SELECT DISTINCT tc.topic_id
                            FROM {link_table} sac
                            JOIN topic_categories tc ON tc.category_id = sac.category_id
                            WHERE sac.subject_area_id = ?
                            """,
                            (area["id"],),
                        ).fetchall()
                    }
                ),
                "postCount": len(
                    {
                        int(row[0])
                        for row in conn.execute(
                            f"""
                            SELECT DISTINCT pt.post_id
                            FROM {link_table} sac
                            JOIN topic_categories tc ON tc.category_id = sac.category_id
                            JOIN post_topics pt ON pt.topic_id = tc.topic_id
                            WHERE sac.subject_area_id = ?
                            """,
                            (area["id"],),
                        ).fetchall()
                    }
                ),
                "categories": [
                    {
                        "name": category["name"],
                        "slug": category["slug"],
                        "description": category["description"],
                        "topicCount": int(category["topic_count"]),
                        "postCount": int(category["post_count"]),
                    }
                    for category in categories
                ],
            }
        )
    return records


def _category_records(conn: sqlite3.Connection) -> list[dict[str, Any]]:
    categories = conn.execute("SELECT * FROM categories ORDER BY name COLLATE NOCASE").fetchall()
    records: list[dict[str, Any]] = []
    for category in categories:
        topics = conn.execute(
            """
            SELECT
                t.name,
                t.slug,
                t.description,
                t.display_in_browser,
                COUNT(DISTINCT pt.post_id) AS post_count
            FROM topics t
            JOIN topic_categories tc ON tc.topic_id = t.id
            LEFT JOIN post_topics pt ON pt.topic_id = t.id
            WHERE tc.category_id = ? AND t.display_in_browser = 1
            GROUP BY t.id
            ORDER BY CASE WHEN tc.position > 0 THEN 0 ELSE 1 END,
                     tc.position,
                     t.name COLLATE NOCASE
            """,
            (category["id"],),
        ).fetchall()
        post_count = int(
            conn.execute(
                """
                SELECT COUNT(DISTINCT pt.post_id)
                FROM post_topics pt
                JOIN topic_categories tc ON tc.topic_id = pt.topic_id
                WHERE tc.category_id = ?
                """,
                (category["id"],),
            ).fetchone()[0]
        )
        records.append(
            {
                "name": category["name"],
                "slug": category["slug"],
                "description": category["description"],
                "topicCount": len(topics),
                "postCount": post_count,
                "topics": [
                    {
                        "name": topic["name"],
                        "slug": topic["slug"],
                        "description": topic["description"],
                        "postCount": int(topic["post_count"]),
                    }
                    for topic in topics
                ],
            }
        )
    return records


def _topic_records(conn: sqlite3.Connection) -> list[dict[str, Any]]:
    rows = conn.execute(
        """
        SELECT
            t.name,
            t.slug,
            t.description,
            t.display_in_browser,
            COUNT(DISTINCT pt.post_id) AS post_count
        FROM topics t
        LEFT JOIN post_topics pt ON pt.topic_id = t.id
        GROUP BY t.id
        ORDER BY t.name COLLATE NOCASE
        """
    ).fetchall()
    records: list[dict[str, Any]] = []
    for topic in rows:
        categories = conn.execute(
            """
            SELECT c.name, c.slug
            FROM categories c
            JOIN topic_categories tc ON tc.category_id = c.id
            JOIN topics t ON t.id = tc.topic_id
            WHERE t.slug = ?
            ORDER BY c.name COLLATE NOCASE
            """,
            (topic["slug"],),
        ).fetchall()
        records.append(
            {
                "name": topic["name"],
                "slug": topic["slug"],
                "description": topic["description"],
                "displayInBrowser": bool(topic["display_in_browser"]),
                "postCount": int(topic["post_count"]),
                "categories": [
                    {"name": category["name"], "slug": category["slug"]}
                    for category in categories
                ],
            }
        )
    return records


def _browse_case() -> dict[str, Any]:
    with app.get_conn() as conn:
        return {
            "operation": "browse",
            "subjectAreas1": _subject_area_records(conn, "subject_areas", "subject_area_categories"),
            "subjectAreas2": _subject_area_records(conn, "subject_areas_2", "subject_area_2_categories"),
            "categories": _category_records(conn),
            "topics": _topic_records(conn),
        }


def execute_case(case: dict[str, Any]) -> dict[str, Any]:
    case_id = _clean_string(case.get("id"))
    if not case_id:
        raise ParityRequestError("each case requires a non-empty id")
    operation = _clean_string(case.get("operation"))
    if operation == "search":
        result = _search_case(case)
    elif operation == "suggest":
        result = _suggest_case(case)
    elif operation == "browse":
        result = _browse_case()
    else:
        raise ParityRequestError(f"unknown operation: {operation}")
    return {"id": case_id, "ok": True, **result}


def run_batch(cases: Any) -> dict[str, Any]:
    if not isinstance(cases, list):
        raise ParityRequestError("cases must be a list")
    if not cases:
        raise ParityRequestError("cases must not be empty")
    if len(cases) > MAX_BATCH_CASES:
        raise ParityRequestError(f"a batch supports at most {MAX_BATCH_CASES} cases")

    results: list[dict[str, Any]] = []
    for case in cases:
        if not isinstance(case, dict):
            results.append({"id": "", "ok": False, "error": "case must be an object"})
            continue
        try:
            results.append(execute_case(case))
        except (ParityRequestError, sqlite3.Error) as exc:
            results.append(
                {
                    "id": _clean_string(case.get("id")),
                    "ok": False,
                    "error": str(exc),
                }
            )
    return {**manifest(), "results": results}
