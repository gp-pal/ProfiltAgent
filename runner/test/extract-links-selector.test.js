const assert = require('assert');
const actions = require('../actions');

/**
 * Without a selector every consent dialog, nav and footer link competes for the limit.
 * Measured on delfi.lt's own search: all 200 slots went to the Cookiebot dialog and not
 * one article came back, which is why scoping exists.
 */
async function main() {
  const seen = [];
  const page = {
    $$eval: async (scope, fn, limit) => {
      seen.push(scope);
      return [{ text: 't', href: 'https://x.lt/a-123456', title: '' }].slice(0, limit);
    },
  };

  await actions.extractLinks(page, 10);
  assert.strictEqual(seen[0], 'a[href]', 'no selector keeps the whole-page behaviour');

  await actions.extractLinks(page, 10, '.search-results');
  assert.strictEqual(seen[1], '.search-results a[href]', 'a selector scopes anchors inside it');

  console.log('extract-links-selector.test OK');
}

main().catch((e) => { console.error(e); process.exit(1); });
