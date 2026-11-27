// All organization tabs; writes only to a disposable local regression site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const root = path.resolve(__dirname, '../..');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);

async function checkFocusChoices(page, url, output) {
  const input = page.locator('#ebd-find-topic');
  const results = page.locator('[data-ebd-topic-results]');
  const list = page.locator('#ebd-topic-matches');
  const links = list.locator('a');
  const more = page.locator('[data-ebd-topic-more]');
  assert(await page.locator('.ehrman-organization__search-controls button[type="submit"]').isHidden(), 'Redundant Search button remains visible');
  const entries = await page.locator('[data-ebd-topic-directory]').evaluate(el => Array.from(el.content.querySelectorAll('a'), link => ({
    name: link.querySelector('[data-ebd-topic-name]').textContent, href: link.href,
  })));
  assert(entries.length > 0, 'Missing search fixture directory');
  assert(await results.isHidden(), 'Suggestions opened before interaction');
  await input.focus();
  assert(await results.isVisible(), 'Empty focus did not show choices');
  assert.equal(await links.count(), Math.min(24, entries.length), 'Initial choices are not bounded');
  assert.deepEqual(await list.locator('strong').allTextContents(), entries.slice(0, 24).map(row => row.name), 'Alphabetical directory order changed');
  if (entries.length > 24) {
    assert(await more.isVisible());
    const destination = new URL(await more.getAttribute('href'));
    assert.equal(destination.searchParams.get('view'), 'all');
    assert.equal(destination.searchParams.get('s'), null);
    assert.equal(destination.searchParams.get('tab'), new URL(url).searchParams.get('tab'));
    await more.focus();
    await page.keyboard.press('Escape');
    assert(await results.isHidden(), 'Escape on View All Matches did not close choices');
    await input.click();
    let count = await links.count();
    while (count < entries.length) {
      await list.evaluate(el => { el.scrollTop = el.scrollHeight; });
      await page.waitForFunction(previous => document.querySelectorAll('#ebd-topic-matches a').length > previous, count);
      count = await links.count();
      assert(count <= entries.length);
    }
    assert.deepEqual(await links.evaluateAll(items => items.map(item => item.href)), entries.map(row => row.href), 'Scrolling skipped or duplicated entries');
    // Start from a fresh first page, then cross its boundary using only the keyboard.
    await input.fill(' ');
    await input.press('ArrowDown');
    for (let index = 1; index <= 24; index++) await page.keyboard.press('ArrowDown');
    assert.equal(await page.evaluate(() => document.activeElement.href), entries[24].href, 'Keyboard could not reach a later batch');
    await page.keyboard.press('Escape');
  }
  await input.fill('  ' + entries.at(-1).name.toLocaleUpperCase() + '  ');
  assert(await links.count() > 0, 'Filtering did not search beyond the first batch');
  assert((await list.locator('strong').allTextContents()).includes(entries.at(-1).name));
  await input.fill('');
  assert.equal(await links.count(), Math.min(24, entries.length), 'Clearing search did not restore choices');
  assert.equal(await list.evaluate(el => el.scrollTop), 0, 'Filtering retained an old scroll position');
  await input.press('ArrowDown');
  assert(await links.first().evaluate(el => el === document.activeElement));
  await page.keyboard.press('Escape');
  assert(await results.isHidden());
  assert(await input.evaluate(el => el === document.activeElement));
  await input.click();
  assert(await results.isVisible(), 'Clicking the focused field did not reopen choices');
  await page.getByRole('heading', { name: 'Manage Topics', exact: true }).click();
  assert(await results.isHidden(), 'Outside click did not close choices');
  await page.locator('.ehrman-organization .nav-tab').last().focus();
  await page.keyboard.press('Tab');
  assert(await input.evaluate(el => el === document.activeElement));
  assert(await results.isVisible(), 'Keyboard focus did not open choices');
  await page.locator('.ehrman-organization .nav-tab').first().focus();
  assert(await results.isHidden(), 'Leaving the search by keyboard did not close choices');
  for (const width of [1440, 768, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await input.click();
    assert(await results.isVisible());
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Focus choices overflow at ' + width);
    assert(await list.evaluate(el => el.clientHeight <= 360), 'Choices are not scrollable');
    assert.equal(await page.locator('.ehrman-organization__search-controls .button:visible').count(), 2, 'Expected only Browse All and Add');
    await page.screenshot({ path: path.join(output, `focus-choices-${width}.png`), fullPage: true });
    await input.press('Escape');
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await input.click();
  const selected = entries[0].name;
  await input.press('ArrowDown');
  await page.keyboard.press('Enter');
  await page.locator('.ehrman-organization__definition').waitFor();
  assert.equal(await page.locator('.ehrman-organization__definition dd').first().innerText(), selected, 'Empty-query selection opened the wrong item');
  await page.goto(url);
}

async function checkSearchFallback(browser, context, url, plural) {
  for (const javaScriptEnabled of [false, true]) {
    const fallback = await browser.newContext({ javaScriptEnabled, reducedMotion: 'reduce', storageState: await context.storageState() });
    try {
      const page = await fallback.newPage();
      if (javaScriptEnabled) await page.route('**/search-organization.js*', route => route.abort());
      await page.goto(url);
      const search = page.getByRole('button', { name: 'Search ' + plural, exact: true });
      assert(await search.isVisible(), 'Fallback missing when live search is unavailable');
      await page.locator('#ebd-find-topic').fill('paul');
      await search.click();
      await page.waitForURL(destination => destination.searchParams.get('s') === 'paul', { waitUntil: 'load' });
      assert.equal(new URL(page.url()).searchParams.get('s'), 'paul');
      assert.equal(new URL(page.url()).searchParams.get('tab'), new URL(url).searchParams.get('tab'));
      await page.locator('.ehrman-organization__topics tbody tr').first().waitFor();
      assert(await page.locator('.ehrman-organization__topics tbody tr').count() > 0, 'Fallback search returned no results');
    } finally { await fallback.close(); }
  }
}

async function checkDescription(browser, context, page, { url, id, read, tab, idField, output }) {
  const original = read(id);
  await page.goto(url + '&edit=' + id);
  await page.getByRole('link', { name: 'Edit Description', exact: true }).click();
  assert.equal(await page.getByLabel('Description', { exact: true }).inputValue(), original.description);
  assert.equal(await page.locator('[data-ebd-link-group], [name="name"]').count(), 0);
  assert.equal(await page.locator('#ebd-topic-description-safeguards').count(), tab === 'topics' ? 1 : 0);
  if (tab === 'topics') assert.match(await page.locator('#ebd-topic-description-safeguards').innerText(), /future AI topic assignments.*does not reassign existing posts[\s\S]*regenerated before approval/);
  await page.getByLabel('Description', { exact: true }).fill('Unsaved change');
  await page.getByRole('link', { name: 'Cancel', exact: true }).click();
  assert.deepEqual(read(id), original, 'Cancel saved a description');
  await page.getByRole('link', { name: 'Edit Description', exact: true }).click();
  await page.getByLabel('Description', { exact: true }).fill('');
  await page.getByRole('button', { name: 'Save Description', exact: true }).click();
  assert.equal(await page.getByLabel('Description', { exact: true }).evaluate(el => el.validity.valueMissing), true);
  const nonce = await page.locator('[name="_wpnonce"]').inputValue();
  const revision = await page.locator('[name="revision"]').inputValue();
  const form = { _wpnonce: nonce, organization_action: 'description', [idField]: String(id), revision, description: '  ' };
  const invalid = await page.request.post(url + '&edit=' + id, { form });
  assert.match(await invalid.text(), /Enter a description of 1-4000 characters/);
  const rejected = await page.request.post(url, { form: { ...form, _wpnonce: 'wrong', description: 'Bad nonce' } });
  assert.equal(rejected.status(), 403);
  assert.deepEqual(read(id), original);
  const stale = await context.newPage();
  await stale.goto(url + '&edit=' + id + '&description=edit');
  const description = 'Clarifies the same subject while preserving its existing scope.';
  await page.getByLabel('Description', { exact: true }).fill(description);
  // Only the description is writable, even with forged additional fields.
  await page.locator('[data-ebd-description-form]').evaluate(el => {
    for (const [name, value] of Object.entries({ name: 'Forbidden rename', 'category_ids[]': '999999', 'subject_area_ids[]': '999999', 'topic_ids[]': '999999' })) {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; el.append(input);
    }
  });
  await page.getByRole('button', { name: 'Save Description', exact: true }).click();
  await page.locator('.notice-success').waitFor();
  assert.equal(new URL(page.url()).searchParams.get('tab'), tab);
  assert.match(await page.locator('.notice-success').innerText(), /Description saved/);
  assert.deepEqual(read(id), { ...original, description });
  await stale.getByLabel('Description', { exact: true }).fill('Older browser edit');
  await stale.getByRole('button', { name: 'Save Description', exact: true }).click();
  await stale.locator('.notice-error').waitFor();
  assert.match(await stale.locator('.notice-error').innerText(), /Cancel and reopen/);
  assert.equal(await stale.getByLabel('Description', { exact: true }).inputValue(), 'Older browser edit');
  assert.equal(await stale.locator('[name="revision"]').inputValue(), revision, 'Stale revision silently refreshed');
  assert.deepEqual(read(id), { ...original, description });
  await stale.close();
  for (const width of [1440, 768, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(url + '&edit=' + id + '&description=edit');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Description edit overflow: ' + tab + ' ' + width);
    await page.screenshot({ path: path.join(output, `edit-description-${width}.png`), fullPage: true });
  }
  const noJs = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce', storageState: await context.storageState() });
  try {
    const native = await noJs.newPage();
    await native.goto(url + '&edit=' + id);
    await native.getByRole('link', { name: 'Edit Description', exact: true }).click();
    await native.getByLabel('Description', { exact: true }).fill(original.description);
    await native.getByRole('button', { name: 'Save Description', exact: true }).click();
    await native.locator('.notice-success').waitFor();
    assert.deepEqual(read(id), original, 'No-JS description save failed');
  } finally { await noJs.close(); }
}

async function checkOrganization(subjectAreas) {
  const output = outputFor(subjectAreas ? 'subject-area-organization-ui' : 'topic-organization-ui');
  const url = base + '/wp-admin/admin.php?page=ehrman-search-organization&tab=' + (subjectAreas ? 'subject-areas' : 'topics');
  const singular = subjectAreas ? 'Subject Area' : 'Topic';
  const plural = subjectAreas ? 'Subject Areas' : 'Topics';
  const nameLabel = subjectAreas ? 'Subject area name' : 'Topic name';
  const idField = subjectAreas ? 'subject_area_id' : 'topic_id';
  const service = subjectAreas ? 'Subject_Area_Organization_Service' : 'Topic_Organization_Service';
  const recordMethod = subjectAreas ? 'area' : 'topic';
  cli(['eval', 'if (!defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL || !defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON) throw new RuntimeException("Topic tests require an isolated site.");']);
  const baseline = JSON.parse(cli(['eval', 'echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());']));
  const cache = JSON.parse(cli(['eval', 'echo wp_json_encode(get_option("ehrman_discovery_ai_cache_version", false));']));
  const categories = JSON.parse(cli(['eval', 'echo wp_json_encode((new EhrmanBlogDiscovery\\Topic_Organization_Service())->categories());']));
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  // Avoid WordPress core view-transition errors during rapid headless navigation.
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const errors = [];
  const createdIds = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  fs.mkdirSync(output, { recursive: true });
  const readTopic = id => JSON.parse(cli(['eval', `echo wp_json_encode((new EhrmanBlogDiscovery\\${service}())->${recordMethod}(${id}));`]));
  try {
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(base + '/wp-admin/admin.php?page=ehrman-search-organization');
    assert.equal(await page.locator('.nav-tab-active').innerText(), 'Subject Areas');
    assert.deepEqual(await page.locator('.ehrman-organization .nav-tab').allTextContents(), ['Subject Areas', 'Categories', 'Topics', 'Export']);
    await page.goto(url);
    await page.getByRole('heading', { name: 'Manage Topics', exact: true }).waitFor();
    await page.locator('.ehrman-organization__search-controls button[type="submit"]').waitFor({ state: 'hidden' });
    assert.deepEqual(await page.locator('.ehrman-organization__search-controls .button:visible').allTextContents(), ['Browse All ' + plural, 'Add ' + singular]);
    assert.equal(await page.locator('.nav-tab-active').innerText(), plural);
    assert.equal(await page.locator('.ehrman-organization__topics').count(), 0, 'Topics listed by default');
    await checkFocusChoices(page, url, output);
    await checkSearchFallback(browser, context, url, plural);
    await page.screenshot({ path: path.join(output, 'search-desktop.png'), fullPage: true });
    await page.locator('#ebd-find-topic').fill('paul');
    const matches = page.locator('#ebd-topic-matches a');
    assert(await matches.count() > 0, 'Typing did not show matching topics');
    assert((await matches.first().locator('span').innerText()).length > 0, 'Match omitted its categories');
    const selectedTopic = await matches.first().locator('strong').innerText();
    await page.locator('#ebd-find-topic').press('ArrowDown');
    assert(await matches.first().evaluate(el => el === document.activeElement));
    await page.keyboard.press('Enter');
    await page.getByRole('heading', { name: 'Edit Category Links', exact: true }).waitFor();
    assert.equal(await page.locator('.ehrman-organization__definition dd').first().innerText(), selectedTopic);
    await page.getByRole('link', { name: 'Back to ' + plural, exact: true }).click();
    await page.locator('#ebd-find-topic').fill('nonexistent-topic-xyz-123');
    assert.equal(await page.locator('[data-ebd-topic-status]').innerText(), subjectAreas ? 'No subject areas found.' : 'No topics found.');
    await page.locator('#ebd-find-topic').fill('a');
    assert(await matches.count() > 0 && await matches.count() <= 24, 'Live matches are not bounded');
    if (subjectAreas) await page.locator('#ebd-find-topic').press('Enter');
    else await page.getByRole('link', { name: 'View All Matches', exact: true }).click();
    assert.equal(new URL(page.url()).searchParams.get('s'), 'a');
    assert(await page.locator('.ehrman-organization__topics tbody tr').count() > 0);
    if (subjectAreas) assert.equal(new URL(page.url()).searchParams.get('tab'), 'subject-areas');
    await page.getByRole('link', { name: 'Clear Search', exact: true }).click();
    assert.equal(await page.locator('.ehrman-organization__topics').count(), 0);
    await page.getByRole('link', { name: 'Browse All ' + plural, exact: true }).click();
    assert(await page.locator('.ehrman-organization__topics tbody tr').count() > 0);
    await page.screenshot({ path: path.join(output, 'topics-desktop.png'), fullPage: true });
    if (!subjectAreas) {
      await page.getByRole('link', { name: 'Next', exact: true }).click();
      assert.equal(new URL(page.url()).searchParams.get('pagenum'), '2');
      assert.equal(new URL(page.url()).searchParams.get('view'), 'all');
    }
    await page.getByRole('link', { name: 'Add ' + singular, exact: true }).click();
    await page.getByRole('button', { name: 'Add category', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Create ' + singular, exact: true }).isDisabled(), !subjectAreas);
    const description = page.getByLabel('Description', { exact: true });
    assert.equal(await description.getAttribute('aria-describedby'), 'ebd-description-guidance');
    const guidance = await page.locator('#ebd-description-guidance').innerText();
    assert.match(guidance, subjectAreas ? /broad scope.*20-40 words/ : /topic's scope.*25-50 words/);
    assert.equal(guidance.includes('guide AI topic selection'), !subjectAreas);
    const example = page.locator('.ehrman-organization__description-example');
    assert.equal(await example.getAttribute('open'), null, 'Description example should start collapsed');
    await example.locator('summary').focus();
    await page.keyboard.press('Enter');
    assert(await example.locator('p').first().isVisible());
    assert.equal(await example.locator('strong').innerText(), subjectAreas ? 'Manuscripts, Canon, and Bible Translations' : 'Composite Pauline Letters');
    await page.keyboard.press('Enter');
    assert.equal(await example.getAttribute('open'), null);
    const name = singular + ' browser fixture ' + Date.now();
    await page.getByLabel(nameLabel, { exact: true }).fill(name);
    await page.getByLabel('Description', { exact: true }).fill('A synthetic topic for checking category membership without changing real post assignments.');
    const choose = async category => {
      await page.getByRole('button', { name: 'Add category', exact: true }).click();
      const input = page.getByRole('combobox', { name: 'Search existing categories', exact: true });
      await input.fill(category.name);
      await page.getByRole('option', { name: category.name, exact: true }).click();
      assert.equal(await input.count(), 0, 'Picker stayed open after selection');
      assert(await page.getByRole('button', { name: 'Add category', exact: true }).evaluate(el => el === document.activeElement));
    };
    let emptyId;
    if (subjectAreas) {
      assert(await page.locator('#ebd-category-warning').isHidden());
      assert.equal(await page.locator('#ebd-category-warning').textContent(), '');
      await page.getByRole('button', { name: 'Create Subject Area', exact: true }).click();
      await page.locator('.notice-success').waitFor();
      emptyId = Number(new URL(page.url()).searchParams.get('edit'));
      assert(emptyId > 0);
      createdIds.push(emptyId);
      assert.deepEqual(readTopic(emptyId).categories, []);
      assert.equal(Number(readTopic(emptyId).post_count), 0);
    }
    await choose(categories[0]);
    await choose(categories[1]);
    await page.getByRole('button', { name: subjectAreas ? 'Save Category Links' : 'Create Topic', exact: true }).click();
    await page.locator('.notice-success').waitFor();
    const id = Number(new URL(page.url()).searchParams.get('edit'));
    assert(id > 0);
    if (subjectAreas) assert.equal(id, emptyId);
    else createdIds.push(id);
    const created = readTopic(id);
    assert.equal(created.categories.length, 2);
    assert.equal(await page.locator('[name="name"]').count(), 0);
    assert.equal(await page.locator('[name="description"]').count(), 0);
    assert.equal(await page.locator('#ebd-description-guidance').count(), 0, 'Creation guidance leaked into link-only editor');
    const stale = await context.newPage();
    await stale.goto(url + '&edit=' + id);
    await page.getByRole('button', { name: 'Remove category: ' + categories[0].name, exact: true }).click();
    await choose(categories[2]);
    await page.getByRole('button', { name: 'Save Category Links', exact: true }).click();
    await page.locator('.notice-success').waitFor();
    let changed = readTopic(id);
    assert.deepEqual(changed.categories.map(item => Number(item.id)), [Number(categories[1].id), Number(categories[2].id)]);
    for (const key of ['id', 'name', 'slug', 'description']) assert.equal(changed[key], created[key]);
    if (!subjectAreas) assert.equal(changed.post_count, created.post_count);
    else assert.equal(new URL(page.url()).searchParams.get('tab'), 'subject-areas');
    await stale.getByRole('button', { name: 'Save Category Links', exact: true }).click();
    await stale.locator('.notice-error').waitFor();
    assert.match(await stale.locator('.notice-error').innerText(), /changed after you opened/);
    assert.deepEqual(readTopic(id), changed);
    await stale.close();
    await page.getByRole('button', { name: 'Remove category: ' + categories[1].name, exact: true }).click();
    await page.getByRole('button', { name: 'Remove category: ' + categories[2].name, exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'Save Category Links', exact: true }).isDisabled(), !subjectAreas);
    assert.equal(await page.locator('#ebd-category-warning').isVisible(), !subjectAreas);
    if (subjectAreas) {
      await page.getByRole('button', { name: 'Save Category Links', exact: true }).click();
      await page.locator('.notice-success').waitFor();
      changed = readTopic(id);
      assert.deepEqual(changed.categories, []);
      assert.equal(Number(changed.post_count), 0);
    }
    await page.getByRole('button', { name: 'Add category', exact: true }).click();
    const picker = page.getByRole('combobox', { name: 'Search existing categories', exact: true });
    await picker.fill(categories[2].name);
    await picker.press('ArrowDown');
    await picker.press('Enter');
    assert.equal(await picker.count(), 0);
    // Forged or malformed selections must not clear links or rename records.
    const nonce = await page.locator('[name="_wpnonce"]').inputValue();
    const revision = await page.locator('[name="revision"]').inputValue();
    const invalid = await page.request.post(url, { form: { _wpnonce: nonce, [idField]: String(id), organization_action: 'categories', revision, name: 'Malicious rename', ...(subjectAreas ? { 'category_ids[]': '0' } : {}) } });
    assert.match(await invalid.text(), subjectAreas ? /Select valid categories/ : /Select at least one valid category/);
    assert.deepEqual(readTopic(id), changed);
    const malformed = await page.request.post(url, { form: { _wpnonce: nonce, [idField]: String(id), organization_action: 'categories', revision, category_ids: String(categories[0].id) } });
    assert.match(await malformed.text(), subjectAreas ? /Select valid categories/ : /Select at least one valid category/);
    assert.deepEqual(readTopic(id), changed);
    const badNonce = await page.request.post(url, { form: { _wpnonce: 'wrong', [idField]: String(id), organization_action: 'categories', 'category_ids[]': String(categories[0].id), revision } });
    assert.equal(badNonce.status(), 403);
    assert.deepEqual(readTopic(id), changed);
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url + '&action=new');
      await example.locator('summary').click();
      assert(await example.locator('strong').isVisible());
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Horizontal overflow in description guidance at ' + width);
      await page.screenshot({ path: path.join(output, `description-example-${width}.png`), fullPage: true });
      await page.goto(url + '&edit=' + id);
      assert(await page.getByRole('button', { name: 'Save Category Links', exact: true }).isVisible());
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Horizontal overflow in topic form at ' + width);
      await page.getByRole('button', { name: 'Add category', exact: true }).click();
      await page.screenshot({ path: path.join(output, `topic-form-${width}.png`), fullPage: true });
      await page.getByRole('combobox', { name: 'Search existing categories', exact: true }).press('Escape');
      await page.goto(url);
      assert.equal(await page.locator('.ehrman-organization__topics').count(), 0);
      await page.locator('#ebd-find-topic').fill('paul');
      assert(await page.locator('[data-ebd-topic-results]').isVisible());
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Horizontal overflow in topic search at ' + width);
      await page.screenshot({ path: path.join(output, `search-${width}.png`), fullPage: true });
      await page.locator('#ebd-find-topic').press('Escape');
      assert(await page.locator('[data-ebd-topic-results]').isHidden());
      await page.getByRole('link', { name: 'Browse All ' + plural, exact: true }).click();
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Horizontal overflow in topic list at ' + width);
      await page.screenshot({ path: path.join(output, `topics-${width}.png`), fullPage: true });
    }
    await page.locator('#ebd-find-topic').fill(name);
    await page.locator('#ebd-find-topic').press('Enter');
    assert.equal(await page.locator('.ehrman-organization__topics tbody tr').count(), 1);
    assert.match(await page.locator('.ehrman-organization__topics tbody').innerText(), new RegExp(name));
    await page.getByRole('link', { name: 'Add ' + singular, exact: true }).click();
    await page.getByLabel(nameLabel, { exact: true }).fill(name);
    await page.getByLabel('Description', { exact: true }).fill('Duplicate fixture');
    await choose(categories[0]);
    await page.getByRole('button', { name: 'Create ' + singular, exact: true }).click();
    await page.locator('.notice-error').waitFor();
    assert.equal(await page.getByLabel(nameLabel, { exact: true }).inputValue(), name, 'Invalid form lost entered values');
    assert.match(await page.locator('.notice-error').innerText(), /already used/);
    // WordPress core navigation animations can cover links when scripts are disabled.
    const noJs = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce', storageState: await context.storageState() });
    const native = await noJs.newPage();
    await native.goto(url);
    await native.locator('#ebd-find-topic').fill('paul');
    await native.getByRole('button', { name: 'Search ' + plural, exact: true }).click();
    assert.equal(new URL(native.url()).searchParams.get('s'), 'paul');
    assert(await native.locator('.ehrman-organization__topics tbody tr').count() > 0, 'No-JS search failed');
    await native.goto(url + '&action=new');
    await native.locator('.ehrman-organization__description-example summary').click();
    assert(await native.locator('.ehrman-organization__description-example strong').isVisible(), 'Description example requires JavaScript');
    assert.equal(await native.locator('#ebd-topic-categories').getAttribute('required') !== null, !subjectAreas);
    if (subjectAreas) {
      await native.getByLabel(nameLabel, { exact: true }).fill(name + ' no-JS');
      await native.getByLabel('Description', { exact: true }).fill('Subject area created before category selection.');
      await native.getByRole('button', { name: 'Create Subject Area', exact: true }).click();
      await native.locator('.notice-success').waitFor();
      const nativeId = Number(new URL(native.url()).searchParams.get('edit'));
      assert(nativeId > 0);
      createdIds.push(nativeId);
      assert.deepEqual(readTopic(nativeId).categories, []);
    }
    await native.goto(url);
    assert.equal(await native.locator('.ehrman-organization__topics').count(), 0);
    await native.locator('#ebd-find-topic').fill(name);
    await native.getByRole('button', { name: 'Search ' + plural, exact: true }).click();
    assert.equal(await native.locator('.ehrman-organization__topics tbody tr').count(), subjectAreas ? 2 : 1);
    await native.locator('.ehrman-organization__topics tbody tr').filter({ has: native.getByRole('cell', { name, exact: true }) }).getByRole('link', { name: 'Edit Category Links', exact: true }).click();
    assert(await native.locator('#ebd-topic-categories').isVisible(), 'Native fallback is unavailable');
    await native.locator('#ebd-topic-categories').selectOption([String(categories[0].id)]);
    await native.getByRole('button', { name: 'Save Category Links', exact: true }).click();
    await native.locator('.notice-success').waitFor();
    assert.deepEqual(readTopic(id).categories.map(item => Number(item.id)), [Number(categories[0].id)]);
    if (subjectAreas) {
      await native.locator('#ebd-topic-categories').selectOption([]);
      await native.getByRole('button', { name: 'Save Category Links', exact: true }).click();
      await native.locator('.notice-success').waitFor();
      assert.deepEqual(readTopic(id).categories, []);
    }
    await noJs.close();
    await checkDescription(browser, context, page, { url, id, read: readTopic, tab: subjectAreas ? 'subject-areas' : 'topics', idField, output });
    assert.deepEqual(errors, []);
    console.log(plural + ' browser checks passed: tab order/default, search, creation, links, separate description editing, Cancel, safeguards, nonce and stale-save rejection, keyboard, no-JS, desktop/tablet/mobile.');
  } finally {
    const linksTable = subjectAreas ? 'subject_area_categories' : 'topic_categories';
    const recordsTable = subjectAreas ? 'subject_areas' : 'topics';
    for (const id of createdIds) cli(['eval', `global $wpdb; $t=EhrmanBlogDiscovery\\Database::tables(); $wpdb->delete($t['${linksTable}'], array('${idField}'=>${id})); $wpdb->delete($t['${recordsTable}'], array('id'=>${id}));`]);
    cli(['eval', cache === false ? 'delete_option("ehrman_discovery_ai_cache_version");' : `update_option("ehrman_discovery_ai_cache_version", ${Number(cache)});`]);
    await browser.close();
  }
  assert.deepEqual(JSON.parse(cli(['eval', 'echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());'])), baseline, 'Browser fixtures changed stored counts');
}

async function checkCategories() {
  cli(['eval', 'if (!defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL || !defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON) throw new RuntimeException("Category tests require an isolated site.");']);
  const url = base + '/wp-admin/admin.php?page=ehrman-search-organization&tab=categories';
  const output = outputFor('category-organization-ui');
  const baseline = JSON.parse(cli(['eval', 'echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());']));
  const cache = JSON.parse(cli(['eval', 'echo wp_json_encode(get_option("ehrman_discovery_ai_cache_version", false));']));
  const areas = JSON.parse(cli(['eval', 'echo wp_json_encode((new EhrmanBlogDiscovery\\Subject_Area_Organization_Service())->areas());']));
  const topics = JSON.parse(cli(['eval', 'echo wp_json_encode(array_values(array_filter((new EhrmanBlogDiscovery\\Topic_Organization_Service())->topics(), array(EhrmanBlogDiscovery\\Topic_Organization_Service::class,"editable"))));']));
  const read = id => JSON.parse(cli(['eval', `echo wp_json_encode((new EhrmanBlogDiscovery\\Category_Organization_Service())->category(${id}));`]));
  const allCategories = JSON.parse(cli(['eval', 'echo wp_json_encode((new EhrmanBlogDiscovery\\Category_Organization_Service())->categories());']));
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const errors = [];
  const ids = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  fs.mkdirSync(output, { recursive: true });
  const choose = async (kind, row, keyboard = false) => {
    await page.getByRole('button', { name: 'Add ' + kind, exact: true }).click();
    const input = page.getByRole('combobox', { name: 'Search existing ' + (kind === 'topic' ? 'topics' : 'subject areas'), exact: true });
    const list = page.locator('#' + await input.getAttribute('aria-controls'));
    assert(await list.getByRole('option').count() > 0, 'Empty focus list');
    await input.fill(row.name);
    if (keyboard) { await input.press('ArrowDown'); await input.press('Enter'); }
    else await list.getByRole('option', { name: row.name, exact: true }).click();
    assert(await input.isHidden(), 'Relationship picker remained open');
    assert(await page.getByRole('button', { name: 'Add ' + kind, exact: true }).evaluate(el => el === document.activeElement));
  };
  try {
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(url);
    assert.equal(await page.locator('.nav-tab-active').innerText(), 'Categories');
    await page.locator('.ehrman-organization__search-controls button[type="submit"]').waitFor({ state: 'hidden' });
    assert.deepEqual(await page.locator('.ehrman-organization__search-controls .button:visible').allTextContents(), ['Browse All Categories', 'Add Category']);
    assert.equal(await page.locator('.ehrman-organization__topics').count(), 0);
    await checkFocusChoices(page, url, output);
    await checkSearchFallback(browser, context, url, 'Categories');
    await page.locator('#ebd-find-topic').fill('paul');
    await page.locator('#ebd-topic-matches a').first().click();
    await page.getByRole('heading', { name: 'Edit Relationships', exact: true }).waitFor();
    assert.equal(await page.locator('[data-ebd-link-group]').count(), 2);
    await page.getByRole('link', { name: 'Back to Categories', exact: true }).click();
    await page.getByRole('link', { name: 'Browse All Categories', exact: true }).click();
    assert.equal(await page.locator('.ehrman-organization__topics tbody tr').count(), 25);
    await checkCategoryDirectory(page, allCategories, output);
    const firstCategory = await page.locator('.ehrman-organization__category-name').first().innerText();
    await page.getByRole('link', { name: firstCategory, exact: true }).click();
    await page.getByRole('heading', { name: 'Edit Relationships', exact: true }).waitFor();
    assert.equal(await page.locator('.ehrman-organization__definition dd').first().innerText(), firstCategory);
    await page.goto(url + '&view=all');
    await page.getByRole('link', { name: 'Next', exact: true }).click();
    assert.equal(new URL(page.url()).searchParams.get('tab'), 'categories');
    assert.equal(new URL(page.url()).searchParams.get('pagenum'), '2');
    await page.getByRole('link', { name: 'Add Category', exact: true }).click();
    await page.getByRole('button', { name: 'Add topic', exact: true }).waitFor();
    const create = page.getByRole('button', { name: 'Create Category', exact: true });
    const name = 'Category browser fixture ' + Date.now();
    await page.getByLabel('Category name', { exact: true }).fill(name);
    await page.getByLabel('Description', { exact: true }).fill('Groups existing topics for a browser test.');
    assert(await create.isDisabled());
    await choose('subject area', areas[0]);
    assert(await create.isDisabled(), 'Subject area alone enabled submission without a topic');
    await page.getByRole('button', { name: 'Remove subject area: ' + areas[0].name, exact: true }).click();
    await choose('topic', topics[0], true);
    assert(await create.isEnabled(), 'Category creation requires a subject area');
    assert(await page.locator('#ebd-category-areas-warning').isHidden());
    await choose('topic', topics[1]);
    await create.click();
    await page.locator('.notice-success').waitFor();
    const id = Number(new URL(page.url()).searchParams.get('edit'));
    assert(id > 0); ids.push(id);
    assert.equal(read(id).subject_areas.length, 0);
    assert.equal(read(id).topics.length, 2);
    await choose('subject area', areas[0]);
    await choose('subject area', areas[1]);
    await page.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await page.locator('.notice-success').waitFor();
    const created = read(id);
    assert.equal(created.subject_areas.length, 2);
    assert.equal(created.topics.length, 2);
    assert.equal(await page.locator('[name="name"], [name="description"]').count(), 0);
    const stale = await context.newPage();
    await stale.goto(url + '&edit=' + id);
    await page.getByRole('button', { name: 'Remove subject area: ' + areas[0].name, exact: true }).click();
    await page.getByRole('button', { name: 'Remove topic: ' + topics[0].name, exact: true }).click();
    await choose('subject area', areas[2], true);
    await choose('topic', topics[2]);
    await page.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await page.locator('.notice-success').waitFor();
    const changed = read(id);
    for (const key of ['name', 'slug', 'description']) assert.equal(changed[key], created[key]);
    assert.deepEqual(changed.subject_areas.map(row => Number(row.id)), [Number(areas[1].id), Number(areas[2].id)]);
    assert.deepEqual(changed.topics.map(row => Number(row.id)), [Number(topics[1].id), Number(topics[2].id)]);
    await stale.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await stale.locator('.notice-error').waitFor();
    assert.match(await stale.locator('.notice-error').innerText(), /changed after you opened/);
    assert.deepEqual(read(id), changed);
    await stale.close();
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url + '&edit=' + id);
      await page.getByRole('button', { name: 'Add topic', exact: true }).click();
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
      await page.screenshot({ path: path.join(output, `edit-${width}.png`), fullPage: true });
      await page.getByRole('combobox', { name: 'Search existing topics', exact: true }).press('Escape');
      await page.goto(url + '&action=new');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
      await page.screenshot({ path: path.join(output, `new-${width}.png`), fullPage: true });
    }
    await page.goto(url + '&edit=' + id);
    const nonce = await page.locator('[name="_wpnonce"]').inputValue();
    const revision = await page.locator('[name="revision"]').inputValue();
    const invalid = await page.request.post(url, { form: { _wpnonce: nonce, category_id: String(id), organization_action: 'links', revision, name: 'Forged rename', 'subject_area_ids[]': String(areas[0].id) } });
    assert.match(await invalid.text(), /Select at least one valid topic/);
    assert.deepEqual(read(id), changed);
    const malformed = await page.request.post(url, { form: { _wpnonce: nonce, category_id: String(id), organization_action: 'links', revision, subject_area_ids: String(areas[0].id), 'topic_ids[]': String(topics[0].id) } });
    assert.match(await malformed.text(), /Select valid subject areas and topics/);
    assert.deepEqual(read(id), changed);
    const badNonce = await page.request.post(url, { form: { _wpnonce: 'wrong', category_id: String(id), organization_action: 'links', revision } });
    assert.equal(badNonce.status(), 403);
    for (const row of changed.topics) await page.getByRole('button', { name: 'Remove topic: ' + row.name, exact: true }).click();
    assert(await page.getByRole('button', { name: 'Save Relationships', exact: true }).isDisabled());
    assert(await page.locator('#ebd-category-topics-warning').isVisible());
    await choose('topic', topics[0]);
    for (const row of changed.subject_areas) await page.getByRole('button', { name: 'Remove subject area: ' + row.name, exact: true }).click();
    assert(await page.getByRole('button', { name: 'Save Relationships', exact: true }).isEnabled());
    assert(await page.locator('#ebd-category-areas-warning').isHidden());
    await page.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await page.locator('.notice-success').waitFor();
    assert.equal(read(id).subject_areas.length, 0);
    assert.deepEqual(read(id).topics.map(row => Number(row.id)), [Number(topics[0].id)]);
    await page.goto(url + '&action=new');
    await page.getByLabel('Category name', { exact: true }).fill(name);
    await page.getByLabel('Description', { exact: true }).fill('Duplicate');
    await choose('subject area', areas[0]); await choose('topic', topics[0]);
    await page.getByRole('button', { name: 'Create Category', exact: true }).click();
    await page.locator('.notice-error').waitFor();
    assert.match(await page.locator('.notice-error').innerText(), /already used/);
    assert.equal(await page.getByLabel('Category name', { exact: true }).inputValue(), name);
    assert.equal(await page.getByRole('button', { name: 'Remove topic: ' + topics[0].name, exact: true }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Remove subject area: ' + areas[0].name, exact: true }).count(), 1);
    const noJs = await browser.newContext({ javaScriptEnabled: false, reducedMotion: 'reduce', storageState: await context.storageState() });
    const native = await noJs.newPage();
    await native.goto(url + '&view=all');
    const nativeDisclosure = native.locator('.ehrman-organization__topic-disclosure').first();
    assert.equal(await nativeDisclosure.getAttribute('open'), null);
    await nativeDisclosure.locator('summary').focus();
    await native.keyboard.press('Enter');
    assert(await nativeDisclosure.locator('.ehrman-organization__topic-full-list').isVisible(), 'Topic expansion requires JavaScript');
    await native.keyboard.press('Enter');
    assert(await nativeDisclosure.locator('.ehrman-organization__topic-full-list').isHidden(), 'Topic collapse requires JavaScript');
    await native.goto(url + '&action=new');
    assert.equal(await native.getByRole('listbox', { name: 'Subject Areas', exact: true }).getAttribute('required'), null);
    assert.notEqual(await native.getByRole('listbox', { name: 'Topics', exact: true }).getAttribute('required'), null);
    await native.getByLabel('Category name', { exact: true }).fill(name + ' native');
    await native.getByLabel('Description', { exact: true }).fill('An unlinked category created without JavaScript.');
    await native.getByRole('listbox', { name: 'Topics', exact: true }).selectOption([String(topics[0].id)]);
    await native.getByRole('button', { name: 'Create Category', exact: true }).click();
    await native.locator('.notice-success').waitFor();
    const nativeId = Number(new URL(native.url()).searchParams.get('edit'));
    assert(nativeId > 0); ids.push(nativeId);
    assert.equal(read(nativeId).subject_areas.length, 0);
    assert.deepEqual(read(nativeId).topics.map(row => Number(row.id)), [Number(topics[0].id)]);
    await native.goto(url + '&edit=' + id);
    // An already-open form from the former Tools page must retain its POST body.
    await native.locator('form[method="post"]').evaluate((form, legacy) => { form.action = legacy; }, url.replace('/admin.php?', '/tools.php?'));
    await native.getByRole('listbox', { name: 'Subject Areas', exact: true }).selectOption([String(areas[0].id)]);
    await native.getByRole('listbox', { name: 'Topics', exact: true }).selectOption([String(topics[0].id)]);
    await native.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await native.locator('.notice-success').waitFor();
    assert.deepEqual(read(id).subject_areas.map(row => Number(row.id)), [Number(areas[0].id)]);
    assert.deepEqual(read(id).topics.map(row => Number(row.id)), [Number(topics[0].id)]);
    await native.getByRole('listbox', { name: 'Subject Areas', exact: true }).selectOption([]);
    await native.getByRole('button', { name: 'Save Relationships', exact: true }).click();
    await native.locator('.notice-success').waitFor();
    assert.equal(read(id).subject_areas.length, 0);
    assert.deepEqual(read(id).topics.map(row => Number(row.id)), [Number(topics[0].id)]);
    await noJs.close();
    await checkDescription(browser, context, page, { url, id, read, tab: 'categories', idField: 'category_id', output });
    assert.deepEqual(errors, []);
    console.log('Categories browser checks passed: search, two independent selectors, optional subject areas, required topics, linking later, shared links, separate description editing, stale saves, invalid POST, keyboard, no-JS, desktop/tablet/mobile.');
  } finally {
    for (const id of ids) cli(['eval', `global $wpdb; $t=EhrmanBlogDiscovery\\Database::tables(); foreach (array("topic_categories","subject_area_categories") as $key) $wpdb->delete($t[$key],array("category_id"=>${id})); $wpdb->delete($t["categories"],array("id"=>${id}));`]);
    cli(['eval', cache === false ? 'delete_option("ehrman_discovery_ai_cache_version");' : `update_option("ehrman_discovery_ai_cache_version", ${Number(cache)});`]);
    await browser.close();
  }
  assert.deepEqual(JSON.parse(cli(['eval', 'echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());'])), baseline);
}

async function checkCategoryDirectory(page, categories, output) {
  const table = page.locator('.ehrman-organization__categories');
  assert.deepEqual(await table.locator('thead th').allTextContents(), ['Category', 'Subject Areas', 'Topics']);
  const rows = table.locator('tbody tr');
  for (const row of await rows.all()) {
    const name = await row.locator('.ehrman-organization__category-name').innerText();
    const record = categories.find(item => item.name === name);
    assert(record, 'Unknown category: ' + name);
    const areaNames = await row.locator('.ehrman-organization__category-areas > span:last-child').innerText();
    assert.deepEqual(areaNames === 'None linked' ? [] : areaNames.split('; ').sort(), record.subject_areas.map(item => item.name).sort());
    const expected = [...record.topics, ...record.protected_topics].map(item => item.name).sort();
    const preview = await row.locator('.ehrman-organization__topic-preview').innerText();
    const details = row.locator('details');
    if (expected.length > 3) {
      assert.equal(await details.count(), 1);
      assert.equal(await details.getAttribute('open'), null);
      const full = (await details.locator('.ehrman-organization__topic-full-list').textContent()).trim().split('; ');
      assert.deepEqual([...full].sort(), expected, 'Topics missing from expanded list');
      assert.equal(preview, full.slice(0, 3).join('; '), 'Preview should contain three topics');
      assert.equal(await details.locator('.ehrman-organization__show-all').innerText(), `Show all ${expected.length} topics`);
      assert(await details.locator('.ehrman-organization__topic-full-list').isHidden());
    } else {
      assert.equal(await details.count(), 0);
      assert.deepEqual(preview === 'None linked' ? [] : preview.split('; ').sort(), expected);
    }
  }
  const details = table.locator('details').first();
  for (const width of [1440, 768, 390]) {
    await page.setViewportSize({ width, height: 1000 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Category directory overflow: ' + width);
    await page.screenshot({ path: path.join(output, `directory-collapsed-${width}.png`), fullPage: true });
    await details.locator('summary').focus();
    await page.keyboard.press('Enter');
    assert(await details.locator('.ehrman-organization__topic-full-list').isVisible());
    assert(await details.locator('.ehrman-organization__topic-preview').isHidden(), 'Expanded preview duplicated topics');
    assert.equal(await details.locator('.ehrman-organization__show-fewer').innerText(), 'Show fewer');
    assert.equal(await table.locator('details[open]').count(), 1, 'Expanding one category opened others');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.screenshot({ path: path.join(output, `directory-expanded-${width}.png`), fullPage: true });
    await page.keyboard.press('Enter');
    assert(await details.locator('.ehrman-organization__topic-full-list').isHidden());
    assert(await details.locator('.ehrman-organization__topic-preview').isVisible());
    if (width === 390) assert(await rows.first().locator('.ehrman-organization__mobile-label').first().isVisible());
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
}

(async () => {
  await checkOrganization(false);
  await checkOrganization(true);
  await checkCategories();
})().catch(error => { console.error(error); process.exitCode = 1; });
