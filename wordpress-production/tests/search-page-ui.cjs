// Read-only local search controls. Provider endpoints are blocked, never called.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');
// Use the PNG reader bundled with the existing browser-test dependency.
const { PNG } = require('../../node_modules/playwright-core/lib/utilsBundle');

const base = 'http://localhost:8086';
const output = path.resolve(__dirname, '../../.tmp/search-page-ui');
const capture = process.argv.includes('--capture-before');
const compare = process.argv.includes('--compare-before');
assert(!(capture && compare), 'Choose capture or comparison, not both.');
const beforeFile = path.join(output, 'before.json');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  let blockedCalls = 0;
  const errors = [];
  const snapshots = [];
  const pixelDifferences = [];
  const expected = compare ? JSON.parse(fs.readFileSync(beforeFile, 'utf8')) : [];
  try {
    const context = await browser.newContext();
    await context.route('**/wp-json/**', async route => {
      const url = new URL(route.request().url());
      if (route.request().method() === 'GET' && /\/ehrman-discovery\/v1\/(search|suggestions)$/.test(url.pathname)) {
        await route.continue();
      } else {
        blockedCalls++;
        await route.abort();
      }
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const targets = [
      { name: 'keyword-empty', url: base + '/keyword-search/', expand: false },
      { name: 'keyword-page-two', url: base + '/keyword-search/?ebd_keyword%5B%5D=Textual%20Variants&ebd_term_mode%5B%5D=topic&ebd_page=2', expand: true },
      { name: 'keyword-no-results', url: base + '/keyword-search/?ebd_keyword%5B%5D=zz-no-match-fixture-2026', expand: true },
      { name: 'keyword-four', url: base + '/keyword-search/?ebd_keyword%5B%5D=Paul&ebd_keyword%5B%5D=Luke&ebd_keyword%5B%5D=Jesus&ebd_keyword%5B%5D=Atonement', expand: true },
    ];
    for (const number of [1, 2]) {
      await page.goto(`${base}/browse-topics-${number}/`);
      await page.locator('main a[href*="ebd_subject="]').first().click();
      await page.locator('main a[href*="ebd_category="]').first().click();
      targets.push({ name: `browse-${number}-category`, url: await page.locator('main a[href*="ebd_view=posts"]').getAttribute('href'), expand: true });
      targets.push({ name: `browse-${number}-topic`, url: await page.locator('main a[href*="ebd_topic="]').first().getAttribute('href'), expand: true });
    }
    const expand = async () => {
      if (!(await page.locator('[data-ebd-search-expanded]').isVisible())) {
        await page.locator('[data-ebd-search-edit]').click();
      }
    };
    const settled = () => page.waitForFunction(() => !document.querySelector('[data-ebd-results]')?.hasAttribute('aria-busy'));
    const dismissSuggestions = async () => {
      await page.waitForLoadState('networkidle');
      await page.locator('.ebd-search-instructions').click();
    };
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 950 });
      for (const target of targets) {
        await page.goto(target.url);
        if (target.expand) await expand();
        await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
        await page.evaluate(() => document.fonts.ready);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), `${target.name} overflows at ${width}px`);
        const snapshot = await page.locator('.ebd-discovery').evaluate(root => {
          const clean = node => {
            if (node.nodeType === Node.TEXT_NODE) {
              const text = node.textContent.replace(/\s+/g, ' ').trim();
              return text || null;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return null;
            return [node.tagName, [...node.attributes].map(a => [a.name, a.value]).sort(),
              [...node.childNodes].map(clean).filter(item => item !== null)];
          };
          return clean(root);
        });
        const name = `${target.name}-${width}`;
        snapshots.push({ name, dom: snapshot });
        const bounds = await page.locator('.ebd-discovery').boundingBox();
        const png = await page.screenshot({ path: path.join(output, `${name}-${capture ? 'before' : 'after'}.png`), fullPage: true, animations: 'disabled' });
        if (compare) {
          assert.deepEqual(snapshot, expected.find(row => row.name === name)?.dom, `${name}: DOM changed`);
          const oldImage = PNG.sync.read(fs.readFileSync(path.join(output, `${name}-before.png`)));
          const newImage = PNG.sync.read(png);
          assert.equal(newImage.width, oldImage.width, `${name}: width changed`);
          assert.equal(newImage.height, oldImage.height, `${name}: height changed`);
          let changed = 0;
          let compared = 0;
          // Compare the plugin, excluding the unchanged theme's resampled portrait.
          for (let y = Math.floor(bounds.y); y < Math.ceil(bounds.y + bounds.height); y++) {
            for (let x = Math.floor(bounds.x); x < Math.ceil(bounds.x + bounds.width); x++) {
              const i = (y * newImage.width + x) * 4;
              if (!newImage.data.subarray(i, i + 4).equals(oldImage.data.subarray(i, i + 4))) changed++;
              compared++;
            }
          }
          const ratio = changed / compared;
          // Separate browser sessions can rasterize a few glyph-edge pixels differently.
          // Require exact DOM and image dimensions plus at least 99.9% identical pixels.
          assert(ratio <= 0.001, `${name}: screenshot changed (${changed} pixels, ${(ratio * 100).toFixed(4)}%)`);
          pixelDifferences.push({ name, changed, percent: ratio * 100 });
        }
      }
    }
    if (capture) {
      fs.writeFileSync(beforeFile, JSON.stringify(snapshots));
    } else {
      // Exercise category keyboard selection, suggestion selection, sorting and clearing.
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 950 });
        await page.goto(base + '/keyword-search/');
        await page.locator('[data-ebd-category-toggle]').press('ArrowDown');
        assert(await page.locator('[data-ebd-category-options]').isVisible());
        await page.locator('[data-ebd-category-option]').first().press('ArrowDown');
        const categoryResponse = page.waitForResponse(r => r.url().includes('/v1/search?') && r.ok());
        await page.locator('[data-ebd-category-option]').nth(1).press('Enter');
        await categoryResponse;
        await settled();
        assert.notEqual(await page.locator('[data-ebd-category]').inputValue(), '');
        await expand();
        await page.locator('.ebd-keyword-input').click();
        await page.locator('.ebd-suggestion').first().waitFor();
        await page.locator('input[name="ebd_suggestion_order"][value="topics-first"]').check();
        const termResponse = page.waitForResponse(r => r.url().includes('/v1/search?') && r.ok());
        await page.locator('.ebd-keyword-input').press('ArrowDown');
        await page.locator('.ebd-keyword-input').press('Enter');
        await termResponse;
        await settled();
        assert.equal(await page.locator('.ebd-keyword-chip').count(), 1);
        await expand();
        const removeResponse = page.waitForResponse(r => r.url().includes('/v1/search?') && r.ok());
        await page.locator('[data-ebd-remove]').click();
        await removeResponse;
        await settled();
        assert.equal(await page.locator('.ebd-keyword-chip').count(), 0);
        await expand();
        await dismissSuggestions();
        await page.locator('[data-ebd-clear]').click();
        await settled();
        assert.equal(await page.locator('[data-ebd-category]').inputValue(), '');
        for (const target of [targets[1], ...targets.slice(4)]) {
          await page.goto(target.url);
          await expand();
          await dismissSuggestions();
          const sorted = page.waitForResponse(r => r.url().includes('/v1/search?') && r.ok());
          await page.locator('input[name="ebd_sort"][value="newest"]').check();
          await sorted;
          await settled();
          assert(await page.locator('.ebd-post-item').count() > 0);
          await expand();
          await dismissSuggestions();
          await page.locator('[data-ebd-search-collapse]').click();
          assert(!(await page.locator('[data-ebd-search-expanded]').isVisible()));
          await expand();
          await dismissSuggestions();
          await page.locator('[data-ebd-description-mode] input[value="hidden"]').press('Space');
          assert(!(await page.locator('.ebd-post-description').first().isVisible()));
          await page.locator('[data-ebd-description-mode] input[value="always"]').press('Space');
          assert(await page.locator('.ebd-post-description').first().isVisible());
        }
        await page.goto(targets[1].url);
        await page.locator('a.ebd-pagination-next').click();
        await page.waitForURL(url => url.searchParams.get('ebd_page') === '3');
        assert((await page.locator('.ebd-pagination-status').innerText()).startsWith('Page 3 of'));
        await page.locator('[data-ebd-back-to-top]').click();
      }
      // Server-rendered links also work without JavaScript.
      const plain = await browser.newContext({ javaScriptEnabled: false });
      const plainPage = await plain.newPage();
      await plainPage.goto(targets[1].url);
      await plainPage.locator('a.ebd-pagination-next').click();
      assert((await plainPage.locator('.ebd-pagination-status').innerText()).startsWith('Page 3 of'));
      await plain.close();
    }
    assert.deepEqual(errors, []);
    assert.equal(blockedCalls, 0, 'Unexpected API request attempted.');
    console.log(JSON.stringify({ status: 'passed', mode: capture ? 'baseline' : 'verification', snapshots: snapshots.length,
      comparisons: compare ? snapshots.length : 0, pixelDifferences, viewports: [1440, 1024, 390], paidCalls: 0 }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
