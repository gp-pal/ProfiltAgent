async function detectBotChallenge(page) {
    try {
        const content = await page.content();
        const title = await page.title();
        const url = page.url();

        // Cloudflare interstitial detection — this is always a real wall
        // (the whole page IS the "checking your browser" screen), so no
        // text-length gate is needed here.
        if (content.includes('Checking your browser') ||
            content.includes('DDoS protection by Cloudflare') ||
            content.includes('cf-browser-verification') ||
            title.includes('Just a moment') ||
            url.includes('cdn-cgi/l/chk_jschl')) {
            return 'Cloudflare';
        }

        const lowerContent = content.toLowerCase();

        // Explicit human-verification phrasing is unambiguous: legitimate
        // content pages never contain these exact phrases, so they are
        // trusted regardless of how much other text is on the page.
        const humanVerificationPhrases = [
            'verify you are human',
            'complete the security check',
            'unusual traffic',
            'are you a robot',
            'confirm you are not a robot',
            'please verify you',
        ];
        if (humanVerificationPhrases.some((phrase) => lowerContent.includes(phrase))) {
            return 'Human verification challenge';
        }

        /**
         * Captcha widget markers (reCAPTCHA, hCaptcha, Turnstile, etc.) are
         * NOT proof of a bot challenge on their own. Countless legitimate,
         * fully-scrapable sites embed a captcha on a contact form or via
         * invisible reCAPTCHA v3 while the rest of the page is normal,
         * content-rich HTML (confirmed live on baltkela.eu: 362KB of real
         * content, "recaptcha" appears 7x, zero challenge-wall markers).
         *
         * A REAL captcha wall is a page that IS the challenge — i.e. it has
         * little or no visible body text besides the captcha itself. So we
         * only treat a captcha marker as a challenge when the page's visible
         * text is short (< 500 chars after whitespace collapsing). Above
         * that threshold we assume it is a widget embedded in real content
         * and do not abort the scrape.
         */
        const captchaMarkers = [
            'hcaptcha',
            'h-captcha',
            'g-recaptcha',
            'google.com/recaptcha',
            'recaptcha',
            'cf-turnstile',
            'challenges.cloudflare.com',
        ];
        if (captchaMarkers.some((marker) => lowerContent.includes(marker))) {
            const bodyText = await page.evaluate(() => (document.body ? document.body.innerText : '')).catch(() => '');
            const textLen = String(bodyText).replace(/\s+/g, ' ').trim().length;
            if (textLen < 500) {
                return 'CAPTCHA wall';
            }
            // Otherwise: captcha widget on a content-rich page — not a challenge.
        }

        // Generic bot/access-block detection patterns — these phrases are
        // themselves the block page, so no text-length gate is needed.
        if (content.includes('Access denied') ||
            content.includes('Blocked by') ||
            content.includes('Bot detected') ||
            content.includes('Suspicious activity') ||
            title.includes('Access Denied') ||
            title.includes('Blocked')) {
            return 'Generic bot protection';
        }

        return null;

    } catch (error) {
        console.warn('Bot challenge detection failed:', error.message);
        return null;
    }
}

async function detectPromptInjection(text) {
    if (!text || typeof text !== 'string') {
        return false;
    }
    
    const suspiciousPatterns = [
        /ignore\s+previous\s+instructions/i,
        /forget\s+everything/i,
        /system\s*:\s*you\s+are/i,
        /assistant\s*:\s*i\s+will/i,
        /\[system\]/i,
        /\[\/system\]/i,
        /<\s*script/i,
        /javascript\s*:/i,
        /data\s*:\s*text\/html/i
    ];
    
    return suspiciousPatterns.some(pattern => pattern.test(text));
}

module.exports = {
    detectBotChallenge,
    detectPromptInjection
};
