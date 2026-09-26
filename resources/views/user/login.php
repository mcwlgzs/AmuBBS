<?php
/**
 * 登录表单
 *
 * 两种渲染形态共用本文件（与通知卡片同样的思路，避免两处标记漂移）：
 *   - 整页：/login           → 带 layout 的独立页面（$isModal = false）
 *   - 片段：/login?modal=1   → 只有表单，被登录弹窗 htmx 取回塞进 #authModalBody（$isModal = true）
 *
 * 提交走 htmx：成功服务端回 HX-Redirect（整页跳转），失败回 422 + frontFlash 提示。
 * 页面里没有任何 Alpine 指令。
 */
$isModal = $isModal ?? false;

if (!$isModal) {
    $pageTitle = '登录';
    $pageCss = ['auth'];
    include APP_PATH . 'resources/views/layout/header.php';
}
?>
<?php if (!$isModal): ?>
<div class="auth-page">
    <div class="auth-card">
<?php else: ?>
<div class="auth-card auth-card-embed">
<?php endif; ?>

    <?php if ($isModal): ?>
    <div class="auth-modal-header">
        <h2>欢迎回来</h2>
        <p>登录你的账号以继续</p>
    </div>
    <?php else: ?>
    <div class="auth-header">
        <h1>登录</h1>
        <p>使用用户名或邮箱登录</p>
    </div>
    <?php endif; ?>

    <?php if ($isModal) { include APP_PATH . 'resources/views/components/social-login.php'; } ?>

    <form hx-post="/login" hx-swap="none" hx-indicator="this" hx-disabled-elt="#loginSubmit">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo ?? '/') ?>">
        <?php if ($captchaRequired ?? false): ?>
        <input type="hidden" name="captcha_id" value="">
        <input type="hidden" name="captcha_answer" value="">
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="loginUsername">用户名 / 邮箱</label>
            <input type="text" id="loginUsername" name="username" placeholder="输入用户名或邮箱"
                   class="form-input" required autocomplete="username" autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="loginPassword">密码</label>
            <div class="input-pwd-wrap">
                <input type="password" id="loginPassword" name="password" placeholder="输入密码"
                       class="form-input" required autocomplete="current-password">
                <button type="button" class="pwd-eye-btn" data-pwd-toggle="#loginPassword" tabindex="-1" aria-label="显示密码" aria-pressed="false">
                    <span class="pwd-eye-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="pwd-eye-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></span>
                </button>
            </div>
        </div>

        <div class="form-group" style="display:flex;align-items:center;gap:6px;margin-top:4px;">
            <input type="checkbox" id="remember_me" name="remember_me" value="1" style="width:auto;margin:0;">
            <label for="remember_me" class="form-label" style="margin:0;font-weight:normal;cursor:pointer;">记住我（30天内免登录）</label>
        </div>

        <?php if ($captchaRequired ?? false): ?>
        <div class="captcha-widget" data-captcha-scene="login"></div>
        <?php endif; ?>

        <button type="submit" id="loginSubmit" class="btn btn-primary" style="width:100%;margin-top:8px;">
            <span class="hx-idle">登录</span>
            <span class="hx-busy">登录中...</span>
        </button>
    </form>

    <div class="auth-links">
        <?php if ($isModal): ?>
        <a href="/forgot-password">忘记密码？</a>
        <span style="margin:0 6px;color:var(--gray-300);">|</span>
        <span>还没有账号？</span><a href="#" data-auth-open="register">立即注册</a>
        <?php else: ?>
        忘记密码？<a href="/forgot-password">点此重置</a>
        <div style="margin-top:6px;">还没有账号？<a href="/register">立即注册</a></div>
        <?php endif; ?>
    </div>

<?php if (!$isModal): ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$isModal) { include APP_PATH . 'resources/views/layout/footer.php'; } ?>
