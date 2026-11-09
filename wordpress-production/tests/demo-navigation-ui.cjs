// Read-only checks: no search submissions, editor actions or paid API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor } = require('./browser-config.cjs');
const output = outputFor('demo-navigation-ui');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const errors = [];
  try {
    for (const width of [1440, 1024, 390, 320]) {
      const context = await browser.newContext({ viewport: { width, height: 950 } });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await context.route('**/wp-json/**', route => route.abort());
      await page.goto(base);
      const menu = page.locator('.ehrman-search-menu');
      const search = menu.locator(':scope > summary');
      const browse = page.locator('.ehrman-browse-menu > summary');
      assert.deepEqual(await page.locator('nav .is-disabled').allTextContents(),
        ['Join!', 'Recent Posts', 'Forum', 'About Blog', 'About Bart', 'Help']);
      assert.equal(await page.locator('header input[type="search"], .ehrman-site-search').count(), 0);
      assert.equal(await page.locator('nav a[href*="structure-review"]').count(), 0);
      assert.equal(await page.locator('.ehrman-reviewer-note a').innerText(), 'Reviewer Tools');
      assert.equal(await menu.getAttribute('open'), null);
      for (const item of await page.locator('nav .is-disabled').all()) {
        assert.equal(await item.getAttribute('aria-disabled'), 'true');
        assert.equal(await item.getAttribute('href'), null);
        // Deliberately click the disabled placeholder to verify it cannot navigate.
        await item.click({ force: true });
        assert.equal(page.url(), `${base}/`);
      }
      await search.focus();
      await page.keyboard.press('Enter');
      assert(await page.getByRole('link', { name: 'Ask AI', exact: true }).isVisible());
      assert.deepEqual(await page.locator('.ehrman-search-options > li').evaluateAll(items => items.map(item =>
        (item.querySelector('summary') || item.querySelector('a')).textContent.trim())),
      ['Browse Topics', 'Keyword Search', 'Ask AI']);
      await browse.click();
      for (const [label, slug] of [['Browse Topics 1', 'browse-topics-1'], ['Browse Topics 2', 'browse-topics-2'], ['Keyword Search', 'keyword-search'], ['Ask AI', 'ask-ai']]) {
        const link = page.getByRole('link', { name: label, exact: true });
        assert.equal(new URL(await link.getAttribute('href')).pathname, `/${slug}/`);
        const box = await link.boundingBox();
        assert(box && box.x >= 0 && box.x + box.width <= width && box.height >= 44);
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.screenshot({ path: path.join(output, `menu-${width}.png`) });
      await page.getByRole('link', { name: 'Browse Topics 1', exact: true }).focus();
      await page.keyboard.press('Escape');
      assert.equal(await page.locator('.ehrman-browse-menu').getAttribute('open'), null);
      assert(await browse.evaluate(el => el === document.activeElement));
      await page.keyboard.press('Escape');
      assert.equal(await menu.getAttribute('open'), null);
      assert(await search.evaluate(el => el === document.activeElement));
      await search.click();
      await page.locator('.ehrman-brand').focus();
      assert.equal(await menu.getAttribute('open'), null);
      await search.click();
      await page.locator('.ehrman-tagline').click();
      assert.equal(await menu.getAttribute('open'), null);
      for (const [label, slug] of [['Browse Topics 1', 'browse-topics-1'], ['Browse Topics 2', 'browse-topics-2'], ['Keyword Search', 'keyword-search'], ['Ask AI', 'ask-ai']]) {
        await search.click();
        if (label.startsWith('Browse')) await browse.click();
        await page.getByRole('link', { name: label, exact: true }).click();
        await page.waitForURL(`**/${slug}/`, { waitUntil: 'load' });
        assert.equal(new URL(page.url()).pathname, `/${slug}/`);
        assert.equal(await page.locator('h1').innerText(), label);
        assert.equal((await page.locator('nav a[aria-current="page"]').textContent()).trim(), label);
      }
      await page.locator('.ehrman-brand').click();
      await page.locator('.ehrman-reviewer-note a').click();
      assert.equal(new URL(page.url()).pathname, '/structure-review/');
      await context.close();
    }
    const plain = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 950 } });
    const page = await plain.newPage();
    await page.goto(base);
    await page.locator('.ehrman-search-menu > summary').click();
    await page.locator('.ehrman-browse-menu > summary').click();
    await page.getByRole('link', { name: 'Browse Topics 1', exact: true }).click();
    assert.equal(await page.locator('h1').innerText(), 'Browse Topics 1');
    await plain.close();
    assert.deepEqual(errors, []);
    console.log('Demo navigation passed: inactive site links, Search hierarchy, keyboard dismissal, outside click, page links, reviewer body link, desktop/mobile, no-JS, no paid calls.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
