<?php
// 获取站点设置（复用 SettingSvc 缓存，避免重复查询 settings 表）
$_siteName = 'AMuBBS';
$_siteDesc = '';
try {
    $_settings = \App\Services\SettingSvc::all();
    $_siteName = $_settings['site_name'] ?? 'AMuBBS';
    $_siteDesc = $_settings['site_description'] ?? '';
    $_cdnUrl = rtrim($_settings['cdn_url'] ?? '', '/');
} catch (\Throwable $e) {
    error_log('[header] settings load: ' . $e->getMessage());
}
if (!isset($_cdnUrl)) $_cdnUrl = '';

// 未读通知数 + 头像（短期缓存，避免每次请求查 DB）
$_unreadCount = 0;
$_userAvatar = '/assets/images/default-avatar.png';
if (isset($_SESSION['user_id'])) {
    try {
        $_uid = (int)$_SESSION['user_id'];
        $_userRow = \Core\Cache::get("user:header:{$_uid}", function() use ($_uid) {
            return \Core\Database::fetchOne(
                "SELECT avatar, unread_notifications FROM users WHERE id = ?",
                [$_uid]
            );
        }, 30);
        if ($_userRow) {
            $_unreadCount = (int)($_userRow['unread_notifications'] ?? 0);
            if (!empty($_userRow['avatar'])) {
                $_userAvatar = $_userRow['avatar'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[header] user header cache: ' . $e->getMessage());
    }
}

$_pageTitle = ($pageTitle ?? '') ? htmlspecialchars($pageTitle) . ' - ' . htmlspecialchars($_siteName) : htmlspecialchars($_siteName);
$_pageCss = $pageCss ?? [];

// 未登录时页面里有登录/注册弹窗，弹窗里 htmx 取回的表单用的是 auth.css 的表单样式；
// 已登录用户看不到弹窗，就不加载这份 CSS（省掉每个已登录页面的 17KB）。
if (!isset($_SESSION['user_id']) && !in_array('auth', $_pageCss, true)) {
    $_pageCss[] = 'auth';
}

// 验证码资源（captcha.css 3KB + captcha.js 9KB，且 captcha.js 是 <head> 里的同步脚本，
// 会阻塞渲染）只在站点确实启用了验证码时加载。
// 关掉验证码的站（共享主机上常见）每个页面因此少 12KB 与一次阻塞请求；
// 一旦启用就照旧全站加载 —— 登录/注册弹窗可能在任意页面被 htmx 取回，不能按页面猜。
$_captchaAssets = false;
try {
    $_captchaAssets = \App\Services\CaptchaSvc::isEnabled();
} catch (\Throwable $e) {
    // 设置读不到（例如未安装完）时按「需要」处理，宁可多加载也不要让验证码失效
    $_captchaAssets = true;
}

// 静态资源版本串：取关键资源 mtime 的最大值，避免发版/改样式后浏览器继续用旧缓存
// （本项目的 CSS/JS 没有构建步骤，也没有内容哈希，只能靠 mtime 做 cache busting）
$_assetVer = '';
foreach (['assets/css/main.css', 'assets/js/app.js'] as $_af) {
    $_m = @filemtime(APP_PATH . 'public/' . $_af);
    if ($_m && $_m > (int)$_assetVer) {
        $_assetVer = (string)$_m;
    }
}
$_assetSuffix = $_assetVer !== '' ? '?v=' . $_assetVer : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script>if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark')</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($_siteDesc) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($pageKeywords ?? ($_settings['site_keywords'] ?? '')) ?>">
    <title><?= $_pageTitle ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
    <?= \App\Middlewares\Csrf::tokenMeta() ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/main.css<?= $_assetSuffix ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/highlight.min.css<?= $_assetSuffix ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/toastify.min.css<?= $_assetSuffix ?>">
    <?php foreach ($_pageCss as $_css): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/<?= htmlspecialchars($_css) ?>.css<?= $_assetSuffix ?>">
    <?php endforeach; ?>
    <?php if ($_captchaAssets): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/captcha.css<?= $_assetSuffix ?>">
    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/captcha.js<?= $_assetSuffix ?>"></script>
    <?php endif; ?>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/vendor/htmx/htmx.min.js<?= $_assetSuffix ?>"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/app.js<?= $_assetSuffix ?>"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/utils.js<?= $_assetSuffix ?>"></script>
</head>
<body hx-headers='{"X-CSRF-TOKEN":"<?= htmlspecialchars(\App\Middlewares\Csrf::generateToken(), ENT_QUOTES, 'UTF-8') ?>"}'>
    <?php include __DIR__ . '/navbar.php'; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
    <script>window._initUnreadCount = <?= $_unreadCount ?>;</script>
    <?php endif; ?>

    <main class="main-content">
        <div class="container">
