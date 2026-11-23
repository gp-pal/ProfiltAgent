const assert = require('assert');
const actions = require('../actions');

/**
 * The ordering of these selectors is the whole point, not an implementation detail.
 * Rejecting non-essential cookies clears a consent wall just as well as accepting does,
 * so reject-first is what keeps an automated pipeline from opting the operator into
 * tracking a few hundred times a day. A future edit that reorders them would silently
 * change what the company consents to, which is why it is pinned here.
 */
function testRejectComesBeforeAccept() {
  const selectors = actions.consentDismissSelectors();

  const firstReject = selectors.findIndex((s) => /Atmesti|Reject|Nesutinku|būtini|Necessary/i.test(s));
  const firstAccept = selectors.findIndex((s) => /Sutinku|Accept/i.test(s));

  assert.ok(firstReject >= 0, 'a reject candidate must exist');
  assert.ok(firstAccept >= 0, 'an accept fallback must exist for dialogs with no reject');
  assert.ok(
    firstReject < firstAccept,
    `reject must be tried before accept (reject at ${firstReject}, accept at ${firstAccept})`
  );
}

function testGoogleLithuanianRejectIsCovered() {
  const selectors = actions.consentDismissSelectors();
  assert.ok(
    selectors.some((s) => s.includes('Atmesti viską')),
    'Google\'s Lithuanian reject button must be covered — that is the wall we actually hit'
  );
}

/** A missing wall must be a no-op, never a thrown error that aborts the plan. */
async function testMissingWallIsNotAnError() {
  const page = {
    locator: () => ({ first: () => ({ click: async () => { throw new Error('no such element'); } }) }),
    waitForLoadState: async () => {},
    waitForTimeout: async () => {},
  };

  const out = await actions.dismissConsent(page, null, 100);
  assert.strictEqual(out.dismissed, false, 'reports that nothing was dismissed');
  assert.strictEqual(out.via, null);
}

/** What it clicked is reported, so the run's artifacts record what was consented to. */
async function testItReportsWhichButtonItUsed() {
  let attempts = 0;
  const page = {
    locator: (sel) => ({
      first: () => ({
        click: async () => {
          attempts++;
          // Fail everything except the first reject candidate.
          if (!sel.includes('Atmesti viską')) { throw new Error('miss'); }
        },
      }),
    }),
    waitForLoadState: async () => {},
    waitForTimeout: async () => {},
  };

  const out = await actions.dismissConsent(page, null, 100);
  assert.strictEqual(out.dismissed, true);
  assert.ok(out.via.includes('Atmesti viską'), 'reports the reject button, not a guess');
  assert.strictEqual(attempts, 1, 'reject is the first thing tried, so nothing else is clicked');
}

(async () => {
  testRejectComesBeforeAccept();
  testGoogleLithuanianRejectIsCovered();
  await testMissingWallIsNotAnError();
  await testItReportsWhichButtonItUsed();
  console.log('consent.test OK');
})().catch((e) => { console.error(e); process.exit(1); });
