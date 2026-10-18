// Local regression only: all semantic-search responses are mocked; no paid calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../node_modules/playwright-core');

const base = 'http://localhost:8086';
const output = path.resolve(__dirname, '../../.tmp/ask-ai-retirement-ui');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const endpoint of [
      'ehrman-discovery/v1/interpret', 'ehrman-discovery/v1/refine',
      'ehrman-section-public/v1/search', 'ehrman-section-pilot/v1/search',
    ]) {
      const response = await context.request.post(`${base}/wp-json/${endpoint}`, { data: { question: '' } });
      assert.equal(response.status(), 404, `Retired route remains available: ${endpoint}`);
    }
    let calls = 0;
    let fail = false;
    await page.route('**/wp-json/ehrman-discovery/v1/semantic-search', async route => {
      calls++;
      assert.deepEqual(Object.keys(route.request().postDataJSON()), ['question']);
      const posts = [{ id: '1', wpId: '13211', title: 'The Preaching of Jesus in a Nutshell',
        url: 'https://ehrmanblog.org/the-preaching-of-jesus-in-a-nutshell/',
        description: 'Discusses the teaching of Jesus.', relevance_tier: 'related', published_at: '2016-01-01' }];
      await route.fulfill(fail ? { status: 503, json: { message: 'Test: search temporarily unavailable.' } } : {
        json: { request_id: 'test-retirement', refinement_id: 'test-review', semantic: true, refined: true,
          question: route.request().postDataJSON().question, posts, count: 1, original_count: 100,
          candidate_count: 100, page: 1, per_page: 25, total_pages: 1, terms: [], sort: 'ranked', notice: '',
          broader: { posts, count: 1, page: 1, per_page: 25, total_pages: 1, terms: [], sort: 'ranked' } },
      });
    });
    for (const slug of ['ask-ai', 'ask-ai-1', 'ask-a-question', 'ask-ai-3']) {
      const response = await context.request.get(`${base}/${slug}/?ebd_question=mustard%20seed&ebd_ai_request=old&ebd_keyword%5B%5D=Paul`, { maxRedirects: 0 });
      assert.equal(response.status(), 302);
      const target = new URL(response.headers().location);
      assert.equal(target.pathname, '/ask-ai-2/');
      assert.deepEqual([...target.searchParams], [['ebd_question', 'mustard seed']]);
    }
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 950 });
      await page.goto(base);
      assert.deepEqual(await page.locator('nav.ehrman-navigation a').allTextContents(),
        ['Home', 'Browse Topics 1', 'Browse Topics 2', 'Keyword Search', 'Ask AI 2', 'Reviewer Tools']);
      assert(!/Ask AI 1|Ask AI 3|two alternative AI search methods/i.test(await page.locator('main').innerText()));
      assert(await page.locator('.ehrman-ai-selected-figure img').evaluate(image => image.complete && image.naturalWidth > 0));
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.screenshot({ path: path.join(output, `home-${width}.png`), fullPage: true });
      await page.locator('.ehrman-ai-selected-figure').screenshot({ path: path.join(output, `workflow-${width}.png`) });
      await page.goto(`${base}/ask-ai-2/`);
      assert.equal(await page.locator('[data-ebd-question-interpret]').count(), 0);
      await page.locator('[data-ebd-semantic-question]').fill('mustard seed');
      await page.locator('[data-ebd-semantic-submit]').click();
      await page.locator('[data-ebd-results]').getByRole('link', { name: 'The Preaching of Jesus in a Nutshell' }).waitFor();
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.screenshot({ path: path.join(output, `results-${width}.png`), fullPage: true });
      fail = true;
      await page.locator('[data-ebd-semantic-submit]').click();
      await page.getByText('Test: search temporarily unavailable.', { exact: true }).waitFor();
      assert(await page.locator('[data-ebd-semantic-submit]').isEnabled());
      fail = false;
    }
    await page.goto(`${base}/wp-content/themes/ehrman-discovery-demo/assets/images/ehrman-ai-2-workflow.svg`);
    const boxes = await page.locator('svg text.label').evaluateAll(labels => labels.map(label => {
      const b = label.getBBox();
      const rect = [...document.querySelectorAll('rect')].find(r => b.y >= Number(r.getAttribute('y')) && b.y < Number(r.getAttribute('y')) + Number(r.getAttribute('height')));
      return { text: label.textContent, fits: !!rect && b.x >= 50 && b.x + b.width <= 710 && b.y + b.height <= Number(rect.getAttribute('y')) + 110 };
    }));
    assert.equal(boxes.length, 6);
    assert(boxes.every(box => box.fits), JSON.stringify(boxes));
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], mockedCalls: calls, paidCalls: 0, boxes }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
