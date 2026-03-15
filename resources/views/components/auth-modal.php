<?php
/**
 * 全局登录/注册弹窗组件（Alpine.js）
 * 仅未登录时渲染
 *
 * 触发方式:
 *   $dispatch('auth-show')           — 默认显示登录
 *   $dispatch('auth-show', 'login')
 *   $dispatch('auth-show', 'register')
 *
 * 社交登录通过插件系统注入
 */
if (isset($_SESSION['user_id'])) return;

// 验证码配置
$_captchaLoginRequired = \App\Services\CaptchaSvc::isRequired('login');
$_captchaRegisterRequired = \App\Services\CaptchaSvc::isRequired('register');

// 获取社交登录提供者数据
$_socialProviders = [];
try {
    $pm = \Core\Bootstrap::getInstance()->getPluginManager();
    $socialPlugin = $pm ? $pm->get('SocialLogin') : null;
    if ($socialPlugin) {
        $_socialProviders = $socialPlugin->getEnabledProviderData();
    }
} catch (\Throwable $e) {
    error_log('[auth-modal] social providers: ' . $e->getMessage());
}
?>
<div x-data="authModal()" x-on:auth-show.window="open($event.detail || 'login')" x-show="visible" x-cloak
     class="auth-overlay" @click.self="close()" style="z-index:1200;">
    <div class="auth-modal" @click.stop x-show="visible" x-transition:enter="auth-enter" x-transition:enter-start="auth-enter-from" x-transition:enter-end="auth-enter-to" x-transition:leave="auth-leave" x-transition:leave-start="auth-leave-from" x-transition:leave-end="auth-leave-to">
        <button class="auth-modal-close" @click="close()" aria-label="关闭">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>

        <!-- 标题区 -->
        <div class="auth-modal-header">
            <h2 x-text="{'login':'欢迎回来','register':'创建账号','forgot':'找回密码','reset':'重置密码'}[tab]"></h2>
            <p x-text="{'login':'登录你的账号以继续','register':'注册一个新账号','forgot':'输入邮箱获取验证码','reset':'输入验证码和新密码'}[tab]"></p>
        </div>

        <!-- 社交登录按钮 -->
        <?php if (!empty($_socialProviders)): ?>
        <div class="auth-social" x-show="tab === 'login' || tab === 'register'">
            <?php foreach ($_socialProviders as $sp): ?>
            <a href="/auth/redirect/<?= htmlspecialchars($sp['name']) ?>" class="auth-social-btn auth-social-<?= htmlspecialchars($sp['name']) ?>" title="<?= htmlspecialchars($sp['label']) ?>登录">
                <span class="auth-social-icon"><?= \Core\HtmlSanitizer::sanitize($sp['icon'] ?? '') ?></span>
                <span><?= htmlspecialchars($sp['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="auth-divider" x-show="tab === 'login' || tab === 'register'"><span>或</span></div>
        <?php endif; ?>

        <!-- 登录表单 -->
        <div x-show="tab === 'login'">
            <form @submit.prevent="submitLogin" class="auth-form">
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" x-model="login.username" placeholder="用户名 / 邮箱" required autocomplete="username">
                </div>
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input :type="showPwd ? 'text' : 'password'" x-model="login.password" placeholder="密码" required autocomplete="current-password">
                    <button type="button" class="auth-pwd-toggle" @click="showPwd = !showPwd" tabindex="-1">
                        <svg x-show="!showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <?php if ($_captchaLoginRequired): ?>
                <div class="captcha-widget" x-ref="loginCaptchaContainer" x-init="$nextTick(() => { _loginCw = new CaptchaWidget($refs.loginCaptchaContainer, { scene: 'login', onVerified: (ok, id, ans) => { loginCaptchaId = id; loginCaptchaAnswer = ans; } }); })"></div>
                <?php endif; ?>
                <button type="submit" class="auth-submit-btn" :disabled="loginLoading">
                    <span x-show="!loginLoading">登 录</span>
                    <span x-show="loginLoading" class="auth-spinner"></span>
                </button>
            </form>
            <div class="auth-modal-footer">
                <a href="#" @click.prevent="tab = 'forgot'" style="font-size:12px;color:var(--text-muted);">忘记密码？</a>
                <span style="margin:0 4px;color:var(--gray-300);">|</span>
                <span>还没有账号？</span><a href="#" @click.prevent="tab = 'register'">立即注册</a>
            </div>
        </div>

        <!-- 注册表单 -->
        <div x-show="tab === 'register'">
            <form @submit.prevent="submitRegister" class="auth-form">
                <div class="auth-input-group" :class="{ 'has-error': regErrors.username }">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" x-model="reg.username" placeholder="用户名（3-20个字符）" required autocomplete="username">
                </div>
                <div class="auth-field-error" x-show="regErrors.username" x-text="regErrors.username" x-cloak></div>
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    <input type="text" x-model="reg.nickname" placeholder="昵称（可选，2-20个字符）" autocomplete="nickname" maxlength="20">
                </div>
                <div class="auth-input-group" :class="{ 'has-error': regErrors.email }">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="email" x-model="reg.email" placeholder="邮箱地址" required autocomplete="email">
                </div>
                <div class="auth-field-error" x-show="regErrors.email" x-text="regErrors.email" x-cloak></div>
                <div class="auth-input-group" :class="{ 'has-error': regErrors.password }">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input type="password" x-model="reg.password" placeholder="密码（至少6位）" required autocomplete="new-password">
                </div>
                <div class="auth-field-error" x-show="regErrors.password" x-text="regErrors.password" x-cloak></div>
                <div class="auth-input-group" :class="{ 'has-error': regErrors.password_confirm }">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <input type="password" x-model="reg.password_confirm" placeholder="确认密码" required autocomplete="new-password">
                </div>
                <div class="auth-field-error" x-show="regErrors.password_confirm" x-text="regErrors.password_confirm" x-cloak></div>
                <?php if ($_captchaRegisterRequired): ?>
                <div class="captcha-widget" x-ref="regCaptchaContainer" x-init="$nextTick(() => { _regCw = new CaptchaWidget($refs.regCaptchaContainer, { scene: 'register', onVerified: (ok, id, ans) => { regCaptchaId = id; regCaptchaAnswer = ans; } }); })"></div>
                <?php endif; ?>
                <button type="submit" class="auth-submit-btn" :disabled="regLoading">
                    <span x-show="!regLoading">注 册</span>
                    <span x-show="regLoading" class="auth-spinner"></span>
                </button>
            </form>
            <div class="auth-modal-footer">
                <span>已有账号？</span><a href="#" @click.prevent="tab = 'login'">立即登录</a>
            </div>
        </div>

        <!-- 忘记密码 - 发送验证码 -->
        <div x-show="tab === 'forgot'">
            <form @submit.prevent="submitForgot" class="auth-form">
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="email" x-model="forgot.email" placeholder="注册时使用的邮箱" required autocomplete="email">
                </div>
                <button type="submit" class="auth-submit-btn" :disabled="forgotLoading">
                    <span x-show="!forgotLoading">发送验证码</span>
                    <span x-show="forgotLoading" class="auth-spinner"></span>
                </button>
            </form>
            <div class="auth-modal-footer">
                <a href="#" @click.prevent="tab = 'login'">← 返回登录</a>
            </div>
        </div>

        <!-- 重置密码 -->
        <div x-show="tab === 'reset'">
            <form @submit.prevent="submitReset" class="auth-form">
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input type="text" x-model="reset.code" placeholder="6位验证码" required maxlength="6" autocomplete="one-time-code" style="letter-spacing:4px;font-weight:600;">
                </div>
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    <input type="password" x-model="reset.password" placeholder="新密码（至少6位）" required autocomplete="new-password">
                </div>
                <div class="auth-input-group">
                    <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <input type="password" x-model="reset.password_confirm" placeholder="确认新密码" required autocomplete="new-password">
                </div>
                <button type="submit" class="auth-submit-btn" :disabled="resetLoading">
                    <span x-show="!resetLoading">重置密码</span>
                    <span x-show="resetLoading" class="auth-spinner"></span>
                </button>
            </form>
            <div class="auth-modal-footer">
                <a href="#" @click.prevent="tab = 'forgot'">← 重新获取验证码</a>
            </div>
        </div>
    </div>
</div>
<script>
function authModal() {
    return {
        visible: false, tab: 'login', showPwd: false,
        login: { username: '', password: '' },
        loginLoading: false,
        loginCaptchaId: '', loginCaptchaAnswer: '', _loginCw: null,
        reg: { username: '', nickname: '', email: '', password: '', password_confirm: '' },
        regErrors: {}, regLoading: false,
        regCaptchaId: '', regCaptchaAnswer: '', _regCw: null,
        forgot: { email: '' },
        forgotLoading: false,
        reset: { code: '', password: '', password_confirm: '' },
        resetLoading: false,
        open(t) {
            this.tab = (t === 'register') ? 'register' : 'login';
            this.regErrors = {};
            this.showPwd = false;
            this.visible = true;
            document.body.style.overflow = 'hidden';
        },
        close() {
            this.visible = false;
            document.body.style.overflow = '';
        },
        async submitLogin() {
            if (!this.login.username || !this.login.password) { toast('请填写用户名和密码', 'error'); return; }
            this.loginLoading = true;
            try {
                var body = Object.assign({}, this.login);
                if (this.loginCaptchaId) { body.captcha_id = this.loginCaptchaId; body.captcha_answer = this.loginCaptchaAnswer; }
                var data = await App.post('/login', body, { silent: true });
                if (data.success) { toast('登录成功', 'success'); setTimeout(function(){ if (data.data && data.data.redirect) { location.href = data.data.redirect; } else { location.reload(); } }, 600); }
                else { toast(data.message || '登录失败', 'error'); if (this._loginCw) this._loginCw.load(); }
            } catch (e) { toast('网络错误，请重试', 'error'); }
            finally { this.loginLoading = false; }
        },
        async submitRegister() {
            this.regErrors = {};
            if (this.reg.username.length < 3 || this.reg.username.length > 20) { this.regErrors.username = '用户名长度为 3-20 个字符'; return; }
            if (this.reg.password.length < 6) { this.regErrors.password = '密码长度至少 6 个字符'; return; }
            if (this.reg.password !== this.reg.password_confirm) { this.regErrors.password_confirm = '两次密码不一致'; return; }
            this.regLoading = true;
            try {
                var body = Object.assign({}, this.reg);
                if (this.regCaptchaId) { body.captcha_id = this.regCaptchaId; body.captcha_answer = this.regCaptchaAnswer; }
                var data = await App.post('/register', body, { silent: true });
                if (data.success) { toast('注册成功，正在跳转...', 'success'); setTimeout(function(){ location.reload(); }, 600); }
                else { toast(data.message || '注册失败', 'error'); if (this._regCw) this._regCw.load(); }
            } catch (e) { toast('网络错误，请重试', 'error'); }
            finally { this.regLoading = false; }
        },
        async submitForgot() {
            if (!this.forgot.email) { toast('请输入邮箱地址', 'error'); return; }
            this.forgotLoading = true;
            try {
                var data = await App.post('/forgot-password/send-code', { email: this.forgot.email }, { silent: true });
                if (data.success) { toast(data.message, 'success'); setTimeout(() => { this.tab = 'reset'; }, 1200); }
                else { toast(data.message || '发送失败', 'error'); }
            } catch (e) { toast('网络错误，请重试', 'error'); }
            finally { this.forgotLoading = false; }
        },
        async submitReset() {
            if (!this.reset.code || this.reset.code.length !== 6) { toast('请输入6位验证码', 'error'); return; }
            if (this.reset.password.length < 6) { toast('密码长度至少 6 个字符', 'error'); return; }
            if (this.reset.password !== this.reset.password_confirm) { toast('两次密码不一致', 'error'); return; }
            this.resetLoading = true;
            try {
                var data = await App.post('/forgot-password/reset', this.reset, { silent: true });
                if (data.success) { toast(data.message, 'success'); setTimeout(() => { this.tab = 'login'; }, 1500); }
                else { toast(data.message || '重置失败', 'error'); }
            } catch (e) { toast('网络错误，请重试', 'error'); }
            finally { this.resetLoading = false; }
        }
    }
}
</script>
