<?php
/**
 * 社交登录按钮（登录/注册表单共用）
 *
 * 原来这段逻辑只写在登录弹窗里，独立页面没有；抽成组件后两边共用一份。
 * 没有启用任何提供者时什么都不输出。
 */
$_socialProviders = [];
try {
    $pm = \Core\Bootstrap::getInstance()->getPluginManager();
    $socialPlugin = $pm ? $pm->get('SocialLogin') : null;
    if ($socialPlugin) {
        $_socialProviders = $socialPlugin->getEnabledProviderData();
    }
} catch (\Throwable $e) {
    error_log('[social-login] providers: ' . $e->getMessage());
}

if (empty($_socialProviders)) {
    return;
}
?>
<div class="auth-social">
    <?php foreach ($_socialProviders as $sp): ?>
    <a href="/auth/redirect/<?= htmlspecialchars($sp['name']) ?>" class="auth-social-btn auth-social-<?= htmlspecialchars($sp['name']) ?>" title="<?= htmlspecialchars($sp['label']) ?>登录">
        <span class="auth-social-icon"><?= \Core\HtmlSanitizer::sanitize($sp['icon'] ?? '') ?></span>
        <span><?= htmlspecialchars($sp['label']) ?></span>
    </a>
    <?php endforeach; ?>
</div>
<div class="auth-divider"><span>或</span></div>
