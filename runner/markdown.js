const cheerio = require('cheerio');
const TurndownService = require('turndown');
const turndownPluginGfm = require('joplin-turndown-plugin-gfm');

// Generic boilerplate selectors (nav/footer/cookie/ads/social widgets) removed
// before conversion so the markdown contains only the page's main content.
// Written independently; NOT copied from AGPL sources.
const EXCLUDE_NON_MAIN_SELECTORS = [
    'script', 'style', 'noscript', 'iframe', 'svg',
    'header', 'footer', 'nav', 'aside',
    '.header', '#header', '.footer', '#footer',
    '.nav', '#nav', '.navbar', '.navigation', '.menu', '.breadcrumbs', '#breadcrumbs',
    '.sidebar', '#sidebar', '.aside',
    '.modal', '#modal', '.popup', '.overlay',
    '.ad', '.ads', '.advert', '#ad',
    '.cookie', '#cookie', '.cookie-banner', '.consent',
    '.share', '#share', '.social', '.social-media', '.social-links', '#social',
    '.lang-selector', '.language', '#language-selector',
    '.widget', '#widget',
    '[id^="CybotCookiebot"]', '[class^="CybotCookiebot"]',
];

function collapseLinkLabelWhitespace(markdown) {
    return markdown.replace(/\[([^\]]*)\]/g, (match, label) => '[' + label.replace(/\s+/g, ' ').trim() + ']');
}

function removeSkipToContentLinks(markdown) {
    return markdown.replace(/\[[^\]]*skip to content[^\]]*\]\(#[^)]*\)/gi, '');
}

function htmlToMarkdown(html, options = {}) {
    const { onlyMainContent = true } = options || {};

    if (typeof html !== 'string' || html.trim() === '') {
        return '';
    }

    try {
        const $ = cheerio.load(html);
        const selectors = onlyMainContent
            ? EXCLUDE_NON_MAIN_SELECTORS
            : ['script', 'style', 'noscript', 'iframe', 'svg'];
        selectors.forEach((sel) => $(sel).remove());

        // Data-URI images become huge base64 blobs in markdown — drop them.
        $('img[src^="data:"]').remove();

        const turndown = new TurndownService({
            headingStyle: 'atx',
            codeBlockStyle: 'fenced',
        });
        turndown.use(turndownPluginGfm.gfm);

        let markdown = turndown.turndown($.html());
        markdown = collapseLinkLabelWhitespace(markdown);
        markdown = removeSkipToContentLinks(markdown);
        markdown = markdown.replace(/\n{3,}/g, '\n\n');

        return markdown.trim();
    } catch (e) {
        // Conversion is best-effort: callers fall back to raw HTML handling.
        return '';
    }
}

module.exports = { htmlToMarkdown };