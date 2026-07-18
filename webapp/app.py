from __future__ import annotations

import html
import json
import mimetypes
import os
import sqlite3
from pathlib import Path
from urllib.parse import parse_qs, unquote

from .import_data import (
    DEFAULT_CATEGORIES_PATH,
    DEFAULT_CATEGORY_GROUPS_PATH,
    DEFAULT_DB_PATH,
    DEFAULT_SEARCH_INDEX_PATH,
    DEFAULT_TOPICS_PATH,
    build_database,
    normalize_keyword,
)


ROOT = Path(__file__).resolve().parents[1]
STATIC_DIR = Path(__file__).resolve().parent / "static"
DB_PATH = Path(os.environ.get("EHRMAN_DB_PATH", DEFAULT_DB_PATH))


def ensure_database() -> None:
    if not DB_PATH.exists():
        build_database(DB_PATH)
        return
    db_mtime = DB_PATH.stat().st_mtime
    sources = [
        DEFAULT_SEARCH_INDEX_PATH,
        DEFAULT_CATEGORIES_PATH,
        DEFAULT_CATEGORY_GROUPS_PATH,
        DEFAULT_TOPICS_PATH,
    ]
    if any(path.exists() and path.stat().st_mtime > db_mtime for path in sources):
        build_database(DB_PATH)


def get_conn() -> sqlite3.Connection:
    ensure_database()
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def esc(value: object) -> str:
    return html.escape("" if value is None else str(value), quote=True)


def pluralize(count: int, singular: str, plural: str | None = None) -> str:
    word = singular if count == 1 else (plural or singular + "s")
    return f"{count:,} {word}"


def format_date_range(conn: sqlite3.Connection) -> str:
    row = conn.execute(
        """
        SELECT
            (SELECT date_text FROM posts WHERE date_iso <> '' ORDER BY date_iso ASC LIMIT 1) AS first_date,
            (SELECT date_text FROM posts WHERE date_iso <> '' ORDER BY date_iso DESC LIMIT 1) AS last_date
        """
    ).fetchone()
    return f"Posts from {row['first_date']} - {row['last_date']}"


def query_one(sql: str, params: tuple[object, ...] = ()) -> sqlite3.Row | None:
    with get_conn() as conn:
        return conn.execute(sql, params).fetchone()


def query_all(sql: str, params: tuple[object, ...] = ()) -> list[sqlite3.Row]:
    with get_conn() as conn:
        return conn.execute(sql, params).fetchall()


def route(path: str) -> str:
    return path


def header(active: str = "") -> str:
    links = [
        ("Join!", "#", "disabled"),
        ("Recent Posts", "#", "disabled"),
        ("Categories1", "/categories", "categories"),
        ("Categories2", "/category-groups", "category-groups"),
        ("Keyword Search", "/keyword-search", "keyword-search"),
        ("Forum", "#", "disabled"),
        ("About Blog", "#", "disabled"),
        ("About Bart", "#", "disabled"),
        ("Help", "#", "disabled"),
    ]
    items: list[str] = []
    for label, href, key in links:
        classes = ["site-menu-link"]
        if key == "disabled":
            classes.append("disabled-link")
            items.append(f'<span class="{" ".join(classes)}">{esc(label)}</span>')
        else:
            if active == key:
                classes.append("active")
            items.append(f'<a class="{" ".join(classes)}" href="{route(href)}">{esc(label)}</a>')

    return f"""
    <header class="site-header">
      <div class="site-utility">
        <div class="site-utility-inner">
          <div class="site-tagline">Engaging Discussions about Early Christianity</div>
          <div class="site-utility-actions" aria-label="Site utility links">
            <form class="site-search" action="#" aria-label="Site search">
              <input type="search" placeholder="Search..." aria-label="Search" disabled>
              <button type="button" disabled>All</button>
            </form>
            <span class="site-utility-link site-join-now">Join Now!</span>
            <span class="site-utility-link site-login">Login</span>
            <span class="site-utility-link">Account</span>
          </div>
        </div>
      </div>
      <div class="site-top">
        <a class="site-brand" href="/">
          <span class="site-logo-mark" aria-hidden="true"></span>
          <span class="site-logo-copy">
            <span class="site-logo-title">The Bart Ehrman Blog:</span>
            <span class="site-logo-subtitle">The History &amp; Literature of Early Christianity</span>
          </span>
        </a>
        <nav class="site-menu" aria-label="Site navigation">
          {"".join(items)}
        </nav>
      </div>
    </header>
    """


def render_page(title: str, body: str, active: str = "") -> bytes:
    full_title = f"{title} | Bart Blog Demo" if title else "Bart Blog Demo"
    html_doc = f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{esc(full_title)}</title>
  <link rel="stylesheet" href="/static/styles.css">
</head>
<body>
  {header(active)}
  <main class="page-shell">
    {body}
  </main>
  <script src="/static/site.js"></script>
</body>
</html>"""
    return html_doc.encode("utf-8")


def content_page(
    title: str,
    count_line: str,
    description: str = "",
    inner: str = "",
    actions: str = "",
    description_first: bool = False,
) -> str:
    description_html = f'<p class="content-description">{esc(description)}</p>' if description else ""
    actions_html = f'<div class="content-actions">{actions}</div>' if actions else ""
    count_html = f'<p class="count-line">{esc(count_line)}</p>'
    header_meta = description_html + count_html if description_first else count_html + description_html
    return f"""
    <section class="content-page">
      <div class="content-header">
        <h1>{esc(title)}</h1>
        {header_meta}
        {actions_html}
      </div>
      {inner}
    </section>
    """


def home_page() -> bytes:
    with get_conn() as conn:
        stats = conn.execute(
            """
            SELECT
                (SELECT COUNT(*) FROM posts) AS posts,
                (SELECT COUNT(*) FROM topics WHERE display_in_browser = 1) AS topics,
                (
                    SELECT COUNT(*)
                    FROM (
                        SELECT name AS label FROM topics WHERE display_in_browser = 1
                        UNION
                        SELECT label FROM keywords
                    )
                ) AS keywords,
                (SELECT COUNT(*) FROM category_groups) AS category_groups,
                (SELECT COUNT(*) FROM categories) AS categories
            """
        ).fetchone()
        date_range = format_date_range(conn)

    body = f"""
    <section class="site-home">
      <section class="site-hero" aria-label="Bart Ehrman lecturing"></section>
      <section class="site-demo-note" aria-label="Demo description">
        <p>This demo introduces new ways to find topics of interest on Bart's blog: <strong>Categories1</strong>, <strong>Categories2</strong>, and <strong>Keyword Search</strong>.</p>
        <p><strong>Categories1</strong> and <strong>Categories2</strong> are two options for category searching. One of these approaches will be selected for future use. The drawback of <strong>Categories1</strong> is that it lists all {pluralize(stats['categories'], 'category', 'categories')} on one page, which may feel overwhelming to navigate. The drawback of <strong>Categories2</strong> is that readers must move through an additional layer before reaching the topic they want.</p>
        <p><strong>Categories1</strong> lets readers browse posts through the current category-and-topic structure. The blog's posts have been organized into {pluralize(stats['categories'], 'broad category', 'broad categories')}, each containing more focused topics.</p>
        <p><strong>Categories2</strong> provides a broader starting point by organizing those categories into {pluralize(stats['category_groups'], 'larger group', 'larger groups')} before showing the topics connected to each category.</p>
        <p><strong>Keyword Search</strong> lets readers find posts by entering up to four keywords.</p>
        <p class="site-demo-date-range">{esc(date_range)} ({pluralize(stats['posts'], 'post')})</p>
        <p class="site-demo-version">Version 2.0 Blog Search Demo | {pluralize(stats['category_groups'], 'category group')} | {pluralize(stats['categories'], 'category', 'categories')} | {pluralize(stats['topics'], 'topic')} | {pluralize(stats['keywords'], 'keyword')}</p>
      </section>
    </section>
    """
    return render_page("Home", body)


def categories_page() -> bytes:
    rows = query_all(
        """
        SELECT
            c.name,
            c.slug,
            c.description,
            COUNT(DISTINCT tc.topic_id) AS topic_count,
            COUNT(DISTINCT pt.post_id) AS post_count
        FROM categories c
        LEFT JOIN topic_categories tc ON tc.category_id = c.id
        LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id
        GROUP BY c.id
        ORDER BY c.name COLLATE NOCASE
        """
    )
    items = []
    for row in rows:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="/categories/{esc(row['slug'])}">{esc(row['name'])}</a>
              <p class="item-description">{esc(row['description'])}</p>
              <p class="item-meta">{pluralize(row['topic_count'], 'topic')} | {pluralize(row['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page("Categories", pluralize(len(rows), "category", "categories"), inner=inner)
    return render_page("Categories", body, active="categories")


def category_groups_page() -> bytes:
    rows = query_all(
        """
        SELECT
            cg.name,
            cg.slug,
            cg.description,
            COUNT(DISTINCT cgc.category_id) AS category_count,
            COUNT(DISTINCT tc.topic_id) AS topic_count,
            COUNT(DISTINCT pt.post_id) AS post_count
        FROM category_groups cg
        LEFT JOIN category_group_categories cgc ON cgc.category_group_id = cg.id
        LEFT JOIN topic_categories tc ON tc.category_id = cgc.category_id
        LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id
        GROUP BY cg.id
        ORDER BY cg.id
        """
    )
    items = []
    for row in rows:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="/category-groups/{esc(row['slug'])}">{esc(row['name'])}</a>
              <p class="item-description">{esc(row['description'])}</p>
              <p class="item-meta">{pluralize(row['category_count'], 'category', 'categories')} | {pluralize(row['topic_count'], 'topic')} | {pluralize(row['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page("Category Groups", pluralize(len(rows), "category group"), inner=inner)
    return render_page("Category Groups", body, active="category-groups")


def category_group_page(slug: str) -> bytes:
    with get_conn() as conn:
        category_group = conn.execute("SELECT * FROM category_groups WHERE slug = ?", (slug,)).fetchone()
        if not category_group:
            return not_found()
        categories = conn.execute(
            """
            SELECT
                c.name,
                c.slug,
                c.description,
                COUNT(DISTINCT tc.topic_id) AS topic_count,
                COUNT(DISTINCT pt.post_id) AS post_count
            FROM category_group_categories cgc
            JOIN categories c ON c.id = cgc.category_id
            LEFT JOIN topic_categories tc ON tc.category_id = c.id
            LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id
            WHERE cgc.category_group_id = ?
            GROUP BY c.id
            ORDER BY cgc.position, c.name COLLATE NOCASE
            """,
            (category_group["id"],),
        ).fetchall()
        counts = conn.execute(
            """
            SELECT
                COUNT(DISTINCT cgc.category_id) AS category_count,
                COUNT(DISTINCT tc.topic_id) AS topic_count,
                COUNT(DISTINCT pt.post_id) AS post_count
            FROM category_group_categories cgc
            LEFT JOIN topic_categories tc ON tc.category_id = cgc.category_id
            LEFT JOIN post_topics pt ON pt.topic_id = tc.topic_id
            WHERE cgc.category_group_id = ?
            """,
            (category_group["id"],),
        ).fetchone()

    items = []
    for category in categories:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="/categories/{esc(category['slug'])}">{esc(category['name'])}</a>
              <p class="item-description">{esc(category['description'])}</p>
              <p class="item-meta">{pluralize(category['topic_count'], 'topic')} | {pluralize(category['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page(
        category_group["name"],
        f"{pluralize(counts['category_count'], 'category', 'categories')} | {pluralize(counts['topic_count'], 'topic')} | {pluralize(counts['post_count'], 'post')}",
        category_group["description"],
        inner,
        description_first=True,
    )
    return render_page(category_group["name"], body, active="category-groups")


def category_page(slug: str) -> bytes:
    with get_conn() as conn:
        category = conn.execute("SELECT * FROM categories WHERE slug = ?", (slug,)).fetchone()
        if not category:
            return not_found()
        topics = conn.execute(
            """
            SELECT
                t.name,
                t.slug,
                t.description,
                COUNT(DISTINCT pt.post_id) AS post_count
            FROM topics t
            JOIN topic_categories tc ON tc.topic_id = t.id
            LEFT JOIN post_topics pt ON pt.topic_id = t.id
            WHERE tc.category_id = ? AND t.display_in_browser = 1
            GROUP BY t.id
            ORDER BY t.name COLLATE NOCASE
            """,
            (category["id"],),
        ).fetchall()
        post_count = conn.execute(
            """
            SELECT COUNT(DISTINCT pt.post_id)
            FROM post_topics pt
            JOIN topic_categories tc ON tc.topic_id = pt.topic_id
            WHERE tc.category_id = ?
            """,
            (category["id"],),
        ).fetchone()[0]

    items = []
    for topic in topics:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="/topics/{esc(topic['slug'])}">{esc(topic['name'])}</a>
              <p class="item-description">{esc(topic['description'])}</p>
              <p class="item-meta">{pluralize(topic['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page(
        category["name"],
        f"{pluralize(len(topics), 'topic')} | {pluralize(post_count, 'post')}",
        category["description"],
        inner,
        description_first=True,
    )
    return render_page(category["name"], body, active="categories")


def keyword_panel(prefill: list[str] | None = None, sort: str = "ranked") -> str:
    values = (prefill or [])[:4]
    while len(values) < 4:
        values.append("")
    options = {
        "ranked": "Ranked",
        "newest": "Newest first",
        "oldest": "Oldest first",
        "title": "Title A-Z",
    }
    sort_options = "".join(
        f'<option value="{esc(value)}"{" selected" if value == sort else ""}>{esc(label)}</option>'
        for value, label in options.items()
    )
    inputs = "".join(
        f"""
        <div class="keyword-input-wrap">
          <input class="keyword-input" name="keyword" value="{esc(value)}" placeholder="Keyword {idx}" autocomplete="off" {"autofocus" if idx == 1 and not value else ""}>
          <ul class="keyword-suggestion-list" hidden></ul>
        </div>
        """
        for idx, value in enumerate(values, start=1)
    )
    return f"""
    <form class="keyword-search-panel" action="/keyword-results" method="get" data-keyword-form>
      <label>Enter up to four keywords. Keywords can be single words or phrases.</label>
      <div class="keyword-grid">{inputs}</div>
      <div class="sort-row">
        <label for="sort">Sort by</label>
        <select id="sort" name="sort">{sort_options}</select>
      </div>
      <button type="submit">Search</button>
      <p class="hover-help">Hover over a post title to see a short description or check box to display all descriptions.
        <label class="description-check"><input type="checkbox" data-description-toggle> Display all descriptions</label>
      </p>
    </form>
    """


def post_list(posts: list[sqlite3.Row], context_topic: str = "") -> str:
    if not posts:
        return '<p class="empty">No posts matched this request.</p>'
    items = []
    for post in posts:
        topic_text = context_topic or post["context_topic"] if "context_topic" in post.keys() else context_topic
        meta_parts = [
            f"By {esc(post['author'])}" if post["author"] else "By unknown author",
            esc(post["date_text"]),
        ]
        if topic_text:
            meta_parts.append(esc(topic_text))
        description = esc(post["description"])
        items.append(
            f"""
            <li class="post-item">
              <a class="post-title" href="{esc(post['url'])}" target="_blank" rel="noopener" data-description="{description}">{esc(post['title'])}</a>
              <p class="post-meta">{" | ".join(meta_parts)}</p>
              <p class="post-description" hidden>{description}</p>
            </li>
            """
        )
    return f'<ul class="post-list">{"".join(items)}</ul>'


def posts_for_topic(slug: str, query: dict[str, list[str]]) -> bytes:
    sort = query.get("sort", ["newest"])[0]
    with get_conn() as conn:
        topic = conn.execute("SELECT * FROM topics WHERE slug = ?", (slug,)).fetchone()
        if not topic:
            return not_found()
        posts = conn.execute(
            """
            SELECT p.*
            FROM posts p
            JOIN post_topics pt ON pt.post_id = p.id
            WHERE pt.topic_id = ?
            ORDER BY p.date_iso DESC, p.id DESC
            """,
            (topic["id"],),
        ).fetchall()
    panel = keyword_panel([topic["name"]], sort)
    inner = panel + post_list(posts, topic["name"])
    body = content_page(topic["name"], pluralize(len(posts), "post"), topic["description"], inner)
    return render_page(topic["name"], body, active="categories")


def posts_for_category(slug: str) -> bytes:
    with get_conn() as conn:
        category = conn.execute("SELECT * FROM categories WHERE slug = ?", (slug,)).fetchone()
        if not category:
            return not_found()
        posts = conn.execute(
            """
            SELECT DISTINCT p.*
            FROM posts p
            JOIN post_topics pt ON pt.post_id = p.id
            JOIN topic_categories tc ON tc.topic_id = pt.topic_id
            WHERE tc.category_id = ?
            ORDER BY p.date_iso DESC, p.id DESC
            """,
            (category["id"],),
        ).fetchall()
    inner = keyword_panel([], "newest") + post_list(posts, category["name"])
    body = content_page(f"{category['name']} Posts", pluralize(len(posts), "post"), category["description"], inner)
    return render_page(f"{category['name']} Posts", body, active="categories")


def find_post_ids_for_term(conn: sqlite3.Connection, term: str) -> dict[int, int]:
    normalized = normalize_keyword(term)
    if not normalized:
        return {}
    rows = conn.execute(
        """
        SELECT post_id, MAX(weight + CASE WHEN normalized = ? THEN 2 ELSE 0 END) AS score
        FROM post_search_terms
        WHERE normalized = ? OR normalized LIKE ?
        GROUP BY post_id
        """,
        (normalized, normalized, f"%{normalized}%"),
    ).fetchall()
    return {int(row["post_id"]): int(row["score"]) for row in rows}


def title_match_boost(title: str, term: str) -> int:
    normalized_title = normalize_keyword(title)
    normalized_term = normalize_keyword(term)
    if not normalized_title or not normalized_term:
        return 0
    padded_title = f" {normalized_title} "
    padded_term = f" {normalized_term} "
    if padded_term in padded_title:
        return 2
    if " " not in normalized_term and any(normalized_term in word for word in normalized_title.split()):
        return 1
    return 0


def search_posts(terms: list[str], sort: str) -> tuple[list[sqlite3.Row], list[str]]:
    clean_terms = [term for term in (clean.strip() for clean in terms) if term]
    if not clean_terms:
        return [], []
    with get_conn() as conn:
        matches: dict[int, int] | None = None
        for term in clean_terms:
            term_matches = find_post_ids_for_term(conn, term)
            if matches is None:
                matches = term_matches
            else:
                matches = {
                    post_id: score + term_matches[post_id]
                    for post_id, score in matches.items()
                    if post_id in term_matches
                }
        if not matches:
            return [], clean_terms
        assert matches is not None
        post_ids = list(matches.keys())
        placeholders = ",".join("?" for _ in post_ids)
        rows = conn.execute(
            f"SELECT p.* FROM posts p WHERE p.id IN ({placeholders})",
            tuple(post_ids),
        ).fetchall()
        for row in rows:
            post_id = int(row["id"])
            matches[post_id] += sum(title_match_boost(row["title"], term) for term in clean_terms)

    def sort_key(row: sqlite3.Row) -> tuple[object, ...]:
        if sort == "newest":
            return (row["date_iso"], row["id"])
        if sort == "oldest":
            return (row["date_iso"], row["id"])
        if sort == "title":
            return (row["title"].casefold(),)
        return (matches[int(row["id"])], row["date_iso"], row["id"])

    reverse = sort in {"ranked", "newest"}
    return sorted(rows, key=sort_key, reverse=reverse), clean_terms


def keyword_search_page() -> bytes:
    body = content_page(
        "Keyword Search",
        "Search posts by keyword",
        inner=keyword_panel(),
    )
    return render_page("Keyword Search", body, active="keyword-search")


def keyword_results_page(query: dict[str, list[str]]) -> bytes:
    terms = query.get("keyword", [])
    sort = query.get("sort", ["ranked"])[0]
    posts, clean_terms = search_posts(terms, sort)
    title = "Keywords: " + " + ".join(clean_terms) if clean_terms else "Keyword Search"
    panel = keyword_panel(clean_terms, sort)
    inner = panel + post_list(posts, "Keyword Search")
    body = content_page(title, pluralize(len(posts), "post"), inner=inner)
    return render_page(title, body, active="keyword-search")


def api_keywords(query: dict[str, list[str]]) -> bytes:
    q = normalize_keyword(query.get("q", [""])[0])
    selected = [value for value in query.get("selected", []) if value.strip()]
    selected_normalized = sorted({normalize_keyword(value) for value in selected if normalize_keyword(value)})
    limit = 18
    with get_conn() as conn:
        selected_ids: set[int] | None = None
        for value in selected:
            matches = set(find_post_ids_for_term(conn, value).keys())
            selected_ids = matches if selected_ids is None else selected_ids & matches
        prefix_like = f"{q}%"
        contains_like = f"%{q}%"
        params: list[object] = [contains_like]
        where = "normalized LIKE ? AND normalized <> 'ignore'"
        if selected_ids is not None:
            if not selected_ids:
                return json.dumps([], ensure_ascii=False).encode("utf-8")
            placeholders = ",".join("?" for _ in selected_ids)
            where += f" AND post_id IN ({placeholders})"
            params.extend(sorted(selected_ids))
        if selected_normalized:
            placeholders = ",".join("?" for _ in selected_normalized)
            where += f" AND normalized NOT IN ({placeholders})"
            params.extend(selected_normalized)
        rows = conn.execute(
            f"""
            SELECT
                COALESCE(
                    MIN(CASE WHEN kind = 'topic' THEN label END),
                    MIN(label)
                ) AS label,
                normalized,
                COUNT(DISTINCT post_id) AS post_count,
                MAX(CASE WHEN kind = 'topic' THEN 1 ELSE 0 END) AS is_topic,
                CASE
                    WHEN normalized = ? THEN 3
                    WHEN normalized LIKE ? THEN 2
                    ELSE 1
                END AS match_quality
            FROM post_search_terms
            WHERE {where}
            GROUP BY normalized
            ORDER BY is_topic DESC, match_quality DESC, post_count DESC, label COLLATE NOCASE
            LIMIT {limit}
            """,
            (q, prefix_like, *params),
        ).fetchall()
    return json.dumps(
        [
            {
                "label": row["label"],
                "normalized": row["normalized"],
                "postCount": row["post_count"],
                "isTopic": bool(row["is_topic"]),
            }
            for row in rows
        ],
        ensure_ascii=False,
    ).encode("utf-8")


def health_page() -> bytes:
    with get_conn() as conn:
        counts = conn.execute(
            """
            SELECT
                (SELECT COUNT(*) FROM posts) AS posts,
                (SELECT COUNT(*) FROM category_groups) AS category_groups,
                (SELECT COUNT(*) FROM categories) AS categories,
                (SELECT COUNT(*) FROM topics) AS topics,
                (SELECT COUNT(*) FROM keywords) AS keywords
            """
        ).fetchone()
    payload = {
        "status": "ok",
        "commit": os.environ.get("RENDER_GIT_COMMIT", ""),
        "databaseExists": DB_PATH.exists(),
        "staticFiles": sorted(path.name for path in STATIC_DIR.iterdir() if path.is_file()),
        "counts": {
            "posts": counts["posts"],
            "categoryGroups": counts["category_groups"],
            "categories": counts["categories"],
            "topics": counts["topics"],
            "keywords": counts["keywords"],
        },
    }
    return json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")


def not_found() -> bytes:
    body = content_page("Page Not Found", "The requested page could not be found.")
    return render_page("Page Not Found", body)


def serve_static(path: str) -> tuple[bytes, str, str]:
    relative = path.removeprefix("/static/").replace("\\", "/")
    file_path = (STATIC_DIR / relative).resolve()
    if STATIC_DIR.resolve() not in file_path.parents and file_path != STATIC_DIR.resolve():
        return b"Not found", "404 Not Found", "text/plain"
    if not file_path.exists() or not file_path.is_file():
        return b"Not found", "404 Not Found", "text/plain"
    content_type = mimetypes.guess_type(file_path.name)[0] or "application/octet-stream"
    return file_path.read_bytes(), "200 OK", content_type


def dispatch(path: str, query: dict[str, list[str]]) -> tuple[bytes, str, str]:
    path = unquote(path)
    if path.startswith("/static/"):
        return serve_static(path)
    if path in ("", "/"):
        return home_page(), "200 OK", "text/html; charset=utf-8"
    if path == "/categories":
        return categories_page(), "200 OK", "text/html; charset=utf-8"
    if path == "/category-groups":
        return category_groups_page(), "200 OK", "text/html; charset=utf-8"
    if path == "/keyword-search":
        return keyword_search_page(), "200 OK", "text/html; charset=utf-8"
    if path == "/keyword-results":
        return keyword_results_page(query), "200 OK", "text/html; charset=utf-8"
    if path == "/api/keywords":
        return api_keywords(query), "200 OK", "application/json; charset=utf-8"
    if path == "/healthz":
        return health_page(), "200 OK", "application/json; charset=utf-8"
    if path.startswith("/categories/") and path.endswith("/posts"):
        slug = path.removeprefix("/categories/").removesuffix("/posts").strip("/")
        return posts_for_category(slug), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/category-groups/"):
        slug = path.removeprefix("/category-groups/").strip("/")
        return category_group_page(slug), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/categories/"):
        slug = path.removeprefix("/categories/").strip("/")
        return category_page(slug), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/topics/"):
        slug = path.removeprefix("/topics/").strip("/")
        return posts_for_topic(slug, query), "200 OK", "text/html; charset=utf-8"
    return not_found(), "404 Not Found", "text/html; charset=utf-8"


def application(environ: dict[str, object], start_response) -> list[bytes]:
    path = str(environ.get("PATH_INFO", "/"))
    query = parse_qs(str(environ.get("QUERY_STRING", "")), keep_blank_values=False)
    try:
        body, status, content_type = dispatch(path, query)
    except Exception as exc:  # pragma: no cover - last-resort web error response
        status = "500 Internal Server Error"
        content_type = "text/plain; charset=utf-8"
        body = f"Internal server error: {exc}".encode("utf-8")
    start_response(status, [("Content-Type", content_type), ("Content-Length", str(len(body)))])
    return [body]


def main() -> int:
    from wsgiref.simple_server import make_server

    port = int(os.environ.get("PORT", "8000"))
    ensure_database()
    with make_server("127.0.0.1", port, application) as server:
        print(f"Serving Bart Blog Demo on http://127.0.0.1:{port}")
        server.serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
