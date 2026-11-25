// Destructive flows use only disposable fixtures on the isolated regression site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const output = outputFor('organization-deletion-ui');
const root = path.resolve(__dirname, '../..');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = code => docker(['wp', 'eval', code, '--allow-root']);
const prefix = '$wpdb=EhrmanBlogDiscovery\\Database::client();$t=EhrmanBlogDiscovery\\Database::tables();';
const option = 'ehrman_organization_deletion';
const organization = base + '/wp-admin/admin.php?page=ehrman-search-organization';
const settings = base + '/wp-admin/admin.php?page=ehrman-blog-discovery';
const scopes = { 'subject-areas': 'subject_area', categories: 'category', topics: 'topic' };

(async () => {
  cli('if (!defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL || !defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON) throw new RuntimeException("Deletion tests require an isolated site.");');
  const old = JSON.parse(cli(`echo wp_json_encode(get_option('${option}',false));`));
  const cache = JSON.parse(cli("echo wp_json_encode(get_option('ehrman_discovery_ai_cache_version',false));"));
  const before = JSON.parse(cli('echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());'));
  const fixtures = JSON.parse(cli(prefix + `$tag=wp_generate_uuid4();$out=array();$path=$wpdb->get_var("SELECT id FROM {$t['browse_paths']} WHERE slug='browse-topics'");foreach(array('subject_areas','categories','topics') as $key){$row=array('name'=>'Deletion UI '.$key.' '.$tag,'slug'=>'delete-ui-'.$tag,'description'=>'Disposable deletion test.');if($key==='subject_areas')$row['browse_path_id']=$path;$wpdb->insert($t[$key],$row);$out[str_replace('_','-',$key)]=(int)$wpdb->insert_id;}echo wp_json_encode($out);`));
  const exists = (tab, id) => cli(prefix + `echo $wpdb->get_var("SELECT COUNT(*) FROM {$t['${tab.replaceAll('-', '_')}']} WHERE id=${id}");`) === '1';
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await context.newPage(); const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
  fs.mkdirSync(output, { recursive: true });
  try {
    cli(prefix + `$wpdb->insert($t['subject_area_categories'],array('subject_area_id'=>${fixtures['subject-areas']},'category_id'=>${fixtures.categories}));`);
    cli(`delete_option('${option}');`);
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    // WordPress focuses the username after a delay; wait before filling either field.
    await page.waitForFunction(() => document.activeElement?.id === 'user_login');
    await page.locator('#user_login').fill(credentials[0]); await page.locator('#user_pass').fill(credentials[1]); credentials.fill('');
    await page.locator('#wp-submit').click();
    try { await page.waitForURL('**/wp-admin/**'); }
    catch (error) {
      await page.screenshot({ path: path.join(output, 'login-failed.png'), fullPage: true });
      console.error('Login failed:', page.url(), await page.locator('#login_error').textContent().catch(() => 'No login message'));
      throw error;
    }
    await page.goto(settings);
    const deletion = page.locator('[data-ebd-deletion-settings]');
    const disabled = deletion.getByRole('radio', { name: 'Disabled', exact: true });
    const enabled = deletion.getByRole('radio', { name: 'Enabled', exact: true });
    const saved = deletion.getByRole('status');
    const publishing = page.locator('[data-ebd-publishing-settings]');
    const initialPublishing = await publishing.locator('input:checked').inputValue();
    assert(await disabled.isChecked());
    assert.equal(await deletion.getByRole('button').count(), 0, 'Deletion must autosave without a save button');
    for (const width of [1440,390]) {
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Settings overflow');
      const layout = await page.locator('.ehrman-admin-setting').evaluateAll(sections => sections.map(section => {
        const heading = section.querySelector('h2');
        const radio = section.querySelector('input[type="radio"]');
        const description = section.querySelector('.description');
        const style = element => ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight'].map(key => getComputedStyle(element)[key]);
        return { heading: style(heading), description: style(description), below: radio.getBoundingClientRect().top > heading.getBoundingClientRect().bottom, left: radio.getBoundingClientRect().left };
      }));
      assert.equal(layout.length, 2);
      assert.deepEqual(layout[0].heading, layout[1].heading, 'Settings headings differ');
      assert.deepEqual(layout[0].description, layout[1].description, 'Settings descriptions differ');
      assert(layout.every(group => group.below), 'Radios must be below the title');
      assert.equal(layout[0].left, layout[1].left, 'Settings radios are not aligned');
      const inlineDescriptions = await page.locator('.ehrman-admin-setting fieldset p').evaluateAll(rows => rows.map(row => {
        const firstCharacter = element => {
          const range = document.createRange();
          range.setStart(element.firstChild, 0); range.setEnd(element.firstChild, 1);
          return range.getBoundingClientRect();
        };
        const label = row.querySelector('label');
        const description = row.querySelector('.description');
        if (!label || !description) return false;
        const labelText = firstCharacter(label.querySelector('strong'));
        const descriptionText = firstCharacter(description);
        return Math.abs(labelText.top - descriptionText.top) <= 2 && descriptionText.left > label.getBoundingClientRect().right;
      }));
      assert.equal(inlineDescriptions.length, 4);
      assert(inlineDescriptions.every(Boolean), 'Each description must start beside its radio label');
      assert.doesNotMatch(await page.locator('.ehrman-admin-setting').allTextContents().then(text => text.join(' ')), /using paid AI requests|Relationship safeguards still apply/);
      await page.screenshot({path:path.join(output, `settings-${width}.png`),fullPage:true});
    }
    for (const [tab, id] of Object.entries(fixtures)) {
      await page.goto(organization + `&tab=${tab}&edit=${id}`);
      assert(await page.getByRole('button', { name: 'Delete', exact: true }).isDisabled());
      await page.getByText('Deletion is disabled in Blog Search settings.', { exact: true }).waitFor();
      // Direct confirmation navigation cannot enable deletion or mutate data.
      await page.goto(organization + `&tab=${tab}&edit=${id}&action=delete`);
      assert(await page.getByRole('button', { name: 'Permanently Delete', exact: true }).isDisabled());
      const nonce = await page.locator('[data-ebd-delete-form] [name="_wpnonce"]').inputValue();
      const response = await page.request.post(organization + `&tab=${tab}&edit=${id}`, { form: { _wpnonce: nonce, organization_action: 'delete', [scopes[tab] + '_id']: String(id), confirm_delete: 'yes', revision: await page.locator('[name="revision"]').inputValue() } });
      assert((await response.text()).includes('Deletion is disabled')); assert(exists(tab, id));
    }
    await page.goto(settings);
    const endpoint = base + '/wp-json/wp/v2/settings';
    const settingsRoute = '**/wp-json/wp/v2/settings*';
    assert.equal((await page.request.post(endpoint, { data: { [option]: 'enabled' } })).status(), 401, 'Cookie authentication requires a nonce');
    assert.equal((await page.request.post(endpoint, { headers: { 'X-WP-Nonce': 'invalid' }, data: { [option]: 'enabled' } })).status(), 403);
    let releaseSave;
    await page.route(settingsRoute, async route => {
      if (route.request().method() === 'POST') {
        assert.deepEqual(route.request().postDataJSON(), { [option]: 'enabled' }, 'Save must only update this setting');
        await new Promise(resolve => { releaseSave = resolve; });
      }
      await route.continue();
    });
    await enabled.check();
    await saved.getByText('Saving...', { exact: true }).waitFor();
    assert(await disabled.isDisabled() && await enabled.isDisabled(), 'Concurrent deletion saves must be blocked');
    assert(await publishing.getByRole('radio', { name: 'Manual', exact: true }).isEnabled(), 'Another group should remain usable');
    for (let attempt = 0; attempt < 100 && !releaseSave; attempt++) await new Promise(resolve => setTimeout(resolve, 10));
    assert(releaseSave, 'Deletion save was not sent');
    releaseSave();
    await saved.getByText('Saved', { exact: true }).waitFor();
    await page.unroute(settingsRoute);
    await page.reload();
    assert(await enabled.isChecked(), 'Autosave did not persist after reload');
    assert.equal(await publishing.locator('input:checked').inputValue(), initialPublishing, 'Deletion save changed publishing preference');
    assert.equal(cli(`echo get_option('${option}');`), 'enabled');
    await page.route(settingsRoute, route => route.request().method() === 'POST'
      ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ code: 'failed', message: 'Test failure' }) }) : route.continue());
    await disabled.check();
    await deletion.getByRole('alert').getByText('The change could not be saved. The current setting has been restored. Please try again.', { exact: true }).waitFor();
    assert(await enabled.isChecked());
    await page.unroute(settingsRoute);
    await page.route(settingsRoute, async route => {
      if (route.request().method() === 'POST') { await route.fetch(); await route.abort(); } else await route.continue();
    });
    await disabled.check();
    await saved.getByText('Saved', { exact: true }).waitFor();
    assert(await disabled.isChecked(), 'Lost response recovery did not use the saved setting');
    await page.unroute(settingsRoute);
    await page.route(settingsRoute, route => route.abort());
    await enabled.check();
    await deletion.getByRole('alert').getByText('The save could not be confirmed. The previous selection is shown. Reload this page to check the setting before continuing.', { exact: true }).waitFor();
    assert(await disabled.isChecked());
    await page.unroute(settingsRoute);
    await enabled.check();
    await saved.getByText('Saved', { exact: true }).waitFor();
    assert.equal(await publishing.locator('input:checked').inputValue(), initialPublishing);
    for (const [tab, id] of Object.entries(fixtures)) assert(exists(tab, id), 'Saving preferences deleted a record');
    for (const [tab, id] of Object.entries(fixtures)) {
      const edit = organization + `&tab=${tab}&edit=${id}`;
      await page.goto(edit);
      await page.getByRole('button', { name: 'Delete', exact: true }).click();
      await page.getByRole('heading', { name: 'Confirm deletion', exact: true }).waitFor();
      assert(exists(tab, id));
      if (tab === 'subject-areas') {
        assert(await page.getByRole('button', { name: 'Permanently Delete', exact: true }).isEnabled(), 'A category with no other subject area blocked deletion');
        assert.match(await page.locator('.ehrman-organization__deletion').innerText(), /Deletion UI categories/);
        const plain = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
        try {
          const nojs = await plain.newPage();
          await nojs.goto(edit + '&action=delete');
          assert(await nojs.getByRole('button', { name: 'Permanently Delete', exact: true }).isEnabled());
          await nojs.getByRole('link', { name: 'Cancel', exact: true }).click();
          assert(exists(tab, id), 'No-JS cancellation deleted a subject area');
        } finally { await plain.close(); }
      }
      const form = { _wpnonce: await page.locator('[data-ebd-delete-form] [name="_wpnonce"]').inputValue(), organization_action: 'delete', [scopes[tab] + '_id']: String(id), confirm_delete: 'yes', revision: await page.locator('[name="revision"]').inputValue() };
      assert.equal((await page.request.post(edit, { form: { ...form, _wpnonce: 'invalid' } })).status(), 403);
      const unconfirmed = await page.request.post(edit, { form: { ...form, confirm_delete: '' } });
      assert((await unconfirmed.text()).includes('Invalid organization action')); assert(exists(tab, id));
      await page.getByRole('link', { name: 'Cancel', exact: true }).click(); assert(exists(tab, id));
      await page.getByRole('button', { name: 'Delete', exact: true }).click();
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), tab + ' confirmation overflow');
        await page.screenshot({ path: path.join(output, `${tab}-${width}.png`), fullPage: true });
      }
      await page.getByRole('button', { name: 'Permanently Delete', exact: true }).click();
      await page.getByText('Item deleted. Posts and other organization items were not deleted.', { exact: true }).waitFor();
      assert(!exists(tab, id));
      if (tab === 'subject-areas') {
        assert(exists('categories', fixtures.categories), 'Subject-area deletion deleted its category');
        assert(exists('topics', fixtures.topics), 'Subject-area deletion deleted a topic');
        assert.equal(cli(prefix + `echo $wpdb->get_var("SELECT COUNT(*) FROM {$t['subject_area_categories']} WHERE subject_area_id=${id} OR category_id=${fixtures.categories}");`), '0', 'Deleted subject-area links remain');
      }
    }
    const used = JSON.parse(cli(prefix + `$id=$wpdb->get_var("SELECT t.id FROM {$t['topics']} t JOIN {$t['post_topics']} pt ON pt.topic_id=t.id WHERE t.display_in_browser=1 AND t.name<>'Ignore' LIMIT 1");echo wp_json_encode((int)$id);`));
    await page.goto(organization + `&tab=topics&edit=${used}`);
    assert(await page.getByRole('button', { name: 'Delete', exact: true }).isDisabled());
    assert.match(await page.locator('#ebd-deletion-reasons').innerText(), /assigned to/);
    const anonymous = await browser.newContext();
    try { assert.notEqual((await anonymous.request.post(organization + '&tab=topics', { form: { organization_action: 'delete', topic_id: String(used) }, maxRedirects: 0 })).status(), 200); } finally { await anonymous.close(); }
    // No-script editing still reaches the server-side confirmation.
    const plain = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
    try {
      const nojs = await plain.newPage();
      await nojs.goto(settings);
      assert(await nojs.locator('[data-ebd-deletion-settings] input[value="enabled"]').isDisabled());
      await nojs.getByText('Deletion settings require JavaScript.', { exact: true }).waitFor();
      await nojs.goto(organization + `&tab=topics&edit=${used}&action=delete`);
      assert(await nojs.getByRole('button', { name: 'Permanently Delete', exact: true }).isDisabled());
    } finally { await plain.close(); }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ result: 'passed', scopes: Object.keys(fixtures), widths: [1440,390], settings: 'default off / independent autosave / matching layout / recovery', guards: 'GET, nonce, permissions, missing confirmation, assigned posts, no-JS, subject-area deletion preserves unlinked categories', output }));
  } finally {
    cli(prefix + `$wpdb->delete($t['subject_area_categories'],array('subject_area_id'=>${fixtures['subject-areas']}));`);
    for (const [tab, id] of Object.entries(fixtures)) {
      cli(prefix + `$wpdb->delete($t['${tab.replaceAll('-', '_')}'],array('id'=>${id}));`);
    }
    for (const [key, value] of [[option, old], ['ehrman_discovery_ai_cache_version', cache]]) {
      cli(value === false ? `delete_option('${key}');` : `update_option('${key}',json_decode(base64_decode('${Buffer.from(JSON.stringify(value)).toString('base64')}'),true));`);
    }
    await browser.close();
  }
  assert.deepEqual(JSON.parse(cli('echo wp_json_encode(EhrmanBlogDiscovery\\Database::counts());')), before, 'Deletion UI fixtures changed stored counts');
})().catch(error => { console.error(error); process.exitCode = 1; });
