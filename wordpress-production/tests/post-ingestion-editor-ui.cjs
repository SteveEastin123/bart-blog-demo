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
    searchSummary: 'Explores the original summary.', topics: [topicNames[0]], secondaryKeywords: [],
    newSecondaryKeywords: [], reviewNotes: [], errorMessage: '', embeddingError: '',
    embeddingStatus: 'not_requested', analysisActive: false, analysisStalled: false,
  };
  let record = { postId, postStatus: 'draft', configured: true, databaseAuthoritative: true, sourceChanged: false,
    reviewUrl: base + '/wp-admin/tools.php?page=ehrman-post-ingestion', draft: null };
  let finishAnalysis = false;
  let failure = null;
  let approveDelay = 0;
  const actions = [];
  let approvalBody;
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
      if (action === 'analyze' || action === 'reanalyze') record.draft = { ...original, revision: 'queued', status: 'queued', analysisActive: true };
      if (action === 'approve') {
        approvalBody = request.postDataJSON();
        await new Promise(resolve => setTimeout(resolve, approveDelay));
        record.draft = { ...original, revision: 'approved', status: 'approved', embeddingStatus: 'complete',
          description: approvalBody.proposal.description, searchSummary: approvalBody.proposal.search_summary,
          topics: approvalBody.proposal.topics };
      }
      if (action === 'retry-embedding') record.draft = { ...record.draft, revision: 'retried', embeddingStatus: 'complete', embeddingError: '' };
    } else if (finishAnalysis && record.draft?.analysisActive) {
      record.draft = { ...original };
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
    const toggle = panel.getByRole('button', { name: 'Search Metadata', exact: true });
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
    await page.waitForFunction(() => !document.querySelector('.ehrman-ingestion-editor')?.textContent.includes('Loading search metadata...'));
  }
  async function reloadState() {
    await open();
  }
  async function shot(name) { await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true }); }
  try {
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await open();
    assert.equal(await panel.getByRole('link', { name: 'Metadata History', exact: true }).getAttribute('href'), record.reviewUrl);
    assert.equal(actions.length, 0, 'Opening the panel started a paid action');
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
    finishAnalysis = true;
    await panel.getByLabel('Description', { exact: true }).waitFor();
    await panel.getByLabel('Description', { exact: true }).fill('Explains the publisher revised description.');
    await panel.getByLabel('Search Summary', { exact: true }).fill('Explores the publisher revised summary.');
    const topicInput = panel.getByRole('combobox', { name: 'Topics', exact: true });
    await topicInput.fill(topicNames[1]);
    await topicInput.press('Enter');
    await shot('review-desktop');
    failure = { status: 400, message: 'Please correct the description.' };
    await button('Approve and Add to Search').click();
    await panel.getByText('Please correct the description.', { exact: true }).waitFor();
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Explains the publisher revised description.');
    approveDelay = 800;
    await button('Approve and Add to Search').click();
    await panel.getByText('Adding to search and creating vector...', { exact: true }).waitFor();
    assert(await button('Approve and Add to Search').isDisabled());
    await panel.getByText('Added to Search', { exact: true }).waitFor();
    assert(approvalBody.proposal.topics.includes(topicNames[1]));
    assert.equal(approvalBody.revision, 'ready-1');
    assert.equal(approvalBody.proposal.description, 'Explains the publisher revised description.');
    assert.equal(approvalBody.proposal.search_summary, 'Explores the publisher revised summary.');
    assert(page.url().includes('post.php'), 'Approval navigated away from the post editor');
    await shot('approved-desktop');

    record.draft = { ...original, newSecondaryKeywords: ['New Keyword Fixture'], reviewNotes: ['Confirm this new label.'] };
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled());
    await panel.getByLabel('Approve these new keyword labels', { exact: true }).check();
    assert(await button('Approve and Add to Search').isEnabled());
    await shot('new-keyword-approval');

    record.databaseAuthoritative = false;
    record.draft = { ...original };
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled(), 'JSON mode allowed approval');
    assert(await panel.getByLabel('Description', { exact: true }).isEditable());
    record.databaseAuthoritative = true;
    record.sourceChanged = true;
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled(), 'Changed source allowed approval');
    record.sourceChanged = false;
    record.draft = { ...original, status: 'held', reviewNotes: ['Select a suitable topic.'] };
    await reloadState();
    assert(await button('Approve and Add to Search').isDisabled());
    await panel.getByLabel('Review issues resolved; metadata is ready', { exact: true }).check();
    assert(await button('Approve and Add to Search').isEnabled());
    await shot('held-review');

    record.draft = { ...original };
    await reloadState();
    await panel.getByLabel('Description', { exact: true }).fill('Preserve this edit until regeneration is confirmed.');
    const beforeRegeneration = actions.length;
    acceptDialog = false;
    await button('Regenerate Metadata').click();
    assert.equal(actions.length, beforeRegeneration, 'Cancelled regeneration sent a request');
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Preserve this edit until regeneration is confirmed.');
    acceptDialog = true;
    await button('Regenerate Metadata').click();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    await panel.getByLabel('Description', { exact: true }).waitFor();
    assert.equal(actions.at(-1), 'reanalyze');
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), original.description);

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

    for (const width of [1024, 390]) {
      record.draft = { ...original };
      await page.setViewportSize({ width, height: 1000 });
      await reloadState();
      await panel.getByLabel('Description', { exact: true }).scrollIntoViewIfNeeded();
      await shot('review-' + width);
      await button('Approve and Add to Search').scrollIntoViewIfNeeded();
      await shot('actions-' + width);
      const overflow = await panel.evaluate(element => element.scrollWidth > element.clientWidth + 2);
      assert.equal(overflow, false, 'Panel overflows at ' + width);
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], actions, realAiRequests: 0, inlineReview: true }, null, 2));
  } finally {
    await browser.close();
    cli(['post', 'delete', String(postId), '--force']);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
