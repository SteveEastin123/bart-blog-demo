// Public frontend checks. All AI, feedback and status responses are mocked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright-core');
const { PNG } = require('../../node_modules/playwright-core/lib/utilsBundle');

const base = 'http://localhost:8086';
const output = path.resolve(__dirname, '../../.tmp/frontend-assets-ui');
const capture = process.argv.includes('--capture-before');
const compare = process.argv.includes('--compare-before');
const reviewerExtraction = process.argv.includes('--reviewer-extraction');
const productionOnly = process.argv.includes('--production-only');
const baselineAssets = process.argv.find(arg => arg.startsWith('--baseline-assets='))?.split('=')[1];
assert(!(capture && compare));
assert(!baselineAssets || capture, 'Original-asset overrides are only allowed when capturing a baseline.');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const errors = [];
  const snapshots = [];
  const pixelDifferences = [];
  const baseline = compare ? JSON.parse(fs.readFileSync(path.join(output, 'before.json'))) : null;
  let aiCalls = 0;
  let feedbackCalls = 0;
  let feedbackFails = true;
  try {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    await context.addInitScript(() => {
      const scrollIntoView = Element.prototype.scrollIntoView;
      Element.prototype.scrollIntoView = function (options) {
        return scrollIntoView.call(this, typeof options === 'object' ? { ...options, behavior: 'instant' } : options);
      };
    });
    if (baselineAssets) {
      await context.route(/\/assets\/(js|css)\/discovery\.(js|css)(\?.*)?$/, route => {
        const type = new URL(route.request().url()).pathname.endsWith('.css') ? 'css' : 'js';
        return route.fulfill({
          contentType: type === 'css' ? 'text/css' : 'text/javascript',
          body: fs.readFileSync(path.resolve(baselineAssets, `original.${type}`)),
        });
      });
    }
    const candidates = Array.from({ length: 30 }, (_, i) => ({
      id: i + 1, title: `Candidate ${i + 1}`, url: `https://example.invalid/posts/${i + 1}`,
      published_at: `2026-09-${String(i + 1).padStart(2, '0')}`,
      date_text: 'September 2026', author: 'Test author', description: `Description for candidate ${i + 1}.`,
    }));
    await context.route('**/wp-json/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/semantic-search')) {
        aiCalls++;
        assert.deepEqual(route.request().postDataJSON(), { question: 'Compare Paul and Jesus' });
        const posts = candidates.slice(0, 3).map((post, i) => ({ ...post, relevance_tier: ['direct', 'related', 'background'][i] }));
        await route.fulfill({ json: {
          request_id: 'frontend-test', semantic: true, refined: true, posts, count: 3,
          original_count: 30, terms: [], sort: 'ranked', page: 1, total_pages: 1,
          broader: { posts: candidates, count: 30, per_page: 25, page: 1, total_pages: 2, semantic: true },
        } });
      } else if (url.pathname.endsWith('/feedback')) {
        feedbackCalls++;
        assert.deepEqual(route.request().postDataJSON(), { request_id: 'frontend-test', helpful: true });
        await route.fulfill({ status: feedbackFails ? 503 : 200, json: { success: !feedbackFails } });
      } else if (url.pathname.endsWith('/status')) {
        await route.fulfill({ json: { database_connected: true, import_state: 'complete' } });
      } else {
        await route.abort();
        errors.push(`Unexpected API request: ${url.pathname}`);
      }
    });
    const page = await context.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${base}/ask-ai/`);
    if (productionOnly) {
      assert.equal(await page.getByRole('link', { name: 'Reviewer Tools', exact: true }).count(), 0);
      assert.equal(await page.locator('[src*="ehrman-reviewer-tools"],[href*="ehrman-reviewer-tools"]').count(), 0);
    }
    const stylesheet = await page.evaluate(() => [...document.styleSheets]
      .filter(sheet => sheet.href?.includes('/assets/css/discovery.css'))
      .flatMap(sheet => [...sheet.cssRules].map(rule => rule.cssText)));
    assert(stylesheet.length > 100);
    if (compare) {
      if (reviewerExtraction) {
        const normalize = async rules => page.evaluate(input => {
          const sheet = new CSSStyleSheet();
          sheet.replaceSync(input.join('\n'));
          const keep = rules => [...rules].flatMap(rule => {
            if (rule.selectorText?.includes('.ebd-review')) return [];
            if (rule.cssRules) return [{ condition: rule.conditionText, rules: keep(rule.cssRules) }];
            return [rule.cssText];
          });
          return keep(sheet.cssRules);
        }, rules);
        assert.deepEqual(await normalize(stylesheet), await normalize(baseline.stylesheet), 'Non-review CSS rules changed');
        assert(!stylesheet.some(rule => rule.includes('.ebd-review')), 'Reviewer CSS remains in production.');
      } else assert.deepEqual(stylesheet, baseline.stylesheet, 'CSS rules or cascade order changed');
    }

    async function snapshot(name) {
      await page.evaluate(async () => {
        document.activeElement?.blur();
        await document.fonts.ready;
        // Cancel the result list's smooth scroll before comparing fixed viewport pixels.
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
        await new Promise(requestAnimationFrame);
        await new Promise(requestAnimationFrame);
      });
      assert.equal(await page.evaluate(() => scrollY), 0);
      const root = page.locator('.ebd-discovery');
      const dom = await root.evaluate(element => element.outerHTML.replace(/\s+/g, ' '));
      const bounds = await root.boundingBox();
      const png = await page.screenshot({ path: path.join(output, `${name}-${capture ? 'before' : 'after'}.png`), animations: 'disabled' });
      snapshots.push({ name, dom });
      if (!compare) return;
      assert.equal(dom, baseline.snapshots.find(item => item.name === name)?.dom, `${name}: DOM changed`);
      const old = PNG.sync.read(fs.readFileSync(path.join(output, `${name}-before.png`)));
      const current = PNG.sync.read(png);
      assert.equal(current.width, old.width);
      assert.equal(current.height, old.height);
      let changed = 0;
      let pixels = 0;
      for (let y = Math.max(0, Math.floor(bounds.y)); y < Math.min(current.height, Math.ceil(bounds.y + bounds.height)); y++) {
        for (let x = Math.max(0, Math.floor(bounds.x)); x < Math.min(current.width, Math.ceil(bounds.x + bounds.width)); x++) {
          const offset = (y * current.width + x) * 4;
          if (!current.data.subarray(offset, offset + 4).equals(old.data.subarray(offset, offset + 4))) changed++;
          pixels++;
        }
      }
      assert(pixels > 0);
      assert(changed / pixels <= 0.001, `${name}: changed ${changed} of ${pixels} pixels`);
      pixelDifferences.push({ name, changed, pixels });
    }

    async function download(kind, name) {
      const pending = page.waitForEvent('download');
      await page.locator(`[data-ebd-review-${kind}]`).click();
      const file = await pending;
      const bytes = fs.readFileSync(await file.path());
      assert(file.suggestedFilename().endsWith(`.${kind}`));
      assert(bytes.length > 100);
      if (kind === 'pdf') assert(bytes.toString().startsWith('%PDF-1.4'));
      else assert(bytes.toString().includes('Topic Description'));
      const before = path.join(output, `${name}-before.${kind}`);
      if (capture) fs.writeFileSync(before, bytes);
      if (compare) assert.deepEqual(bytes, fs.readFileSync(before), `${name}: ${kind} export changed`);
    }

    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 950 });
      await page.goto(`${base}/ask-ai/`);
      await page.evaluate(() => sessionStorage.clear());
      await page.reload();
      await snapshot(`ask-empty-${width}`);
      await page.locator('[data-ebd-semantic-question]').fill('Compare Paul and Jesus');
      await page.locator('[data-ebd-semantic-question]').press('Enter');
      await page.locator('[data-ebd-show-broader]').waitFor();
      assert.equal(await page.locator('.ebd-relevance-group').count(), 3);
      await snapshot(`ask-refined-${width}`);
      feedbackFails = true;
      await page.locator('[data-ebd-feedback-value="yes"]').click();
      await page.getByText('The feedback could not be saved. Please try again.', { exact: true }).waitFor();
      assert(await page.locator('[data-ebd-feedback-value="yes"]').isEnabled());
      feedbackFails = false;
      await page.locator('[data-ebd-feedback-value="yes"]').click();
      await page.locator('[data-ebd-ai-feedback][data-submitted="true"]').waitFor();
      await page.locator('[data-ebd-show-broader]').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 25);
      await snapshot(`ask-broader-${width}`);
      await page.locator('.ebd-pagination-next[href]').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 5);
      await page.locator('[data-ebd-show-refined]').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 3);
      await page.locator('[data-ebd-description-mode] input[value="hover"]').check();
      await page.locator('.ebd-post-title').first().focus();
      assert(await page.locator('.ebd-tooltip').isVisible());
      await page.locator('[data-ebd-description-mode] input[value="hidden"]').check();
      assert(!(await page.locator('.ebd-tooltip').isVisible()));

      const reviewViews = productionOnly ? [] : [['browse-1', '?ebd_path=1'], ['browse-2', '?ebd_path=2'], ['categories', '?ebd_view=categories'], ['topics', '?ebd_view=topics']];
      for (const [name, query] of reviewViews) {
        await page.goto(`${base}/structure-review/${query}`);
        await page.evaluate(() => sessionStorage.clear());
        await page.reload();
        const details = page.locator('[data-ebd-review-tree] details');
        if (name === 'topics') {
          const total = await page.locator('[data-ebd-review-topic-item]').count();
          await page.locator('[data-ebd-review-topic-search]').fill('Paul');
          const visible = await page.locator('[data-ebd-review-topic-item]:visible').count();
          assert(visible > 0 && visible < total);
          await snapshot(`review-${name}-${width}`);
          await page.locator('[data-ebd-review-topic-clear]').click();
          assert.equal(await page.locator('[data-ebd-review-topic-item]:visible').count(), total);
        } else {
          await page.locator('[data-ebd-review-collapse]').click();
          assert(await details.evaluateAll(items => items.every(item => !item.open)));
          await snapshot(`review-${name}-${width}`);
          await page.locator('[data-ebd-review-expand]').click();
          assert(await details.evaluateAll(items => items.every(item => item.open)));
        }
        await page.locator('[data-ebd-description-mode] input[value="hover"]').check();
        await page.locator('.ebd-review-name > span:last-child').first().hover();
        assert(await page.locator('.ebd-tooltip').isVisible());
        await page.locator('[data-ebd-description-mode] input[value="hidden"]').check();
        assert(!(await page.locator('.ebd-tooltip').isVisible()));
        await page.locator('[data-ebd-description-mode] input[value="always"]').check();
        assert(await page.locator('.ebd-review-description').first().isVisible());
        if (width === 1440) {
          await download('csv', name);
          await download('pdf', name);
        }
      }
    }
    // Verify the separately extracted status control, without changing server data.
    await page.goto(`${base}/ask-ai/`);
    await page.route('**/ask-ai/?frontend-status-test', async route => {
      const response = await route.fetch();
      const html = (await response.text()).replace('<main ', '<button data-ebd-status-refresh>Refresh</button><p data-ebd-status-message></p><main ');
      await route.fulfill({ response, body: html });
    });
    await page.goto(`${base}/ask-ai/?frontend-status-test`);
    await page.locator('[data-ebd-status-refresh]').click();
    await page.getByText('WordPress is connected to MySQL. Import state: complete.', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    assert.equal(aiCalls, 2);
    assert.equal(feedbackCalls, 4);
    if (capture) fs.writeFileSync(path.join(output, 'before.json'), JSON.stringify({ stylesheet, snapshots }));
    console.log(JSON.stringify({ status: 'passed', snapshots: snapshots.length, cssRules: stylesheet.length, pixelDifferences, mockedAiCalls: aiCalls, paidCalls: 0 }));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
