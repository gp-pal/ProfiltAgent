/**
 * Derive browser language preferences from a locale string (e.g. "lt-LT").
 * Returns the navigator.languages array and a matching Accept-Language header,
 * so the browser's advertised language is consistent with its locale/geo.
 * @param {string} locale
 * @returns {{ languages: string[], acceptLanguage: string }}
 */
function localePrefs(locale) {
    const primary = (typeof locale === 'string' && locale.trim() !== '') ? locale.trim() : 'en-US';
    const base = primary.split('-')[0];
    const languages = [...new Set([primary, base, 'en'])];
    const acceptLanguage = languages
        .map((lang, i) => (i === 0 ? lang : `${lang};q=${(0.9 - (i - 1) * 0.1).toFixed(1)}`))
        .join(',');
    return { languages, acceptLanguage };
}

module.exports = { localePrefs };