/* Regression for WordPress plain and pretty permalink REST roots. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {window: {}, URL};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/i18n.js'), 'utf8'), context);
const url = context.window.PESM_restURL;
assert.equal(url('sessions/7/layout', 'https://example.test/blog/wp-json/pesm/v1/').href,
  'https://example.test/blog/wp-json/pesm/v1/sessions/7/layout');
const plain = url('sessions/7/layout', 'https://example.test/blog/index.php?rest_route=%2Fpesm%2Fv1%2F');
assert.equal(plain.pathname, '/blog/index.php');
assert.equal(plain.searchParams.get('rest_route'), '/pesm/v1/sessions/7/layout');
const query = url('tickets/me?order_id=42', 'https://example.test/?rest_route=/pesm/v1/&lang=fa');
assert.equal(query.searchParams.get('rest_route'), '/pesm/v1/tickets/me');
assert.equal(query.searchParams.get('order_id'), '42');
assert.equal(query.searchParams.get('lang'), 'fa');
assert.throws(() => url('https://other.test/api', 'https://example.test/wp-json/pesm/v1/'));
console.log('PASS: REST URLs preserve plain permalinks, subdirectories and endpoint query parameters.');
