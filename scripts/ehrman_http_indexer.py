import argparse
import csv
import json
import math
import os
import re
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[1]
RAW_DIR = ROOT / "data" / "raw"
INDEX_DIR = ROOT / "data" / "index"
CREDENTIALS_PATH = ROOT / ".ehrman_credentials.env"

MONTHS_PATH = RAW_DIR / "archive_months.json"
POST_URLS_PATH = RAW_DIR / "post_urls.json"
POSTS_JSONL_PATH = RAW_DIR / "posts.jsonl"
INDEX_JSON_PATH = INDEX_DIR / "posts_index.json"
INDEX_CSV_PATH = INDEX_DIR / "posts_index.csv"

BASE_URL = "https://ehrmanblog.org"
USER_AGENT = "Mozilla/5.0 Codex local indexing helper for editorial use"

SKIP_SLUGS = {
    "about-the-blog",
    "about-bart",
    "account",
    "charities-we-support",
    "contact-bart",
    "contact-support",
    "forum",
    "login",
    "member-landing-page",
    "privacy-policies",
    "register",
    "rss",
    "support-faq",
}

TAG_RULES = [
    ("jesus", re.compile(r"\bjesus|historical jesus|son of man|messiah|christ\b", re.I)),
    ("gospels", re.compile(r"\bgospels?\b|\bmatthew\b|\bmark\b|\bluke\b|\bgospel of john\b|\bjohn\s+\d", re.I)),
    ("paul", re.compile(r"\bpaul|pauline|romans|corinthians|galatians|philippians|thessalonians\b", re.I)),
    ("new-testament", re.compile(r"\bnew testament|canon|acts|revelation|epistle\b", re.I)),
    ("hebrew-bible", re.compile(r"\bhebrew bible|old testament|genesis|exodus|isaiah|jeremiah|psalm\b", re.I)),
    ("early-christianity", re.compile(r"\bearly christian|christian origins|proto-orthodox|apostolic\b", re.I)),
    ("apocrypha", re.compile(r"\bapocryph|pseudepigraph|non-canonical|gospel of thomas|gospel of peter\b", re.I)),
    ("textual-criticism", re.compile(r"\bmanuscript|scribe|textual|variant|copyist|interpolation\b", re.I)),
    ("forgery-authorship", re.compile(r"\bforgery|forged|pseudonymous|authorship|wrote\b", re.I)),
    ("resurrection", re.compile(r"\bresurrection|raised from the dead|empty tomb|easter\b", re.I)),
    ("miracles", re.compile(r"\bmiracle|wonder-working|healing|exorcism|supernatural\b", re.I)),
    ("martyrdom", re.compile(r"\bmartyr|persecution|suffering for the faith\b", re.I)),
    ("canon", re.compile(r"\bcanon|canonical|scripture|orthodoxy|heresy\b", re.I)),
    ("christology", re.compile(r"\bchristology|divine christ|incarnation|trinity|god became\b", re.I)),
    ("suffering-evil", re.compile(r"\bsuffering|evil|theodicy|problem of evil|pain\b", re.I)),
    ("heaven-hell", re.compile(r"\bheaven|hell|afterlife|eternal life|torment|salvation\b", re.I)),
    ("roman-world", re.compile(r"\brome|roman|empire|constantine|pagan|gentile\b", re.I)),
    ("judaism", re.compile(r"\bjewish|judaism|pharisee|sadducee|torah|rabbi\b", re.I)),
    ("church-history", re.compile(r"\bchurch history|augustine|constantine|council|reformation|calvin|luther\b", re.I)),
    ("reader-questions", re.compile(r"\bquestion:|reader|mailbag|q&a|questions\b", re.I)),
    ("blog-news", re.compile(r"\bannouncement|webinar|gold|platinum|blog dinner|members\b", re.I)),
]


@dataclass
class Options:
    limit_months: int | None
    limit_posts: int | None
    skip_login: bool
    debug_login: bool
    reset: bool
    delay: float


def ensure_dirs() -> None:
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    INDEX_DIR.mkdir(parents=True, exist_ok=True)


def read_json(path: Path, fallback):
    if not path.exists():
        return fallback
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value) -> None:
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def read_jsonl(path: Path) -> list[dict]:
    if not path.exists():
        return []
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            rows.append(json.loads(line))
    return rows


def append_jsonl(path: Path, value: dict) -> None:
    with path.open("a", encoding="utf-8", newline="") as handle:
        handle.write(json.dumps(value, ensure_ascii=False) + "\n")


def clean_text(text: str) -> str:
    if any(marker in text for marker in ("â", "Ã", "Â")):
        try:
            text = text.encode("cp1252").decode("utf-8")
        except UnicodeError:
            pass
    text = text.replace("\xa0", " ")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def strip_boilerplate(text: str) -> str:
    for marker in [
        "\nSave to PDF",
        "\nPrint Page",
        "\nShare Bart",
        "\nClick for the Previous Post",
        "\nClick for the Next Post",
        "\nLogged in as",
        "\nComment\nComment Rules",
    ]:
        if marker in text:
            text = text.split(marker, 1)[0].strip()
    return text


def canonical_url(url: str) -> str:
    parsed = urlparse(url)
    return f"{parsed.scheme}://{parsed.netloc}{parsed.path.rstrip('/')}/"


def slug_from_url(url: str) -> str:
    return urlparse(url).path.strip("/").split("/")[-1]


def is_likely_post_url(url: str) -> bool:
    parsed = urlparse(url)
    if f"{parsed.scheme}://{parsed.netloc}" != BASE_URL:
        return False
    parts = [part for part in parsed.path.split("/") if part]
    return len(parts) == 1 and parts[0] not in SKIP_SLUGS


def slugify(value: str) -> str:
    return re.sub(r"(^-|-$)", "", re.sub(r"[^a-z0-9]+", "-", value.lower()))


def load_credentials() -> tuple[str, str]:
    values = dict(os.environ)
    if CREDENTIALS_PATH.exists():
        for line in CREDENTIALS_PATH.read_text(encoding="utf-8").splitlines():
            if "=" in line and not line.lstrip().startswith("#"):
                key, value = line.split("=", 1)
                value = value.strip()
                if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
                    value = value[1:-1]
                values[key.strip()] = value
    username = values.get("EHRMAN_USERNAME", "")
    password = values.get("EHRMAN_PASSWORD", "")
    if not username or not password:
        raise RuntimeError(f"Missing credentials. Create {CREDENTIALS_PATH} from .ehrman_credentials.env.example.")
    return username, password


def soup_from_response(response: requests.Response) -> BeautifulSoup:
    response.raise_for_status()
    return BeautifulSoup(response.text, "html.parser")


def request(session: requests.Session, url: str) -> requests.Response:
    response = session.get(url, timeout=30)
    response.encoding = "utf-8"
    return response


def form_fields(form) -> dict[str, str]:
    fields = {}
    for control in form.select("input, textarea, select"):
        name = control.get("name")
        if not name:
            continue
        if control.name == "select":
            selected = control.select_one("option[selected]") or control.select_one("option")
            fields[name] = selected.get("value", "") if selected else ""
        elif control.get("type") in {"checkbox", "radio"} and not control.has_attr("checked"):
            continue
        else:
            fields[name] = control.get("value", "")
    return fields


def fill_login_form(form, username: str, password: str) -> dict[str, str]:
    data = form_fields(form)
    password_input = form.select_one('input[type="password"]')
    if not password_input or not password_input.get("name"):
        raise RuntimeError("No password field in form")
    data[password_input["name"]] = password

    username_input = (
        form.select_one('input[name="log"]')
        or form.select_one('input[name*="user" i]')
        or form.select_one('input[name*="email" i]')
        or form.select_one('input[type="email"]')
        or form.select_one('input[type="text"]')
    )
    if not username_input or not username_input.get("name"):
        raise RuntimeError("No username field in form")
    data[username_input["name"]] = username
    return data


def login(session: requests.Session, username: str, password: str) -> None:
    candidates = [f"{BASE_URL}/login/", f"{BASE_URL}/wp-login.php"]
    for login_url in candidates:
        response = request(session, login_url)
        if response.status_code == 403:
            continue
        soup = soup_from_response(response)
        forms = [form for form in soup.find_all("form") if form.select_one('input[type="password"]')]
        for form in forms:
            data = fill_login_form(form, username, password)
            action = urljoin(login_url, form.get("action") or login_url)
            method = (form.get("method") or "post").lower()
            if method == "get":
                session.get(action, params=data, timeout=30)
            else:
                session.post(action, data=data, timeout=30)
            if is_logged_in(session):
                return

    session.post(
        f"{BASE_URL}/wp-login.php",
        data={
            "log": username,
            "pwd": password,
            "wp-submit": "Log In",
            "redirect_to": f"{BASE_URL}/member-landing-page/",
            "testcookie": "1",
        },
        timeout=30,
    )
    if not is_logged_in(session):
        raise RuntimeError("Login failed. Check the temporary credentials or whether the account needs MFA/CAPTCHA.")


def debug_login(session: requests.Session, username: str, password: str) -> dict:
    login_url = f"{BASE_URL}/login/"
    response = request(session, login_url)
    soup = soup_from_response(response)
    forms = [form for form in soup.find_all("form") if form.select_one('input[type="password"]')]
    if not forms:
        return {"status": response.status_code, "url": response.url, "error": "No password login form found"}
    data = fill_login_form(forms[0], username, password)
    data["wp-submit"] = data.get("wp-submit") or "Log In"
    action = urljoin(login_url, forms[0].get("action") or login_url)
    posted = session.post(action, data=data, timeout=30, allow_redirects=True)
    posted.encoding = "utf-8"
    text = BeautifulSoup(posted.text, "html.parser").get_text("\n", strip=True)
    return {
        "status": posted.status_code,
        "url": posted.url,
        "cookieNames": sorted({cookie.name for cookie in session.cookies}),
        "markers": {
            "logout": "Logout" in text,
            "loggedInAs": "Logged in as" in text,
            "invalid": "invalid" in text.lower(),
            "incorrect": "incorrect" in text.lower(),
            "captcha": "captcha" in text.lower(),
            "memberPress": "mepr" in posted.text.lower() or "memberpress" in text.lower(),
        },
        "textSample": text[:1500],
    }


def is_logged_in(session: requests.Session) -> bool:
    response = request(session, f"{BASE_URL}/member-landing-page/")
    text = BeautifulSoup(response.text, "html.parser").get_text("\n")
    return "Logout" in text or "Logged in as" in text


def categories_and_tags_from_classes(classes: Iterable[str]) -> tuple[list[str], list[str]]:
    categories = []
    tags = []
    for class_name in classes:
        if class_name.startswith("category-"):
            categories.append(class_name.removeprefix("category-"))
        elif class_name.startswith("tag-"):
            tags.append(class_name.removeprefix("tag-"))
    return sorted(set(categories)), sorted(set(tags))


def discover_months(session: requests.Session) -> list[dict]:
    soup = soup_from_response(request(session, f"{BASE_URL}/member-landing-page/"))
    months = {}
    for anchor in soup.select("a[href]"):
        href = canonical_url(urljoin(BASE_URL, anchor["href"]))
        match = re.match(r"https://ehrmanblog\.org/(\d{4})/(\d{2})/$", href)
        if not match:
            continue
        count_match = re.search(r"\((\d+)\)", anchor.get_text(" ", strip=True))
        months[href] = {
            "title": anchor.get_text(" ", strip=True),
            "url": href,
            "year": int(match.group(1)),
            "month": int(match.group(2)),
            "count": int(count_match.group(1)) if count_match else 0,
        }
    result = sorted(months.values(), key=lambda row: (row["year"], row["month"]), reverse=True)
    write_json(MONTHS_PATH, result)
    return result


def archive_page_url(month_url: str, page: int) -> str:
    return month_url if page == 1 else f"{month_url}page/{page}/"


def posts_from_archive_html(html: str, source_archive: dict, page_url: str) -> list[dict]:
    soup = BeautifulSoup(html, "html.parser")
    posts = []
    for article in soup.select("article"):
        heading = article.select_one("h1, h2, h3")
        link = heading.select_one("a[href]") if heading else article.select_one("a[href]")
        if not link:
            continue
        url = canonical_url(urljoin(page_url, link["href"]))
        if not is_likely_post_url(url):
            continue
        categories, tags = categories_and_tags_from_classes(article.get("class", []))
        wp_id_match = re.search(r"\bpost-(\d+)\b", " ".join(article.get("class", [])))
        posts.append(
            {
                "title": heading.get_text(" ", strip=True) if heading else "",
                "url": url,
                "wpId": wp_id_match.group(1) if wp_id_match else None,
                "categories": categories,
                "tags": tags,
                "archiveExcerpt": clean_text(article.get_text("\n", strip=True)),
                "sourceArchive": source_archive["url"],
                "sourceArchiveTitle": source_archive["title"],
            }
        )
    return posts


def discover_post_urls(session: requests.Session, options: Options) -> list[dict]:
    months = read_json(MONTHS_PATH, None) or discover_months(session)
    if options.limit_months:
        months = months[: options.limit_months]

    seen = {row["url"]: row for row in read_json(POST_URLS_PATH, [])}
    for month in months:
        max_pages = max(1, math.ceil((month.get("count") or 0) / 20) + 1)
        empty_pages = 0
        for page in range(1, max_pages + 1):
            page_url = archive_page_url(month["url"], page)
            response = request(session, page_url)
            if response.status_code == 404:
                break
            posts = posts_from_archive_html(response.text, month, page_url)
            if not posts:
                empty_pages += 1
                if empty_pages >= 1:
                    break
            for post in posts:
                seen[post["url"]] = {**seen.get(post["url"], {}), **post}
            time.sleep(options.delay)
        write_json(POST_URLS_PATH, list(seen.values()))
    return list(seen.values())


def extract_post(html: str, source: dict, url: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    article = soup.select_one("article") or soup.select_one(".post-content") or soup.select_one(".entry-content") or soup.body
    for unwanted in article.select("script, style, noscript, form, nav, .fusion-sharing-box, .related-posts"):
        unwanted.decompose()

    title_node = soup.select_one("article h1, h1.entry-title, h1")
    title = title_node.get_text(" ", strip=True) if title_node else source.get("title") or soup.title.get_text(" ", strip=True)
    time_node = soup.select_one("time")
    date_text = ""
    if time_node:
        date_text = time_node.get("datetime") or time_node.get_text(" ", strip=True)
    if not date_text:
        match = re.search(r"\b(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\b", article.get_text(" "))
        date_text = match.group(0) if match else ""
    author_node = soup.select_one('[rel="author"], .author, .vcard')
    author = author_node.get_text(" ", strip=True) if author_node else ""

    categories, tags = categories_and_tags_from_classes(article.get("class", []))
    text = strip_boilerplate(clean_text(article.get_text("\n", strip=True)))
    record = {
        **source,
        "scrapedAt": datetime.now(timezone.utc).isoformat(),
        "title": title,
        "url": canonical_url(url),
        "dateText": date_text,
        "author": author,
        "categories": sorted(set((source.get("categories") or []) + categories)),
        "tags": sorted(set((source.get("tags") or []) + tags)),
        "text": text,
        "wordCount": len(re.findall(r"\S+", text)),
    }
    record["summary"] = excerpt_summary(record)
    record["candidateTags"] = candidate_tags(record)
    return record


def excerpt_summary(record: dict) -> str:
    body = record["text"].replace(record["title"], "", 1).strip()
    body = re.sub(r"^(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\s*", "", body)
    paragraphs = [p for p in re.split(r"\n{2,}", body) if len(p) > 80]
    source = paragraphs[0] if paragraphs else body
    sentences = re.findall(r'[^.!?]+[.!?]+(?:["\')\]]+)?', source) or [source]
    return clean_text(" ".join(sentences[:2]))[:500]


def candidate_tags(record: dict) -> list[str]:
    haystack = "\n".join(
        [
            record.get("title", ""),
            " ".join(record.get("categories") or []),
            " ".join(record.get("tags") or []),
            record.get("text", ""),
        ]
    )
    tags = [tag for tag, pattern in TAG_RULES if pattern.search(haystack)]
    for category in record.get("categories") or []:
        normalized = slugify(category)
        if normalized and normalized not in tags:
            tags.append(normalized)
    return tags[:8]


def scrape_posts(session: requests.Session, options: Options) -> list[dict]:
    post_urls = read_json(POST_URLS_PATH, None) or discover_post_urls(session, options)
    scraped = {row["url"] for row in read_jsonl(POSTS_JSONL_PATH)}
    pending = [post for post in post_urls if post["url"] not in scraped]
    if options.limit_posts:
        pending = pending[: options.limit_posts]

    for post in pending:
        response = request(session, post["url"])
        record = extract_post(response.text, post, post["url"])
        append_jsonl(POSTS_JSONL_PATH, record)
        time.sleep(options.delay)
    return read_jsonl(POSTS_JSONL_PATH)


def build_index() -> list[dict]:
    posts = read_jsonl(POSTS_JSONL_PATH)
    index = [
        {
            "title": post.get("title", ""),
            "url": post.get("url", ""),
            "date": post.get("dateText", ""),
            "author": post.get("author", ""),
            "categories": post.get("categories", []),
            "originalTags": post.get("tags", []),
            "candidateTags": post.get("candidateTags", []),
            "summary": post.get("summary", ""),
            "wordCount": post.get("wordCount", 0),
            "sourceArchive": post.get("sourceArchive", ""),
        }
        for post in posts
    ]
    write_json(INDEX_JSON_PATH, index)
    with INDEX_CSV_PATH.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(index[0].keys()) if index else [
            "title", "url", "date", "author", "categories", "originalTags", "candidateTags", "summary", "wordCount", "sourceArchive"
        ])
        writer.writeheader()
        for row in index:
            writer.writerow({key: "; ".join(value) if isinstance(value, list) else value for key, value in row.items()})
    return index


def parse_args() -> Options:
    parser = argparse.ArgumentParser(description="Index Ehrman Blog posts using a temporary member login.")
    parser.add_argument("--limit-months", type=int)
    parser.add_argument("--limit-posts", type=int)
    parser.add_argument("--skip-login", action="store_true")
    parser.add_argument("--debug-login", action="store_true", help="Print non-secret login diagnostics and exit.")
    parser.add_argument("--reset", action="store_true", help="Delete generated index files before running.")
    parser.add_argument("--delay", type=float, default=0.25)
    args = parser.parse_args()
    return Options(args.limit_months, args.limit_posts, args.skip_login, args.debug_login, args.reset, args.delay)


def main() -> None:
    ensure_dirs()
    options = parse_args()
    if options.reset:
        for path in [MONTHS_PATH, POST_URLS_PATH, POSTS_JSONL_PATH, INDEX_JSON_PATH, INDEX_CSV_PATH]:
            if path.exists():
                path.unlink()
    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT})
    if options.debug_login:
        username, password = load_credentials()
        print(json.dumps(debug_login(session, username, password), indent=2))
        return
    if not options.skip_login:
        username, password = load_credentials()
        login(session, username, password)
    months = discover_months(session)
    post_urls = discover_post_urls(session, options)
    posts = scrape_posts(session, options)
    index = build_index()
    print(
        json.dumps(
            {
                "months": len(months),
                "discoveredPostUrls": len(post_urls),
                "scrapedPosts": len(posts),
                "indexRows": len(index),
                "files": {
                    "months": str(MONTHS_PATH),
                    "postUrls": str(POST_URLS_PATH),
                    "posts": str(POSTS_JSONL_PATH),
                    "indexJson": str(INDEX_JSON_PATH),
                    "indexCsv": str(INDEX_CSV_PATH),
                },
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
