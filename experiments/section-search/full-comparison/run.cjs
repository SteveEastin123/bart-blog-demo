// Both arms use the same browser form and renderer; only the private backend varies.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const assert = require('assert');
const {chromium} = require('../../../node_modules/playwright-core');
const root = path.resolve(__dirname, '../../..');
const run = 'full-corpus-250-2026-10-18';
const work = path.resolve(__dirname, '../private', run);
const publicDir = path.resolve(__dirname, '../runs', run);
const read = p => JSON.parse(fs.readFileSync(p, 'utf8').replace(/^\uFEFF/, ''));
const manifest = read(path.join(publicDir, 'manifest.json'));
const questions = read(path.join(publicDir, 'questions.json')).questions;
const limit = Number(process.argv.find(x => x.startsWith('--limit='))?.split('=')[1] || 250);
const base = 'http://localhost:8086';
const endpoint = base + '/wp-json/ehrman-full-comparison/v1/';
if (!process.argv.includes('--approve-api')) throw new Error('Requires --approve-api');
for (const [name, expected] of Object.entries(manifest.preserved)) {
  const actual = crypto.createHash('sha256').update(fs.readFileSync(path.join(root,name))).digest('hex');
  assert.strictEqual(actual, expected, `Frozen file changed: ${name}`);
}
function save(file, data) {
  if (fs.existsSync(file)) throw new Error('Refusing to replace saved result: ' + file);
  fs.writeFileSync(file+'.tmp', JSON.stringify(data,null,2)+'\n');
  fs.renameSync(file+'.tmp',file);
}
function environment() {
  const values = {};
  for (const line of fs.readFileSync(path.join(root,'wordpress-production/.env'),'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Z0-9_]+)=(.*)$/);
    if (match) values[match[1]] = match[2].trim().replace(/^["']|["']$/g,'');
  }
  return {...values,...process.env};
}
(async () => {
  fs.mkdirSync(path.join(work,'results'),{recursive:true});
  const browser = await chromium.launch({headless:true,channel:'msedge'});
  try {
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    const page = await context.newPage();
    const config = environment();
    await page.goto(base+'/wp-login.php');
    await page.locator('#user_login').fill(config.WORDPRESS_ADMIN_USER || 'admin');
    await page.locator('#user_pass').fill(config.WORDPRESS_ADMIN_PASSWORD || 'ehrman-admin-production-test-only');
    await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')),page.locator('#wp-submit').click()]);
    let active = null, received = null, routeError = null;
    await page.route('**/wp-json/ehrman-section-public/v1/search', async route => {
      try {
        assert(active);
        assert.strictEqual(route.request().postDataJSON().question,active.question.question);
        const start = performance.now();
        const response = await route.fetch({url:endpoint+'run',method:'POST',
          postData:JSON.stringify({questionId:active.question.id,arm:active.arm}),timeout:300000,maxRetries:0});
        received = await response.json();
        received.httpSeconds = (performance.now()-start)/1000;
        await route.fulfill({response});
      } catch (error) { routeError = error; await route.abort(); }
    });
    let spend = 0;
    for (const name of fs.readdirSync(path.join(work,'results')).filter(n=>/^q\d+-ask-ai-[23]\.json$/.test(n))) {
      spend += read(path.join(work,'results',name)).costUsd;
    }
    for (const question of questions.slice(0,limit)) {
      await page.goto(base+'/ask-ai-3/');
      const nonce = await page.evaluate(()=>ehrmanSectionPublic.nonce);
      const order = question.id % 2 ? ['ask-ai-2','ask-ai-3'] : ['ask-ai-3','ask-ai-2'];
      for (const arm of order) {
        const file = path.join(work,'results',`q${String(question.id).padStart(3,'0')}-${arm}.json`);
        if (fs.existsSync(file)) {
          const old=read(file); assert.strictEqual(old.run,run); assert.strictEqual(old.question,question.question);
          continue;
        }
        const response = await context.request.get(endpoint+'status',{
          params:{questionId:question.id,arm},headers:{'X-WP-Nonce':nonce}});
        assert(response.ok(),await response.text());
        const status=await response.json();
        if (status.run === run) {
          save(file,{...status,recovered:true,submissionToResultsSeconds:null});
          spend+=status.costUsd;
          console.log(JSON.stringify({recovered:question.id,arm,timingUnavailable:true}));
          continue;
        }
        assert.strictEqual(status.state,'not-started','An interrupted paid attempt requires inspection');
        if (spend + .25 > manifest.searchSafetyCeilingUsd) throw new Error('Search spending safety ceiling');
        active={question,arm}; received=null; routeError=null;
        await page.locator('#section-public-question').fill(question.question);
        await page.evaluate(()=>{
          const form=document.querySelector('#section-public-form');
          window.benchmarkStart=performance.now();
          form.requestSubmit();
        });
        await page.waitForFunction(()=>document.querySelector('#section-public-results').getAttribute('aria-busy')==='false',{},{timeout:300000});
        if (routeError) throw routeError;
        assert(received && received.run===run, JSON.stringify(received));
        assert.strictEqual(await page.locator('#section-public-status').textContent(),'Search complete.');
        assert.strictEqual(await page.locator('.section-public-result').count(),received.posts.length);
        const seconds = await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(()=>resolve((performance.now()-window.benchmarkStart)/1000)))));
        save(file,{...received,submissionToResultsSeconds:seconds,recovered:false});
        spend+=received.costUsd;
        console.log(JSON.stringify({question:question.id,arm,posts:received.posts.length,seconds:+seconds.toFixed(2),costUsd:received.costUsd,totalUsd:+spend.toFixed(5)}));
        if ([1,201,250].includes(question.id)) await page.screenshot({path:path.join(work,`q${question.id}-${arm}.png`),fullPage:true});
      }
    }
    console.log(JSON.stringify({completedThrough:limit,totalSearchCostUsd:spend}));
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
