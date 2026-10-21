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
    let feedbackCalls = 0;
    const candidates = Array.from({ length: 30 }, (_, i) => ({
      id: String(i + 1), wpId: String(13211 + i),
      title: i === 0 ? 'The Preaching of Jesus in a Nutshell' : `Test candidate ${i + 1}`,
      url: `https://ehrmanblog.org/test-candidate-${i + 1}/`,
      description: 'Discusses the teaching of Jesus.',
      published_at: `2016-01-${String(i + 1).padStart(2, '0')}`,
    }));
    await page.route('**/wp-json/ehrman-discovery/v1/feedback', async route => {
      feedbackCalls++;
      assert.deepEqual(route.request().postDataJSON(), { request_id: 'test-retirement', helpful: true });
      await route.fulfill({ json: { success: true } });
    });
    await page.route('**/wp-json/ehrman-discovery/v1/semantic-search', async route => {
      calls++;
      assert.deepEqual(Object.keys(route.request().postDataJSON()), ['question']);
      const posts = candidates.slice(0, 2).map(post => ({ ...post, relevance_tier: 'related' }));
      await route.fulfill(fail ? { status: 503, json: { message: 'Test: search temporarily unavailable.' } } : {
        json: { request_id: 'test-retirement', refinement_id: 'test-review', semantic: true, refined: true,
          question: route.request().postDataJSON().question, posts, count: 2, original_count: 30,
          candidate_count: 30, page: 1, per_page: 2, total_pages: 1, terms: [], sort: 'ranked', notice: '',
          broader: { posts: candidates, count: 30, page: 1, per_page: 25, total_pages: 2, terms: [], sort: 'ranked', semantic: true } },
      });
    });
    for (const slug of ['ask-ai-2', 'ask-ai-1', 'ask-a-question', 'ask-ai-3']) {
      const response = await context.request.get(`${base}/${slug}/?ebd_question=mustard%20seed&ebd_ai_request=old&ebd_keyword%5B%5D=Paul`, { maxRedirects: 0 });
      assert.equal(response.status(), 302);
      const target = new URL(response.headers().location);
      assert.equal(target.pathname, '/ask-ai/');
      assert.deepEqual([...target.searchParams], [['ebd_question', 'mustard seed']]);
    }
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 950 });
      await page.goto(base);
      assert.deepEqual(await page.locator('nav.ehrman-navigation a').allTextContents(),
        ['Home', 'Browse Topics 1', 'Browse Topics 2', 'Keyword Search', 'Ask AI', 'Reviewer Tools']);
      assert(!/Ask AI [123]|two alternative AI search methods/i.test(await page.locator('main').innerText()));
      assert.equal(new URL(await page.getByRole('link', { name: 'Ask AI', exact: true }).getAttribute('href')).pathname, '/ask-ai/');
      assert(await page.locator('.ehrman-ai-selected-figure img').evaluate(image => image.complete && image.naturalWidth > 0));
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.screenshot({ path: path.join(output, `home-${width}.png`), fullPage: true });
      await page.locator('.ehrman-ai-selected-figure').screenshot({ path: path.join(output, `workflow-${width}.png`) });
      const canonical = await page.goto(`${base}/ask-ai/`);
      assert.equal(canonical.status(), 200);
      assert.equal(canonical.request().redirectedFrom(), null, 'Canonical page redirected');
      assert.equal(await page.locator('h1').innerText(), 'Ask AI');
      assert(!/Ask AI [123]/i.test(await page.locator('body').innerText()));
      assert.equal(new URL(await page.locator('[data-ebd-semantic-form]').getAttribute('action')).pathname, '/ask-ai/');
      assert.equal(await page.locator('[data-ebd-question-interpret]').count(), 0);
      await page.locator('[data-ebd-semantic-question]').fill('mustard seed');
      await page.locator('[data-ebd-semantic-submit]').click();
      await page.locator('[data-ebd-results]').getByRole('link', { name: 'The Preaching of Jesus in a Nutshell' }).waitFor();
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
      await page.screenshot({ path: path.join(output, `results-${width}.png`), fullPage: true });
      const beforeControls = calls;
      await page.locator('[data-ebd-show-broader]').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 25);
      await page.locator('a.ebd-pagination-next').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 5);
      assert.equal(await page.locator('.ebd-post-title').first().textContent(), 'Test candidate 26');
      await page.locator('input[name="ebd_sort"][value="newest"]').check();
      assert.equal(await page.locator('.ebd-post-title').first().textContent(), 'Test candidate 30');
      await page.locator('[data-ebd-show-refined]').click();
      assert.equal(await page.locator('.ebd-post-item').count(), 2);
      assert.equal(await page.locator('.ebd-post-title').first().textContent(), 'Test candidate 2');
      await page.locator('input[name="ebd_sort"][value="ranked"]').check();
      assert.equal(await page.locator('.ebd-post-title').first().textContent(), candidates[0].title);
      await page.locator('[data-ebd-feedback-value="yes"]').click();
      await page.locator('[data-ebd-ai-feedback][data-submitted="true"]').waitFor();
      assert.equal(calls, beforeControls, 'Result controls must not rerun AI');
      fail = true;
      await page.locator('[data-ebd-semantic-submit]').click();
      await page.getByText('Test: search temporarily unavailable.', { exact: true }).waitFor();
      assert(await page.locator('[data-ebd-semantic-submit]').isEnabled());
      fail = false;
    }
    await page.locator('[data-ebd-semantic-clear]').click();
    await page.waitForURL(`${base}/ask-ai/`);
    assert.equal(await page.locator('[data-ebd-semantic-question]').inputValue(), '');
    assert.equal(await page.locator('.ebd-post-item').count(), 0);
    await page.goto(`${base}/ask-ai-2/?ebd_question=mustard%20seed`);
    assert.equal(new URL(page.url()).pathname, '/ask-ai/');
    await page.locator('[data-ebd-show-broader]').waitFor();
    assert.equal(calls, 7, 'Saved question should autorun once');
    assert.equal(feedbackCalls, 3);

    // Read-only database searches exercise the shared controls on both browse paths and keyword search.
    await page.setViewportSize({ width: 1440, height: 950 });
    for (const path of ['/keyword-search/?ebd_keyword[]=Paul', '/browse-topics-1/', '/browse-topics-2/']) {
      await page.goto(`${base}${path}`);
      if (path.startsWith('/browse')) {
        await page.locator('main a[href*="ebd_subject="]').first().click();
        await page.locator('main a[href*="ebd_category="]').first().click();
        await page.locator('main a[href*="ebd_topic="]').first().click();
      }
      const expanded = page.locator('[data-ebd-search-expanded]');
      if (!(await expanded.isVisible())) await page.locator('[data-ebd-search-edit]').click();
      const sorted = page.waitForResponse(response => response.url().includes('/ehrman-discovery/v1/search?') && response.status() === 200);
      await page.locator('input[name="ebd_sort"][value="newest"]').check();
      await sorted;
      await page.waitForFunction(() => !document.querySelector('[data-ebd-results]').hasAttribute('aria-busy'));
      assert((await page.locator('.ebd-post-item').count()) > 0);
      await page.locator('[data-ebd-search-collapse]').click();
      assert(!(await expanded.isVisible()));
      await page.locator('[data-ebd-search-edit]').click();
      assert(await expanded.isVisible());
    }
    await page.goto(`${base}/wp-content/themes/ehrman-discovery-demo/assets/images/ehrman-ai-workflow.svg`);
    assert.equal(await page.locator('svg text.heading').textContent(), 'Ask AI');
    const boxes = await page.locator('svg text.label').evaluateAll(labels => labels.map(label => {
      const b = label.getBBox();
      const rect = [...document.querySelectorAll('rect')].find(r => b.y >= Number(r.getAttribute('y')) && b.y < Number(r.getAttribute('y')) + Number(r.getAttribute('height')));
      return { text: label.textContent, fits: !!rect && b.x >= 50 && b.x + b.width <= 710 && b.y + b.height <= Number(rect.getAttribute('y')) + 110 };
    }));
    assert.equal(boxes.length, 6);
    assert(boxes.every(box => box.fits), JSON.stringify(boxes));
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], mockedCalls: calls, feedbackCalls, sharedSearchPages: 3, paidCalls: 0, boxes }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
