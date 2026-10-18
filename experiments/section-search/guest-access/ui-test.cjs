const fs = require('fs');
const path = require('path');
const assert = require('assert');
const {chromium} = require('../../../node_modules/playwright-core');
const output = path.resolve(__dirname, '../private/guest-access-ui');

(async () => {
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless: true, channel: 'msedge'});
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const endpoint = 'http://localhost:8086/wp-json/ehrman-section-public/v1/search';
    const denied = await context.request.post(endpoint, {data: {question: 'test'}});
    assert.strictEqual(denied.status(), 403);
    for (const route of ['search', 'feedback', 'review-evidence', 'compare-300']) {
      const result = await context.request.post(`http://localhost:8086/wp-json/ehrman-section-pilot/v1/${route}`, {data:{question:'test',mode:'300'}});
      assert([401, 403].includes(result.status()), `${route}: ${result.status()}`);
    }
    let fail = false;
    let calls = 0;
    await page.route('**/wp-json/ehrman-section-public/v1/search', async route => {
      calls++;
      assert(route.request().headers()['x-wp-nonce']);
      assert.deepStrictEqual(Object.keys(route.request().postDataJSON()), ['question']);
      await route.fulfill(fail ? {status:429,json:{message:'Search limit reached. Please try again in five minutes.'}} : {json:{
        requestId:'mock', question:'How do Jesus and Paul differ?', sampleCount:3986,
        posts:[{id:'1',wpId:'999',title:'Jesus and Paul',url:'https://ehrmanblog.org/',description:'Explores similarities and differences in their teachings.'},
          {id:'2',wpId:'998',title:'<img src=x onerror=alert(1)>',url:'https://ehrmanblog.org/',description:'<script>window.testInjection=true</script>'}],
        tiers:{'1':'direct','2':'background'}, notice:'', costUsd:0, seconds:0
      }});
    });
    for (const viewport of [{width:1440,height:1000},{width:1024,height:800},{width:390,height:844}]) {
      await page.setViewportSize(viewport);
      await page.goto('http://localhost:8086/');
      const nav = page.locator('nav.ehrman-navigation');
      assert.deepStrictEqual(await nav.locator('a').allTextContents(), ['Home','Browse Topics 1','Browse Topics 2','Keyword Search','Ask AI 1','Ask AI 2','Ask AI 3','Reviewer Tools']);
      const geometry = await nav.evaluate(el => {
        const box = el.getBoundingClientRect();
        return {left:box.left,right:box.right,width:innerWidth,scroll:document.documentElement.scrollWidth,
          overflow:getComputedStyle(el).overflowX,links:[...el.querySelectorAll('a')].map(a => {const r=a.getBoundingClientRect();return {text:a.textContent,left:r.left,right:r.right,top:r.top};})};
      });
      assert(geometry.scroll <= viewport.width + 2, JSON.stringify(geometry));
      if (viewport.width > 940) assert(geometry.links.every(a => a.left>=geometry.left-1 && a.right<=geometry.right+1), JSON.stringify(geometry));
      await page.screenshot({path:path.join(output,`home-${viewport.width}.png`),fullPage:false});
      await nav.getByRole('link',{name:'Ask AI 3',exact:true}).click();
      await page.waitForURL('**/ask-ai-3/');
      assert(await page.locator('#section-public-form').isVisible());
      assert(await page.getByText('Section-search trial: 3,986 posts').isVisible());
      assert.strictEqual(await page.locator('#wpadminbar').count(), 0);
      assert.strictEqual(await page.getByText('Sign in as an administrator').count(),0);
      const nonce = await page.evaluate(() => ehrmanSectionPublic.nonce);
      const invalid = await context.request.post(endpoint, {headers:{'X-WP-Nonce':nonce},data:{question:[]}});
      assert.strictEqual(invalid.status(),400, 'A logged-out nonce must reach input validation without an API call.');
      const crossOrigin = await context.request.post(endpoint, {headers:{'X-WP-Nonce':nonce,Origin:'https://example.invalid'},data:{question:'test'}});
      assert.strictEqual(crossOrigin.status(),403);
      const formCalls = calls;
      await page.locator('#section-public-question').fill('   ');
      await page.getByRole('button',{name:'Find Posts'}).click();
      assert.strictEqual(calls,formCalls);
      await page.locator('#section-public-question').fill('How do Jesus and Paul differ?');
      await page.getByRole('button',{name:'Find Posts'}).click();
      await page.getByRole('heading',{name:'2 posts found'}).waitFor();
      assert.strictEqual(await page.locator('.section-public-result').count(),2);
      assert.strictEqual(await page.locator('.section-public-result img,.section-public-result script').count(),0);
      const layout = await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth}));
      assert(layout.scroll<=layout.width+2);
      await page.screenshot({path:path.join(output,`results-${viewport.width}.png`),fullPage:true});
      fail = true;
      await page.getByRole('button',{name:'Find Posts'}).click();
      await page.locator('#section-public-error').waitFor({state:'visible'});
      assert.strictEqual(await page.locator('.section-public-result').count(),0);
      assert(await page.getByRole('button',{name:'Find Posts'}).isEnabled());
      fail = false;
      console.log(JSON.stringify({viewport,anonymous:true,geometry,layout}));
    }
    for (const slug of ['ask-ai','ask-ai-2']) {
      const response = await page.goto(`http://localhost:8086/${slug}/`);
      assert.strictEqual(response.status(),200);
      assert.strictEqual(await page.locator('#wpadminbar').count(),0);
    }
    assert.deepStrictEqual(errors,[]);
    console.log('Anonymous desktop/mobile UI, menu, validation, error handling, and private-route checks passed. Paid responses mocked.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error);process.exit(1);});
