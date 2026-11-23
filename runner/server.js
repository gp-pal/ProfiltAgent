const express = require('express');
const { chromium } = require('playwright');
const fs = require('fs').promises;
const path = require('path');
const Ajv = require('ajv');
const addFormats = require('ajv-formats');
const { localePrefs } = require('./locale');
const { htmlToMarkdown } = require('./markdown');

const app = express();
app.use(express.json({ limit: '10mb' }));

const PORT = process.env.PORT || 8080;
const HEADLESS = process.env.HEADLESS !== 'false';
const MAX_STEPS = parseInt(process.env.MAX_STEPS) || 30;
const RUNS_DIR = process.env.RUNS_DIR || '/app/runs';

// Browser stealth configuration
const STEALTH_MODE = process.env.STEALTH_MODE !== 'false';
const USER_AGENT = process.env.USER_AGENT || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const VIEWPORT_WIDTH = parseInt(process.env.VIEWPORT_WIDTH) || 1920;
const VIEWPORT_HEIGHT = parseInt(process.env.VIEWPORT_HEIGHT) || 1080;
const LOCALE = process.env.LOCALE || 'en-US';
const TIMEZONE = process.env.TIMEZONE || 'Europe/Vilnius';
const { languages: BROWSER_LANGUAGES, acceptLanguage: ACCEPT_LANGUAGE } = localePrefs(LOCALE);

// Load DSL schema
let dslSchema;

// Where the schema lives depends on how the runner is started: the container mounts
// orchestrator/resources/dsl at /app/dsl, while a checkout run directly (npm test, node
// server.js) has it beside the runner directory. DSL_SCHEMA_PATH overrides both.
const DSL_SCHEMA_CANDIDATES = [
    process.env.DSL_SCHEMA_PATH,
    '/app/dsl/schema.json',
    path.join(__dirname, '..', 'orchestrator', 'resources', 'dsl', 'schema.json'),
].filter(Boolean);

async function readDslSchema() {
    let lastError;
    for (const candidate of DSL_SCHEMA_CANDIDATES) {
        try {
            return JSON.parse(await fs.readFile(candidate, 'utf8'));
        } catch (error) {
            if (error.code !== 'ENOENT') {
                throw error; // present but unreadable or invalid: do not fall through silently
            }
            lastError = error;
        }
    }
    throw lastError;
}

(async () => {
    try {
        dslSchema = await readDslSchema();
    } catch (error) {
        console.error('Failed to load DSL schema:', error);
        if (require.main === module) {
            process.exit(1);
        }
    }
})();

// Initialize JSON schema validator
const ajv = new Ajv({ allErrors: true });
addFormats(ajv);

// Import action handlers and detectors
const actions = require('./actions');
const detectors = require('./detectors');

// Shared-secret auth. When RUNNER_TOKEN is unset, auth is disabled (dev); set it
// for any deployment where the port is reachable. Constant-time comparison.
const RUNNER_TOKEN = process.env.RUNNER_TOKEN || '';
const crypto = require('crypto');

function isAuthorized(req) {
    if (!RUNNER_TOKEN) {
        return true;
    }
    const header = req.headers['authorization'] || '';
    const provided = header.startsWith('Bearer ') ? header.slice(7) : '';
    const a = Buffer.from(provided);
    const b = Buffer.from(RUNNER_TOKEN);
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}

async function launchBrowser() {
    const browser = await chromium.launch({
        headless: HEADLESS,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled',
               '--disable-features=VizDisplayCompositor', '--disable-web-security', '--disable-features=TranslateUI',
               '--disable-ipc-flooding-protection'],
    });
    const context = await browser.newContext({
        userAgent: USER_AGENT,
        viewport: { width: VIEWPORT_WIDTH, height: VIEWPORT_HEIGHT },
        locale: LOCALE,
        timezoneId: TIMEZONE,
        permissions: ['geolocation'],
        geolocation: { latitude: 54.6872, longitude: 25.2797 }, // Vilnius, Lithuania
        deviceScaleFactor: 1,
        isMobile: false,
        hasTouch: false,
        colorScheme: 'light',
        extraHTTPHeaders: { 'Accept-Language': ACCEPT_LANGUAGE },
    });

    // Same stealth init script as the /run route's browser context.
    if (STEALTH_MODE) {
        await context.addInitScript((langs) => {
            // Remove webdriver property
            Object.defineProperty(navigator, 'webdriver', {
                get: () => undefined,
            });

            // Mock plugins
            Object.defineProperty(navigator, 'plugins', {
                get: () => [1, 2, 3, 4, 5],
            });

            // Mock languages (derived from LOCALE, no hardcode)
            Object.defineProperty(navigator, 'languages', {
                get: () => langs,
            });

            // Mock permissions
            const originalQuery = window.navigator.permissions.query;
            window.navigator.permissions.query = (parameters) => (
                parameters.name === 'notifications' ?
                    Promise.resolve({ state: Notification.permission }) :
                    originalQuery(parameters)
            );

            // Hide automation indicators
            window.chrome = {
                runtime: {},
            };
        }, BROWSER_LANGUAGES);
    }

    return { browser, context };
}

/**
 * Convert already-fetched HTML into clean markdown, without launching a browser.
 *
 * The orchestrator already downloads every news article to read its publish date and
 * then throws the body away. This lets it keep the text instead, reusing the same
 * turndown conversion `extract_markdown` uses, at the cost of one local HTTP call —
 * no page, no navigation, so nothing here can hit a bot wall or a domain policy.
 * Articles that only render their body in JavaScript still need the browser path.
 */
app.post('/markdown', async (req, res) => {
    if (!isAuthorized(req)) {
        return res.status(401).json({ ok: false, error: 'unauthorized' });
    }

    const html = (req.body && typeof req.body.html === 'string') ? req.body.html : '';
    if (html.trim() === '') {
        return res.status(422).json({ ok: false, error: 'html_required' });
    }

    try {
        const onlyMainContent = !(req.body && req.body.only_main_content === false);
        const markdown = htmlToMarkdown(html, { onlyMainContent });

        res.json({ ok: true, markdown, length: markdown.length });
    } catch (e) {
        res.status(500).json({ ok: false, error: 'markdown_failed: ' + e.message });
    }
});

app.post('/run', async (req, res) => {
    if (!isAuthorized(req)) {
        return res.status(401).json({ ok: false, error: 'unauthorized' });
    }

    const plan = req.body;
    let browser = null;
    let page = null;

    const result = {
        ok: false,
        url: '',
        data: {},
        artifacts: [],
        logs: [],
        error: null
    };

    try {
        // Validate plan against schema
        const validate = ajv.compile(dslSchema);
        if (!validate(plan)) {
            result.error = 'Invalid plan schema: ' + ajv.errorsText(validate.errors);
            return res.json(result);
        }

        // Policy checks
        if (plan.actions.length > MAX_STEPS) {
            result.error = `Too many steps: ${plan.actions.length} > ${MAX_STEPS}`;
            return res.json(result);
        }

        // Launch browser with stealth configuration
        const launchOptions = {
            headless: HEADLESS,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-blink-features=AutomationControlled',
                '--disable-features=VizDisplayCompositor',
                '--disable-web-security',
                '--disable-features=TranslateUI',
                '--disable-ipc-flooding-protection'
            ]
        };

        // Add display args for non-headless mode
        if (!HEADLESS) {
            launchOptions.args.push(
                '--start-maximized',
                '--disable-infobars',
                '--disable-extensions'
            );
        }

        browser = await chromium.launch(launchOptions);

        const context = await browser.newContext({
            userAgent: USER_AGENT,
            viewport: { width: VIEWPORT_WIDTH, height: VIEWPORT_HEIGHT },
            locale: LOCALE,
            timezoneId: TIMEZONE,
            permissions: ['geolocation'],
            geolocation: { latitude: 54.6872, longitude: 25.2797 }, // Vilnius, Lithuania
            deviceScaleFactor: 1,
            isMobile: false,
            hasTouch: false,
            colorScheme: 'light',
            extraHTTPHeaders: { 'Accept-Language': ACCEPT_LANGUAGE }
        });

        // Add stealth scripts if enabled
        if (STEALTH_MODE) {
            await context.addInitScript((langs) => {
                // Remove webdriver property
                Object.defineProperty(navigator, 'webdriver', {
                    get: () => undefined,
                });

                // Mock plugins
                Object.defineProperty(navigator, 'plugins', {
                    get: () => [1, 2, 3, 4, 5],
                });

                // Mock languages (derived from LOCALE, no hardcode)
                Object.defineProperty(navigator, 'languages', {
                    get: () => langs,
                });

                // Mock permissions
                const originalQuery = window.navigator.permissions.query;
                window.navigator.permissions.query = (parameters) => (
                    parameters.name === 'notifications' ?
                        Promise.resolve({ state: Notification.permission }) :
                        originalQuery(parameters)
                );

                // Hide automation indicators
                window.chrome = {
                    runtime: {},
                };
            }, BROWSER_LANGUAGES);
        }

        page = await context.newPage();

        // Execute actions
        for (let i = 0; i < plan.actions.length; i++) {
            const action = plan.actions[i];

            result.logs.push({
                step: i + 1,
                action: action.do,
                note: `Executing ${action.do}`
            });

            try {
                await executeAction(page, action, result);

                // Check for bot challenges after navigation actions
                if (['goto', 'click'].includes(action.do)) {
                    const challenge = await detectors.detectBotChallenge(page);
                    if (challenge) {
                        applyChallengeOutcome(result, challenge, i + 1);
                    }
                }

            } catch (actionError) {
                result.error = `Step ${i + 1} failed: ${actionError.message}`;
                result.ok = false;
                return res.json(result);
            }
        }

        result.url = page.url();
        result.ok = true;

    } catch (error) {
        result.error = error.message;
    } finally {
        if (browser) {
            await browser.close();
        }
    }

    res.json(result);
});

/**
 * Soft challenge handling: record the challenge type + log it and CONTINUE the run
 * (capture whatever public content rendered) instead of aborting. We never try to
 * solve or bypass the challenge — we just stop treating detection as a hard failure.
 */
function applyChallengeOutcome(result, challenge, step) {
    if (!result.challenge) {
        result.challenge = challenge;
    }
    result.logs.push({ step, action: 'detect', note: `Bot challenge detected (continuing): ${challenge}` });
}

async function executeAction(page, action, result) {
    const timeout = action.timeout_ms || 10000;

    switch (action.do) {
        case 'goto':
            await actions.goto(page, action.url, timeout);
            break;

        case 'wait_for':
            await actions.waitFor(page, action.selector, timeout);
            break;

        case 'click':
            await actions.click(page, action.selector, timeout);
            break;

        case 'type':
            await actions.type(page, action.selector, action.value, timeout);
            break;

        case 'press':
            await actions.press(page, action.selector, action.key, timeout);
            break;


        case 'dismiss_consent': {
            // Recorded under save_as so the run's own artifacts say whether this
            // rejected or accepted, rather than leaving it to be inferred later.
            const consent = await actions.dismissConsent(page, action.selector, timeout);
            if (action.save_as) {
                result.data[action.save_as] = consent;
            }
            break;
        }

        case 'scroll':
            await actions.scroll(page, action.delta_y || 500);
            break;

        case 'scroll_to_bottom':
            await actions.scrollToBottom(page, action.scroll_behavior || 'human');
            break;

        case 'scroll_progressive':
            await actions.scrollProgressive(page, {
                count: action.scroll_count || 3,
                behavior: action.scroll_behavior || 'human',
                delayMin: action.delay_min || 1000,
                delayMax: action.delay_max || 3000
            });
            break;

        case 'scroll_smart':
            await actions.scrollSmart(page, action.scroll_behavior || 'human');
            break;

        case 'random_delay':
            await actions.randomDelay(action.delay_min || 1000, action.delay_max || 3000);
            break;

        case 'human_scroll':
            await actions.humanScroll(page, action.delta_y || 800);
            break;

        case 'extract_text':
            const text = await actions.extractText(page, action.selector, timeout);
            result.data[action.save_as] = text;
            break;

        case 'extract_attr':
            const attr = await actions.extractAttr(page, action.selector, action.attr, timeout);
            result.data[action.save_as] = attr;
            break;

        case 'extract_html':
            const html = await actions.extractHtml(page, action.selector, timeout);
            result.data[action.save_as] = html;
            break;

        case 'extract_links':
            const links = await actions.extractLinks(page, action.limit || 50, action.selector || null);
            result.data[action.save_as] = links;
            break;

        case 'extract_iframe':
            const iframes = await actions.extractIframe(page);
            result.data[action.save_as] = iframes;
            break;

        case 'extract_markdown':
            const markdown = await actions.extractMarkdown(
                page,
                action.selector || 'body',
                action.only_main_content !== false,
                timeout
            );
            result.data[action.save_as] = markdown;
            break;

        case 'screenshot':
            const screenshotPath = await actions.screenshot(page, action.path, RUNS_DIR);
            result.artifacts.push(screenshotPath);
            break;

        case 'screenshot_fullpage':
            const fullpagePath = await actions.screenshotFullpage(page, action.path, RUNS_DIR);
            result.artifacts.push(fullpagePath);
            break;

        case 'screenshot_viewport':
            const viewportPath = await actions.screenshotViewport(page, action.path, RUNS_DIR);
            result.artifacts.push(viewportPath);
            break;

        case 'screenshot_element':
            const elementPath = await actions.screenshotElement(page, action.selector, action.path, RUNS_DIR, timeout);
            result.artifacts.push(elementPath);
            break;


        case 'return':
            // Collect specified data keys
            const returnData = {};
            for (const key of action.data) {
                if (result.data.hasOwnProperty(key)) {
                    returnData[key] = result.data[key];
                }
            }
            result.data = returnData;
            break;

        default:
            throw new Error(`Unknown action: ${action.do}`);
    }
}

// Health check endpoint
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        headless: HEADLESS,
        maxSteps: MAX_STEPS,
        stealthMode: STEALTH_MODE,
        userAgent: USER_AGENT,
        viewport: `${VIEWPORT_WIDTH}x${VIEWPORT_HEIGHT}`,
        locale: LOCALE,
        timezone: TIMEZONE
    });
});

if (require.main === module) {
    app.listen(PORT, () => {
        console.log(`ProfiltAgent Runner listening on port ${PORT}`);
        console.log(`Headless mode: ${HEADLESS}`);
        console.log(`Max steps: ${MAX_STEPS}`);
    });
}

module.exports = app;
module.exports.applyChallengeOutcome = applyChallengeOutcome;