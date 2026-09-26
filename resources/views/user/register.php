<?php
/**
 * 注册表单
 *
 * 与登录表单同样的双形态设计（整页 / ?modal=1 片段），页面里没有任何 Alpine 指令。
 * 邮箱验证码按钮走 htmx（hx-include 把邮箱一起提交），成功后由 JS 做 60 秒倒计时。
 */
$isModal = $isModal ?? false;
$verifyEnabled = $verifyEnabled ?? false;
$captchaRequired = $captchaRequired ?? false;

if (!$isModal) {
    $pageTitle = '注册';
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
        <h2>创建账号</h2>
        <p>注册一个新账号</p>
    </div>
    <?php else: ?>
    <div class="auth-header">
        <h1>注册</h1>
        <p>创建你的账号，开始交流</p>
    </div>
    <?php endif; ?>

    <?php if ($isModal) { include APP_PATH . 'resources/views/components/social-login.php'; } ?>

    <form hx-post="/register" hx-swap="none" hx-indicator="this" hx-disabled-elt="#registerSubmit">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo ?? '/') ?>">
        <?php if ($captchaRequired): ?>
        <input type="hidden" name="captcha_id" value="">
        <input type="hidden" name="captcha_answer" value="">
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="regUsername">用户名</label>
            <input type="text" id="regUsername" name="username" placeholder="3-20个字符"
                   class="form-input" required minlength="3" maxlength="20" autocomplete="username" autofocus>
        </div>
        <div class="form-group">
            <label class="form-label" for="regNickname">昵称 <span style="color:var(--text-muted);font-weight:normal;">(可选)</span></label>
            <input type="text" id="regNickname" name="nickname" placeholder="2-20个字符，留空则显示用户名"
                   class="form-input" maxlength="20" autocomplete="nickname">
        </div>
        <div class="form-group">
            <label class="form-label" for="regEmail">邮箱</label>
            <input type="email" id="regEmail" name="email" placeholder="your@email.com"
                   class="form-input" required autocomplete="email">
        </div>

        <?php if ($verifyEnabled): ?>
        <div class="form-group">
            <label class="form-label" for="regEmailCode">邮箱验证码</label>
            <div style="display:flex;gap:8px;">
                <input type="text" id="regEmailCode" name="email_code" placeholder="6位验证码"
                       class="form-input" style="flex:1;" maxlength="6" required autocomplete="one-time-code">
                <button type="button" class="btn btn-ghost" style="white-space:nowrap;min-width:118px;"
                        hx-post="/register/send-code" hx-include="#regEmail" hx-swap="none"
                        data-code-countdown="60">
                    <span class="hx-idle" data-code-label>获取验证码</span>
                    <span class="hx-busy">发送中...</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="regPassword">密码</label>
            <div class="input-pwd-wrap">
                <input type="password" id="regPassword" name="password" placeholder="至少6个字符"
                       class="form-input" required minlength="6" autocomplete="new-password">
                <button type="button" class="pwd-eye-btn" data-pwd-toggle="#regPassword" tabindex="-1" aria-label="显示密码" aria-pressed="false">
                    <span class="pwd-eye-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="pwd-eye-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></span>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="regPasswordConfirm">确认密码</label>
            <div class="input-pwd-wrap">
                <input type="password" id="regPasswordConfirm" name="password_confirm" placeholder="再次输入密码"
                       class="form-input" required minlength="6" autocomplete="new-password">
                <button type="button" class="pwd-eye-btn" data-pwd-toggle="#regPasswordConfirm" tabindex="-1" aria-label="显示密码" aria-pressed="false">
                    <span class="pwd-eye-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="pwd-eye-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></span>
                </button>
            </div>
        </div>

        <?php if ($captchaRequired): ?>
        <div class="captcha-widget" data-captcha-scene="register"></div>
        <?php endif; ?>

        <button type="submit" id="registerSubmit" class="btn btn-primary" style="width:100%;margin-top:8px;">
            <span class="hx-idle">注册</span>
            <span class="hx-busy">注册中...</span>
        </button>
    </form>

    <div class="auth-links">
        <?php if ($isModal): ?>
        <span>已有账号？</span><a href="#" data-auth-open="login">立即登录</a>
        <?php else: ?>
        已有账号？<a href="/login">立即登录</a>
        <?php endif; ?>
    </div>

<?php if (!$isModal): ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$isModal) { include APP_PATH . 'resources/views/layout/footer.php'; } ?>
