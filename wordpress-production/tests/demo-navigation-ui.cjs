// Read-only checks: no search submissions, editor actions or paid API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor } = require('./browser-config.cjs');
const output = outputFor('demo-navigation-ui');
const choices = [['Browse Topics', 'browse-topics'], ['Keyword Search', 'keyword-search'], ['Ask AI', 'ask-ai']];

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const errors = [];
  try {
    for (const width of [1920, 1440, 1024, 390, 320]) {
      const context = await browser.newContext({ viewport: { width, height: 950 } });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await context.route('**/wp-json/**', route => route.abort());
      await page.goto(base);
      const search = page.getByRole('navigation', { name: 'Search options', exact: true });
      assert.deepEqual(await page.locator('nav .is-disabled').allTextContents(),
        ['Join!', 'Recent Posts', 'Forum', 'About Blog', 'About Bart', 'Help']);
      assert.equal(await page.locator('header input[type="search"], .ehrman-site-search').count(), 0);
      assert.equal(await page.locator('header details, header summary, [data-ehrman-search-menu]').count(), 0);
      assert.equal(await page.locator('script[src*="/ehrman-discovery-demo/assets/js/navigation.js"]').count(), 0);
      assert.equal(await page.locator('nav a[href*="structure-review"]').count(), 0);
      assert.equal(await page.locator('.ehrman-reviewer-note a').innerText(), 'Reviewer Tools');
      for (const item of await page.locator('nav .is-disabled').all()) {
        assert.equal(await item.getAttribute('aria-disabled'), 'true');
        assert.equal(await item.getAttribute('href'), null);
        // Deliberately click the disabled placeholder to verify it cannot navigate.
        await item.click({ force: true });
        assert.equal(page.url(), `${base}/`);
      }
      assert.deepEqual(await search.getByRole('link').allTextContents(), choices.map(([label]) => label));
      const searchBox = await search.boundingBox();
      const heroBox = await page.locator('.ehrman-hero').boundingBox();
      assert(searchBox.y + searchBox.height <= heroBox.y, 'Search links are not above the photo.');
      const boxes = [];
      for (const [label, slug] of choices) {
        const link = search.getByRole('link', { name: label, exact: true });
        assert.equal(new URL(await link.getAttribute('href')).pathname, `/${slug}/`);
        const box = await link.boundingBox();
        assert(box && box.x >= 0 && box.x + box.width <= width && box.height >= 44 && box.y + box.height < 950);
        assert(await link.evaluate(element => element.scrollWidth <= element.clientWidth && element.scrollHeight <= element.clientHeight), 'Search label overflows.');
        boxes.push(box);
      }
      for (let i = 1; i < boxes.length; i++) {
        assert(Math.abs(boxes[i].width - boxes[0].width) <= 1, 'Search links have inconsistent widths.');
        if (width > 640) assert.equal(boxes[i].y, boxes[0].y, 'Desktop search links are not in one row.');
        else assert(boxes[i].y >= boxes[i - 1].y + boxes[i - 1].height, 'Mobile search links overlap.');
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.waitForFunction(() => [...document.querySelectorAll('main img')].every(image => image.complete && image.naturalWidth > 0));
      await page.screenshot({ path: path.join(output, `home-${width}.png`) });
      await search.getByRole('link').first().focus();
      for (const [label] of choices.slice(1)) {
        await page.keyboard.press('Tab');
        assert(await search.getByRole('link', { name: label, exact: true }).evaluate(el => el === document.activeElement));
      }
      assert(await search.getByRole('link').last().evaluate(el => parseFloat(getComputedStyle(el).outlineWidth) >= 2));
      await page.keyboard.press('Enter');
      await page.waitForURL('**/ask-ai/');
      await page.locator('.ehrman-brand').click();
      for (const [label, slug] of choices) {
        await search.getByRole('link', { name: label, exact: true }).click();
        await page.waitForURL(`**/${slug}/`, { waitUntil: 'load' });
        assert.equal(new URL(page.url()).pathname, `/${slug}/`);
        assert.equal(await page.locator('h1').innerText(), label);
        assert.equal(await page.locator('header details, header summary, .ehrman-home-search').count(), 0);
        await page.locator('.ehrman-brand').click();
        await page.waitForURL(`${base}/`);
      }
      await page.locator('.ehrman-reviewer-note a').click();
      assert.equal(new URL(page.url()).pathname, '/structure-review/');
      await context.close();
    }
    const plain = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 950 } });
    const page = await plain.newPage();
    await page.goto(base);
    for (const [label, slug] of choices) {
      await page.getByRole('navigation', { name: 'Search options', exact: true }).getByRole('link', { name: label, exact: true }).click();
      assert.equal(new URL(page.url()).pathname, `/${slug}/`);
      assert.equal(await page.locator('h1').innerText(), label);
      await page.locator('.ehrman-brand').click();
    }
    await plain.close();
    assert.deepEqual(errors, []);
    console.log('Demo navigation passed: home-page search links, removed dropdown/script, keyboard navigation, canonical page links, brand return path, reviewer body link, desktop/mobile, no-JS, no paid calls.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
