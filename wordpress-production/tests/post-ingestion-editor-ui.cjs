// Real Gutenberg components, mocked ingestion requests. No paid API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const root = path.resolve(__dirname, '../..');
const output = outputFor('post-ingestion-editor-ui');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);
const topicNames = JSON.parse(fs.readFileSync(path.join(root, 'data/index/ehrman_post_topics.json'), 'utf8').replace(/^\uFEFF/, '')).topics.filter(t => t.name !== 'Ignore').map(t => t.name);

(async () => {
  cli(['eval', 'if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON || !defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL) { throw new RuntimeException("Editor tests require an isolated regression site."); }']);
  fs.mkdirSync(output, { recursive: true });
  const postId = Number(cli(['post', 'create', '--post_type=post', '--post_status=draft', '--post_title=Metadata panel regression fixture', '--post_content=Temporary editor fixture with no real AI requests.', '--porcelain']));
  assert(Number.isInteger(postId) && postId > 0);
  const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  let acceptDialog = true;
  page.on('dialog', dialog => acceptDialog ? dialog.accept() : dialog.dismiss());
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const original = {
    id: 900001, revision: 'ready-1', status: 'ready', description: 'Explains the original description.',
    searchSummary: 'Explores the original summary.', topics: [topicNames[0], topicNames[1]], secondaryKeywords: ['Existing Keyword Fixture'],
    newSecondaryKeywords: ['New Keyword Fixture'], reviewNotes: [], errorMessage: '', embeddingError: '',
    embeddingStatus: 'not_requested', analysisActive: false, analysisStalled: false,
  };
  let record = { postId, postStatus: 'draft', configured: true, databaseAuthoritative: true, sourceChanged: false,
    reviewUrl: base + '/wp-admin/admin.php?page=ehrman-post-ingestion', draft: null };
  let finishAnalysis;
  let analysisDelay = 500;
  let failure = null;
  let approveDelay = 0;
  const actions = [];
  let approvalBody;
  let approvedVersion;
  await page.route(url => url.pathname.includes('/ehrman-discovery/v1/ingestion/post/'), async route => {
    const request = route.request();
    if (request.method() === 'POST') {
      const action = new URL(request.url()).pathname.split('/').pop();
      actions.push(action);
      if (failure) {
        const response = failure;
        failure = null;
        await route.fulfill({ status: response.status, json: { code: 'test_failure', message: response.message } });
        return;
      }
      if (action === 'analyze' || action === 'reanalyze') {
        const revising = record.draft?.status === 'approved' || record.draft?.isRevision;
        if (record.draft?.status === 'approved') approvedVersion = { ...record.draft };
        if (action === 'analyze') await new Promise(resolve => { finishAnalysis = resolve; });
        else await new Promise(resolve => setTimeout(resolve, analysisDelay));
        record.draft = { ...original, isRevision: revising, revision: action === 'reanalyze' ? 'ready-regenerated' : original.revision };
      }
      if (action === 'edit-metadata') {
        approvedVersion = { ...record.draft };
        record.draft = { ...record.draft, id: 900002, revision: 'editable-revision', status: 'ready', isRevision: true, previousVersionUrl: record.reviewUrl + '&draft=900001' };
      }
      if (action === 'cancel-revision') record.draft = { ...approvedVersion };
      if (action === 'approve') {
        approvalBody = request.postDataJSON();
        await new Promise(resolve => setTimeout(resolve, approveDelay));
        record.draft = { ...original, revision: 'approved', status: 'approved', embeddingStatus: 'complete',
          description: approvalBody.proposal.description, searchSummary: approvalBody.proposal.search_summary,
          topics: [...original.topics.filter(value => !approvalBody.proposal.removed_topics.includes(value)), ...approvalBody.proposal.added_topics],
          secondaryKeywords: [...original.secondaryKeywords.filter(value => !approvalBody.proposal.removed_keywords.includes(value)), ...approvalBody.proposal.added_keywords],
          newSecondaryKeywords: original.newSecondaryKeywords.filter(value => !approvalBody.proposal.removed_keywords.includes(value)) };
      }
      if (action === 'retry-embedding') record.draft = { ...record.draft, revision: 'retried', embeddingStatus: 'complete', embeddingError: '' };
    }
    await route.fulfill({ status: request.method() === 'POST' && record.draft?.analysisActive ? 202 : 200, json: record });
  });
  const panel = page.locator('.ehrman-ingestion-editor');
  const button = name => panel.getByRole('button', { name, exact: true });
  async function open() {
    await page.goto(base + `/wp-admin/post.php?post=${postId}&action=edit`);
    await page.waitForFunction(() => !!window.wp?.data?.select('core/editor')?.getCurrentPostId());
    await page.evaluate(() => {
      wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false);
      const store = wp.data.dispatch('core/edit-post');
      store.openGeneralSidebar('edit-post/document');
    });
    await panel.waitFor();
    const toggle = panel.getByRole('button', { name: 'Post Metadata', exact: true });
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
    await page.waitForFunction(() => !document.querySelector('.ehrman-ingestion-editor')?.textContent.includes('Loading post metadata...'));
  }
  async function reloadState() {
    await open();
  }
  async function shot(name) { await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true }); }
  try {
    await page.goto(base + '/wp-login.php');
    await page.waitForFunction(() => document.activeElement?.id === 'user_login');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(base + '/wp-admin/admin.php?page=ehrman-blog-discovery');
    const settings = page.locator('[data-ebd-publishing-settings]');
    const manual = settings.getByRole('radio', { name: 'Manual', exact: true });
    const automatic = settings.getByRole('radio', { name: 'Automatic', exact: true });
    const saved = settings.getByRole('status');
    const settingsUrl = base + '/wp-json/wp/v2/settings';
    const settingsRoute = url => url.pathname === '/wp-json/wp/v2/settings';
    assert(await manual.isChecked());
    assert.equal(await settings.getByRole('combobox').count(), 0);
    assert.equal(await settings.getByRole('button').count(), 0);
    const missingNonce = await context.request.post(settingsUrl, { data: { ehrman_ingestion_default_mode: 'automatic' } });
    assert.equal(missingNonce.status(), 401, 'Settings accepted a cookie-authenticated request without a nonce');
    const deniedSave = await context.request.post(settingsUrl, {
      headers: { 'X-WP-Nonce': 'invalid-fixture' }, data: { ehrman_ingestion_default_mode: 'automatic' },
    });
    assert.equal(deniedSave.status(), 403, 'Settings accepted a missing/invalid nonce');
    await page.reload();
    assert(await manual.isChecked());
    let releaseSave;
    let settingPosts = 0;
    await page.route(settingsRoute, async route => {
      if (route.request().method() === 'POST') {
        settingPosts++;
        await new Promise(resolve => { releaseSave = resolve; });
      }
      await route.continue();
    });
    await automatic.check();
    await saved.getByText('Saving...', { exact: true }).waitFor();
    assert(await automatic.isDisabled() && await manual.isDisabled(), 'Concurrent saves must be blocked.');
    await page.waitForFunction(() => document.querySelector('[data-ebd-publishing-modes]').getAttribute('aria-busy') === 'true');
    for (let attempt = 0; attempt < 100 && !releaseSave; attempt++) await new Promise(resolve => setTimeout(resolve, 10));
    assert(releaseSave, 'The settings save request was not sent.');
    releaseSave();
    await saved.getByText('Saved', { exact: true }).waitFor();
    assert.equal(settingPosts, 1);
    await page.unroute(settingsRoute);
    await page.reload();
    assert(await automatic.isChecked(), 'Autosave did not persist.');
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1100 });
      await shot(`publishing-settings-${width}`);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2));
    }
    await page.setViewportSize({ width: 1440, height: 1100 });
    await manual.check();
    await saved.getByText('Saved', { exact: true }).waitFor();
    await page.reload();
    assert(await manual.isChecked());
    // An explicit server failure restores the actual saved choice and allows retry.
    await page.route(settingsRoute, route => route.request().method() === 'POST'
      ? route.fulfill({ status: 500, json: { code: 'fixture_failure', message: 'Synthetic save failure' } }) : route.continue());
    await automatic.check();
    await settings.getByRole('alert').getByText('The change could not be saved. The current setting has been restored. Please try again.', { exact: true }).waitFor();
    assert(await manual.isChecked() && await automatic.isEnabled());
    assert.equal(cli(['option', 'get', 'ehrman_ingestion_default_mode']), 'manual');
    await page.unroute(settingsRoute);
    // A lost save response must not claim a confirmed rollback when the write succeeded.
    await page.route(settingsRoute, async route => {
      if (route.request().method() === 'POST') { await route.fetch(); await route.abort(); }
      else await route.continue();
    });
    await automatic.check();
    await saved.getByText('Saved', { exact: true }).waitFor();
    assert(await automatic.isChecked());
    await page.unroute(settingsRoute);
    await page.route(settingsRoute, route => route.abort());
    await manual.check();
    await settings.getByRole('alert').getByText('The save could not be confirmed. The previous selection is shown. Reload this page to check the setting before continuing.', { exact: true }).waitFor();
    assert(await automatic.isChecked() && await manual.isEnabled());
    await page.unroute(settingsRoute);
    await manual.check();
    await saved.getByText('Saved', { exact: true }).waitFor();
    assert(!(await settings.getByRole('alert').isVisible()));
    await page.reload();
    assert(await manual.isChecked());
    const plainContext = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
    try {
      const plainPage = await plainContext.newPage();
      await plainPage.goto(base + '/wp-admin/admin.php?page=ehrman-blog-discovery');
      assert(await plainPage.getByRole('radio', { name: 'Manual', exact: true }).isDisabled());
      assert(await plainPage.getByText('Publishing settings require JavaScript.', { exact: true }).isVisible());
    } finally { await plainContext.close(); }
    await open();
    assert.equal(await panel.getByRole('link', { name: 'Open Post Metadata', exact: true }).getAttribute('href'), record.reviewUrl);
    assert.equal(actions.length, 0, 'Opening the panel started a paid action');
    const mode = panel.getByRole('combobox', { name: 'Metadata mode', exact: true });
    assert.equal(await mode.inputValue(), 'inherit');
    assert(await mode.isEnabled());
    await mode.selectOption('automatic');
    await panel.getByText('Metadata will be created and approved automatically after publication.', { exact: true }).waitFor();
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
    await reloadState();
    assert.equal(await mode.inputValue(), 'automatic', 'Per-post mode did not persist through the core WordPress REST save');
    await shot('automatic-mode');
    await mode.selectOption('manual');
    await page.evaluate(() => wp.data.dispatch('core/editor').savePost());
    await reloadState();
    const publishNotice = panel.getByText('Publish this post before creating metadata.', { exact: true });
    const saveNotice = panel.getByText('Save your changes before continuing.', { exact: true });
    await publishNotice.waitFor();
    assert(await button('Create Metadata').isDisabled(), 'A saved draft allowed metadata creation');
    assert.equal(await saveNotice.count(), 0);
    await page.evaluate(() => wp.data.dispatch('core/editor').editPost({ title: 'Unsaved draft fixture edit' }));
    await page.waitForFunction(() => wp.data.select('core/editor').isEditedPostDirty());
    assert.equal(await publishNotice.count(), 1);
    assert.equal(await saveNotice.count(), 0, 'An unpublished draft showed both prerequisite notices');
    assert(await button('Create Metadata').isDisabled());
    await publishNotice.scrollIntoViewIfNeeded();
    await shot('publish-required');
    cli(['post', 'update', String(postId), '--post_status=publish']);
    record.postStatus = 'publish';
    await reloadState();
    assert(await mode.isDisabled(), 'First-publication mode remained editable after publishing');
    assert.equal(await publishNotice.count(), 0);
    assert(await button('Create Metadata').isEnabled());
    await page.evaluate(() => wp.data.dispatch('core/editor').editPost({ title: 'Unsaved published fixture edit' }));
    await saveNotice.waitFor();
    assert.equal(await publishNotice.count(), 0);
    assert(await button('Create Metadata').isDisabled(), 'Unsaved post changes allowed metadata creation');
    await shot('save-required');
    await reloadState();
    assert.equal(await saveNotice.count(), 0);
    assert(await button('Create Metadata').isEnabled());
    assert.equal(actions.length, 0, 'A prerequisite check submitted metadata');
    await button('Create Metadata').click();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    await shot('creating-desktop');
    assert(await button('Create Metadata').isDisabled());
    assert.equal(await button('Approve and Add to Search').count(), 0, 'Approval appeared before generation finished');
    assert.deepEqual(actions, ['analyze']);
    finishAnalysis();
    await panel.getByLabel('Description', { exact: true }).waitFor();
    await panel.getByLabel('Description', { exact: true }).fill('Explains the publisher revised description.');
    await panel.getByLabel('Summary', { exact: true }).fill('Explores the publisher revised summary.');
    assert.equal(await panel.getByRole('combobox').count(), 1, 'Pickers should stay closed until requested');
    assert.equal(await panel.getByLabel('Review Notes and Warnings').count(), 0);
    assert(await panel.getByText(topicNames[0], { exact: true }).isVisible());
    await button('Remove topic: ' + topicNames[1]).click();
    assert(await button('Restore topic: ' + topicNames[1]).isVisible());
    await button('Remove topic: ' + topicNames[0]).focus();
    await page.keyboard.press('Enter');
    assert(await button('Approve and Add to Search').isDisabled());
    assert(await panel.getByText('Keep at least one topic. Add an existing topic or restore a removed topic.', { exact: true }).isVisible());
    await button('Restore topic: ' + topicNames[0]).click();
    assert(await button('Approve and Add to Search').isEnabled());
    await button('Remove keyword: Existing Keyword Fixture').click();
    await button('Remove keyword: New Keyword Fixture').click();
    await button('Restore keyword: New Keyword Fixture').click();
    assert.deepEqual(actions, ['analyze'], 'Removing or restoring a term triggered an API action');
    const additions = await page.evaluate(selected => ({
      topic: EhrmanMetadataVocabulary.topic.find(item => item.label !== 'Ignore' && !selected.includes(item.label)),
      keyword: EhrmanMetadataVocabulary.keyword.find(item => !EhrmanMetadataVocabulary.topic.some(topic => topic.label === item.label)),
    }), topicNames.slice(0, 2));
    for (const [kind, searchLabel, option] of [
      ['topic', 'Search existing topics', additions.topic],
      ['keyword', 'Search existing secondary keywords', additions.keyword],
    ]) {
      assert.equal(await button('Add ' + kind).textContent(), 'Add ' + kind);
      assert.equal(await button('Add ' + kind).locator('*').count(), 0, 'Add buttons should be text-only');
      await button('Add ' + kind).click();
      const search = panel.getByRole('combobox', { name: searchLabel, exact: true });
      assert(await search.evaluate(element => element === document.activeElement));
      const list = panel.getByRole('listbox', { name: searchLabel, exact: true });
      const available = await page.evaluate(kind => {
        const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
        const selected = Array.from(document.querySelectorAll('.ehrman-metadata-selections__term:not(.is-removed) .ehrman-metadata-selections__label'), element => normalize(element.textContent));
        return EhrmanMetadataVocabulary[kind].map(item => item.label).filter(label => !selected.includes(normalize(label))).sort((a, b) => a.localeCompare(b));
      }, kind);
      assert(available.length > 20, 'Fixture needs enough terms to test scrolling beyond the old limit');
      assert.deepEqual(await list.getByRole('option').allTextContents(), available, 'Focus did not show all available terms alphabetically');
      assert.equal(await search.getAttribute('aria-expanded'), 'true');
      assert(await list.evaluate(element => element.clientHeight <= 220 && element.scrollHeight > element.clientHeight), 'List is not height-limited and scrollable');
      await search.press('ArrowUp');
      assert(await list.evaluate(element => element.scrollTop > 0), 'Last option was not scrolled into view');
      await search.press('Enter');
      await button('Remove ' + kind + ': ' + available.at(-1)).waitFor();
      assert.equal(await search.count(), 0, 'Keyboard selection left the search field open');
      assert.equal(await list.count(), 0, 'Keyboard selection left the list open');
      assert(await button('Add ' + kind).evaluate(element => element === document.activeElement));
      await button('Add ' + kind).click();
      assert.equal(await list.getByRole('option', { name: available.at(-1), exact: true }).count(), 0, 'A selected term remains in the browsing list');
      await button('Remove ' + kind + ': ' + available.at(-1)).click();
      await button('Add ' + kind).click();
      await search.fill('zzz-nonexistent-vocabulary-fixture');
      await panel.getByText('No matching ' + (kind === 'topic' ? 'topics' : 'keywords') + ' found', { exact: true }).waitFor();
      await search.press('Enter');
      assert.deepEqual(actions, ['analyze'], 'Unmatched text submitted a request');
      await search.fill('');
      assert.deepEqual(await list.getByRole('option').allTextContents(), available, 'Clearing the query did not restore the full list');
      await search.fill(option.label);
      await search.press(kind === 'topic' ? 'ArrowDown' : 'ArrowUp');
      await search.press('Enter');
      await button('Remove ' + kind + ': ' + option.label).waitFor();
      assert.equal(await search.count(), 0, 'Filtered selection left the search field open');
      assert.equal(await list.count(), 0, 'Filtered selection left the list open');
      assert(await button('Add ' + kind).evaluate(element => element === document.activeElement));
      await button('Add ' + kind).click();
      assert.equal(await search.inputValue(), '');
      await search.fill(option.label);
      assert.equal(await panel.getByRole('option', { name: option.label, exact: true }).count(), 0, 'Selected term is still suggested');
      await search.press('Escape');
      assert(await button('Add ' + kind).evaluate(element => element === document.activeElement));
      await button('Remove ' + kind + ': ' + option.label).click();
      await button('Add ' + kind).click();
      await search.fill(option.aliases[0] || option.label);
      await panel.getByRole('option', { name: option.label, exact: true }).click();
      assert.equal(await search.count(), 0, 'Click selection left the search field open');
      assert.equal(await list.count(), 0, 'Click selection left the list open');
      assert(await button('Add ' + kind).evaluate(element => element === document.activeElement));
      await button('Add ' + kind).click();
      await panel.getByLabel('Description', { exact: true }).click();
      assert.equal(await search.count(), 0, 'Picker did not close after focus moved away');
    }
    await shot('review-desktop');
    failure = { status: 400, message: 'Please correct the description.' };
    await button('Approve and Add to Search').click();
    await panel.getByText('Please correct the description.', { exact: true }).waitFor();
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Explains the publisher revised description.');
    assert(await button('Restore topic: ' + topicNames[1]).isVisible(), 'Validation failure lost topic removals');
    assert(await button('Restore keyword: Existing Keyword Fixture').isVisible(), 'Validation failure lost keyword removals');
    assert(await button('Remove topic: ' + additions.topic.label).isVisible(), 'Validation failure lost topic additions');
    assert(await button('Remove keyword: ' + additions.keyword.label).isVisible(), 'Validation failure lost keyword additions');
    approveDelay = 800;
    await button('Approve and Add to Search').click();
    await panel.getByText('Adding to search and creating vector...', { exact: true }).waitFor();
    assert(await button('Approve and Add to Search').isDisabled());
    await panel.getByText('Added to Search', { exact: true }).waitFor();
    assert.equal(approvalBody.proposal.topics, undefined, 'Client submitted mutable AI selections');
    assert.deepEqual(approvalBody.proposal.removed_topics, [topicNames[1]]);
    assert.deepEqual(approvalBody.proposal.removed_keywords, ['Existing Keyword Fixture']);
    assert.deepEqual(approvalBody.proposal.added_topics, [additions.topic.label]);
    assert.deepEqual(approvalBody.proposal.added_keywords, [additions.keyword.label]);
    assert.equal(approvalBody.revision, 'ready-1');
    assert.equal(approvalBody.proposal.description, 'Explains the publisher revised description.');
    assert.equal(approvalBody.proposal.search_summary, 'Explores the publisher revised summary.');
    assert(page.url().includes('post.php'), 'Approval navigated away from the post editor');
    assert.equal(await button('Remove topic: ' + topicNames[0]).count(), 0, 'Approved metadata remained editable');
    assert.equal(await panel.getByText(topicNames[1], { exact: true }).count(), 0, 'Removed topic remained after approval');
    await shot('approved-desktop');

    assert(await button('Edit Metadata').isEnabled());
    assert(await button('Regenerate Metadata').isEnabled());
    await button('Edit Metadata').click();
    await button('Approve Changes').waitFor();
    assert(await panel.getByLabel('Description', { exact: true }).isEditable());
    await button('Remove keyword: New Keyword Fixture').click();
    await shot('approved-revision-desktop');
    acceptDialog = false;
    const beforeCancel = actions.length;
    await button('Cancel Changes').click();
    assert.equal(actions.length, beforeCancel);
    acceptDialog = true;
    await button('Cancel Changes').click();
    await button('Edit Metadata').waitFor();
    assert.equal(record.draft.description, approvedVersion.description);
    await button('Regenerate Metadata').click();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    await button('Approve Changes').waitFor();
    await panel.getByLabel('Description', { exact: true }).fill('Explains the newly approved revision.');
    await button('Approve Changes').click();
    await button('Edit Metadata').waitFor();
    assert.equal(record.draft.description, 'Explains the newly approved revision.');

    record.draft = { ...original, newSecondaryKeywords: ['New Keyword Fixture'], reviewNotes: ['Confirm this new label.'] };
    await reloadState();
    assert(await button('Approve and Add to Search').isEnabled());
    assert.equal(await panel.getByRole('checkbox').count(), 0);
    assert.equal(await panel.getByText('Proposed New Keywords', { exact: true }).count(), 0);
    assert(await panel.getByText('New Keyword Fixture', { exact: true }).isVisible());
    await shot('combined-keywords');

    record.databaseAuthoritative = false;
    record.draft = { ...original };
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled(), 'JSON mode allowed approval');
    assert.doesNotMatch(await panel.innerText(), /\bJSON\b/i, 'Editor displays internal data-source terminology');
    assert(await panel.getByLabel('Description', { exact: true }).isEditable());
    record.databaseAuthoritative = true;
    record.sourceChanged = true;
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled(), 'Changed source allowed approval');
    record.sourceChanged = false;
    record.draft = { ...original, status: 'held', reviewNotes: ['Select a suitable topic.'] };
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled());
    assert.equal(await panel.getByRole('checkbox').count(), 0);
    assert(await panel.getByText('Select a suitable topic.', { exact: true }).isVisible());
    await shot('held-review');

    record.draft = { ...original };
    await reloadState();
    await panel.getByLabel('Description', { exact: true }).fill('Preserve this edit until regeneration is confirmed.');
    await button('Remove topic: ' + topicNames[1]).click();
    const beforeRegeneration = actions.length;
    acceptDialog = false;
    await button('Regenerate Metadata').click();
    assert.equal(actions.length, beforeRegeneration, 'Cancelled regeneration sent a request');
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Preserve this edit until regeneration is confirmed.');
    acceptDialog = true;
    await button('Regenerate Metadata').click();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    assert.equal(await button('Approve and Add to Search').count(), 0);
    await panel.getByLabel('Description', { exact: true }).waitFor();
    assert.equal(actions.at(-1), 'reanalyze');
    await page.waitForFunction(expected => document.querySelector('.ehrman-ingestion-editor textarea')?.value === expected, original.description);
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), original.description);
    assert(await button('Remove topic: ' + topicNames[1]).isVisible(), 'Regeneration retained removals from an old proposal');

    await panel.getByLabel('Description', { exact: true }).fill('Preserve this edit when the review becomes stale.');
    record.draft = { ...original, revision: 'ready-2', description: 'Updated by another reviewer.' };
    failure = { status: 409, message: 'The metadata changed. Reload it before approval.' };
    await button('Approve and Add to Search').click();
    await panel.getByText('The metadata changed. Reload it before approval.', { exact: true }).waitFor();
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Preserve this edit when the review becomes stale.');
    await button('Reload Metadata').click();
    await panel.getByLabel('Description', { exact: true }).waitFor();
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Updated by another reviewer.');

    record.draft = { ...original, status: 'error', errorMessage: 'Metadata creation failed.' };
    await reloadState();
    await button('Regenerate Metadata').waitFor();
    record.draft = { ...original, status: 'analyzing', analysisActive: true, analysisStalled: true };
    await reloadState();
    assert(await button('Regenerate Metadata').isEnabled());
    record.draft = { ...original, status: 'approved', embeddingStatus: 'pending', embeddingError: 'Vector request failed.' };
    await reloadState();
    await button('Retry Vector').click();
    await panel.getByText('Complete', { exact: true }).waitFor();
    assert.equal(await button('Retry Vector').count(), 0);

    const beforeAutomatic = actions.length;
    record.draft = { ...original, status: 'queued', automaticActive: true, analysisActive: true };
    await reloadState();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    assert(await button('Regenerate Metadata').isDisabled());
    assert.equal(await button('Approve and Add to Search').count(), 0);
    await shot('automatic-queued');
    record.draft = { ...original, status: 'approved', automaticActive: true, analysisActive: true, approvalMode: 'automatic', embeddingStatus: 'pending' };
    await panel.getByText('Creating vector...', { exact: true }).waitFor();
    assert(await button('Edit Metadata').isDisabled());
    assert(await button('Retry Vector').isDisabled());
    record.draft = { ...record.draft, automaticActive: false, analysisActive: false, embeddingStatus: 'complete' };
    await panel.getByText('Added to Search', { exact: true }).waitFor();
    assert(await panel.locator('dd').filter({ hasText: /^Automatic$/ }).isVisible());
    assert(await button('Edit Metadata').isEnabled());
    assert(await button('Regenerate Metadata').isEnabled());
    assert.equal(actions.length, beforeAutomatic, 'Automatic progress polling submitted a paid action');
    await shot('automatic-complete');

    for (const width of [1024, 390]) {
      record.draft = { ...original };
      await page.setViewportSize({ width, height: 1000 });
      await reloadState();
      await panel.getByLabel('Description', { exact: true }).scrollIntoViewIfNeeded();
      await button('Remove topic: ' + topicNames[1]).click();
      await button('Remove keyword: Existing Keyword Fixture').click();
      await button('Add keyword').click();
      await panel.getByRole('listbox', { name: 'Search existing secondary keywords', exact: true }).waitFor();
      await shot('browse-picker-' + width);
      await panel.getByRole('combobox', { name: 'Search existing secondary keywords', exact: true }).fill(additions.keyword.label);
      await panel.getByRole('option', { name: additions.keyword.label, exact: true }).waitFor();
      await shot('picker-' + width);
      await panel.getByRole('option', { name: additions.keyword.label, exact: true }).click();
      assert(await button('Add keyword').isVisible(), 'Add button did not return after selection at ' + width);
      assert.equal(await panel.getByRole('listbox').count(), 0, 'Selection left a list open at ' + width);
      await shot('review-' + width);
      await button('Approve and Add to Search').scrollIntoViewIfNeeded();
      await shot('actions-' + width);
      const overflow = await panel.evaluate(element => element.scrollWidth > element.clientWidth + 2);
      assert.equal(overflow, false, 'Panel overflows at ' + width);
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], actions, realAiRequests: 0, inlineReview: true, publishingAutosave: true }, null, 2));
  } finally {
    await browser.close();
    cli(['option', 'delete', 'ehrman_ingestion_default_mode']);
    cli(['post', 'delete', String(postId), '--force']);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
