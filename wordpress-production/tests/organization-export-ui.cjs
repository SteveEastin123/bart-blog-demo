// Read-only authenticated exports; run against the disposable regression site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const output = outputFor('organization-export-ui');
const root = path.resolve(__dirname, '../..');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);
const url = base + '/wp-admin/admin.php?page=ehrman-search-organization&tab=export';
const endpoint = base + '/wp-admin/admin-post.php';

(async () => {
  cli(['eval', 'if (!defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL || !defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON) throw new RuntimeException("Export tests require an isolated site.");']);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const errors = []; const external = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => {
    const target = new URL(route.request().url());
    if (target.protocol.startsWith('http') && target.origin !== base) {
      // WordPress core requests admin avatars; block them too, but audit exporter requests separately.
      if (target.hostname !== 'secure.gravatar.com' || route.request().resourceType() !== 'image') external.push(target.origin);
      return route.abort();
    }
    return route.continue();
  });
  fs.mkdirSync(output, { recursive: true });
  try {
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]); await page.locator('#user_pass').fill(credentials[1]); credentials.fill('');
    await page.locator('#wp-submit').click(); await page.waitForURL('**/wp-admin/**');
    await page.goto(url);
    assert.equal(await page.locator('.nav-tab-active').innerText(), 'Export');
    assert(await page.getByLabel('Entire structure', { exact: true }).isChecked());
    assert(await page.locator('[data-ebd-export-areas]').isHidden());
    await page.waitForFunction(() => !document.querySelector('[data-ebd-export-pdf]').disabled);
    const nonce = await page.locator('[name="_wpnonce"]').inputValue();
    const form = { action: 'ehrman_organization_export', _wpnonce: nonce, scope: 'all', format: 'pdf' };
    const all = JSON.parse(cli(['eval', 'echo wp_json_encode((new EhrmanBlogDiscovery\\Organization_Export())->rows("all",null));']));
    const response = await page.request.post(endpoint, { form });
    assert.equal(response.status(), 200);
    assert.match(response.headers()['cache-control'], /no-cache/);
    assert.deepEqual((await response.json()).data.rows, all);
    fs.writeFileSync(path.join(output, 'expected-all.json'), JSON.stringify(all));
    const getDownload = async (format, filename) => {
      const promise = page.waitForEvent('download');
      await page.getByRole('button', { name: 'Download ' + format, exact: true }).click();
      const download = await promise.catch(async error => {
        await page.screenshot({ path: path.join(output, 'download-error.png'), fullPage: true });
        throw new Error(error.message + '\nExport status: ' + await page.locator('[data-ebd-export-status]').innerText());
      });
      assert.match(download.suggestedFilename(), new RegExp('^ehrman-browse-structure-\\d{4}-\\d{2}-\\d{2}\\.' + format.toLowerCase() + '$'));
      await download.saveAs(path.join(output, filename));
      await page.getByText('Download ready.', { exact: true }).waitFor();
      return fs.readFileSync(path.join(output, filename));
    };
    const pdf = await getDownload('PDF', 'entire-structure.pdf');
    assert.equal(pdf.subarray(0, 5).toString(), '%PDF-');
    const csv = await getDownload('CSV', 'entire-structure.csv');
    assert.equal(csv.subarray(0, 3).toString('hex'), 'efbbbf');
    assert(csv.toString().includes('Subject Area,Category,Topic') || csv.toString().includes('"Subject Area",Category,Topic'));
    await page.getByLabel('Selected Subject Areas', { exact: true }).check();
    assert(await page.getByRole('button', { name: 'Download PDF', exact: true }).isDisabled());
    assert(await page.getByRole('button', { name: 'Download CSV', exact: true }).isDisabled());
    const selections = page.locator('[data-ebd-export-areas] input');
    const chosen = [];
    for (const index of [0, 2]) { chosen.push((await selections.nth(index).locator('..').innerText()).trim()); await selections.nth(index).check(); }
    const expected = all.filter(row => chosen.includes(row.subject_area));
    fs.writeFileSync(path.join(output, 'expected-selected.json'), JSON.stringify(expected));
    await getDownload('PDF', 'selected-structure.pdf');
    await getDownload('CSV', 'selected-structure.csv');
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Export overflow at ' + width);
      await page.screenshot({ path: path.join(output, `export-${width}.png`), fullPage: true });
    }
    for (const invalid of [{ scope: 'selected' }, { scope: 'invalid' }, { format: 'html' }, { scope: 'selected', 'subject_area_ids[]': '99999999' }]) {
      const rejected = await page.request.post(endpoint, { form: { ...form, ...invalid } });
      assert.equal(rejected.status(), 400); assert.equal((await rejected.json()).success, false);
    }
    assert.equal((await page.request.post(endpoint, { form: { ...form, _wpnonce: 'invalid' } })).status(), 403);
    assert.equal((await page.request.get(endpoint + '?action=ehrman_organization_export')).status(), 405);
    const anonymous = await browser.newContext();
    try { assert.notEqual((await anonymous.request.post(endpoint, { form, maxRedirects: 0 })).status(), 200); } finally { await anonymous.close(); }
    await context.route('**/wp-admin/admin-post.php', route => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Synthetic export failure' } }) }));
    await page.getByRole('button', { name: 'Download PDF', exact: true }).click();
    await page.getByText('Synthetic export failure', { exact: true }).waitFor();
    assert(await page.getByRole('button', { name: 'Download PDF', exact: true }).isEnabled());
    await context.unroute('**/wp-admin/admin-post.php');
    const native = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce', storageState: await context.storageState() });
    try {
      const plain = await native.newPage(); await plain.goto(url);
      assert(await plain.getByRole('button', { name: 'Download PDF', exact: true }).isDisabled());
      const promise = plain.waitForEvent('download');
      await plain.getByRole('button', { name: 'Download CSV', exact: true }).click();
      await (await promise).saveAs(path.join(output, 'no-js.csv'));
      assert.deepEqual(fs.readFileSync(path.join(output, 'no-js.csv')), csv);
    } finally { await native.close(); }
    await page.goto(base + '/wp-admin/admin.php?page=ehrman-search-organization&tab=topics');
    assert.equal(await page.locator('#ehrman-pdfmake-js, #ehrman-pdfmake-fonts-js, #ehrman-organization-export-js').count(), 0, 'PDF scripts loaded outside Export');
    assert.deepEqual(errors, []); assert.deepEqual(external, []);
    console.log('Export browser checks passed: full/selected PDF and CSV, permissions, nonce, invalid input, errors, no-JS CSV, desktop/tablet/mobile, no external requests.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
