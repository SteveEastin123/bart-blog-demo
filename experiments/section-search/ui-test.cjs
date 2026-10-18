const fs = require('fs');
const path = require('path');
const assert = require('assert');
const {chromium} = require('../../node_modules/playwright-core');
const root = path.resolve(__dirname, '../..');
const env = Object.fromEntries(fs.readFileSync(path.join(root, 'wordpress-production/.env'), 'utf8').split(/\r?\n/).filter(x => x.includes('=') && !x.startsWith('#')).map(x => { const i = x.indexOf('='); return [x.slice(0, i), x.slice(i + 1).replace(/^['"]|['"]$/g, '')]; }));
(async () => {
  const browser = await chromium.launch({headless: true, channel: 'msedge'});
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('http://localhost:8086/wp-login.php');
  await page.locator('#user_login').fill(env.WORDPRESS_ADMIN_USER || 'admin');
  await page.locator('#user_pass').fill(env.WORDPRESS_ADMIN_PASSWORD || 'ehrman-admin-production-test-only');
  await Promise.all([page.waitForURL(url => url.pathname.includes('wp-admin')), page.locator('#wp-submit').click()]);
  await page.route('**/wp-json/ehrman-section-pilot/v1/search', async route => {
    const {mode} = route.request().postDataJSON();
    const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, `private/development/q01-${mode}.json`)));
    await route.fulfill({json: fixture});
  });
  for (const viewport of [{width:1440,height:1000}, {width:390,height:844}]) {
    await page.setViewportSize(viewport);
    await page.goto('http://localhost:8086/ask-ai-3/');
    await page.locator('#pilot-question').fill('How do Jesus and Paul differ in their teachings?');
    await page.locator('button[value=compare]').click();
    await page.waitForFunction(() => document.querySelectorAll('.pilot-result-column').length === 2);
    assert(await page.locator('#pilot-feedback').isVisible());
    const layout = await page.evaluate(() => ({width:innerWidth, scroll:document.documentElement.scrollWidth,
      grid:getComputedStyle(document.querySelector('#pilot-results')).display,
      columns:[...document.querySelectorAll('.pilot-result-column')].map(el => {const b=el.getBoundingClientRect();return {x:b.x,y:b.y,w:b.width};})}));
    assert(layout.scroll <= layout.width + 2, JSON.stringify(layout));
    assert.strictEqual(layout.grid, 'grid');
    if (viewport.width < 760) assert(layout.columns[1].y > layout.columns[0].y);
    else assert(Math.abs(layout.columns[0].y - layout.columns[1].y) < 2);
    await page.screenshot({path:path.join(__dirname, `private/ui-${viewport.width}.png`),fullPage:true});
    console.log(JSON.stringify({viewport, layout, resultColumns:2}));
  }
  const anonymous = await browser.newContext();
  const anon = await anonymous.newPage();
  await anon.goto('http://localhost:8086/ask-ai-3/');
  assert(await anon.getByText('Sign in as an administrator').isVisible());
  const response = await anonymous.request.post('http://localhost:8086/wp-json/ehrman-section-pilot/v1/search', {data:{question:'test',mode:'300'}});
  assert([401,403].includes(response.status()));
  assert.deepStrictEqual(errors, []);
  await browser.close();
  console.log('UI and access-control checks passed; search responses mocked, no paid UI calls.');
})().catch(error => {console.error(error);process.exit(1);});
