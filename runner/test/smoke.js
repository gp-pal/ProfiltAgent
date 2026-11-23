const http = require('http');

// Simple smoke test for the runner
async function runSmokeTest() {
    console.log('Running ProfiltAgent Runner smoke test...');
    
    const testPlan = {
        "version": "1.0",
        "meta": {
            "task": "Smoke test",
            "company": "Example",
            "domain": "example.com"
        },
        "actions": [
            {"do": "goto", "url": "https://example.com"},
            {"do": "wait_for", "selector": "body", "timeout_ms": 10000},
            // example.com no longer renders an <h1>; its one paragraph is what the page has.
            {"do": "extract_text", "selector": "p", "save_as": "text"},
            {"do": "screenshot", "path": "runs/screenshots/smoke_test.png"},
            {"do": "return", "data": ["text"]}
        ]
    };

    const postData = JSON.stringify(testPlan);
    
    const options = {
        hostname: 'localhost',
        port: 8080,
        path: '/run',
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
        }
    };

    return new Promise((resolve, reject) => {
        const req = http.request(options, (res) => {
            let data = '';
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                try {
                    const result = JSON.parse(data);
                    
                    if (result.ok) {
                        console.log('✓ Smoke test PASSED');
                        console.log('  - Successfully navigated to example.com');
                        console.log('  - Extracted text:', result.data.text);
                        console.log('  - Screenshot saved:', result.artifacts?.[0] || 'none');
                        resolve(true);
                    } else {
                        console.log('✗ Smoke test FAILED');
                        console.log('  Error:', result.error);
                        resolve(false);
                    }
                } catch (e) {
                    console.log('✗ Smoke test FAILED - Invalid response');
                    console.log('  Response:', data);
                    resolve(false);
                }
            });
        });

        req.on('error', (e) => {
            console.log('✗ Smoke test FAILED - Connection error');
            console.log('  Error:', e.message);
            resolve(false);
        });

        req.write(postData);
        req.end();
    });
}

// Health check test
async function healthCheck() {
    console.log('Checking runner health...');
    
    const options = {
        hostname: 'localhost',
        port: 8080,
        path: '/health',
        method: 'GET'
    };

    return new Promise((resolve, reject) => {
        const req = http.request(options, (res) => {
            let data = '';
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                try {
                    const result = JSON.parse(data);
                    
                    if (result.status === 'ok') {
                        console.log('✓ Health check PASSED');
                        console.log('  - Status:', result.status);
                        console.log('  - Headless:', result.headless);
                        console.log('  - Max steps:', result.maxSteps);
                        resolve(true);
                    } else {
                        console.log('✗ Health check FAILED');
                        console.log('  Response:', result);
                        resolve(false);
                    }
                } catch (e) {
                    console.log('✗ Health check FAILED - Invalid response');
                    console.log('  Response:', data);
                    resolve(false);
                }
            });
        });

        req.on('error', (e) => {
            console.log('✗ Health check FAILED - Connection error');
            console.log('  Error:', e.message);
            resolve(false);
        });

        req.end();
    });
}

// Run tests
async function main() {
    console.log('ProfiltAgent Runner Test Suite\n');
    
    const healthResult = await healthCheck();
    console.log('');
    
    if (!healthResult) {
        console.log('Health check failed - skipping smoke test');
        process.exit(1);
    }
    
    const smokeResult = await runSmokeTest();
    console.log('');
    
    if (smokeResult) {
        console.log('All tests passed! 🎉');
        process.exit(0);
    } else {
        console.log('Some tests failed! ❌');
        process.exit(1);
    }
}

main().catch(console.error);
