// Local authenticated, read-only UI checks. Never submits a valid reset or an AI request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');

const root = path.resolve(__dirname, '../..');
const base = 'http://localhost:8086';
const url = base + '/wp-admin/tools.php?page=ehrman-ai-analytics';
const output = path.join(root, '.tmp/analytics-dashboard-ui');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  // Use existing local credentials in memory; never print or persist them.
  const credentials = execFileSync('docker', ['compose', '-f', 'wordpress-production/compose.local.yaml',
    'exec', '-T', 'wordpress', 'printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD'],
  { cwd: root, encoding: 'utf8' }).trimEnd().split(/\r?\n/);
  assert.equal(credentials.length, 2);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.route('**/wp-json/ehrman-discovery/v1/semantic-search', route => route.abort());
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(url);
    const metrics = page.locator('[data-ebd-analytics-metrics]');
    const initial = await metrics.innerText();
    assert(initial.includes('Total estimated cost') && initial.includes('Average cost per question'));
    for (const view of ['ask-ai', 'ask-ai-2', 'comparison', 'combined']) {
      await page.goto(url + '&view=' + view + '&interface=taxonomy');
      assert.equal(await page.locator('#wpbody .nav-tab-wrapper').count(), 0);
      assert.equal(await metrics.innerText(), initial, 'A legacy URL changed the method scope');
    }
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url);
      assert.equal(await page.locator('#wpbody h1').innerText(), 'Ask AI Analytics');
      assert.equal(await page.locator('select[name="interface"]').count(), 0);
      await page.screenshot({ path: path.join(output, 'dashboard-' + width + '.png') });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Horizontal overflow at ' + width);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('input[name="search"]').fill('mustard');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await page.waitForURL('**/*search=mustard*');
    const texts = await page.locator('[data-ebd-analytics-questions] tbody tr td:nth-child(2)').allTextContents();
    assert(texts.every(text => /mustard/i.test(text)), 'Question filtering failed');
    const questionExport = await page.getByRole('link', { name: 'Export questions CSV', exact: true }).first().getAttribute('href');
    const exported = await context.request.get(questionExport);
    assert.equal(exported.status(), 200);
    const csv = await exported.text();
    assert(csv.includes('"Total cost USD"') && csv.includes('"AI review cost USD"'));
    assert(!csv.split('\n')[0].includes('Method'));
    const missingNonce = new URL(questionExport);
    missingNonce.searchParams.delete('_wpnonce');
    assert.equal((await context.request.get(missingNonce.href)).status(), 403, 'CSV endpoint accepted a missing nonce');
    assert.equal((await context.request.post(base + '/wp-admin/admin-post.php', {
      form: { action: 'ehrman_ai_analytics_reset', _wpnonce: 'intentionally-invalid' },
    })).status(), 403, 'Reset endpoint accepted an invalid nonce');
    await page.goto(url + '&search=unmatched-regression-question-924135');
    assert(await page.getByText('No requests match these filters.', { exact: true }).isVisible());
    await page.goto(url);
    assert.equal(await metrics.innerText(), initial, 'Read-only UI tests changed analytics');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], legacyViews: 4,
      filters: true, csv: true, nonceProtection: true, validResetSubmitted: false, paidCalls: 0 }, null, 2));
  } finally {
    // Destroy only this test's login session, leaving the user's sessions untouched.
    const logout = await page.locator('#wp-admin-bar-logout a').getAttribute('href').catch(() => null);
    if (logout) await context.request.get(logout);
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
