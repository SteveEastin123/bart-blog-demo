const assert = require('node:assert/strict');
const { test } = require('node:test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

const modulePath = path.join(__dirname, 'browser-config.cjs');
function config(base) {
  return spawnSync(process.execPath, ['-e', `console.log(JSON.stringify(require(${JSON.stringify(modulePath)})))`], {
    encoding: 'utf8', env: { ...process.env, EHRMAN_TEST_BASE_URL: base },
  });
}
test('browser tests retain default and accept isolated loopback ports', () => {
  assert.equal(JSON.parse(config('').stdout).base, 'http://localhost:8086');
  assert.equal(JSON.parse(config('http://localhost:49123/').stdout).base, 'http://localhost:49123');
});
test('browser tests reject remote hosts, credentials and non-origin URLs', () => {
  for (const url of ['https://example.com', 'http://user:pass@localhost:8086', 'http://localhost:8086/page', 'http://localhost:8086/?query=yes']) {
    assert.notEqual(config(url).status, 0);
  }
});
