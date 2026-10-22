const assert = require('node:assert/strict');
const path = require('node:path');
const vm = require('node:vm');
const fs = require('node:fs');
const { test } = require('node:test');
const { buildSync } = require('esbuild');
const { compileAssets } = require('../../scripts/build_wordpress_assets.cjs');

const root = path.resolve(__dirname, '../..');
const source = 'wordpress-plugin/ehrman-blog-discovery/assets/src/js';

function load(name) {
  const result = buildSync({
    absWorkingDir: root,
    entryPoints: [`${source}/${name}.js`],
    bundle: true, write: false, format: 'cjs', platform: 'browser',
  });
  const context = { module: { exports: {} }, window: { EhrmanDiscovery: {}, location: { origin: 'https://blog.example' } }, URL };
  vm.runInNewContext(result.outputFiles[0].text, context);
  return context.module.exports;
}

test('frontend modules bundle without external dependencies or import cycles', () => {
  for (const result of compileAssets()) {
    const visiting = new Set();
    const visited = new Set();
    const visit = name => {
      assert(!visiting.has(name), `Circular dependency: ${name}`);
      if (visited.has(name)) return;
      visiting.add(name);
      for (const entry of result.metafile.inputs[name].imports) {
        assert(!entry.external, `Unexpected external dependency: ${entry.path}`);
        visit(entry.path);
      }
      visiting.delete(name);
      visited.add(name);
    };
    Object.keys(result.metafile.inputs).forEach(visit);
  }
});

test('production bundles and controller do not depend on the optional reviewer plugin', () => {
  for (const result of compileAssets().filter(result => Object.keys(result.metafile.outputs).some(name => name.includes('/ehrman-blog-discovery/')))) {
    assert(Object.keys(result.metafile.inputs).every(name => !name.includes('wordpress-demo-plugins')));
    for (const file of result.outputFiles) assert(!/ebd-review|StructureReview|Download PDF|review-tools/.test(file.text));
  }
  const controller = fs.readFileSync(path.join(root, 'wordpress-plugin/ehrman-blog-discovery/src/Core/Page_Controller.php'), 'utf8');
  assert(!/structure_review|Structure_Review|structure-review/.test(controller));
  assert(!fs.existsSync(path.join(root, 'wordpress-plugin/ehrman-blog-discovery/src/Browse/Structure_Review_Renderer.php')));
});

test('result sorting and pagination preserve ranked data', () => {
  const { semanticPageResult, sortedRefinedResult } = load('results');
  const posts = Array.from({ length: 30 }, (_, i) => ({ id: i + 1, published_at: `2026-09-${String(i + 1).padStart(2, '0')}` }));
  const original = { posts, count: 30, per_page: 25 };
  const newest = semanticPageResult(original, 1, 'newest');
  assert.equal(newest.posts[0].id, 30);
  assert.equal(newest.posts.length, 25);
  const last = semanticPageResult(newest, 99, 'ranked');
  assert.equal(last.page, 2);
  assert.equal(last.posts[0].id, 26);
  assert.equal(last.posts.length, 5);
  assert.equal(posts[0].id, 1);
  assert.equal(sortedRefinedResult(original, 'ranked'), original);
  assert.equal(sortedRefinedResult(original, 'newest').posts[0].id, 30);
  const empty = semanticPageResult({ posts: [] }, 99);
  assert.equal(empty.page, 1);
  assert.equal(empty.total_pages, 0);
});

test('search URLs preserve terms, modes, questions and request IDs', () => {
  const { browserUrl, termEntries, MAX_TERMS } = load('search-state');
  const chips = ['Paul', 'PAUL', 'Luke', 'Jesus', 'Atonement', 'Extra'].map(value => ({
    querySelector: selector => ({ value: selector.includes('ebd_keyword') ? value : 'topic' }),
  }));
  const controls = {
    'input[name="ebd_sort"]:checked': { value: 'newest' },
    '[data-ebd-category]': { value: 'history' },
    '[name="ebd_question"]': { value: 'Paul & Jesus?' },
    'input[name="ebd_ai_request"]': { value: 'saved-request' },
  };
  const form = {
    action: 'https://blog.example/keyword-search/?ebd_keyword=old&ebd_page=8',
    dataset: {},
    querySelector: selector => controls[selector] || null,
    querySelectorAll: () => chips,
  };
  assert.equal(termEntries(form).length, MAX_TERMS);
  const url = browserUrl(form, 2);
  assert.deepEqual(url.searchParams.getAll('ebd_keyword[]'), ['Paul', 'Luke', 'Jesus', 'Atonement']);
  assert.deepEqual(url.searchParams.getAll('ebd_term_mode[]'), ['topic', 'topic', 'topic', 'topic']);
  assert.equal(url.searchParams.get('ebd_question'), 'Paul & Jesus?');
  assert.equal(url.searchParams.get('ebd_ai_request'), 'saved-request');
  assert.equal(url.searchParams.get('ebd_category'), 'history');
  assert.equal(url.searchParams.get('ebd_sort'), 'newest');
  assert.equal(url.searchParams.get('ebd_page'), '2');
  assert.equal(url.searchParams.has('ebd_keyword'), false);
  assert.equal(url.hash, '#ebd-results');
});
