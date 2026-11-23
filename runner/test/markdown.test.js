const assert = require('assert');
const { htmlToMarkdown } = require('../markdown');

// 1. Boilerplate šalinimas (onlyMainContent default true)
const md1 = htmlToMarkdown(
    '<nav><a href="/x">Meniu punktas</a></nav>'
    + '<main><h1>Apie mus</h1><p>Įmonės tekstas</p></main>'
    + '<footer>© 2026 Footer</footer>'
    + '<script>var x = 1;</script>'
);
assert.ok(md1.includes('# Apie mus'), 'h1 turi virsti # antrašte: ' + md1);
assert.ok(md1.includes('Įmonės tekstas'), 'pagrindinis turinys išlieka');
assert.ok(!md1.includes('Meniu punktas'), 'nav pašalintas');
assert.ok(!md1.includes('Footer'), 'footer pašalintas');
assert.ok(!md1.includes('var x'), 'script pašalintas');

// 2. Lentelės per GFM
const md2 = htmlToMarkdown(
    '<table><thead><tr><th>Stulpelis</th></tr></thead><tbody><tr><td>Reikšmė</td></tr></tbody></table>'
);
assert.ok(md2.includes('| Stulpelis |'), 'GFM lentelė: ' + md2);
assert.ok(md2.includes('| Reikšmė |'), 'GFM lentelės eilutė');

// 3. onlyMainContent=false palieka nav/footer
const md3 = htmlToMarkdown('<nav>Meniu</nav><p>Turinys</p>', { onlyMainContent: false });
assert.ok(md3.includes('Meniu'), 'onlyMainContent=false palieka nav');

// 4. Nuorodos su daugiaeiliu tekstu sutvarkomos
const md4 = htmlToMarkdown('<main><a href="/a">Pirma\n   eilutė</a></main>');
assert.ok(md4.includes('[Pirma eilutė](/a)'), 'link label be newline: ' + md4);

// 5. Tušti / blogi įėjimai
assert.strictEqual(htmlToMarkdown(''), '');
assert.strictEqual(htmlToMarkdown(null), '');
assert.strictEqual(htmlToMarkdown(undefined), '');

// 6. null options nesugriauna konversijos
assert.ok(htmlToMarkdown('<main><p>Tekstas</p></main>', null).includes('Tekstas'), 'null options veikia kaip default');

// 7. data-URI paveikslai pašalinami, normalios nuorodos lieka
const md7 = htmlToMarkdown('<main><img src="data:image/png;base64,iVBORw0KGgoAAAA" alt="logo"><img src="https://example.com/a.png" alt="pav"><p>Turinys</p></main>');
assert.ok(!md7.includes('data:image'), 'data-URI img pašalintas: ' + md7.slice(0, 120));
assert.ok(md7.includes('Turinys'), 'turinys lieka');

// 8. Cookiebot dialogas pašalinamas
const md8 = htmlToMarkdown('<div id="CybotCookiebotDialog"><a href="https://www.cookiebot.com/x">Powered by Cookiebot</a>Sutikimas</div><main><p>Tikras turinys</p></main>');
assert.ok(!md8.includes('Cookiebot'), 'cookiebot pašalintas: ' + md8.slice(0, 120));
assert.ok(md8.includes('Tikras turinys'), 'turinys lieka');

console.log('markdown.test.js: visi testai PASS');