<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            color: white;
            max-width: 600px;
            padding: 2rem;
        }
        h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 300;
        }
        p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        .status {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }
        .api-info {
            margin-top: 2rem;
            font-size: 0.9rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>{{ config('app.name') }}</h1>
        <p>Web scraping and analysis orchestrator</p>
        
        <div class="status">
            <h3>🚀 Service Running</h3>
            <p>The {{ config('app.name') }} orchestrator is running and ready to process requests.</p>
        </div>
        
        <div class="api-info">
            <p>API endpoints available:</p>
            <ul style="list-style: none; padding: 0;">
                <li>GET /health - Health check</li>
                <li>POST /api/run - Execute analysis</li>
                <li>GET /dashboard - Reasoning dashboard</li>
            </ul>
        </div>
    </div>
</body>
</html>
