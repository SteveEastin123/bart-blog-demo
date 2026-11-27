// Read-only local UI checks. Never submits a valid ingestion or AI request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');

const root = path.resolve(__dirname, '../..');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const url = base + '/wp-admin/admin.php?page=ehrman-post-ingestion';
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
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  const errors = [];
  let matchedScreenshots = 0;
  let nonceChecks = 0;
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.route('**/wp-json/ehrman-discovery/**', route => route.abort());
    await page.goto(base + '/wp-login.php');
    await page.waitForFunction(() => document.activeElement?.id === 'user_login');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    const menu = page.locator('#toplevel_page_ehrman-post-ingestion');
    await page.goto(url);
    assert.equal(await menu.locator('.wp-menu-name').innerText(), 'Blog Search');
    const menuItems = await menu.locator('.wp-submenu li:not(.wp-submenu-head) a').allTextContents();
    assert.equal(menuItems.length, 5);
    assert.match(menuItems[0], /^Post Metadata/);
    assert.deepEqual(menuItems.slice(1), ['Manage Topics', 'AI Analytics', 'Settings', 'Info & Documentation']);
    const slugs = ['ehrman-post-ingestion', 'ehrman-search-organization', 'ehrman-ai-analytics', 'ehrman-blog-discovery', 'ehrman-info-documentation'];
    assert.deepEqual(await menu.locator('.wp-submenu li:not(.wp-submenu-head) a').evaluateAll(links => links.map(link => link.getAttribute('href'))), slugs.map(slug => 'admin.php?page=' + slug));
    assert.equal(await page.locator('#menu-tools a[href*="page=ehrman-"]').count(), 0, 'Plugin pages remain under Tools');
    for (const slug of slugs) {
      const legacy = base + '/wp-admin/tools.php?page=' + slug;
      const query = '&tab=categories&status=complete&draft=123&search=Paul%27s+letters&_wpnonce=probe&items%5B%5D=one';
      const redirect = await context.request.get(legacy + query, { maxRedirects: 0 });
      assert.equal(redirect.status(), 302);
      const target = new URL(redirect.headers().location);
      assert.equal(target.origin, new URL(base).origin);
      assert.equal(target.pathname, '/wp-admin/admin.php');
      assert.equal(target.searchParams.get('page'), slug);
      for (const [key, value] of new URL(legacy + query).searchParams) {
        // WordPress may normalize [] to [0] while encoding an array query value.
        if (key !== 'items[]') assert.equal(target.searchParams.get(key), value);
      }
      assert.equal(target.searchParams.get('items[0]'), 'one');
      const post = await context.request.post(legacy, { form: { _wpnonce: 'invalid' }, maxRedirects: 0 });
      assert.equal(post.status(), 307, 'Legacy form submission would lose its POST body');
    }
    const legacyOrganization = base + '/wp-admin/tools.php?page=ehrman-search-organization';
    const rejected = await context.request.post(legacyOrganization, { form: { organization_action: 'create', _wpnonce: 'invalid' } });
    assert.equal(rejected.status(), 403, 'Legacy organization POST bypassed the nonce check');
    const tools = await context.request.get(base + '/wp-admin/tools.php', { maxRedirects: 0 });
    assert.equal(tools.status(), 200, 'Standard WordPress Tools was redirected');
    await page.goto(base + '/wp-admin/tools.php?page=ehrman-post-ingestion&status=complete');
    assert.equal(new URL(page.url()).pathname, '/wp-admin/admin.php');
    assert.equal(await page.getByLabel('Status', { exact: true }).inputValue(), 'complete');
    const anonymous = await browser.newContext();
    try {
      for (const slug of slugs) {
        const response = await anonymous.request.get(base + '/wp-admin/admin.php?page=' + slug, { maxRedirects: 0 });
        assert.equal(response.status(), 302);
        assert(new URL(response.headers().location).pathname.endsWith('/wp-login.php'), 'Anonymous visitor was not sent to login');
      }
    } finally { await anonymous.close(); }
    await page.goto(url);
    assert.equal(await page.locator('#ehrman-post-ingestion-review-js').count(), 1);
    assert.equal(await page.locator('#ehrman-search-organization-js, #ehrman-admin-settings-js').count(), 0, 'Page-specific scripts leaked onto metadata');
    const initial = await page.locator('.ehrman-ingestion').textContent();
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url);
      assert.equal(await page.locator('.ehrman-ingestion h1').innerText(), 'Post Metadata');
      assert.match(await page.locator('#toplevel_page_ehrman-post-ingestion .wp-submenu a[href="admin.php?page=ehrman-post-ingestion"]').innerText(), /^Post Metadata/);
      assert.equal(await page.getByLabel('Status', { exact: true }).inputValue(), 'all');
      const vectorLabels = await page.locator('[data-ebd-metadata-worklist] tbody tr td:nth-child(4)').allTextContents();
      assert(vectorLabels.length > 0, 'Default All worklist omitted imported metadata');
      assert(vectorLabels.every(label => ['Complete', 'Pending', 'Not requested', 'Not applicable'].includes(label.trim())), 'Vector labels are not consistently capitalized');
      assert.equal(await page.locator('.ehrman-ingestion form[method="get"]').count(), 1);
      assert.equal(await page.getByLabel('WordPress post ID', { exact: true }).count(), 0);
      assert.equal(await page.getByLabel('Complete post text', { exact: true }).count(), 0);
      assert.equal(await page.locator('.ehrman-ingestion').getByRole('link', { name: 'Posts', exact: true }).count(), 0, 'The redundant Posts button remains');
      assert.doesNotMatch(await page.locator('.ehrman-ingestion').innerText(), /JSON is the current source of truth|Draft analysis and review are available/);
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
          // WordPress uses an animated GIF spinner, which animations: 'disabled' cannot freeze.
          images.push(await page.screenshot({ path: path.join(output, `${index}-${version}-${width}.png`), fullPage: true, animations: 'disabled', mask: [page.locator('[data-ebd-metadata-progress] .spinner')] }));
        }
        assert(images[0].equals(images[1]), `Visual difference in state ${index} at ${width}px`);
        matchedScreenshots++;
      }
      await page.locator('#wpbody-content').evaluate((element, html) => { element.innerHTML = html; }, liveContent);

      await page.goto(base + '/wp-admin/admin.php?page=ehrman-blog-discovery');
      const status = page.locator('#wpbody-content .wrap').first();
      assert.equal(await status.getByRole('heading', { name: 'Settings', exact: true }).count(), 1);
      assert.equal(await status.locator('input[name="action"][value="ehrman_discovery_import"]').count(), 0, 'Discovery status still exposes a bulk import form');
      assert.equal(await status.locator('form').count(), 0, 'Settings still require a form submission');
      const publishingModes = status.getByRole('group', { name: 'Metadata Generation', exact: true });
      assert.equal(await publishingModes.getByRole('radio', { name: 'Manual', exact: true }).count(), 1);
      assert.equal(await publishingModes.getByRole('radio', { name: 'Automatic', exact: true }).count(), 1);
      assert.equal(await status.getByRole('heading', { name: 'Allow Deletion', exact: true }).count(), 1);
      assert.doesNotMatch(await status.innerText(), /Posts and Secondary Keywords cannot be deleted here\./);
      assert.doesNotMatch(await status.innerText(), /Authoritative JSON import|All five authoritative JSON files/);
      assert.equal(await status.getByRole('rowheader').count(), 0, 'Settings still contains system information');
      assert.equal(await status.getByRole('heading', { name: 'AI analytics', exact: true }).count(), 0, 'Discovery status still duplicates analytics');
      assert.equal(await status.locator('a[href*="page=ehrman-ai-analytics"]').count(), 0, 'Discovery status still shows the analytics shortcut');
      assert.doesNotMatch(await status.innerText(), /Questions, token usage, refinement activity/);
      assert.equal(await page.locator('#toplevel_page_ehrman-post-ingestion a[href="admin.php?page=ehrman-ai-analytics"]').innerText(), 'AI Analytics', 'Blog Search analytics entry is missing');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Settings overflows at ' + width);
      await page.screenshot({ path: path.join(output, 'settings-' + width + '.png'), fullPage: true });

      await page.goto(base + '/wp-admin/admin.php?page=ehrman-info-documentation');
      const info = page.locator('#wpbody-content .wrap').first();
      assert.equal(await info.getByRole('heading', { name: 'Info & Documentation', exact: true }).count(), 1);
      assert.equal(await info.getByRole('row').filter({ has: page.getByRole('rowheader', { name: 'Import state', exact: true }) }).getByRole('cell').innerText(), 'Complete');
      assert.equal(await info.getByRole('rowheader').count(), 7);
      assert.equal(await info.locator('form, input, select').count(), 0, 'Information page contains settings controls');
      assert.equal(await page.locator('#ehrman-admin-settings-js, #ehrman-post-ingestion-review-js').count(), 0, 'Editing scripts leaked onto information page');
      assert(await info.getByRole('link', { name: 'Read Guide', exact: true }).isVisible());
      assert(await info.getByRole('link', { name: 'Download PDF', exact: true }).isVisible());
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Information overflows at ' + width);
      await page.screenshot({ path: path.join(output, 'information-' + width + '.png'), fullPage: true });
    }
    const popupPromise = context.waitForEvent('page');
    await page.getByRole('link', { name: 'Read Guide', exact: true }).click();
    const guide = await popupPromise;
    await guide.waitForLoadState();
    assert.equal(await guide.title(), 'Ehrman Blog Publisher Guide');
    assert.equal(new URL(guide.url()).origin, new URL(base).origin);
    assert.equal(await guide.locator('table[aria-label="Quick Guide"] a').count(), 6);
    assert.equal(await guide.getByRole('heading', { name: '1. Introduction', exact: true }).count(), 1);
    assert.equal(await guide.locator('nav[aria-label="Table of Contents"] a').count(), 21);
    assert(await guide.locator('img').evaluateAll(images => images.length > 0 && images.every(image => image.complete && image.naturalWidth > 0)));
    await guide.close();
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download PDF', exact: true }).click();
    const download = await downloadPromise;
    assert.equal(download.suggestedFilename(), 'ehrman_blog_publisher_guide.pdf');
    const downloaded = fs.readFileSync(await download.path());
    const bundledPdf = fs.readFileSync(path.join(root, 'wordpress-plugin/ehrman-blog-discovery/docs/ehrman_blog_publisher_guide.pdf'));
    assert(downloaded.equals(bundledPdf), 'Downloaded guide differs from the packaged PDF');
    assert.equal(downloaded.subarray(0, 5).toString(), '%PDF-');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('#toplevel_page_ehrman-post-ingestion a[href="admin.php?page=ehrman-ai-analytics"]').click();
    await page.waitForURL(base + '/wp-admin/admin.php?page=ehrman-ai-analytics');
    assert(await page.getByRole('heading', { name: 'AI Analytics', exact: true }).isVisible(), 'Dedicated analytics page is unavailable');
    for (const action of ['ehrman_ingestion_analyze', 'ehrman_discovery_import']) {
      const retired = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action } });
      assert.equal(retired.status(), 400, `Retired action is still available: ${action}`);
    }
    for (const action of ['create_metadata', 'proposal', 'reanalyze', 'edit_metadata', 'retry_embedding', 'discard']) {
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
