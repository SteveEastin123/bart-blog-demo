import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright-core";
import { buildIndex, discoverMonths, discoverPostUrls, scrapePosts } from "./ehrman_chrome_indexer.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const BASE_URL = "https://ehrmanblog.org";
const CREDENTIALS_PATH = path.join(ROOT, ".ehrman_credentials.env");
const PROFILE_DIR = path.join(ROOT, "data", "raw", "playwright-profile");

function outputPaths(root) {
  return {
    root,
    raw: path.join(root, "data", "raw"),
    index: path.join(root, "data", "index"),
    months: path.join(root, "data", "raw", "archive_months.json"),
    postUrls: path.join(root, "data", "raw", "post_urls.json"),
    postsJsonl: path.join(root, "data", "raw", "posts.jsonl"),
    indexJson: path.join(root, "data", "index", "posts_index.json"),
    indexCsv: path.join(root, "data", "index", "posts_index.csv"),
  };
}

function loadCredentials() {
  const values = {};
  for (const line of fs.readFileSync(CREDENTIALS_PATH, "utf8").split(/\r?\n/)) {
    if (!line.includes("=") || line.trimStart().startsWith("#")) continue;
    const [key, ...rest] = line.split("=");
    let value = rest.join("=").trim();
    if (value.length >= 2 && value[0] === value.at(-1) && ["'", '"'].includes(value[0])) {
      value = value.slice(1, -1);
    }
    values[key.trim()] = value;
  }
  if (!values.EHRMAN_USERNAME || !values.EHRMAN_PASSWORD) {
    throw new Error(`Missing EHRMAN_USERNAME or EHRMAN_PASSWORD in ${CREDENTIALS_PATH}`);
  }
  return values;
}

function parseArgs(argv) {
  const options = { delayMs: 50 };
  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === "--reset") options.reset = true;
    else if (arg === "--discover-only") options.discoverOnly = true;
    else if (arg === "--cache-only") options.cacheOnly = true;
    else if (arg === "--force-discover") options.forceDiscover = true;
    else if (arg === "--limit-months") options.limitMonths = Number(argv[++index]);
    else if (arg === "--start-month") options.startMonth = Number(argv[++index]);
    else if (arg === "--limit-posts") options.limitPosts = Number(argv[++index]);
    else if (arg === "--from-date") options.fromDate = argv[++index];
    else if (arg === "--to-date") options.toDate = argv[++index];
    else if (arg === "--delay-ms") options.delayMs = Number(argv[++index]);
    else if (arg === "--headless") options.headless = true;
    else if (arg === "--fresh-profile") options.profileDir = path.join(os.tmpdir(), `ehrman_blog_profile_${Date.now()}`);
    else if (arg === "--profile-dir") options.profileDir = argv[++index];
    else if (arg === "--temp-output") options.outputRoot = path.join(os.tmpdir(), "ehrman_blog_playwright_export");
    else if (arg === "--output-root") options.outputRoot = argv[++index];
    else if (arg === "--copy-to-workspace") options.copyToWorkspace = true;
  }
  return options;
}

function readJson(filePath, fallback) {
  if (!fs.existsSync(filePath)) return fallback;
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function copyOutputsToWorkspace(output, options = {}) {
  const workspace = outputPaths(ROOT);
  fs.mkdirSync(workspace.raw, { recursive: true });
  fs.mkdirSync(workspace.index, { recursive: true });
  const keys = options.cacheOnly
    ? ["months", "postUrls", "postsJsonl"]
    : ["months", "postUrls", "postsJsonl", "indexJson", "indexCsv"];
  for (const key of keys) {
    if (!fs.existsSync(output[key])) continue;
    try {
      fs.copyFileSync(output[key], workspace[key]);
    } catch (error) {
      if (error.code !== "EBUSY") throw error;
      console.warn(`Skipped locked workspace file during copy: ${workspace[key]}`);
    }
  }
}

function copyWorkspaceToOutput(output) {
  if (path.resolve(output.root) === path.resolve(ROOT)) return;
  const workspace = outputPaths(ROOT);
  fs.mkdirSync(output.raw, { recursive: true });
  fs.mkdirSync(output.index, { recursive: true });
  for (const key of ["months", "postUrls", "postsJsonl", "indexJson", "indexCsv"]) {
    if (fs.existsSync(workspace[key]) && !fs.existsSync(output[key])) {
      fs.copyFileSync(workspace[key], output[key]);
    }
  }
}

function findChromeExecutable() {
  const candidates = [
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
    `${process.env.LOCALAPPDATA}/Google/Chrome/Application/chrome.exe`,
  ];
  const found = candidates.find((candidate) => candidate && fs.existsSync(candidate));
  if (!found) throw new Error("Could not find Google Chrome.");
  return found;
}

function adaptPage(page) {
  return {
    goto: (url) => page.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 }),
    title: () => page.title(),
    playwright: {
      evaluate: (fn, arg) => page.evaluate(fn, arg),
      waitForLoadState: ({ state, timeoutMs }) => page.waitForLoadState(state || "domcontentloaded", { timeout: timeoutMs || 30000 }),
      waitForTimeout: (timeoutMs) => page.waitForTimeout(timeoutMs),
      locator: (selector) => ({
        innerText: ({ timeoutMs } = {}) => page.locator(selector).innerText({ timeout: timeoutMs || 30000 }),
      }),
    },
  };
}

async function loginIfNeeded(page) {
  await page.goto(`${BASE_URL}/member-landing-page/`, { waitUntil: "domcontentloaded", timeout: 45000 });
  let text = await page.locator("body").innerText({ timeout: 30000 }).catch(() => "");
  if (/Logout|Logged in as/i.test(text)) return;

  const { EHRMAN_USERNAME, EHRMAN_PASSWORD } = loadCredentials();
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await page.goto(`${BASE_URL}/login/`, { waitUntil: "domcontentloaded", timeout: 45000 });
    const loginForm = page.locator("form:has(input[type='password'])").first();
    const username = loginForm.locator("#user_login, input[name='log']").first();
    const password = loginForm.locator("#user_pass, input[name='pwd']").first();
    await username.fill(EHRMAN_USERNAME, { timeout: 30000 });
    await password.fill(EHRMAN_PASSWORD, { timeout: 30000 });
    if (attempt === 1) {
      await Promise.all([
        page.waitForLoadState("domcontentloaded", { timeout: 45000 }).catch(() => {}),
        loginForm.locator("#wp-submit, input[name='wp-submit']").first().click({ timeout: 30000, force: true }),
      ]);
    } else {
      await Promise.all([
        page.waitForLoadState("domcontentloaded", { timeout: 45000 }).catch(() => {}),
        password.press("Enter", { timeout: 30000 }),
      ]);
    }
    await page.waitForTimeout(2000);
    text = await page.locator("body").innerText({ timeout: 30000 }).catch(() => "");
    if (/invalid|incorrect|unknown username|password/i.test(text)) {
      throw new Error(`Login form reported an error. Page text: ${text.slice(0, 500)}`);
    }
    await page.goto(`${BASE_URL}/member-landing-page/`, { waitUntil: "domcontentloaded", timeout: 45000 });
    text = await page.locator("body").innerText({ timeout: 30000 }).catch(() => "");
    if (/Logout|Logged in as/i.test(text)) return;
    await page.waitForTimeout(1000 * attempt);
  }
  throw new Error(`Playwright Chrome did not reach a logged-in member page. Page text: ${text.slice(0, 500)}`);
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  const OUTPUT = outputPaths(options.outputRoot || ROOT);
  fs.mkdirSync(OUTPUT.raw, { recursive: true });
  fs.mkdirSync(OUTPUT.index, { recursive: true });
  if (options.reset) {
    if (options.outputRoot && fs.existsSync(OUTPUT.root)) fs.rmSync(OUTPUT.root, { recursive: true, force: true });
    fs.mkdirSync(OUTPUT.raw, { recursive: true });
    fs.mkdirSync(OUTPUT.index, { recursive: true });
    if (!options.outputRoot) {
      for (const file of [OUTPUT.months, OUTPUT.postUrls, OUTPUT.postsJsonl, OUTPUT.indexJson, OUTPUT.indexCsv]) {
        if (fs.existsSync(file)) fs.rmSync(file, { force: true });
      }
    }
  }
  if (!options.reset) copyWorkspaceToOutput(OUTPUT);

  const context = await chromium.launchPersistentContext(options.profileDir || PROFILE_DIR, {
    executablePath: findChromeExecutable(),
    headless: Boolean(options.headless),
    viewport: { width: 1280, height: 900 },
  });
  const page = context.pages()[0] || await context.newPage();
  try {
    await loginIfNeeded(page);
    const tab = adaptPage(page);
    const refreshDiscovery = Boolean(options.forceDiscover || options.fromDate || options.toDate);
    const months = (!refreshDiscovery && fs.existsSync(OUTPUT.months))
      ? readJson(OUTPUT.months, [])
      : await discoverMonths(tab, OUTPUT);
    const urls = (!refreshDiscovery && !options.discoverOnly && fs.existsSync(OUTPUT.postUrls))
      ? readJson(OUTPUT.postUrls, [])
      : await discoverPostUrls(tab, OUTPUT, options);
    let posts = [];
    let index = [];
    if (!options.discoverOnly) {
      posts = await scrapePosts(tab, OUTPUT, options);
      if (!options.cacheOnly) index = buildIndex(OUTPUT);
    }
    if (options.copyToWorkspace) copyOutputsToWorkspace(OUTPUT, options);
    console.log(JSON.stringify({
      months: months.length,
      discoveredPostUrls: urls.length,
      scrapedPosts: posts.length,
      indexRows: index.length,
      fromDate: options.fromDate || null,
      toDate: options.toDate || null,
      files: OUTPUT,
    }, null, 2));
  } finally {
    await context.close();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
