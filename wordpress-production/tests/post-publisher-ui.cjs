// Native WordPress form and saving, mocked metadata API. Isolated database only.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const root = path.resolve(__dirname, '../..');
const output = outputFor('post-publisher-ui');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);
const ids = new Set();
let authorId = 0;
(async () => {
  cli(['eval', 'if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON || !defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL) throw new RuntimeException("Isolated site required.");']);
  fs.mkdirSync(output, { recursive: true });
  const oldMode = JSON.parse(cli(['eval', 'echo wp_json_encode(get_option("ehrman_ingestion_default_mode",false));']));
  const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const page = await context.newPage();
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  let acceptDialog = true; let nativeSaves = 0;
  page.on('dialog', dialog => acceptDialog ? dialog.accept() : dialog.dismiss());
  page.on('request', request => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/wp-admin/post.php') nativeSaves++;
  });
  let record = { configured: true, databaseAuthoritative: false, sourceChanged: false, hasPublished: false,
    draft: null, reviewUrl: base + '/wp-admin/admin.php?page=ehrman-post-ingestion' };
  let release; let approval; const actions = [];
  const topics = JSON.parse(fs.readFileSync(path.join(root, 'data/index/ehrman_post_topics.json'), 'utf8').replace(/^\uFEFF/, '')).topics.filter(item => item.name !== 'Ignore').slice(0, 2).map(item => item.name);
  await page.route(url => url.pathname.includes('/ehrman-discovery/v1/ingestion/post/'), async route => {
    if (route.request().method() === 'POST') {
      const action = new URL(route.request().url()).pathname.split('/').pop(); actions.push(action);
      if (action === 'analyze') {
        await new Promise(resolve => { release = resolve; });
        record.draft = { id: 999100, revision: 'publisher-ready', status: 'ready', description: 'Explains the publisher fixture.',
          searchSummary: 'Explores a temporary post used to verify publishing.', topics, secondaryKeywords: [], newSecondaryKeywords: [],
          embeddingStatus: 'not_requested', analysisActive: false, reviewNotes: [] };
      } else if (action === 'approve') {
        approval = route.request().postDataJSON();
        record.draft = { ...record.draft, revision: 'publisher-approved', status: 'approved', embeddingStatus: 'complete', approvalMode: 'manual',
          description: approval.proposal.description, topics: topics.filter(topic => !approval.proposal.removed_topics.includes(topic)) };
      } else throw new Error('Unexpected metadata action: ' + action);
    }
    await route.fulfill({ json: record });
  });
  const panel = page.locator('#ehrman-post-metadata');
  const button = name => panel.getByRole('button', { name, exact: true });
  async function save(selector) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator(selector).click()]);
    await page.locator('#ehrman-post-metadata-root').waitFor();
  }
  async function checkTitleLabel(target = page) {
    const label = target.locator('#title-prompt-text');
    assert.equal(await label.innerText(), 'Title');
    assert(await label.isVisible(), 'Title label disappears after typing.');
    const labelBox = await label.boundingBox();
    const inputBox = await target.locator('#title').boundingBox();
    assert(labelBox.height >= 19 && labelBox.y + labelBox.height <= inputBox.y, 'Title label is clipped or overlaps its input.');
  }
  async function checkAuthorPlacement(target = page) {
    const title = await target.locator('#titlediv').boundingBox();
    const author = await target.locator('#ehrman-post-author').boundingBox();
    const heading = await target.locator('.ehrman-post-heading').boundingBox();
    assert(author.y >= title.y + title.height && author.y + author.height <= heading.y, 'Author is not between Title and Post.');
    assert(Math.abs(author.x - title.x) <= 1 && Math.abs(author.width - title.width) <= 1, 'Author and Title have different alignment.');
    assert(await target.locator('#ehrman-post-author label').isVisible());
    assert.equal(await target.locator('#post_author_override').count(), 1, 'Duplicate author fields.');
    assert.equal(await target.locator('#wp-content-media-buttons').count(), 0, 'Add Media is still shown.');
  }
  const typography = locator => locator.evaluate(element => {
    const style = getComputedStyle(element);
    return { family: style.fontFamily, size: style.fontSize, weight: style.fontWeight };
  });
  try {
    cli(['option', 'update', 'ehrman_ingestion_default_mode', 'automatic']);
    authorId = Number(cli(['eval', '$id=wp_insert_user(array("user_login"=>"publisher-author-".wp_generate_uuid4(),"user_pass"=>wp_generate_password(),"display_name"=>"Publisher Author Fixture","role"=>"author"));if(is_wp_error($id))throw new RuntimeException($id->get_error_message());echo $id;']));
    assert(authorId > 0);
    await page.goto(base + '/wp-login.php');
    await page.waitForFunction(() => document.activeElement?.id === 'user_login');
    await page.locator('#user_login').fill(credentials[0]); await page.locator('#user_pass').fill(credentials[1]); credentials.fill('');
    await page.locator('#wp-submit').click(); await page.waitForURL('**/wp-admin/**');
    await page.goto(base + '/wp-admin/post-new.php');
    const postId = Number(await page.locator('#post_ID').inputValue()); ids.add(postId);
    assert(postId > 0);
    await button('Create Metadata').waitFor();
    assert.doesNotMatch(await panel.innerText(), /\bJSON\b/i, 'Add Post displays internal data-source terminology');
    assert.equal(await page.locator('.block-editor').count(), 0);
    assert.equal(await page.locator('#title').count(), 1);
    assert.equal(await page.locator('#post_author_override').count(), 1);
    const originalAuthor = await page.locator('#post_author_override').inputValue();
    await checkAuthorPlacement();
    assert(await page.locator('#menu-media > a').isVisible(), 'Media Library navigation was removed.');
    assert.equal(await page.locator('#categorydiv, #tagsdiv-post_tag').count(), 0);
    assert.equal(await panel.getByRole('combobox').count(), 0);
    assert(await panel.getByRole('radio', { name: 'Automatic (site default)', exact: true }).isChecked());
    assert(await button('Create Metadata').isDisabled());
    await checkTitleLabel();
    const headingStyle = await typography(page.locator('.ehrman-post-heading'));
    assert.equal(headingStyle.size, '14px');
    assert.equal(headingStyle.weight, '600');
    for (const selector of ['#title-prompt-text', '#ehrman-post-author label', '#submitdiv h2', '#ehrman-post-metadata h2']) {
      assert.deepEqual(await typography(page.locator(selector)), headingStyle, 'Inconsistent heading: ' + selector);
    }
    for (const action of [page.locator('#save-post'), page.locator('#post-preview'), page.locator('#publish'), button('Create Metadata')]) {
      assert.equal((await action.boundingBox()).height, 40, 'Publishing and metadata button heights differ.');
    }
    const history = panel.getByRole('link', { name: 'Open Post Metadata', exact: true });
    assert.equal(await history.getAttribute('href'), record.reviewUrl);
    assert.equal(await history.evaluate(element => getComputedStyle(element).marginTop), '16px');
    await panel.getByRole('radio', { name: 'Manual', exact: true }).check();
    await page.locator('#post_author_override').selectOption(String(authorId));
    await page.locator('#title').fill('Native publisher UI fixture');
    await checkTitleLabel();
    await page.locator('#content-html').click();
    await page.locator('#content').fill('<p>Native publisher <strong>content</strong> and <a href="https://example.test/">link</a>.</p><p><img src="' + base + '/wp-includes/images/w-logo-blue.png" alt="Publisher image fixture" width="24" height="24" /></p>');
    await save('#save-post');
    await checkTitleLabel();
    assert(await panel.getByRole('radio', { name: 'Manual', exact: true }).isChecked());
    assert.equal(cli(['post', 'meta', 'get', String(postId), '_ehrman_form_editor']), '1');
    assert.equal(cli(['post', 'get', String(postId), '--field=post_status']), 'draft');
    assert.equal(cli(['post', 'get', String(postId), '--field=post_author']), String(authorId));
    assert.equal(await page.locator('#post_author_override').inputValue(), String(authorId));
    assert.equal(cli(['post', 'meta', 'get', String(postId), '_ehrman_metadata_mode']), 'manual');
    const content = cli(['post', 'get', String(postId), '--field=post_content']);
    assert(content.includes('<strong>content</strong>') && content.includes('href="https://example.test/"'), 'Saving changed rich-text markup.');
    assert(content.includes('<img ') && content.includes('alt="Publisher image fixture"'), 'Hiding Add Media removed existing image markup.');
    assert.equal(cli(['option', 'get', 'ehrman_ingestion_default_mode']), 'automatic');
    const popupPromise = page.waitForEvent('popup');
    await page.locator('#post-preview').click();
    const preview = await popupPromise; await preview.waitForLoadState();
    assert(new URL(preview.url()).searchParams.has('preview'));
    await preview.close();
    record.hasPublished = true;
    await save('#publish');
    await checkTitleLabel();
    assert.equal(cli(['post', 'get', String(postId), '--field=post_status']), 'publish');
    assert.equal(cli(['post', 'get', String(postId), '--field=post_author']), String(authorId));
    assert.equal(cli(['post', 'meta', 'get', String(postId), '_ehrman_metadata_mode']), 'manual');
    assert.equal(cli(['eval', `$db=EhrmanBlogDiscovery\\Database::client();$table=EhrmanBlogDiscovery\\Database::tables()["ingestion_drafts"];echo $db->get_var($db->prepare("SELECT COUNT(*) FROM %i WHERE wordpress_post_id=%d",$table,${postId}));`]), '0');
    assert(await panel.getByRole('radio', { name: 'Manual', exact: true }).isDisabled());
    await button('Create Metadata').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !window.EhrmanPostPublisher.read().isDirty);
    assert(await button('Create Metadata').isEnabled());
    await page.locator('#post_author_override').selectOption(originalAuthor);
    await page.waitForFunction(() => window.EhrmanPostPublisher.read().isDirty);
    assert(await button('Create Metadata').isDisabled(), 'Unsaved author changes bypassed the metadata source guard.');
    await page.locator('#post_author_override').selectOption(String(authorId));
    await page.waitForFunction(() => !window.EhrmanPostPublisher.read().isDirty);
    await page.locator('#title').fill('Unsaved publisher title');
    await page.waitForFunction(() => window.EhrmanPostPublisher.read().isDirty);
    assert(await button('Create Metadata').isDisabled());
    await page.locator('#title').fill('Native publisher UI fixture');
    await page.waitForFunction(() => !window.EhrmanPostPublisher.read().isDirty);
    await page.locator('#content-html').click();
    await page.locator('#content').fill('<p>Unsaved content must remain dirty after switching to Visual.</p>');
    await page.locator('#content-tmce').click();
    await page.waitForFunction(() => window.tinymce?.get('content')?.initialized);
    const writingStyle = await typography(page.frameLocator('#content_ifr').locator('body'));
    assert.equal(writingStyle.family, headingStyle.family, 'Writing area uses a different font family.');
    assert.equal(writingStyle.size, '16px');
    assert(await button('Create Metadata').isDisabled());
    await page.reload();
    await button('Create Metadata').waitFor();
    await page.waitForFunction(() => !window.EhrmanPostPublisher.read().isDirty);
    await button('Create Metadata').click();
    await panel.getByText('Creating metadata...', { exact: true }).waitFor();
    assert(await button('Create Metadata').isDisabled());
    const savedBeforeAnalysis = nativeSaves;
    await page.locator('#publish').click();
    await page.waitForTimeout(100);
    assert.equal(nativeSaves, savedBeforeAnalysis, 'Native saving interrupted metadata generation.');
    for (let i = 0; i < 100 && !release; i++) await page.waitForTimeout(20);
    assert(release); release();
    await panel.getByText('Metadata is ready for review.', { exact: true }).waitFor();
    assert(await button('Approve and Add to Search').isDisabled(), 'Publisher bypassed the approval guard.');
    assert.doesNotMatch(await panel.innerText(), /\bJSON\b/i, 'Review displays internal data-source terminology');
    record.databaseAuthoritative = true;
    await page.reload();
    await button('Approve and Add to Search').waitFor();
    for (const label of ['Description', 'Summary']) {
      const field = panel.getByLabel(label, { exact: true });
      const style = await typography(field);
      assert.equal(style.family, headingStyle.family);
      assert.equal(style.size, '14px');
    }
    const descriptionBox = await panel.getByLabel('Description', { exact: true }).boundingBox();
    const summaryLabel = await panel.locator('label').filter({ hasText: /^Summary$/ }).boundingBox();
    assert(summaryLabel.y - (descriptionBox.y + descriptionBox.height) >= 16, 'Review fields are crowded.');
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1100 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Review overflows at ' + width);
      await page.screenshot({ path: path.join(output, 'publisher-review-' + width + '.png'), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1100 });
    assert(await page.locator('#publish').isEnabled(), 'Blocked save left Update unusable.');
    await panel.getByLabel('Description', { exact: true }).fill('Explains the revised publisher description.');
    acceptDialog = false;
    await page.locator('#publish').click();
    await page.waitForTimeout(100);
    assert.equal(nativeSaves, savedBeforeAnalysis, 'Native saving discarded metadata without confirmation.');
    assert.equal(await panel.getByLabel('Description', { exact: true }).inputValue(), 'Explains the revised publisher description.');
    acceptDialog = true;
    await panel.getByRole('button', { name: 'Remove topic: ' + topics[1], exact: true }).click();
    assert.equal(await panel.getByRole('button', { name: 'Add topic', exact: true }).count(), 1);
    assert.equal(await panel.getByRole('button', { name: 'Add keyword', exact: true }).count(), 1);
    await button('Approve and Add to Search').click();
    await panel.getByText('Added to Search', { exact: true }).waitFor();
    assert.equal(approval.proposal.description, 'Explains the revised publisher description.');
    assert.deepEqual(approval.proposal.removed_topics, [topics[1]]);
    assert.deepEqual(actions, ['analyze', 'approve']);
    assert.equal(await button('Edit Metadata').count(), 1);
    assert.equal(await button('Regenerate Metadata').count(), 1);
    assert((await panel.innerText()).includes('Complete'));
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1100 });
      await page.evaluate(() => window.scrollTo(0, 0));
      await checkAuthorPlacement();
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Publisher overflows at ' + width);
      await page.screenshot({ path: path.join(output, 'publisher-' + width + '.png'), fullPage: true });
    }
    // Without JavaScript, native saving and the persisted radio selection remain usable.
    const plain = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
    const plainPage = await plain.newPage();
    await plainPage.goto(base + `/wp-admin/post.php?post=${postId}&action=edit`);
    await checkTitleLabel(plainPage);
    await checkAuthorPlacement(plainPage);
    assert.equal(await plainPage.locator('#post_author_override').inputValue(), String(authorId));
    assert(await plainPage.locator('#content').isVisible());
    assert(await plainPage.locator('#publish').isEnabled());
    assert(await plainPage.locator('noscript').getByRole('link', { name: 'Open Post Metadata', exact: true }).isVisible());
    await plain.close();
    assert.deepEqual(errors, []);
    console.log('Native publisher UI: author placement/selection/saving, scoped media hiding, consistent typography/controls, persistent Title label, draft/preview/publish, preserved rich text/images, saved override, lock, dirty-source guard, inline generation/approval, term removal, no-JS and desktop/tablet/mobile: OK');
  } finally {
    await browser.close();
    if (oldMode === false) cli(['option', 'delete', 'ehrman_ingestion_default_mode']);
    else cli(['option', 'update', 'ehrman_ingestion_default_mode', oldMode]);
    for (const id of ids) cli(['post', 'delete', String(id), '--force']);
    if (authorId) cli(['user', 'delete', String(authorId), '--yes']);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
