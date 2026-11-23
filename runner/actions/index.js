const fs = require('fs').promises;
const path = require('path');
const { htmlToMarkdown } = require('../markdown');

// Resolve an artifact path under runsDir, rejecting path traversal. The naive
// `path.join(runsDir, p.replace('runs/',''))` is exploitable: `path.join`
// normalizes `..`, so "runs/../../etc/x" escapes runsDir. Canonicalize and verify.
function resolveWithinRuns(screenshotPath, runsDir) {
    if (typeof screenshotPath !== 'string' || !screenshotPath.startsWith('runs/')) {
        throw new Error('Screenshot path must be within runs/ directory');
    }
    const rel = screenshotPath.slice('runs/'.length);
    const base = path.resolve(runsDir);
    const full = path.resolve(base, rel);
    if (full !== base && !full.startsWith(base + path.sep)) {
        throw new Error('Screenshot path escapes runs/ directory');
    }
    return full;
}

async function goto(page, url, timeout = 10000) {
    // 'domcontentloaded' is reliable on ad-heavy sites where the network never
    // goes idle; content readiness is ensured by subsequent wait_for/settle steps.
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
}

async function waitFor(page, selector, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
}

async function click(page, selector, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    await page.click(selector);
}

async function type(page, selector, value, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    await page.fill(selector, value);
}

async function press(page, selector, key, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    await page.press(selector, key);
}

// Buttons that dismiss a search-engine cookie/consent wall, REJECT-FIRST on purpose.
// Rejecting non-essential cookies clears the wall just as well as accepting does, so
// the ordering here is what keeps the automated path from opting the company into
// tracking a few hundred times a day. Accept candidates are last, for a dialog that
// offers no reject at all.
function consentDismissSelectors() {
    return [
        // Google, Lithuanian and English, reject first.
        'button:has-text("Atmesti viską")',
        'button:has-text("Reject all")',
        '[aria-label="Atmesti viską"]',
        '[aria-label="Reject all"]',
        'button#W0wltc',
        // Cookiebot, the CMP delfi.lt uses. Measured: 200 links extracted from its
        // dialog and zero articles, because the Google-shaped selectors above do not
        // match it. Decline first, then "allow selection" (which submits only the
        // necessary category), and only then the accept-all buttons.
        '#CybotCookiebotDialogBodyButtonDecline',
        '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowallSelection',
        'button:has-text("Atmesti")',
        // Generic CMPs.
        'button:has-text("Nesutinku")',
        'button:has-text("Tik būtini")',
        'button:has-text("Necessary only")',
        // Last resort: accepting still only clears the wall.
        'button:has-text("Sutinku")',
        'button:has-text("Accept all")',
        'button#L2AGLb',
        '#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll',
        '#CybotCookiebotDialogBodyButtonAccept',
    ];
}

// Best-effort dismissal of a cookie/consent wall so the results underneath are
// reachable. NEVER throws: with no wall present it is a no-op, so a plan can include it
// defensively (unlike `click`, whose miss aborts the run).
//
// Reports which button it used, because "we rejected" and "we accepted" are different
// facts about what the run consented to on the operator's behalf and must not be
// guessed at afterwards.
async function dismissConsent(page, selector, timeout = 10000) {
    const per = Math.min(timeout, 1500);
    const candidates = selector ? [selector, ...consentDismissSelectors()] : consentDismissSelectors();

    for (const sel of candidates) {
        try {
            await page.locator(sel).first().click({ timeout: per });
            try { await page.waitForLoadState('domcontentloaded', { timeout: 5000 }); } catch (e) { /* noop */ }
            try { await page.waitForTimeout(400); } catch (e) { /* noop */ }

            return { dismissed: true, via: sel };
        } catch (e) { /* try next candidate */ }
    }

    return { dismissed: false, via: null };
}

async function scroll(page, deltaY = 500) {
    await page.mouse.wheel(0, deltaY);
    await page.waitForTimeout(1000); // Wait for scroll to complete
}

async function extractText(page, selector, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    const element = await page.$(selector);
    if (!element) {
        throw new Error(`Element not found: ${selector}`);
    }
    return await element.innerText();
}

async function extractAttr(page, selector, attr, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    const element = await page.$(selector);
    if (!element) {
        throw new Error(`Element not found: ${selector}`);
    }
    return await element.getAttribute(attr);
}

async function extractHtml(page, selector, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    const element = await page.$(selector);
    if (!element) {
        throw new Error(`Element not found: ${selector}`);
    }
    return await element.innerHTML();
}

async function extractMarkdown(page, selector = 'body', onlyMainContent = true, timeout = 10000) {
    await page.waitForSelector(selector, { timeout });
    const element = await page.$(selector);
    if (!element) {
        throw new Error(`Element not found: ${selector}`);
    }
    const html = await element.innerHTML();
    return htmlToMarkdown(html, { onlyMainContent });
}

// Anchors from the page, or from within `selector` when one is given — container
// semantics, the same as extract_markdown('body'). Without it every consent dialog,
// nav and footer link competes for the limit: measured on delfi.lt's search, all 200
// slots went to the Cookiebot dialog and not one article came back.
async function extractLinks(page, limit = 50, selector = null) {
    const scope = selector ? `${selector} a[href]` : 'a[href]';
    const links = await page.$$eval(scope, (elements, limit) => {
        return elements.slice(0, limit).map(el => ({
            text: el.textContent.trim(),
            href: el.href,
            title: el.title || ''
        }));
    }, limit);

    return links;
}

// Full-page screenshots need the page "settled": scroll through it to trigger
// lazy-loaded images/sections, return to top, and let the network go idle.
// Height is capped so infinite-scroll pages don't stall the run.
async function settlePageForScreenshot(page) {
    try {
        await page.evaluate(async () => {
            const step = window.innerHeight || 800;
            const maxHeight = Math.min(document.body.scrollHeight, 20000);
            for (let y = 0; y < maxHeight; y += step) {
                window.scrollTo(0, y);
                await new Promise((resolve) => setTimeout(resolve, 120));
            }
            window.scrollTo(0, 0);
        });
        await page.waitForLoadState('networkidle', { timeout: 5000 });
    } catch (e) {
        // Best effort: proceed with the screenshot even if settling failed.
    }
    await page.waitForTimeout(500);
}

async function screenshot(page, screenshotPath, runsDir) {
    // Ensure the path is within runs directory
    const fullPath = resolveWithinRuns(screenshotPath, runsDir);
    const dir = path.dirname(fullPath);

    // Create directory if it doesn't exist
    await fs.mkdir(dir, { recursive: true });

    await settlePageForScreenshot(page);
    await page.screenshot({
        path: fullPath,
        fullPage: true,
        type: 'png',
        animations: 'disabled'
    });

    return screenshotPath;
}

// Enhanced scrolling functions
async function scrollToBottom(page, behavior = 'human') {
    if (behavior === 'human') {
        // Human-like scroll to bottom with multiple steps
        const scrollHeight = await page.evaluate(() => document.body.scrollHeight);
        const viewportHeight = await page.evaluate(() => window.innerHeight);
        const totalScrolls = Math.ceil(scrollHeight / viewportHeight);

        for (let i = 0; i < totalScrolls; i++) {
            await page.evaluate((step) => {
                window.scrollBy(0, window.innerHeight * 0.8);
            }, i);

            // Random delay between scrolls
            await new Promise(resolve => setTimeout(resolve, Math.random() * 2000 + 1000));
        }

        // Final scroll to absolute bottom
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    } else {
        // Instant scroll to bottom
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    }
}

async function scrollProgressive(page, options = {}) {
    const { count = 3, behavior = 'human', delayMin = 1000, delayMax = 3000 } = options;

    for (let i = 0; i < count; i++) {
        // Variable scroll distances for natural behavior
        const scrollDistance = Math.random() * 1000 + 500 + (i * 200);

        if (behavior === 'human') {
            // Smooth scroll with easing
            await page.evaluate((distance) => {
                window.scrollBy({ top: distance, behavior: 'smooth' });
            }, scrollDistance);
        } else {
            await page.evaluate((distance) => {
                window.scrollBy(0, distance);
            }, scrollDistance);
        }

        // Random delay between scrolls
        if (i < count - 1) {
            const delay = Math.random() * (delayMax - delayMin) + delayMin;
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}

async function scrollSmart(page, behavior = 'human') {
    // Detect page height and scroll intelligently
    const pageInfo = await page.evaluate(() => ({
        scrollHeight: document.body.scrollHeight,
        clientHeight: document.documentElement.clientHeight,
        currentScroll: window.pageYOffset
    }));

    const remainingHeight = pageInfo.scrollHeight - pageInfo.clientHeight - pageInfo.currentScroll;

    if (remainingHeight > 0) {
        const scrollSteps = Math.min(Math.ceil(remainingHeight / 800), 8);
        const scrollDistance = remainingHeight / scrollSteps;

        for (let i = 0; i < scrollSteps; i++) {
            if (behavior === 'human') {
                await page.evaluate((distance) => {
                    window.scrollBy({ top: distance, behavior: 'smooth' });
                }, scrollDistance);

                // Human-like pause
                await new Promise(resolve => setTimeout(resolve, Math.random() * 1500 + 800));
            } else {
                await page.evaluate((distance) => {
                    window.scrollBy(0, distance);
                }, scrollDistance);
            }
        }
    }
}

async function randomDelay(min = 1000, max = 3000) {
    const delay = Math.random() * (max - min) + min;
    await new Promise(resolve => setTimeout(resolve, delay));
}

async function humanScroll(page, deltaY = 800) {
    // Simulate human scrolling with variable speed and micro-movements
    const steps = Math.floor(Math.random() * 3) + 2; // 2-4 steps
    const stepSize = deltaY / steps;

    for (let i = 0; i < steps; i++) {
        const variation = (Math.random() - 0.5) * 100; // ±50px variation
        const currentStep = stepSize + variation;

        await page.evaluate((distance) => {
            window.scrollBy({ top: distance, behavior: 'smooth' });
        }, currentStep);

        // Micro-delay between steps
        await new Promise(resolve => setTimeout(resolve, Math.random() * 200 + 100));
    }
}

// Enhanced screenshot functions
async function screenshotFullpage(page, screenshotPath, runsDir) {
    const fullPath = resolveWithinRuns(screenshotPath, runsDir);
    const dir = path.dirname(fullPath);
    await fs.mkdir(dir, { recursive: true });

    await settlePageForScreenshot(page);
    await page.screenshot({
        path: fullPath,
        fullPage: true,
        type: 'png',
        animations: 'disabled'
    });

    return screenshotPath;
}

async function screenshotViewport(page, screenshotPath, runsDir) {
    const fullPath = resolveWithinRuns(screenshotPath, runsDir);
    const dir = path.dirname(fullPath);
    await fs.mkdir(dir, { recursive: true });

    await page.screenshot({
        path: fullPath,
        fullPage: false,
        type: 'png'
    });

    return screenshotPath;
}

async function screenshotElement(page, selector, screenshotPath, runsDir, timeout = 10000) {
    const fullPath = resolveWithinRuns(screenshotPath, runsDir);
    const dir = path.dirname(fullPath);
    await fs.mkdir(dir, { recursive: true });

    const element = await page.waitForSelector(selector, { timeout });
    await element.screenshot({
        path: fullPath,
        type: 'png'
    });

    return screenshotPath;
}

async function extractIframe(page) {
    // Extract same-origin iframe content according to Bootstrap Phase One specification
    const iframes = await page.evaluate(() => {
        const iframeElements = Array.from(document.querySelectorAll('iframe'));
        const sameOriginIframes = [];

        iframeElements.forEach((iframe, index) => {
            try {
                // Test if we can access the iframe's content (same-origin)
                if (iframe.contentDocument || iframe.contentWindow.document) {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    sameOriginIframes.push({
                        index: index,
                        src: iframe.src || '',
                        html: doc.documentElement.outerHTML
                    });
                }
            } catch (e) {
                // Cross-origin iframe - skip it as per policy
            }
        });

        return sameOriginIframes;
    });

    // Return empty array if no same-origin iframes exist (as per Bootstrap spec)
    return iframes;
}


module.exports = {
    goto,
    waitFor,
    click,
    type,
    press,
    consentDismissSelectors,
    dismissConsent,
    scroll,
    scrollToBottom,
    scrollProgressive,
    scrollSmart,
    randomDelay,
    humanScroll,
    extractText,
    extractAttr,
    extractHtml,
    extractMarkdown,
    extractLinks,
    extractIframe,
    screenshot,
    screenshotFullpage,
    screenshotViewport,
    screenshotElement
};