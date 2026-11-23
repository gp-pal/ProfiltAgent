// Verifies the challenge handling is soft (records + continues), by asserting the
// exact decision helper the /run loop uses — a pure function, so no live browser.
const assert = require('assert');
const { applyChallengeOutcome } = require('../server');

const result = { logs: [], challenge: undefined, ok: false };
applyChallengeOutcome(result, 'cloudflare', 3);
assert.strictEqual(result.challenge, 'cloudflare', 'records the challenge type');
assert.ok(result.logs.some((l) => /challenge/i.test(l.note)), 'logs the challenge');
// second detection does not overwrite / does not throw
applyChallengeOutcome(result, 'turnstile', 5);
assert.strictEqual(result.challenge, 'cloudflare', 'keeps first challenge type');
console.log('run-challenge.test OK');
