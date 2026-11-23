const assert = require('assert');
const http = require('http');

process.env.PORT = '0';
process.env.HEADLESS = 'true';
const app = require('../server');

function post(port, body) {
  return new Promise((resolve) => {
    const req = http.request(
      { port, path: '/markdown', method: 'POST', headers: { 'Content-Type': 'application/json' } },
      (r) => {
        let d = '';
        r.on('data', (c) => (d += c));
        r.on('end', () => resolve({ status: r.statusCode, body: JSON.parse(d || '{}') }));
      }
    );
    req.end(JSON.stringify(body));
  });
}

async function main() {
  const server = app.listen(0);
  const port = server.address().port;

  // The orchestrator already downloads every news article to read its publish date.
  // This endpoint turns that same HTML into text without launching a browser.
  const article = `
    <html><head><title>T</title></head><body>
      <nav>Navigacija ir meniu</nav>
      <script>console.log('drop me')</script>
      <article><h1>Didmena uždaro parduotuvę</h1><p>Prekybos tinklas paskelbė sprendimą.</p></article>
      <footer>Visos teisės saugomos</footer>
    </body></html>`;

  const ok = await post(port, { html: article });
  assert.strictEqual(ok.status, 200, 'converts html');
  assert.strictEqual(ok.body.ok, true);
  assert.ok(ok.body.markdown.includes('Didmena uždaro parduotuvę'), 'keeps the headline');
  assert.ok(ok.body.markdown.includes('Prekybos tinklas'), 'keeps the body text');
  assert.ok(!ok.body.markdown.includes('drop me'), 'drops scripts');
  assert.ok(!/<[a-z]+[ >]/i.test(ok.body.markdown), 'no html tags survive');
  assert.strictEqual(ok.body.length, ok.body.markdown.length, 'reports the length');

  // Chrome for the boilerplate stays out by default; asking for it brings it back,
  // which is how a caller can tell a thin article from a stripped one.
  const full = await post(port, { html: article, only_main_content: false });
  assert.ok(full.body.markdown.includes('Navigacija'), 'only_main_content=false keeps chrome');
  assert.ok(!ok.body.markdown.includes('Navigacija'), 'default strips chrome');

  const blank = await post(port, { html: '   ' });
  assert.strictEqual(blank.status, 422, 'blank html is refused, not silently empty');
  assert.strictEqual(blank.body.error, 'html_required');

  const missing = await post(port, {});
  assert.strictEqual(missing.status, 422, 'missing html is refused');

  server.close();
  console.log('markdown.endpoint.test OK');
}

main().catch((e) => { console.error(e); process.exit(1); });
