// Read-only browsing and redirect checks; no AI requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor } = require('./browser-config.cjs');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const output = outputFor('browse-retirement-ui');
  fs.mkdirSync(output, { recursive: true });
  try {
    const context = await browser.newContext();
    await context.route('**/wp-json/**', route => route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const slug of ['browse-topics-1', 'browse-topics-2']) {
      const response = await context.request.get(`${base}/${slug}/?ebd_subject=retired-area&ebd_category=historical-jesus&ebd_keyword[]=Mustard%20Seed&ebd_term_mode[]=secondary&ebd_page=2`, { maxRedirects: 0 });
      assert.equal(response.status(), 301);
      const target = new URL(response.headers().location);
      assert.equal(target.pathname, '/browse-topics/');
      assert.equal(target.searchParams.get('ebd_subject'), null);
      assert.equal(target.searchParams.get('ebd_category'), 'historical-jesus');
      assert.equal(target.searchParams.get('ebd_keyword[0]'), 'Mustard Seed');
      assert.equal(target.searchParams.get('ebd_page'), '2');
    }
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 950 });
      await page.goto(`${base}/browse-topics/`);
      assert.equal(await page.locator('h1').innerText(), 'Browse Topics');
      assert.equal(await page.locator('[data-ebd-browse-list] > li').count(), 10);
      for (const parameter of ['ebd_subject', 'ebd_category', 'ebd_topic']) {
        const rows = page.locator('[data-ebd-browse-list] > li');
        const names = await rows.locator('a').allTextContents();
        assert.deepEqual(names, [...names].sort((a, b) => a.localeCompare(b, 'en', { sensitivity: 'base', numeric: true })));
        await page.locator('[data-ebd-browse-sort]').selectOption('posts');
        const counts = await rows.evaluateAll(items => items.map(item => Number(item.dataset.ebdPostCount)));
        assert.deepEqual(counts, [...counts].sort((a, b) => b - a));
        await page.locator('[data-ebd-browse-sort]').selectOption('alphabetical');
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
        await page.screenshot({ path: path.join(output, `${parameter}-${width}.png`), fullPage: true });
        await page.locator(`main a[href*="${parameter}="]`).first().click();
        assert.equal(new URL(page.url()).pathname, '/browse-topics/');
      }
      assert(!/Browse Topics [12]/.test(await page.locator('main').innerText()));
      await page.goto(`${base}/structure-review/`);
      assert.deepEqual(await page.locator('.ebd-review-paths a').allTextContents(), ['Browse Topics', 'Categories', 'Topics']);
      assert.equal(await page.locator('.ebd-review-area').count(), 10);
    }
    assert.deepEqual(errors, []);
    console.log('Browse retirement UI: redirects, filtering, hierarchy, both sorts, reviewer navigation, desktop/mobile passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
