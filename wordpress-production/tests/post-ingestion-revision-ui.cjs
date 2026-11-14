// Real Tools revision forms with a synthetic vector; regeneration is intercepted. Disposable site only.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../node_modules/playwright-core');
const { base, outputFor, composeFile } = require('./browser-config.cjs');
const root = path.resolve(__dirname, '../..');
const output = outputFor('post-ingestion-revision-ui');
const docker = args => execFileSync('docker', ['compose', '-f', composeFile, 'exec', '-T', 'wordpress', ...args], { cwd: root, encoding: 'utf8' }).trim();
const cli = args => docker(['wp', ...args, '--allow-root']);
const url = base + '/wp-admin/tools.php?page=ehrman-post-ingestion';

(async () => {
  cli(['eval', 'if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON || !defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL) { throw new RuntimeException("Revision tests require an isolated site."); }']);
  const author = Number(cli(['user', 'list', '--role=administrator', '--field=ID']).split(/\s+/)[0]);
  const postId = Number(cli(['post', 'create', '--post_type=post', '--post_status=publish', '--post_author=' + author, '--post_title=Approved metadata revision browser fixture', '--post_content=Saved synthetic article for reviewing revised metadata without paid API calls.', '--porcelain']));
  const sourceSetting = JSON.parse(cli(['eval', 'echo wp_json_encode(defined("EHRMAN_DISCOVERY_POST_SOURCE") ? EHRMAN_DISCOVERY_POST_SOURCE : null);']));
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  let accept = true;
  let regenerations = 0;
  let releaseGeneration;
  let failRegeneration = false;
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => accept ? dialog.accept() : dialog.dismiss());
  fs.mkdirSync(output, { recursive: true });
  const live = () => JSON.parse(cli(['eval', `global $wpdb; echo wp_json_encode($wpdb->get_row($wpdb->prepare('SELECT id,description,search_summary FROM %i WHERE wordpress_post_id=%d', EhrmanBlogDiscovery\\Database::tables()['external_posts'], ${postId}), ARRAY_A));`]));
  try {
    cli(['config', 'set', 'EHRMAN_DISCOVERY_POST_SOURCE', 'mysql', '--type=constant']);
    const fixture = JSON.parse(cli(['eval', `
      wp_set_current_user(${author}); putenv('EHRMAN_DISCOVERY_POST_SOURCE=mysql'); putenv('OPENAI_API_KEY=');
      $store = new EhrmanBlogDiscovery\\Post_Ingestion_Repository();
      $vocab = $store->vocabulary();
      $topics = array_values(array_filter($vocab['topics'], static fn($t) => $t['name'] !== 'Ignore'));
      $keywords = array_values(array_filter($vocab['keywords'], static fn($k) => !in_array($k['label'], array_column($topics, 'name'), true)));
      $input = (new EhrmanBlogDiscovery\\Post_Ingestion_Validator())->validate_submission(EhrmanBlogDiscovery\\Post_Ingestion_Post_Source::input(get_post(${postId})));
      $id = $store->create_draft($input, $store->vocabulary_hash($vocab), ${author}, ${postId});
      $proposal = array('description'=>'Explains how publishers revise approved metadata while keeping the current search entry active until the proposed changes have been approved.',
        'searchSummary'=>'Explains how publishers can review metadata and remove selected topics or keywords without replacing the existing live search entry immediately. Describes the approval step that applies revised descriptions and summaries together with matching vectors. Examines cancellation and retained history so an abandoned revision does not affect previously approved information available to readers in the discovery index.',
        'topics'=>array($topics[0]['name'], $topics[1]['name']), 'topicRationales'=>array(), 'secondaryKeywords'=>array($keywords[0]['label'], $keywords[1]['label']), 'newSecondaryKeywords'=>array(), 'status'=>'ready', 'reviewNotes'=>array());
      global $wpdb; $tables = EhrmanBlogDiscovery\\Database::tables();
      $wpdb->update($tables['ingestion_drafts'], array('status'=>'ready', 'proposal_json'=>wp_json_encode($proposal)), array('id'=>$id));
      $approved = (new EhrmanBlogDiscovery\\Post_Ingestion_Service())->approve($id, true);
      if (is_wp_error($approved)) throw new RuntimeException($approved->get_error_message());
      $post = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $tables['external_posts'], $approved['approved_post_id']), ARRAY_A);
      $vector = array_fill(0, EhrmanBlogDiscovery\\Embedding_Service::dimensions(), 0.0); $vector[0]=1.0;
      $wpdb->replace($tables['post_embeddings'], array('source_wp_id'=>${postId}, 'content_hash'=>EhrmanBlogDiscovery\\Semantic_Index_Service::content_hash($post), 'model'=>EhrmanBlogDiscovery\\Embedding_Service::model_id(), 'dimensions'=>count($vector), 'embedding'=>pack('g*', ...$vector), 'embedding_norm'=>1, 'updated_at'=>current_time('mysql', true)));
      $wpdb->update($tables['ingestion_drafts'], array('embedding_status'=>'complete', 'embedding_error'=>null), array('id'=>$id));
      echo wp_json_encode(array('id'=>$id, 'topics'=>$proposal['topics'], 'keywords'=>$proposal['secondaryKeywords']));
    `]));
    const initial = live();
    const credentials = docker(['printenv', 'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_PASSWORD']).split(/\r?\n/);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(credentials[0]);
    await page.locator('#user_pass').fill(credentials[1]);
    credentials.fill('');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(url + '&draft=' + fixture.id);
    await page.getByRole('button', { name: 'Edit Metadata', exact: true }).click();
    await page.getByRole('button', { name: 'Approve Changes', exact: true }).waitFor();
    const pendingUrl = page.url();
    assert.notEqual(new URL(pendingUrl).searchParams.get('draft'), String(fixture.id));
    assert.deepEqual(live(), initial, 'Opening edits changed live search');
    await page.getByRole('button', { name: 'Remove topic: ' + fixture.topics[1], exact: true }).click();
    await page.getByRole('button', { name: 'Remove keyword: ' + fixture.keywords[1], exact: true }).click();
    for (const width of [1440, 1024, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: path.join(output, 'revision-' + width + '.png'), fullPage: true });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Revision page overflows at ' + width);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    const description = 'Explains how revised metadata enters the discovery index after approval while retaining earlier approved versions for inspection and preserving unchanged vectors.';
    await page.getByLabel('Description', { exact: true }).fill(description);
    await page.getByRole('button', { name: 'Approve Changes', exact: true }).click();
    await page.getByRole('button', { name: 'Edit Metadata', exact: true }).waitFor();
    assert.equal(live().description, description);
    assert.equal(live().id, initial.id);
    const approvedUrl = page.url();
    await page.getByRole('link', { name: 'View previous approved version', exact: true }).click();
    assert(await page.getByText(initial.description, { exact: true }).isVisible());
    assert.equal(await page.getByRole('button', { name: 'Edit Metadata', exact: true }).count(), 0, 'Old history offers editing');
    await page.getByRole('link', { name: 'View latest metadata record', exact: true }).click();
    await page.getByRole('button', { name: 'Edit Metadata', exact: true }).click();
    await page.getByLabel('Description', { exact: true }).fill('Unapproved edit must not change the live record.');
    await page.getByRole('button', { name: 'Cancel Changes', exact: true }).click();
    await page.getByRole('button', { name: 'Edit Metadata', exact: true }).waitFor();
    assert.equal(page.url(), approvedUrl);
    assert.equal(live().description, description);

    await page.route(current => current.pathname.endsWith(`/ingestion/post/${postId}/reanalyze`), async route => {
      regenerations++;
      assert(route.request().postDataJSON().revision, 'Regeneration omitted revision protection');
      if (failRegeneration) {
        await route.fulfill({ status: 503, json: { message: 'Generation was unavailable; no new draft was created.' } });
        return;
      }
      await new Promise(resolve => { releaseGeneration = resolve; });
      const draft = JSON.parse(cli(['eval', `wp_set_current_user(${author}); $service = new EhrmanBlogDiscovery\\Post_Ingestion_Service(); $current=$service->latest_for_post(${postId}); $draft=$service->edit_metadata((int)$current['id']); if(is_wp_error($draft)) throw new RuntimeException($draft->get_error_message()); echo wp_json_encode(array('id'=>(int)$draft['id'], 'analysisActive'=>false));`]));
      await route.fulfill({ json: { draft } });
    });
    accept = false;
    await page.getByRole('button', { name: 'Regenerate Metadata', exact: true }).click();
    assert.equal(regenerations, 0);
    accept = true;
    await page.getByRole('button', { name: 'Regenerate Metadata', exact: true }).click();
    await page.getByText('Creating metadata...', { exact: true }).waitFor();
    for (let attempt = 0; !releaseGeneration && attempt < 100; attempt++) await new Promise(resolve => setTimeout(resolve, 50));
    assert(releaseGeneration, 'Regeneration did not start from progress screen');
    await page.screenshot({ path: path.join(output, 'regenerating.png'), fullPage: true });
    assert.equal(live().description, description);
    releaseGeneration();
    await page.getByRole('button', { name: 'Approve Changes', exact: true }).waitFor();
    assert.equal(regenerations, 1);
    assert.equal(live().description, description);
    await page.getByRole('button', { name: 'Cancel Changes', exact: true }).click();
    await page.getByRole('button', { name: 'Edit Metadata', exact: true }).waitFor();
    failRegeneration = true;
    await page.getByRole('button', { name: 'Regenerate Metadata', exact: true }).click();
    await page.getByText('Generation was unavailable; no new draft was created.', { exact: true }).waitFor();
    assert.equal(regenerations, 2, 'Failed regeneration was automatically retried');
    assert.equal(live().description, description);
    const bad = await context.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'ehrman_ingestion_edit_metadata', draft_id: fixture.id, _wpnonce: 'invalid' } });
    assert.equal(bad.status(), 403);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ status: 'passed', viewports: [1440, 1024, 390], history: true, cancellation: true, atomicManualApproval: true, foregroundRegeneration: true, realAiRequests: 0 }, null, 2));
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    await browser.close();
    cli(['post', 'delete', String(postId), '--force']);
    if (sourceSetting === null) cli(['config', 'delete', 'EHRMAN_DISCOVERY_POST_SOURCE', '--type=constant']);
    else cli(['config', 'set', 'EHRMAN_DISCOVERY_POST_SOURCE', sourceSetting, '--type=constant']);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
