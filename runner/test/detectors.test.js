const assert = require('assert');
const { detectBotChallenge } = require('../detectors');

function fakePage({ content = '', title = '', url = 'https://example.com/', bodyText = '' }) {
    return {
        content: async () => content,
        title: async () => title,
        url: () => url,
        evaluate: async () => bodyText,
    };
}

(async () => {
    // 1. REAL content page with an embedded reCAPTCHA widget → NOT a challenge (the bug).
    const bigText = 'Baltkela logistics transport services about career contact '.repeat(200);
    const embedded = await detectBotChallenge(fakePage({
        content: '<html><body><nav>Logistics</nav>...<script src="https://www.google.com/recaptcha/api.js"></script><div class="g-recaptcha"></div></body></html>',
        title: "Europe's Largest Asset-Based Logistics Company - Baltkela",
        bodyText: bigText,
    }));
    assert.strictEqual(embedded, null, 'embedded reCAPTCHA on a content page must NOT be a challenge: got ' + embedded);

    // 2. Cloudflare interstitial → challenge.
    const cf = await detectBotChallenge(fakePage({
        content: '<html><body>Checking your browser before accessing</body></html>',
        title: 'Just a moment...', bodyText: 'Checking your browser before accessing baltkela.eu',
    }));
    assert.strictEqual(cf, 'Cloudflare', 'Cloudflare interstitial must be detected: got ' + cf);

    // 3. Real captcha WALL (captcha + almost no content) → challenge.
    const wall = await detectBotChallenge(fakePage({
        content: '<html><body><div class="g-recaptcha"></div>Please verify you are human</body></html>',
        title: 'Verify', bodyText: 'Verify you are human to continue',
    }));
    assert.ok(wall && /captcha|human|verif/i.test(wall), 'captcha wall must be detected: got ' + wall);

    // 4. Explicit human-verification phrase (even with some text) → challenge.
    const phrase = await detectBotChallenge(fakePage({
        content: '<html><body>Verify you are human by completing the action below.</body></html>',
        title: 'Attention Required', bodyText: 'Verify you are human by completing the action below',
    }));
    assert.ok(phrase, 'explicit human-verification phrase must be detected: got ' + phrase);

    // 5. Access denied → challenge.
    const denied = await detectBotChallenge(fakePage({
        content: '<html><body>Access denied</body></html>', title: 'Access Denied', bodyText: 'Access denied',
    }));
    assert.ok(denied, 'access denied must be detected: got ' + denied);

    // 6. Clean page, no captcha → null.
    const clean = await detectBotChallenge(fakePage({
        content: '<html><body>Welcome to our shop</body></html>', title: 'Shop', bodyText: 'Welcome to our shop, browse products',
    }));
    assert.strictEqual(clean, null, 'clean page must be null: got ' + clean);

    console.log('detectors.test.js: visi testai PASS');
})();