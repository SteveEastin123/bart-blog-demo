import fs from "node:fs";
import path from "node:path";

const SITE_ORIGIN = "https://ehrmanblog.org";
const DEFAULT_OUTPUT_ROOT = path.join(globalThis.nodeRepl?.tmpDir || process.cwd(), "ehrman_blog_export");
const SKIP_SLUGS = new Set([
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
]);

const TAG_RULES = [
  ["jesus", /\bjesus|historical jesus|son of man|messiah|christ\b/i],
  ["gospels", /\bgospels?\b|\bmatthew\b|\bmark\b|\bluke\b|\bgospel of john\b|\bjohn\s+\d/i],
  ["paul", /\bpaul|pauline|romans|corinthians|galatians|philippians|thessalonians\b/i],
  ["new-testament", /\bnew testament|canon|acts|revelation|epistle\b/i],
  ["hebrew-bible", /\bhebrew bible|old testament|genesis|exodus|isaiah|jeremiah|psalm\b/i],
  ["early-christianity", /\bearly christian|christian origins|proto-orthodox|apostolic\b/i],
  ["apocrypha", /\bapocryph|pseudepigraph|non-canonical|gospel of thomas|gospel of peter\b/i],
  ["textual-criticism", /\bmanuscript|scribe|textual|variant|copyist|interpolation\b/i],
  ["forgery-authorship", /\bforgery|forged|pseudonymous|authorship|wrote\b/i],
  ["resurrection", /\bresurrection|raised from the dead|empty tomb|easter\b/i],
  ["miracles", /\bmiracle|wonder-working|healing|exorcism|supernatural\b/i],
  ["martyrdom", /\bmartyr|persecution|suffering for the faith\b/i],
  ["canon", /\bcanon|canonical|scripture|orthodoxy|heresy\b/i],
  ["christology", /\bchristology|divine christ|incarnation|trinity|god became\b/i],
  ["suffering-evil", /\bsuffering|evil|theodicy|problem of evil|pain\b/i],
  ["heaven-hell", /\bheaven|hell|afterlife|eternal life|torment|salvation\b/i],
  ["roman-world", /\brome|roman|empire|constantine|pagan|gentile\b/i],
  ["judaism", /\bjewish|judaism|pharisee|sadducee|torah|rabbi\b/i],
  ["church-history", /\bchurch history|augustine|constantine|council|reformation|calvin|luther\b/i],
  ["reader-questions", /\bquestion:|reader|mailbag|q&a|questions\b/i],
  ["blog-news", /\bannouncement|webinar|gold|platinum|blog dinner|members\b/i],
];

function paths(outputRoot = DEFAULT_OUTPUT_ROOT) {
  return {
    root: outputRoot,
    raw: path.join(outputRoot, "data", "raw"),
    index: path.join(outputRoot, "data", "index"),
    months: path.join(outputRoot, "data", "raw", "archive_months.json"),
    postUrls: path.join(outputRoot, "data", "raw", "post_urls.json"),
    postsJsonl: path.join(outputRoot, "data", "raw", "posts.jsonl"),
    indexJson: path.join(outputRoot, "data", "index", "posts_index.json"),
    indexCsv: path.join(outputRoot, "data", "index", "posts_index.csv"),
  };
}

function ensureDirs(out) {
  fs.mkdirSync(out.raw, { recursive: true });
  fs.mkdirSync(out.index, { recursive: true });
}

function readJson(filePath, fallback) {
  if (!fs.existsSync(filePath)) return fallback;
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function writeJson(filePath, value) {
  fs.writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, "utf8");
}

function readJsonl(filePath) {
  if (!fs.existsSync(filePath)) return [];
  return fs.readFileSync(filePath, "utf8")
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

function appendJsonl(filePath, value) {
  fs.appendFileSync(filePath, `${JSON.stringify(value)}\n`, "utf8");
}

function cleanText(text) {
  return (text || "")
    .replace(/\u00a0/g, " ")
    .replace(/[ \t]+/g, " ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

function stripBoilerplate(text) {
  const markers = [
    "\nSave to PDF",
    "\nPrint Page",
    "\nShare Bart",
    "\nClick for the Previous Post",
    "\nClick for the Next Post",
    "\nLogged in as",
    "\nComment\nComment Rules",
  ];
  let out = text;
  for (const marker of markers) {
    const idx = out.indexOf(marker);
    if (idx !== -1) out = out.slice(0, idx).trim();
  }
  return out;
}

function canonicalUrl(url) {
  const parsed = new URL(url);
  parsed.hash = "";
  parsed.search = "";
  if (!parsed.pathname.endsWith("/")) parsed.pathname += "/";
  return parsed.href;
}

function isLikelyPostUrl(url) {
  try {
    const parsed = new URL(url);
    if (parsed.origin !== SITE_ORIGIN) return false;
    const parts = parsed.pathname.split("/").filter(Boolean);
    if (parts.length !== 1) return false;
    return !SKIP_SLUGS.has(parts[0]);
  } catch {
    return false;
  }
}

function slugify(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

function parseDateValue(value) {
  if (!value) return null;
  let text = cleanText(String(value));
  if (!text) return null;

  let match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (match) {
    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  }

  match = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (match) {
    return Date.UTC(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
  }

  text = text.replace(/\b(\d{1,2})(?:st|nd|rd|th)\b/i, "$1");
  match = text.match(/^([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})$/);
  if (!match) return null;
  const months = new Map([
    ["january", 0], ["jan", 0],
    ["february", 1], ["feb", 1],
    ["march", 2], ["mar", 2],
    ["april", 3], ["apr", 3],
    ["may", 4],
    ["june", 5], ["jun", 5],
    ["july", 6], ["jul", 6],
    ["august", 7], ["aug", 7],
    ["september", 8], ["sep", 8], ["sept", 8],
    ["october", 9], ["oct", 9],
    ["november", 10], ["nov", 10],
    ["december", 11], ["dec", 11],
  ]);
  const month = months.get(match[1].toLowerCase());
  if (month === undefined) return null;
  return Date.UTC(Number(match[3]), month, Number(match[2]));
}

function parseRequiredDate(value, label) {
  if (!value) return null;
  const parsed = parseDateValue(value);
  if (parsed === null) {
    throw new Error(`Could not parse ${label}: ${JSON.stringify(value)}. Use YYYY-MM-DD, M/D/YYYY, or 'Month D, YYYY'.`);
  }
  return parsed;
}

function dateRange(options = {}) {
  const from = parseRequiredDate(options.fromDate, "--from-date");
  const to = parseRequiredDate(options.toDate, "--to-date");
  if (from !== null && to !== null && from > to) {
    throw new Error("--from-date cannot be later than --to-date.");
  }
  return { from, to };
}

function archiveExcerptDate(post) {
  const match = String(post.archiveExcerpt || "").match(/\b([A-Z][a-z]+\s+\d{1,2}(?:st|nd|rd|th)?),\s+(\d{4})\|/);
  if (!match) return null;
  return parseDateValue(`${match[1]}, ${match[2]}`);
}

function postDate(post) {
  return parseDateValue(post.dateText) ?? archiveExcerptDate(post);
}

function dateInRange(value, range) {
  if (range.from === null && range.to === null) return true;
  if (value === null) return true;
  if (range.from !== null && value < range.from) return false;
  if (range.to !== null && value > range.to) return false;
  return true;
}

function monthOverlapsRange(month, range) {
  if (range.from === null && range.to === null) return true;
  const start = Date.UTC(Number(month.year), Number(month.month) - 1, 1);
  const end = Date.UTC(Number(month.year), Number(month.month), 0);
  if (range.from !== null && end < range.from) return false;
  if (range.to !== null && start > range.to) return false;
  return true;
}

function excerptSummary(record) {
  let body = record.text.replace(record.title, "").trim();
  body = body.replace(/^(January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4}\s*/, "");
  const paragraphs = body.split(/\n{2,}/).filter((p) => p.length > 80);
  const source = paragraphs[0] || body;
  const sentences = source.match(/[^.!?]+[.!?]+(?:["')\]]+)?/g) || [source];
  return cleanText(sentences.slice(0, 2).join(" ")).slice(0, 500);
}

function candidateTags(record) {
  const haystack = `${record.title}\n${record.categories?.join(" ")}\n${record.tags?.join(" ")}\n${record.text}`;
  const tags = [];
  for (const [tag, pattern] of TAG_RULES) {
    if (pattern.test(haystack)) tags.push(tag);
  }
  for (const category of record.categories || []) {
    const normalized = slugify(category);
    if (normalized && !tags.includes(normalized)) tags.push(normalized);
  }
  return tags.slice(0, 8);
}

function csvEscape(value) {
  const text = Array.isArray(value) ? value.join("; ") : String(value ?? "");
  return `"${text.replace(/"/g, '""')}"`;
}

async function goto(tab, url) {
  await tab.goto(url);
  await tab.playwright.waitForLoadState({ state: "domcontentloaded", timeoutMs: 20000 }).catch(() => {});
}

export async function discoverMonths(tab, out) {
  await goto(tab, `${SITE_ORIGIN}/member-landing-page/`);
  const months = await tab.playwright.evaluate(() => {
    const links = Array.from(document.querySelectorAll("a[href]"));
    return links
      .map((a) => ({ title: a.textContent.trim(), url: a.href }))
      .filter((item) => /^https:\/\/ehrmanblog\.org\/\d{4}\/\d{2}\/$/.test(item.url))
      .map((item) => ({
        ...item,
        count: Number((item.title.match(/\((\d+)\)/) || [])[1] || 0),
        year: Number(item.url.match(/\/(\d{4})\/\d{2}\//)[1]),
        month: Number(item.url.match(/\/\d{4}\/(\d{2})\//)[1]),
      }));
  }, undefined, { timeoutMs: 20000 });
  const unique = Array.from(new Map(months.map((month) => [month.url, month])).values());
  writeJson(out.months, unique);
  return unique;
}

export async function discoverPostUrls(tab, out, options = {}) {
  const range = dateRange(options);
  const months = (readJson(out.months, null) || await discoverMonths(tab, out))
    .filter((month) => monthOverlapsRange(month, range));
  const startMonth = options.startMonth || 0;
  const selectedMonths = options.limitMonths
    ? months.slice(startMonth, startMonth + options.limitMonths)
    : months.slice(startMonth);
  const seen = new Map(readJson(out.postUrls, []).map((item) => [item.url, item]));

  for (const month of selectedMonths) {
    const maxPages = Math.max(1, Math.ceil((month.count || 0) / 20) + 1);
    for (let page = 1; page <= maxPages; page += 1) {
      const archiveUrl = page === 1 ? month.url : `${month.url}page/${page}/`;
      await goto(tab, archiveUrl);
      const pageTitle = await tab.title();
      if (/Page not found|404/i.test(pageTitle)) break;
      const posts = await tab.playwright.evaluate((source) => {
        return Array.from(document.querySelectorAll("article")).map((article) => {
          const heading = article.querySelector("h1, h2, h3");
          const link = heading?.querySelector("a[href]") || article.querySelector("a[href]");
          const classNames = Array.from(article.classList);
          return {
            title: heading?.textContent.trim() || "",
            url: link?.href || "",
            archiveExcerpt: article.innerText.trim(),
            wpId: (article.className.match(/\bpost-(\d+)\b/) || [])[1] || null,
            categories: classNames.filter((name) => name.startsWith("category-")).map((name) => name.slice(9)),
            tags: classNames.filter((name) => name.startsWith("tag-")).map((name) => name.slice(4)),
            sourceArchive: source.url,
            sourceArchiveTitle: source.title,
          };
        });
      }, month, { timeoutMs: 20000 });
      const validPosts = posts.filter((post) => post.url && isLikelyPostUrl(post.url));
      if (validPosts.length === 0) break;
      for (const post of validPosts) {
        if (!dateInRange(postDate(post), range)) continue;
        const url = canonicalUrl(post.url);
        seen.set(url, { ...seen.get(url), ...post, url });
      }
      writeJson(out.postUrls, Array.from(seen.values()));
      if (options.delayMs) await tab.playwright.waitForTimeout(options.delayMs);
    }
  }

  return Array.from(seen.values());
}

export async function scrapePosts(tab, out, options = {}) {
  const range = dateRange(options);
  const postUrls = readJson(out.postUrls, null) || await discoverPostUrls(tab, out, options);
  const scrapedUrls = new Set(readJsonl(out.postsJsonl).map((post) => post.url));
  const pending = postUrls.filter((post) => !scrapedUrls.has(post.url) && dateInRange(postDate(post), range));
  const selected = options.limitPosts ? pending.slice(0, options.limitPosts) : pending;

  for (const post of selected) {
    await goto(tab, post.url);
    const record = await tab.playwright.evaluate((archivePost) => {
      const article = document.querySelector("article") || document.querySelector(".post-content") || document.querySelector(".entry-content") || document.body;
      const classNames = article ? Array.from(article.classList) : [];
      const title = document.querySelector("article h1, h1.entry-title, h1")?.textContent.trim()
        || archivePost.title
        || document.title.replace(/ - The Bart Ehrman Blog$/, "");
      const dateText = document.querySelector("time")?.getAttribute("datetime")
        || document.querySelector("time")?.textContent.trim()
        || (article?.innerText.match(/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/) || [])[0]
        || "";
      const author = document.querySelector('[rel="author"], .author, .vcard')?.textContent.trim() || "";
      return {
        ...archivePost,
        title,
        dateText,
        author,
        url: location.href.replace(/[#?].*$/, "").replace(/([^/])$/, "$1/"),
        categories: Array.from(new Set([
          ...(archivePost.categories || []),
          ...classNames.filter((name) => name.startsWith("category-")).map((name) => name.slice(9)),
        ])),
        tags: Array.from(new Set([
          ...(archivePost.tags || []),
          ...classNames.filter((name) => name.startsWith("tag-")).map((name) => name.slice(4)),
        ])),
        rawText: article?.innerText || document.body.innerText,
      };
    }, post, { timeoutMs: 20000 });

    const text = stripBoilerplate(cleanText(record.rawText));
    const finalRecord = {
      scrapedAt: new Date().toISOString(),
      ...record,
      rawText: undefined,
      text,
      wordCount: text.split(/\s+/).filter(Boolean).length,
    };
    if (!dateInRange(postDate(finalRecord), range)) continue;
    finalRecord.summary = excerptSummary(finalRecord);
    finalRecord.candidateTags = candidateTags(finalRecord);
    appendJsonl(out.postsJsonl, finalRecord);
    if (options.delayMs) await tab.playwright.waitForTimeout(options.delayMs);
  }

  return readJsonl(out.postsJsonl);
}

export function buildIndex(out) {
  const posts = readJsonl(out.postsJsonl);
  const index = posts.map((post) => ({
    title: post.title,
    url: post.url,
    date: post.dateText,
    author: post.author,
    categories: post.categories || [],
    originalTags: post.tags || [],
    candidateTags: post.candidateTags || [],
    summary: post.summary,
    wordCount: post.wordCount,
    sourceArchive: post.sourceArchive,
  }));
  writeJson(out.indexJson, index);
  const header = ["title", "url", "date", "author", "categories", "originalTags", "candidateTags", "summary", "wordCount", "sourceArchive"];
  const rows = [header.map(csvEscape).join(",")].concat(
    index.map((post) => header.map((key) => csvEscape(post[key])).join(","))
  );
  fs.writeFileSync(out.indexCsv, `${rows.join("\n")}\n`, "utf8");
  return index;
}

export async function run(tab, options = {}) {
  const out = paths(options.outputRoot || DEFAULT_OUTPUT_ROOT);
  if (options.reset && fs.existsSync(out.root)) fs.rmSync(out.root, { recursive: true, force: true });
  ensureDirs(out);
  const months = await discoverMonths(tab, out);
  const urls = await discoverPostUrls(tab, out, options);
  const posts = await scrapePosts(tab, out, options);
  const index = buildIndex(out);
  return {
    outputRoot: out.root,
    months: months.length,
    discoveredPostUrls: urls.length,
    scrapedPosts: posts.length,
    indexRows: index.length,
    fromDate: options.fromDate || null,
    toDate: options.toDate || null,
    files: {
      months: out.months,
      postUrls: out.postUrls,
      posts: out.postsJsonl,
      indexJson: out.indexJson,
      indexCsv: out.indexCsv,
    },
  };
}
