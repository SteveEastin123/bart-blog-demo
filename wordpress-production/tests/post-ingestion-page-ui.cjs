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
      assert.equal(await page.locator('.ehrman-ingestion h1').innerText(), 'Metadata History');
      assert.match(await page.locator('#menu-tools a[href="tools.php?page=ehrman-post-ingestion"]').innerText(), /^Metadata History/);
      assert.equal(await page.locator('.ehrman-ingestion form').count(), 0, 'History still exposes the manual submission form');
      assert.equal(await page.getByLabel('WordPress post ID', { exact: true }).count(), 0);
      assert.equal(await page.getByLabel('Complete post text', { exact: true }).count(), 0);
      assert.equal(await page.locator('.ehrman-ingestion').getByRole('link', { name: 'Posts', exact: true }).getAttribute('href'), base + '/wp-admin/edit.php');
      await page.screenshot({ path: path.join(output, 'ingestion-' + width + '.png'), fullPage: true });
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
      assert.equal(await status.locator('form').count(), 0, 'Discovery status still exposes a bulk import form');
      assert.doesNotMatch(await status.innerText(), /Authoritative JSON import|All five authoritative JSON files/);
      assert.equal(await status.getByRole('rowheader', { name: 'Imported posts', exact: true }).count(), 1);
      assert.equal(await status.getByRole('link', { name: 'Open AI Search Analytics', exact: true }).getAttribute('href'), base + '/wp-admin/tools.php?page=ehrman-ai-analytics');
      await page.screenshot({ path: path.join(output, 'discovery-status-' + width + '.png'), fullPage: true });
    }
    for (const action of ['ehrman_ingestion_analyze', 'ehrman_discovery_import']) {
      const retired = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action } });
      assert.equal(retired.status(), 400, `Retired action is still available: ${action}`);
    }
    for (const action of ['proposal', 'reanalyze', 'retry_embedding', 'discard']) {
      const response = await context.request.post(base + '/wp-admin/admin-post.php', {
        form: { action: 'ehrman_ingestion_' + action, _wpnonce: 'intentionally-invalid' },
      });
      assert.equal(response.status(), 403, `Ingestion ${action} accepted an invalid nonce`);
      nonceChecks++;
    }
    await page.goto(url + '&draft=2147483647');
    assert(await page.getByText('The requested ingestion record was not found.', { exact: true }).isVisible());
    assert.equal(await page.locator('.ehrman-ingestion form').count(), 0, 'Missing record reopened manual entry');
    await page.goto(url);
    assert.equal(await page.locator('.ehrman-ingestion').textContent(), initial, 'Read-only UI checks changed ingestion records');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], nonceChecks,
      matchedScreenshots, validActionsSubmitted: 0 }, null, 2));
  } finally {
    const logout = await page.locator('#wp-admin-bar-logout a').getAttribute('href').catch(() => null);
    if (logout) await context.request.get(logout);
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
