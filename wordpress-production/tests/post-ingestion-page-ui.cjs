// Read-only local UI checks. Never submits a valid ingestion or AI request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');

const root = path.resolve(__dirname, '../..');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const url = base + '/wp-admin/tools.php?page=ehrman-post-ingestion';
const output = outputFor('post-ingestion-page-ui');
// Optional old/new HTML from the temporary, transaction-scoped parity harness.
const samples = process.argv[2] ? JSON.parse(fs.readFileSync(process.argv[2], 'utf8')) : [];

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const credentials = execFileSync('docker', ['compose', '-f', composeFile,
    'exec', '-T', 'wordpress', 'printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD'],
  { cwd: root, encoding: 'utf8' }).trimEnd().split(/\r?\n/);
  assert.equal(credentials.length, 2);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  let matchedScreenshots = 0;
  let nonceChecks = 0;
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.route('**/wp-json/ehrman-discovery/**', route => route.abort());
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(url);
    const initial = await page.locator('.ehrman-ingestion').textContent();
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url);
      assert.equal(await page.locator('.ehrman-ingestion h1').innerText(), 'Search Metadata');
      assert.match(await page.locator('#menu-tools a[href="tools.php?page=ehrman-post-ingestion"]').innerText(), /^Search Metadata/);
      assert.equal(await page.getByLabel('Status', { exact: true }).inputValue(), 'all');
      const vectorLabels = await page.locator('[data-ebd-metadata-worklist] tbody tr td:nth-child(4)').allTextContents();
      assert(vectorLabels.length > 0, 'Default All worklist omitted imported metadata');
      assert(vectorLabels.every(label => ['Complete', 'Pending', 'Not requested', 'Not applicable'].includes(label.trim())), 'Vector labels are not consistently capitalized');
      assert.equal(await page.locator('.ehrman-ingestion form[method="get"]').count(), 1);
      assert.equal(await page.getByLabel('WordPress post ID', { exact: true }).count(), 0);
      assert.equal(await page.getByLabel('Complete post text', { exact: true }).count(), 0);
      assert.equal(await page.locator('.ehrman-ingestion').getByRole('link', { name: 'Posts', exact: true }).getAttribute('href'), base + '/wp-admin/edit.php');
      await page.screenshot({ path: path.join(output, 'ingestion-' + width + '.png'), fullPage: true });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Worklist overflows at ' + width);
      const liveContent = await page.locator('#wpbody-content').innerHTML();
      for (let index = 0; index < samples.length; index++) {
        const sample = samples[index];
        if (!sample.before) continue; // Unauthenticated case has no markup.
        const images = [];
        for (const version of ['before', 'after']) {
          await page.locator('#wpbody-content').evaluate((element, html) => { element.innerHTML = html; }, sample[version]);
          await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
          images.push(await page.screenshot({ path: path.join(output, `${index}-${version}-${width}.png`), fullPage: true, animations: 'disabled' }));
        }
        assert(images[0].equals(images[1]), `Visual difference in state ${index} at ${width}px`);
        matchedScreenshots++;
      }
      await page.locator('#wpbody-content').evaluate((element, html) => { element.innerHTML = html; }, liveContent);

      await page.goto(base + '/wp-admin/tools.php?page=ehrman-blog-discovery');
      const status = page.locator('#wpbody-content .wrap').first();
      assert.equal(await status.getByRole('heading', { name: 'Ehrman Blog Discovery', exact: true }).count(), 1);
      assert.equal(await status.locator('input[name="action"][value="ehrman_discovery_import"]').count(), 0, 'Discovery status still exposes a bulk import form');
      assert.equal(await status.locator('form').count(), 0, 'Discovery settings still require a form submission');
      const publishingModes = status.getByRole('group', { name: 'Metadata generation', exact: true });
      assert.equal(await publishingModes.getByRole('radio', { name: 'Manual', exact: true }).count(), 1);
      assert.equal(await publishingModes.getByRole('radio', { name: 'Automatic', exact: true }).count(), 1);
      assert.doesNotMatch(await status.innerText(), /Authoritative JSON import|All five authoritative JSON files/);
      assert.equal(await status.getByRole('rowheader', { name: 'Imported posts', exact: true }).count(), 1);
      assert.equal(await status.getByRole('heading', { name: 'AI analytics', exact: true }).count(), 0, 'Discovery status still duplicates analytics');
      assert.equal(await status.locator('a[href*="page=ehrman-ai-analytics"]').count(), 0, 'Discovery status still shows the analytics shortcut');
      assert.doesNotMatch(await status.innerText(), /Questions, token usage, refinement activity/);
      assert.equal(await page.locator('#menu-tools a[href="tools.php?page=ehrman-ai-analytics"]').innerText(), 'AI Analytics', 'Dedicated Tools menu entry is missing');
      await page.screenshot({ path: path.join(output, 'discovery-status-' + width + '.png'), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('#menu-tools a[href="tools.php?page=ehrman-ai-analytics"]').click();
    await page.waitForURL(base + '/wp-admin/tools.php?page=ehrman-ai-analytics');
    assert(await page.getByRole('heading', { name: 'AI Analytics', exact: true }).isVisible(), 'Dedicated analytics page is unavailable');
    for (const action of ['ehrman_ingestion_analyze', 'ehrman_discovery_import']) {
      const retired = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action } });
      assert.equal(retired.status(), 400, `Retired action is still available: ${action}`);
    }
    for (const action of ['create_metadata', 'proposal', 'reanalyze', 'retry_embedding', 'discard']) {
      const response = await context.request.post(base + '/wp-admin/admin-post.php', {
        form: { action: 'ehrman_ingestion_' + action, _wpnonce: 'intentionally-invalid' },
      });
      assert.equal(response.status(), 403, `Ingestion ${action} accepted an invalid nonce`);
      nonceChecks++;
    }
    await page.goto(url + '&draft=2147483647');
    assert(await page.getByText('The requested ingestion record was not found.', { exact: true }).isVisible());
    assert.equal(await page.getByLabel('Complete post text', { exact: true }).count(), 0, 'Missing record reopened manual entry');
    await page.goto(url + '&status=complete');
    assert.equal(await page.locator('[data-ebd-metadata-worklist] tbody tr').count(), 25);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.screenshot({ path: path.join(output, 'completed-' + width + '.png'), fullPage: true });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Populated worklist overflows at ' + width);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    const firstTitles = await page.locator('[data-ebd-metadata-worklist] tbody tr td:nth-child(2)').allTextContents();
    await page.getByRole('link', { name: 'Next', exact: true }).click();
    await page.waitForURL(current => current.searchParams.get('paged') === '2');
    assert(new URL(page.url()).searchParams.get('status') === 'complete', 'Pagination lost the status filter');
    assert(new URL(page.url()).searchParams.get('paged') === '2');
    const secondTitles = await page.locator('[data-ebd-metadata-worklist] tbody tr td:nth-child(2)').allTextContents();
    assert.equal(secondTitles.length, 25);
    assert.notDeepEqual(secondTitles, firstTitles, 'Pagination returned the first page again');
    await page.locator('[data-ebd-metadata-worklist] tbody tr').first().getByRole('link', { name: 'View', exact: true }).click();
    await page.getByText('Approved enrichment', { exact: true }).waitFor({ state: 'visible' });
    assert(await page.getByText('Approved enrichment', { exact: true }).isVisible());
    await page.goto(url + '&status=all&search=not-found-worklist-97124');
    assert(await page.getByText('No posts match these filters.', { exact: true }).isVisible());
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url + '&status=complete&search=not-found-worklist-97124&date_from=2026-01-01&date_to=2026-12-31&paged=2');
      const clearFilters = page.getByRole('link', { name: 'Clear filters', exact: true });
      assert.equal(await clearFilters.getAttribute('href'), url);
      await clearFilters.click();
      await page.waitForURL(url);
      assert.equal(await page.getByLabel('Status', { exact: true }).inputValue(), 'all');
      for (const label of ['Post title', 'Published from', 'Published through']) {
        assert.equal(await page.getByLabel(label, { exact: true }).inputValue(), '', 'Clear filters did not reset ' + label);
      }
      assert.equal(await page.locator('.ehrman-ingestion').textContent(), initial, 'Clearing filters changed records or failed to restore the first page');
      await page.screenshot({ path: path.join(output, 'clear-filters-' + width + '.png'), fullPage: true });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Clear filters worklist overflows at ' + width);
    }
    await page.goto(url);
    assert.equal(await page.locator('.ehrman-ingestion').textContent(), initial, 'Read-only UI checks changed ingestion records');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], nonceChecks,
      matchedScreenshots, validActionsSubmitted: 0 }, null, 2));
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    console.error('Metadata UI failure at', page.url(), await page.locator('body').innerText().catch(() => ''));
    throw error;
  } finally {
    const logout = await page.locator('#wp-admin-bar-logout a').getAttribute('href').catch(() => null);
    if (logout) await context.request.get(logout);
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
