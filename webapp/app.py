from __future__ import annotations

import html
import json
import mimetypes
import os
import sqlite3
from pathlib import Path
from urllib.parse import parse_qs, urlencode, unquote

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

STARTER_TOPIC_LABELS = (
    "Gospel of Luke",
    "Gospel of Mark",
    "Gospel of Matthew",
    "Gospel of John",
    "Jesus' Teachings",
    "Historical Jesus (General)",
    "Pauline Letters",
    "Textual Variants",
    "Scribal Changes",
    "Original Text Questions",
    "Biblical Contradictions",
    "Canon",
    "Revelation",
    "Birth Narrative",
    "Resurrection of Jesus",
    "Heaven/Hell",
    "Literary Forgery (General)",
    "Early Christianity (General)",
)


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


def unique_terms(terms: list[str] | None) -> list[str]:
    values: list[str] = []
    seen: set[str] = set()
    for term in terms or []:
        value = term.strip()
        if not value:
            continue
        key = normalize_keyword(value)
        if not key or key in seen:
            continue
        seen.add(key)
        values.append(value)
    return values


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
        ("Keyword Search", "/keyword-search", "keyword-search"),
        ("Browse by Topic", "/browse-by-topic", "browse-by-topic"),
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
            items.append(f'<span class="{" ".join(classes)}" aria-disabled="true">{esc(label)}</span>')
        else:
            classes.append("primary-menu-link")
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


def description_toggle(checked: bool = False, scope: str = "browse") -> str:
    checked_attr = " checked" if checked else ""
    return f"""
        <label class="hover-help description-check">
          <input type="checkbox" data-description-toggle data-description-scope="{esc(scope)}"{checked_attr}>
          <span>Show descriptions</span>
        </label>
    """


def breadcrumb_nav(items: list[tuple[str, str | None]]) -> str:
    if not items:
        return ""
    crumbs = []
    for label, href in items:
        if href:
            content = f'<a href="{route(href)}">{esc(label)}</a>'
        else:
            content = f'<span aria-current="page">{esc(label)}</span>'
        crumbs.append(f"<li>{content}</li>")
    return f"""
        <nav class="breadcrumbs" aria-label="Breadcrumb">
          <ol>
            {"".join(crumbs)}
          </ol>
        </nav>
    """


def primary_group_for_category(conn: sqlite3.Connection, category_id: int, group_slug: str = "") -> sqlite3.Row | None:
    if group_slug:
        row = conn.execute(
            """
            SELECT cg.name, cg.slug
            FROM category_groups cg
            JOIN category_group_categories cgc ON cgc.category_group_id = cg.id
            WHERE cgc.category_id = ? AND cg.slug = ?
            ORDER BY cgc.position
            LIMIT 1
            """,
            (category_id, group_slug),
        ).fetchone()
        if row:
            return row
    return conn.execute(
        """
        SELECT cg.name, cg.slug
        FROM category_groups cg
        JOIN category_group_categories cgc ON cgc.category_group_id = cg.id
        WHERE cgc.category_id = ?
        ORDER BY cg.id, cgc.position
        LIMIT 1
        """,
        (category_id,),
    ).fetchone()


def category_context_query(source: str = "", group_slug: str = "") -> str:
    if group_slug:
        return "?" + urlencode({"group": group_slug})
    return ""


def category_href(category: sqlite3.Row, source: str = "", group_slug: str = "") -> str:
    return f"/categories/{category['slug']}{category_context_query(source, group_slug)}"


def category_posts_href(category: sqlite3.Row, source: str = "", group_slug: str = "") -> str:
    return f"/categories/{category['slug']}/posts{category_context_query(source, group_slug)}"


def topic_href(topic: sqlite3.Row, category: sqlite3.Row, source: str = "", group_slug: str = "") -> str:
    params = {"category": category["slug"]}
    if group_slug:
        params["group"] = group_slug
    return f"/topics/{topic['slug']}?{urlencode(params)}"


def category_breadcrumbs(
    conn: sqlite3.Connection,
    category: sqlite3.Row,
    current_label: str | None = None,
    source: str = "",
    group_slug: str = "",
) -> list[tuple[str, str | None]]:
    group = primary_group_for_category(conn, int(category["id"]), group_slug)
    if group:
        items: list[tuple[str, str | None]] = [
            ("Browse by Topic", "/browse-by-topic"),
            (group["name"], f"/browse-by-topic/{group['slug']}"),
        ]
    else:
        items = [("Browse by Topic", "/browse-by-topic")]
    category_label = current_label or category["name"]
    if current_label:
        items.append((category["name"], category_href(category, source, group["slug"] if group else "")))
        items.append((current_label, None))
    else:
        items.append((category_label, None))
    return items


def content_page(
    title: str,
    count_line: str,
    description: str = "",
    inner: str = "",
    actions: str = "",
    description_first: bool = False,
    toggle_descriptions: bool = False,
    descriptions_checked: bool = False,
    breadcrumbs: list[tuple[str, str | None]] | None = None,
) -> str:
    h1_html = f"<h1>{esc(title)}</h1>"
    description_html = f'<p class="content-description">{esc(description)}</p>' if description else ""
    actions_html = f'<div class="content-actions">{actions}</div>' if actions else ""
    count_html = f'<p class="count-line">{esc(count_line)}</p>'
    header_meta = description_html + count_html if description_first else count_html + description_html
    toggle_html = description_toggle(descriptions_checked, "browse") if toggle_descriptions else ""
    return f"""
    <section class="content-page">
      <div class="content-header">
        {breadcrumb_nav(breadcrumbs or [])}
        {h1_html}
        {header_meta}
        {actions_html}
        {toggle_html}
      </div>
      {inner}
    </section>
    """


def home_page() -> bytes:
    with get_conn() as conn:
        date_range = format_date_range(conn)

    body = f"""
    <section class="site-home">
      <section class="site-hero" aria-label="Bart Ehrman lecturing"></section>
      <section class="site-demo-note" aria-label="Demo description">
        <p>This demo introduces two ways to find topics of interest on Bart's blog: <strong>Keyword Search</strong> and <strong>Browse by Topic</strong>.</p>
        <p><strong>Keyword Search</strong> lets readers find posts by entering up to four keywords. It is designed for readers who already know what they are looking for.</p>
        <p><strong>Browse by Topic</strong> lets readers explore the blog through broad subject areas. Selecting a subject area shows related categories, selecting a category shows its topics, and selecting a topic shows the posts connected to it.</p>
        <figure class="search-methods-figure">
          <img class="search-methods-image" src="/static/ehrman-search-methods.png" alt="Diagram comparing Browse by Topic with Keyword Search">
        </figure>
        <p class="site-demo-date-range">{esc(date_range)}</p>
        <p class="site-demo-version">Version 2.0</p>
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
              <a class="item-title" href="/categories/{esc(row['slug'])}" data-description="{esc(row['description'])}">{esc(row['name'])}</a>
              <p class="item-description" hidden>{esc(row['description'])}</p>
              <p class="item-meta">{pluralize(row['topic_count'], 'topic')} &bull; {pluralize(row['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page("Categories", pluralize(len(rows), "category", "categories"), inner=inner, toggle_descriptions=True)
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
              <a class="item-title" href="/browse-by-topic/{esc(row['slug'])}" data-description="{esc(row['description'])}">{esc(row['name'])}</a>
              <p class="item-description" hidden>{esc(row['description'])}</p>
              <p class="item-meta">{pluralize(row['category_count'], 'category', 'categories')} &bull; {pluralize(row['topic_count'], 'topic')} &bull; {pluralize(row['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page("Browse by Topic", pluralize(len(rows), "subject area"), inner=inner, toggle_descriptions=True)
    return render_page("Browse by Topic", body, active="browse-by-topic")


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
        breadcrumbs = [
            ("Browse by Topic", "/browse-by-topic"),
            (category_group["name"], None),
        ]

    items = []
    for category in categories:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="/categories/{esc(category['slug'])}?group={esc(category_group['slug'])}" data-description="{esc(category['description'])}">{esc(category['name'])}</a>
              <p class="item-description" hidden>{esc(category['description'])}</p>
              <p class="item-meta">{pluralize(category['topic_count'], 'topic')} &bull; {pluralize(category['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page(
        category_group["name"],
        f"{pluralize(counts['category_count'], 'category', 'categories')} \u2022 {pluralize(counts['topic_count'], 'topic')} \u2022 {pluralize(counts['post_count'], 'post')}",
        "",
        inner,
        toggle_descriptions=True,
        breadcrumbs=breadcrumbs,
    )
    return render_page(category_group["name"], body, active="browse-by-topic")


def category_page(slug: str, query: dict[str, list[str]]) -> bytes:
    source = query.get("source", [""])[0]
    group_slug = query.get("group", [""])[0]
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
            ORDER BY CASE WHEN tc.position > 0 THEN 0 ELSE 1 END, tc.position, t.name COLLATE NOCASE
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
        breadcrumbs = category_breadcrumbs(conn, category, source=source, group_slug=group_slug)

    items = []
    for topic in topics:
        items.append(
            f"""
            <li class="list-item">
              <a class="item-title" href="{esc(topic_href(topic, category, source, group_slug))}" data-description="{esc(topic['description'])}">{esc(topic['name'])}</a>
              <p class="item-description" hidden>{esc(topic['description'])}</p>
              <p class="item-meta">{pluralize(topic['post_count'], 'post')}</p>
            </li>
            """
        )
    inner = f'<ul class="item-list">{"".join(items)}</ul>'
    body = content_page(
        category["name"],
        f"{pluralize(len(topics), 'topic')} \u2022 {pluralize(post_count, 'post')}",
        "",
        inner,
        toggle_descriptions=True,
        breadcrumbs=breadcrumbs,
    )
    return render_page(category["name"], body, active="browse-by-topic")


def keyword_panel(
    prefill: list[str] | None = None,
    sort: str = "ranked",
    descriptions_checked: bool = False,
    refresh_on_remove: bool = False,
    sort_current_page: bool = False,
) -> str:
    values = unique_terms(prefill)[:4]
    options = (
        ("ranked", "Best match"),
        ("newest", "Newest first"),
        ("oldest", "Oldest first"),
    )
    if sort not in {value for value, _ in options}:
        sort = "ranked"
    sort_options = "".join(
        f"""
        <label class="sort-choice">
          <input type="radio" name="sort" value="{esc(value)}"{" checked" if value == sort else ""}>
          <span>{esc(label)}</span>
        </label>
        """
        for value, label in options
    )
    chips = "".join(
        f"""
        <span class="keyword-slot keyword-chip">
          <input type="hidden" name="keyword" value="{esc(value)}">
          <span>{esc(value)}</span>
          <button type="button" class="keyword-chip-remove" data-remove-keyword aria-label="Remove {esc(value)}">x</button>
        </span>
        """
        for value in values
    )
    next_index = len(values) + 1
    entry = f"""
        <div class="keyword-slot keyword-input-wrap"{" hidden" if len(values) >= 4 else ""}>
          <input class="keyword-input" name="keyword" value="" placeholder="Keyword {min(next_index, 4)}" autocomplete="off" {"autofocus" if not values else ""}{" disabled" if len(values) >= 4 else ""}>
          <ul class="keyword-suggestion-list" hidden></ul>
        </div>
    """
    empty_slots = "".join(
        f'<span class="keyword-slot keyword-empty-slot">Keyword {slot_index}</span>'
        for slot_index in range(next_index + (0 if len(values) >= 4 else 1), 5)
    )
    refresh_attr = ' data-refresh-on-remove="true"' if refresh_on_remove else ""
    sort_attr = ' data-sort-current-page="true"' if sort_current_page else ""
    return f"""
    <form class="keyword-search-panel" action="/keyword-results" method="get" data-keyword-form{refresh_attr}{sort_attr}>
      <label>Enter up to four keywords. Keywords can be single words or phrases. Each additional keyword narrows the results.</label>
      <div class="keyword-grid">
        <div class="keyword-slot-grid" data-keyword-chip-list>
          {chips}
          {entry}
          {empty_slots}
        </div>
      </div>
      <div class="sort-row">
        <span class="sort-label">Sort by</span>
        {sort_options}
      </div>
      <div class="keyword-action-row">
        <button type="submit">Search</button>
        <button type="button" class="keyword-clear-button" data-clear-keywords>Clear all</button>
      </div>
    </form>
    <div class="search-description-toggle">
      {description_toggle(descriptions_checked, "posts")}
    </div>
    """


def results_summary(post_count: int, terms: list[str]) -> str:
    clean_terms = unique_terms(terms)
    if not clean_terms:
        return ""
    count_label = pluralize(post_count, "post")
    verb = "matches" if post_count == 1 else "match"
    query_label = " + ".join(clean_terms)
    return (
        f'<p class="results-summary" aria-live="polite">'
        f'<strong>{esc(count_label)}</strong> {verb} <strong>{esc(query_label)}</strong>.'
        "</p>"
    )


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


def topic_context_category(
    conn: sqlite3.Connection,
    topic_id: int,
    requested_category_slug: str = "",
) -> sqlite3.Row | None:
    if requested_category_slug:
        row = conn.execute(
            """
            SELECT c.*
            FROM categories c
            JOIN topic_categories tc ON tc.category_id = c.id
            WHERE tc.topic_id = ? AND c.slug = ?
            LIMIT 1
            """,
            (topic_id, requested_category_slug),
        ).fetchone()
        if row:
            return row
    return conn.execute(
        """
        SELECT c.*
        FROM categories c
        JOIN topic_categories tc ON tc.category_id = c.id
        WHERE tc.topic_id = ?
        ORDER BY c.name COLLATE NOCASE
        LIMIT 1
        """,
        (topic_id,),
    ).fetchone()


def posts_for_topic(slug: str, query: dict[str, list[str]]) -> bytes:
    sort = query.get("sort", ["ranked"])[0]
    requested_category_slug = query.get("category", [""])[0]
    source = query.get("source", [""])[0]
    group_slug = query.get("group", [""])[0]
    with get_conn() as conn:
        topic = conn.execute("SELECT * FROM topics WHERE slug = ?", (slug,)).fetchone()
        if not topic:
            return not_found()
        category = topic_context_category(conn, int(topic["id"]), requested_category_slug)
        breadcrumbs = category_breadcrumbs(conn, category, topic["name"], source, group_slug) if category else []
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
    posts = sort_scoped_posts(posts, sort, [topic["name"]])
    panel = keyword_panel(
        [topic["name"]],
        sort,
        descriptions_checked=True,
        sort_current_page=True,
    )
    inner = panel + results_summary(len(posts), [topic["name"]]) + post_list(posts, topic["name"])
    body = content_page(topic["name"], pluralize(len(posts), "post"), "", inner, breadcrumbs=breadcrumbs)
    return render_page(topic["name"], body, active="browse-by-topic")


def posts_for_category(slug: str, query: dict[str, list[str]]) -> bytes:
    sort = query.get("sort", ["ranked"])[0]
    source = query.get("source", [""])[0]
    group_slug = query.get("group", [""])[0]
    with get_conn() as conn:
        category = conn.execute("SELECT * FROM categories WHERE slug = ?", (slug,)).fetchone()
        if not category:
            return not_found()
        breadcrumbs = category_breadcrumbs(conn, category, "Posts", source, group_slug)
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
    posts = sort_scoped_posts(posts, sort, [])
    inner = keyword_panel(
        [],
        sort,
        descriptions_checked=True,
        sort_current_page=True,
    ) + post_list(posts, category["name"])
    body = content_page(f"{category['name']} Posts", pluralize(len(posts), "post"), "", inner, breadcrumbs=breadcrumbs)
    return render_page(f"{category['name']} Posts", body, active="browse-by-topic")


def find_post_ids_for_term(conn: sqlite3.Connection, term: str) -> dict[int, int]:
    normalized = normalize_keyword(term)
    if not normalized:
        return {}
    padded_like = f"% {normalized} %"
    rows = conn.execute(
        """
        SELECT post_id, MAX(weight + CASE WHEN normalized = ? THEN 2 ELSE 0 END) AS score
        FROM post_search_terms
        WHERE normalized = ? OR (' ' || normalized || ' ') LIKE ?
        GROUP BY post_id
        """,
        (normalized, normalized, padded_like),
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
    if " " not in normalized_term and any(normalized_term == word for word in normalized_title.split()):
        return 1
    return 0


def sort_scoped_posts(
    posts: list[sqlite3.Row],
    sort: str,
    ranking_terms: list[str],
) -> list[sqlite3.Row]:
    if sort not in {"ranked", "newest", "oldest"}:
        sort = "ranked"

    def sort_key(row: sqlite3.Row) -> tuple[object, ...]:
        if sort == "ranked":
            relevance = sum(title_match_boost(row["title"], term) for term in ranking_terms)
            return (relevance, row["date_iso"], row["id"])
        return (row["date_iso"], row["id"])

    return sorted(posts, key=sort_key, reverse=sort != "oldest")


def search_posts(terms: list[str], sort: str) -> tuple[list[sqlite3.Row], list[str]]:
    if sort not in {"ranked", "newest", "oldest"}:
        sort = "ranked"
    clean_terms = unique_terms(terms)
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
        return (matches[int(row["id"])], row["date_iso"], row["id"])

    reverse = sort in {"ranked", "newest"}
    return sorted(rows, key=sort_key, reverse=reverse), clean_terms


def keyword_search_page() -> bytes:
    body = content_page(
        "Keyword Search",
        "Search posts by keyword",
        inner=keyword_panel(descriptions_checked=True),
    )
    return render_page("Keyword Search", body, active="keyword-search")


def keyword_results_page(query: dict[str, list[str]]) -> bytes:
    terms = query.get("keyword", [])
    sort = query.get("sort", ["ranked"])[0]
    posts, clean_terms = search_posts(terms, sort)
    title = "Keywords: " + " + ".join(clean_terms) if clean_terms else "Keyword Search"
    panel = keyword_panel(clean_terms, sort, descriptions_checked=True, refresh_on_remove=True)
    inner = panel + results_summary(len(posts), clean_terms) + post_list(posts, "Keyword Search")
    body = content_page(title, pluralize(len(posts), "post"), inner=inner)
    return render_page(title, body, active="keyword-search")


def starter_keyword_suggestions(conn: sqlite3.Connection) -> list[dict[str, object]]:
    placeholders = ",".join("?" for _ in STARTER_TOPIC_LABELS)
    rows = conn.execute(
        f"""
        SELECT label, normalized, COUNT(DISTINCT post_id) AS post_count
        FROM post_search_terms
        WHERE kind = 'topic' AND label IN ({placeholders})
        GROUP BY label, normalized
        """,
        STARTER_TOPIC_LABELS,
    ).fetchall()
    by_label = {row["label"]: row for row in rows}
    return [
        {
            "label": row["label"],
            "normalized": row["normalized"],
            "postCount": row["post_count"],
            "isTopic": True,
        }
        for label in STARTER_TOPIC_LABELS
        if (row := by_label.get(label)) is not None
    ]


def api_keywords(query: dict[str, list[str]]) -> bytes:
    q = normalize_keyword(query.get("q", [""])[0])
    selected = [value for value in query.get("selected", []) if value.strip()]
    selected_normalized = sorted({normalize_keyword(value) for value in selected if normalize_keyword(value)})
    limit = 48
    with get_conn() as conn:
        if not q and not selected_normalized:
            return json.dumps(starter_keyword_suggestions(conn), ensure_ascii=False).encode("utf-8")
        selected_ids: set[int] | None = None
        for value in selected:
            matches = set(find_post_ids_for_term(conn, value).keys())
            selected_ids = matches if selected_ids is None else selected_ids & matches
        prefix_like = f"{q}%"
        word_prefix_like = f"% {q}%"
        params: list[object] = []
        where = "normalized <> 'ignore'"
        if q:
            where += " AND (normalized LIKE ? OR normalized LIKE ?)"
            params.extend([prefix_like, word_prefix_like])
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
                    WHEN normalized LIKE ? THEN 1
                    ELSE 1
                END AS match_quality
            FROM post_search_terms
            WHERE {where}
            GROUP BY normalized
            ORDER BY match_quality DESC, is_topic DESC, post_count DESC, label COLLATE NOCASE
            LIMIT {limit}
            """,
            (q, prefix_like, word_prefix_like, *params),
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
    if path in ("/categories", "/category-groups", "/browse-by-topic"):
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
        return posts_for_category(slug, query), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/category-groups/"):
        slug = path.removeprefix("/category-groups/").strip("/")
        return category_group_page(slug), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/browse-by-topic/"):
        slug = path.removeprefix("/browse-by-topic/").strip("/")
        return category_group_page(slug), "200 OK", "text/html; charset=utf-8"
    if path.startswith("/categories/"):
        slug = path.removeprefix("/categories/").strip("/")
        return category_page(slug, query), "200 OK", "text/html; charset=utf-8"
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
