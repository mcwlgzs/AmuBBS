<?php
/**
 * 找回密码卡片（片段）
 *
 * 两种渲染路径：
 *   - 整页：user/forgot.php 把它 include 进 .auth-card
 *   - htmx：发送验证码成功后，控制器只渲染它并切到第 2 步（替换 #forgotCard）
 *
 * 依赖变量：$step（'email' 填邮箱 | 'reset' 填验证码和新密码）
 */
$step = $step ?? 'email';
?>
<div id="forgotCard">
<?php if ($step === 'email'): ?>
    <div class="auth-header">
        <h1>找回密码</h1>
        <p>输入注册邮箱，我们会发送验证码</p>
    </div>

    <form hx-post="/forgot-password/send-code" hx-target="#forgotCard" hx-swap="outerHTML"
          hx-indicator="this" hx-disabled-elt="#forgotSendBtn">
        <div class="form-group">
            <label class="form-label" for="forgotEmail">邮箱</label>
            <input type="email" id="forgotEmail" name="email" placeholder="注册时使用的邮箱"
                   class="form-input" required autocomplete="email" autofocus>
        </div>
        <button type="submit" id="forgotSendBtn" class="btn btn-primary" style="width:100%;margin-top:8px;">
            <span class="hx-idle">发送验证码</span>
            <span class="hx-busy">发送中...</span>
        </button>
    </form>

    <div class="auth-links"><a href="/login">← 返回登录</a></div>
<?php else: ?>
    <div class="auth-header">
        <h1>重置密码</h1>
        <p>输入邮箱收到的验证码和新密码</p>
    </div>

    <form hx-post="/forgot-password/reset" hx-swap="none" hx-indicator="this" hx-disabled-elt="#forgotResetBtn">
        <div class="form-group">
            <label class="form-label" for="forgotCode">验证码</label>
            <input type="text" id="forgotCode" name="code" placeholder="6位验证码"
                   class="form-input" required maxlength="6" autocomplete="one-time-code"
                   style="letter-spacing:4px;font-weight:600;" autofocus>
        </div>
        <div class="form-group">
            <label class="form-label" for="forgotPassword">新密码</label>
            <div class="input-pwd-wrap">
                <input type="password" id="forgotPassword" name="password" placeholder="新密码（至少6位）"
                       class="form-input" required minlength="6" autocomplete="new-password">
                <button type="button" class="pwd-eye-btn" data-pwd-toggle="#forgotPassword" tabindex="-1" aria-label="显示密码" aria-pressed="false">
                    <span class="pwd-eye-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="pwd-eye-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></span>
                </button>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="forgotPasswordConfirm">确认新密码</label>
            <div class="input-pwd-wrap">
                <input type="password" id="forgotPasswordConfirm" name="password_confirm" placeholder="再次输入新密码"
                       class="form-input" required minlength="6" autocomplete="new-password">
                <button type="button" class="pwd-eye-btn" data-pwd-toggle="#forgotPasswordConfirm" tabindex="-1" aria-label="显示密码" aria-pressed="false">
                    <span class="pwd-eye-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="pwd-eye-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></span>
                </button>
            </div>
        </div>
        <button type="submit" id="forgotResetBtn" class="btn btn-primary" style="width:100%;margin-top:8px;">
            <span class="hx-idle">重置密码</span>
            <span class="hx-busy">提交中...</span>
        </button>
    </form>

    <div class="auth-links">
        <a href="#" hx-get="/forgot-password" hx-target="#forgotCard" hx-swap="outerHTML">← 重新获取验证码</a>
    </div>
<?php endif; ?>
</div>
