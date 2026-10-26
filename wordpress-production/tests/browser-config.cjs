// Defaults preserve direct test runs; the full runner supplies an isolated site.
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const base = process.env.EHRMAN_TEST_BASE_URL || 'http://localhost:8086';
const target = new URL(base);
if (target.protocol !== 'http:' || !['localhost', '127.0.0.1'].includes(target.hostname)
    || target.username || target.password || target.pathname !== '/' || target.search || target.hash) {
  throw new Error('Browser regression tests require a local HTTP origin.');
}

module.exports = {
  base: target.origin,
  composeFile: path.join(root, 'wordpress-production/compose.local.yaml'),
  outputFor(name) {
    return path.resolve(process.env.EHRMAN_TEST_OUTPUT_DIR || path.join(root, '.tmp'), name);
  },
};
