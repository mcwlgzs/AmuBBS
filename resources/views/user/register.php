<?php
$pageTitle = '注册';
$pageCss = ['auth'];
$_verifyEnabled = $verifyEnabled ?? false;
$_captchaRequired = $captchaRequired ?? false;
include APP_PATH . 'resources/views/layout/header.php';
?>

<div class="auth-page">
    <div class="auth-card" x-data="registerForm()">
        <div class="auth-header">
            <h1>注册</h1>
            <p>创建你的账号，开始交流</p>
        </div>

        <form @submit.prevent="submit">
            <div class="form-group">
                <label class="form-label">用户名</label>
                <input type="text" x-model="form.username" placeholder="3-20个字符" class="form-input" required>
                <div class="msg-error" x-show="errors.username" x-text="errors.username"></div>
            </div>
            <div class="form-group">
                <label class="form-label">昵称 <span style="color:var(--text-muted);font-weight:normal;">(可选)</span></label>
                <input type="text" x-model="form.nickname" placeholder="2-20个字符，留空则显示用户名" class="form-input" maxlength="20">
            </div>
            <div class="form-group">
                <label class="form-label">邮箱</label>
                <input type="email" x-model="form.email" placeholder="your@email.com" class="form-input" required>
                <div class="msg-error" x-show="errors.email" x-text="errors.email"></div>
            </div>
            <?php if ($_verifyEnabled): ?>
            <div class="form-group">
                <label class="form-label">邮箱验证码</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" x-model="form.email_code" placeholder="6位验证码" class="form-input" style="flex:1;" maxlength="6" required>
                    <button type="button" class="btn btn-ghost" style="white-space:nowrap;min-width:110px;" @click="sendCode" :disabled="codeCd > 0 || sendingCode">
                        <span x-show="codeCd > 0" x-text="codeCd + 's 后重发'"></span>
                        <span x-show="codeCd <= 0 && !sendingCode">获取验证码</span>
                        <span x-show="sendingCode">发送中...</span>
                    </button>
                </div>
                <div class="msg-error" x-show="errors.email_code" x-text="errors.email_code"></div>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">密码</label>
                <div class="input-pwd-wrap">
                    <input :type="showPwd ? 'text' : 'password'" x-model="form.password" placeholder="至少6个字符" class="form-input" required>
                    <button type="button" class="pwd-eye-btn" @click="showPwd = !showPwd" tabindex="-1">
                        <svg x-show="!showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div class="msg-error" x-show="errors.password" x-text="errors.password"></div>
            </div>
            <div class="form-group">
                <label class="form-label">确认密码</label>
                <div class="input-pwd-wrap">
                    <input :type="showPwd2 ? 'text' : 'password'" x-model="form.password_confirm" placeholder="再次输入密码" class="form-input" required>
                    <button type="button" class="pwd-eye-btn" @click="showPwd2 = !showPwd2" tabindex="-1">
                        <svg x-show="!showPwd2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="showPwd2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div class="msg-error" x-show="errors.password_confirm" x-text="errors.password_confirm"></div>
            </div>

            <?php if ($_captchaRequired): ?>
            <div class="captcha-widget" x-ref="captchaContainer" x-init="$nextTick(() => { _cw = new CaptchaWidget($refs.captchaContainer, { scene: 'register', onVerified: (ok, id, ans) => { captchaId = id; captchaAnswer = ans; } }); })"></div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;" :disabled="loading">
                <span x-show="!loading">注册</span>
                <span x-show="loading">注册中...</span>
            </button>
        </form>

        <div class="auth-links">
            已有账号？<a href="/login">立即登录</a>
        </div>
    </div>
</div>

<script>
function registerForm() {
    return {
        form: { username: '', nickname: '', email: '', password: '', password_confirm: '', email_code: '' },
        errors: {}, loading: false, showPwd: false, showPwd2: false,
        codeCd: 0, sendingCode: false, verifyEnabled: <?= $_verifyEnabled ? 'true' : 'false' ?>,
        captchaId: '', captchaAnswer: '', _cw: null,
        async sendCode() {
            this.errors = {};
            if (!this.form.email || !/\S+@\S+\.\S+/.test(this.form.email)) {
                this.errors.email = '请输入有效的邮箱地址'; return;
            }
            this.sendingCode = true;
            try {
                const d = await App.post('/register/send-code', { email: this.form.email }, { silent: true });
                if (d.success) {
                    toast(d.message, 'success');
                    this.codeCd = 60;
                    const t = setInterval(() => { this.codeCd--; if (this.codeCd <= 0) clearInterval(t); }, 1000);
                } else { toast(d.message || '发送失败', 'error'); }
            } catch (e) { toast('发送失败，请重试', 'error'); }
            finally { this.sendingCode = false; }
        },
        async submit() {
            this.errors = {};
            if (this.form.username.length < 3 || this.form.username.length > 20) { this.errors.username = '用户名长度为 3-20 个字符'; return; }
            if (this.form.password.length < 6) { this.errors.password = '密码长度至少 6 个字符'; return; }
            if (this.form.password !== this.form.password_confirm) { this.errors.password_confirm = '两次密码不一致'; return; }
            if (this.verifyEnabled && !this.form.email_code) { this.errors.email_code = '请输入验证码'; return; }
            this.loading = true;
            try {
                const body = Object.assign({}, this.form);
                if (this.captchaId) { body.captcha_id = this.captchaId; body.captcha_answer = this.captchaAnswer; }
                const data = await App.post('/register', body, { silent: true });
                if (data.success) { toast(data.message + '，正在跳转...', 'success'); setTimeout(() => window.location.href = '/', 800); }
                else { toast(data.message || '注册失败', 'error'); if (this._cw) this._cw.load(); }
            } catch (e) { toast('注册失败，请重试', 'error'); }
            finally { this.loading = false; }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
