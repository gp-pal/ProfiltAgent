const assert = require('assert');
const { localePrefs } = require('../locale');

let p = localePrefs('lt-LT');
assert.deepStrictEqual(p.languages, ['lt-LT', 'lt', 'en'], 'lt-LT languages: ' + JSON.stringify(p.languages));
assert.strictEqual(p.acceptLanguage, 'lt-LT,lt;q=0.9,en;q=0.8', 'lt-LT AL: ' + p.acceptLanguage);

p = localePrefs('en-US');
assert.deepStrictEqual(p.languages, ['en-US', 'en'], 'en-US languages: ' + JSON.stringify(p.languages));
assert.strictEqual(p.acceptLanguage, 'en-US,en;q=0.9', 'en-US AL: ' + p.acceptLanguage);

p = localePrefs('');
assert.deepStrictEqual(p.languages, ['en-US', 'en'], 'empty falls back to en-US');

p = localePrefs('de');
assert.deepStrictEqual(p.languages, ['de', 'en'], 'de languages: ' + JSON.stringify(p.languages));
assert.strictEqual(p.acceptLanguage, 'de,en;q=0.9', 'de AL: ' + p.acceptLanguage);

console.log('locale.test.js: visi testai PASS');