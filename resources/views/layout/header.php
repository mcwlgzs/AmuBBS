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
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/highlight.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/toastify.min.css">
    <?php foreach ($_pageCss as $_css): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/<?= htmlspecialchars($_css) ?>.css">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($_cdnUrl) ?>/assets/css/captcha.css">
    <style>[x-cloak]{display:none!important}</style>
    <script src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/captcha.js"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/app.js"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/utils.js"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/alpine-collapse.min.js"></script>
    <script defer src="<?= htmlspecialchars($_cdnUrl) ?>/assets/js/alpine.min.js"></script>
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
    <script>window._initUnreadCount = <?= $_unreadCount ?>;</script>
    <?php endif; ?>

    <main class="main-content">
        <div class="container">
