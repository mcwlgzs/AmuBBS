<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>页面未找到</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .error-box {
            text-align: center;
            max-width: 480px;
            padding: 60px 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .error-code {
            font-size: 72px;
            font-weight: 700;
            color: #ddd;
            margin-bottom: 12px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #1a1a1a;
        }
        .error-msg {
            font-size: 15px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .error-home {
            display: inline-block;
            padding: 8px 24px;
            background: #0066ff;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.2s;
        }
        .error-home:hover { background: #0052cc; }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-code">404</div>
        <h1 class="error-title">页面未找到</h1>
        <p class="error-msg">你访问的页面不存在或已被移除。</p>
        <a href="/" class="error-home">返回首页</a>
    </div>
</body>
</html>
