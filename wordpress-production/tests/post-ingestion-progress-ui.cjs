// Real progress/review pages with mocked generation. Disposable site only; no paid calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const root = path.resolve(__dirname, '../..');
const output = outputFor('post-ingestion-progress-ui');
const url = base + '/wp-admin/tools.php?page=ehrman-post-ingestion';
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);

(async () => {
  cli(['eval', 'if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON || !defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL) { throw new RuntimeException("Progress tests require an isolated regression site."); }']);
  const authorId = Number(cli(['user', 'list', '--role=administrator', '--field=ID']).split(/\s+/)[0]);
  assert(Number.isInteger(authorId) && authorId > 0);
  const approvalEnabled = cli(['eval', 'echo EhrmanBlogDiscovery\\Post_Ingestion_Service::database_is_authoritative() ? "1" : "0";']) === '1';
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const ids = [];
  const errors = [];
  let postId;
  let record = null;
  let failure = false;
  let finish;
  let creates = 0;
  let statusReads = 0;
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  const createPost = () => {
    const id = Number(cli(['post', 'create', '--post_type=post', '--post_status=publish', '--post_author=' + authorId, '--post_title=Metadata progress regression fixture', '--post_content=Saved content for an isolated progress screen regression.', '--porcelain']));
    assert(Number.isInteger(id) && id > 0);
    ids.push(id);
    return id;
  };
  const createDraft = (id, failed = false) => Number(cli(['eval', `
    $post = get_post(${id});
    $repository = new EhrmanBlogDiscovery\\Post_Ingestion_Repository();
    $input = (new EhrmanBlogDiscovery\\Post_Ingestion_Validator())->validate_submission(EhrmanBlogDiscovery\\Post_Ingestion_Post_Source::input($post));
    if (is_wp_error($input)) { throw new RuntimeException($input->get_error_message()); }
    $vocabulary = $repository->vocabulary();
    $id = $repository->create_draft($input, $repository->vocabulary_hash($vocabulary), $post->post_author, $post->ID);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $proposal = array('description'=>'Explains the generated description.', 'searchSummary'=>'Examines the generated summary.', 'topics'=>array($vocabulary['topics'][0]['name']), 'secondaryKeywords'=>array(), 'newSecondaryKeywords'=>array(), 'status'=>'ready', 'reviewNotes'=>array());
    global $wpdb;
    $tables = EhrmanBlogDiscovery\\Database::tables();
    $wpdb->update($tables['ingestion_drafts'], array('status'=>'${failed ? 'error' : 'ready'}', 'proposal_json'=>${failed ? "''" : 'wp_json_encode($proposal)'}, 'error_message'=>'${failed ? 'Generation failed in the simulated API.' : ''}'), array('id'=>$id));
    echo $id;
  `]));
  const start = async id => {
    postId = id;
    record = null;
    finish = null;
    await page.goto(url + '&status=not_generated&search=Metadata+progress+regression+fixture');
    const form = page.locator(`form:has(input[name="post_id"][value="${id}"])`);
    const nonce = await form.locator('[name="_wpnonce"]').inputValue();
    await form.getByRole('button', { name: 'Create Metadata', exact: true }).click();
    await page.locator('[data-ebd-metadata-progress]').waitFor();
    await page.getByText('Creating metadata...', { exact: true }).waitFor();
    for (let attempt = 0; !finish && attempt < 100; attempt++) {
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert(finish, 'The progress page never started its foreground request');
    return nonce;
  };
  try {
    // All ingestion API traffic is intercepted; nothing reaches the real analyzer.
    await page.route('**/wp-json/ehrman-discovery/**', route => route.abort());
    await page.route(current => current.pathname.includes('/ehrman-discovery/v1/ingestion/post/'), async route => {
      const request = route.request();
      assert(request.headers()['x-wp-nonce'], 'Progress request omitted the REST nonce');
      if (request.method() === 'POST') {
        assert(new URL(request.url()).pathname.endsWith('/analyze'), 'Progress submitted an unexpected paid action');
        creates++;
        await new Promise(resolve => { finish = resolve; });
        if (failure) {
          await route.fulfill({ status: 500, json: { message: 'Simulated server failure.' } });
          return;
        }
      } else {
        statusReads++;
      }
      await route.fulfill({ json: { postId, postStatus: 'publish', configured: true, draft: record } });
    });
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(url);
    assert.equal(creates, 0, 'Ordinary navigation started generation');
    const invalid = await context.request.post(url, { form: { action: 'ehrman_ingestion_prepare_metadata', _wpnonce: 'invalid', post_id: 1 } });
    assert.equal(invalid.status(), 403, 'Progress accepted an invalid nonce');

    const successId = createPost();
    const nonce = await start(successId);
    assert.equal(await page.getByRole('heading', { name: 'Metadata progress regression fixture' }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Approve and Add to Search' }).count(), 0);
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.screenshot({ path: path.join(output, `waiting-${width}.png`), fullPage: true, animations: 'disabled' });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Progress overflows at ' + width);
    }
    assert.equal(creates, 1, 'Generation was not started once');
    const draftId = createDraft(successId);
    record = { id: draftId, status: 'ready' };
    finish();
    await page.waitForURL(current => current.searchParams.get('draft') === String(draftId));
    await page.getByLabel('Description', { exact: true }).waitFor();
    assert.equal(await page.getByLabel('Summary', { exact: true }).inputValue(), 'Examines the generated summary.');
    const approve = page.getByRole('button', { name: 'Approve and Add to Search', exact: true });
    assert(await approve.isVisible());
    assert.equal(await approve.isEnabled(), approvalEnabled, 'Progress changed the configured approval protection');
    await page.screenshot({ path: path.join(output, 'review-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.reload();
    assert.equal(creates, 1, 'Reloading review repeated generation');
    const repeated = await context.request.post(url, { form: { action: 'ehrman_ingestion_prepare_metadata', post_id: successId, _wpnonce: nonce } });
    assert.equal(new URL(repeated.url()).searchParams.get('draft'), String(draftId), 'Repeated Create Metadata lost the existing record');
    assert.equal(creates, 1);

    failure = true;
    await start(createPost());
    finish();
    await page.getByText('Unable to confirm metadata generation.', { exact: true }).waitFor();
    assert(await page.getByText('Simulated server failure.', { exact: true }).isVisible());
    assert.equal(await page.locator('.spinner.is-active').count(), 0, 'Error left progress spinning');
    assert.equal(creates, 2, 'Failure automatically retried a paid request');
    await page.screenshot({ path: path.join(output, 'failure-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.getByRole('link', { name: 'Return to Search Metadata', exact: true }).click();
    assert.equal(new URL(page.url()).searchParams.get('status'), 'all', 'Recovery link hides existing records');
    assert.equal(creates, 2);

    const failedId = createPost();
    await start(failedId);
    const failedDraftId = createDraft(failedId, true);
    record = { id: failedDraftId, status: 'error' };
    finish();
    await page.waitForURL(current => current.searchParams.get('draft') === String(failedDraftId));
    assert(await page.getByText('Generation failed in the simulated API.', { exact: true }).isVisible());
    assert.equal(creates, 3, 'Saved failure triggered another generation request');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', progressViewports: [1440, 1024, 390], mockedCreates: creates, statusReads, paidCalls: 0 }, null, 2));
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'test-failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    if (finish) finish();
    await browser.close();
    for (const id of ids) cli(['post', 'delete', String(id), '--force']);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
