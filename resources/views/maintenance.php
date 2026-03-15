<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>站点维护中</title>
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
        .maintenance-box {
            text-align: center;
            max-width: 480px;
            padding: 60px 40px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .maintenance-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .maintenance-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #1a1a1a;
        }
        .maintenance-msg {
            font-size: 15px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .maintenance-login {
            display: inline-block;
            padding: 8px 24px;
            background: #0066ff;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.2s;
        }
        .maintenance-login:hover { background: #0052cc; }
    </style>
</head>
<body>
    <?php
        $pageType = $pageType ?? 'maintenance';
        $showLogin = $showLogin ?? true;
        $showRegister = $showRegister ?? false;
        $isReadonly = ($pageType === 'readonly');
    ?>
    <div class="maintenance-box">
        <div class="maintenance-icon"><?= $isReadonly ? '📖' : '🔧' ?></div>
        <h1 class="maintenance-title"><?= $isReadonly ? '只读模式' : '站点维护中' ?></h1>
        <p class="maintenance-msg"><?= htmlspecialchars($message ?? '站点维护中，请稍后再访问...') ?></p>
        <?php if (!$isReadonly && $showLogin): ?>
            <div class="maintenance-actions">
                <a href="/login" class="maintenance-login"><?= $showRegister ? '登录' : '管理员登录' ?></a>
                <?php if ($showRegister): ?>
                    <a href="/register" class="maintenance-login" style="background:#52c41a;margin-left:10px;">注册</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
