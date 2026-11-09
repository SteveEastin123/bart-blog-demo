// Read-only browsing checks. Sorting must never make an API request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor } = require('./browser-config.cjs');
const output = outputFor('browse-sort-ui');
const key = 'ebd.browseTopicSort';

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const errors = [];
  let apiCalls = 0;
  try {
    const context = await browser.newContext();
    await context.route('**/wp-json/**', route => { apiCalls++; return route.abort(); });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const targets = [];
    for (const number of [1, 2]) {
      await page.goto(`${base}/browse-topics-${number}/`);
      targets.push({ name: `browse-${number}-subjects`, url: page.url(), level: 'subjects' });
      await page.locator('main a[href*="ebd_subject="]').first().click();
      targets.push({ name: `browse-${number}-categories`, url: page.url(), level: 'categories' });
      await page.locator('main a[href*="ebd_category="]').first().click();
      targets.push({ name: `browse-${number}-topics`, url: page.url(), level: 'topics' });
    }
    const readItems = target => target.locator('[data-ebd-browse-list] > li').evaluateAll(items => items.map(item => ({
      name: item.querySelector('.ebd-navigation-name > span').textContent,
      href: item.querySelector('a').href,
      count: Number(item.dataset.ebdPostCount),
      description: item.querySelector('.ebd-item-description').textContent,
    })));
    const mostPosts = items => [...items].sort((a, b) => b.count - a.count);
    const alphabeticalByUrl = new Map();
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 950 });
      await page.evaluate(() => sessionStorage.clear());
      for (const [index, { name, url, level }] of targets.entries()) {
        await page.goto(url);
        const sort = page.getByLabel('Sort by:', { exact: true });
        assert.equal(await sort.inputValue(), 'alphabetical');
        const original = await readItems(page);
        assert(original.length > 0);
        alphabeticalByUrl.set(url, original);
        await sort.focus();
        await sort.press('ArrowDown');
        await sort.press('Tab');
        assert.equal(await sort.inputValue(), 'posts');
        assert.deepEqual(await readItems(page), mostPosts(original));
        assert.equal(await page.evaluate(k => sessionStorage.getItem(k), key), 'posts');
        await page.locator('[data-ebd-description-mode] input[value="always"]').check();
        assert(await page.locator('.ebd-item-description').first().isVisible());
        await page.screenshot({ path: path.join(output, `${name}-posts-${width}.png`), fullPage: true });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
        await page.locator('[data-ebd-browse-list] a').first().click();
        if (level === 'topics') {
          assert.equal(await page.locator('[data-ebd-browse-sort]').count(), 0);
          assert.equal(await page.locator('[data-ebd-results]').count(), 1);
        } else {
          await sort.waitFor({ state: 'visible' });
          assert.equal(await sort.inputValue(), 'posts', 'Preference must carry down the browse hierarchy.');
          const childItems = await readItems(page);
          assert.deepEqual(childItems, mostPosts(childItems));
        }
        await page.goBack();
        await sort.waitFor({ state: 'visible' });
        assert.equal(await sort.inputValue(), 'posts');
        assert.deepEqual(await readItems(page), mostPosts(original));
        await page.reload();
        assert.equal(await sort.inputValue(), 'posts');
        assert.deepEqual(await readItems(page), mostPosts(original));
        await page.goto(targets[(index + 1) % targets.length].url);
        assert.equal(await sort.inputValue(), 'posts', 'Preference must carry across browse paths and levels.');
        await sort.selectOption('alphabetical');
        await page.goto(url);
        assert.equal(await sort.inputValue(), 'alphabetical');
        assert.deepEqual(await readItems(page), original);
        await page.screenshot({ path: path.join(output, `${name}-alphabetical-${width}.png`), fullPage: true });
      }
    }
    await page.getByLabel('Sort by:', { exact: true }).selectOption('posts');
    const fresh = await browser.newContext();
    const freshPage = await fresh.newPage();
    for (const { url } of targets) {
      await freshPage.goto(url);
      assert.equal(await freshPage.getByLabel('Sort by:', { exact: true }).inputValue(), 'alphabetical');
      assert.deepEqual(await readItems(freshPage), alphabeticalByUrl.get(url));
    }
    await fresh.close();

    const plain = await browser.newContext({ javaScriptEnabled: false });
    const plainPage = await plain.newPage();
    for (const { url, level } of targets) {
      await plainPage.goto(url);
      assert.deepEqual(await readItems(plainPage), alphabeticalByUrl.get(url));
      assert(!(await plainPage.locator('[data-ebd-browse-sort-control]').isVisible()));
      await plainPage.locator('[data-ebd-browse-list] a').first().click();
      assert.equal(await plainPage.locator(level === 'topics' ? '[data-ebd-results]' : '[data-ebd-browse-list]').count(), 1);
    }
    await plain.close();

    const blocked = await browser.newContext();
    await blocked.addInitScript(() => Object.defineProperty(window, 'sessionStorage', { get() { throw Error('Storage disabled'); } }));
    const blockedPage = await blocked.newPage();
    blockedPage.on('pageerror', error => errors.push(error.message));
    for (const { url } of targets) {
      await blockedPage.goto(url);
      await blockedPage.getByLabel('Sort by:', { exact: true }).selectOption('posts');
      assert.deepEqual(await readItems(blockedPage), mostPosts(alphabeticalByUrl.get(url)));
    }
    await blocked.close();
    assert.equal(apiCalls, 0);
    assert.deepEqual(errors, []);
    console.log('Browse sorting: subject areas, categories and topics in both paths; defaults, counts, ties, shared session preference, keyboard, mobile, no-JS and blocked storage passed. No API calls.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
