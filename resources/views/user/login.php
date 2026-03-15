<?php $pageTitle = '登录'; $pageCss = ['auth']; $_captchaRequired = $captchaRequired ?? false; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="auth-page">
    <div class="auth-card" x-data="loginForm()">
        <div class="auth-header">
            <h1>登录</h1>
            <p>使用用户名或邮箱登录</p>
        </div>

        <form @submit.prevent="submit">
            <div class="form-group">
                <label class="form-label">用户名 / 邮箱</label>
                <input type="text" x-model="form.username" placeholder="输入用户名或邮箱" class="form-input" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">密码</label>
                <div class="input-pwd-wrap">
                    <input :type="showPwd ? 'text' : 'password'" x-model="form.password" placeholder="输入密码" class="form-input" required>
                    <button type="button" class="pwd-eye-btn" @click="showPwd = !showPwd" tabindex="-1">
                        <svg x-show="!showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg x-show="showPwd" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                <input type="checkbox" id="remember_me" x-model="form.remember_me" style="width:auto;margin:0;">
                <label for="remember_me" class="form-label" style="margin:0;font-weight:normal;cursor:pointer;">记住我（30天内免登录）</label>
            </div>

            <?php if ($_captchaRequired): ?>
            <div class="captcha-widget" x-ref="captchaContainer" x-init="$nextTick(() => { _cw = new CaptchaWidget($refs.captchaContainer, { scene: 'login', onVerified: (ok, id, ans) => { captchaId = id; captchaAnswer = ans; } }); })"></div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px;" :disabled="loading">
                <span x-show="!loading">登录</span>
                <span x-show="loading">登录中...</span>
            </button>
        </form>

        <div class="auth-links">
            还没有账号？<a href="/register">立即注册</a>
        </div>
    </div>
</div>

<script>
function loginForm() {
    return {
        form: { username: '', password: '', remember_me: false },
        loading: false, showPwd: false,
        captchaId: '', captchaAnswer: '', _cw: null,
        async submit() {
            if (!this.form.username || !this.form.password) { toast('请填写用户名和密码', 'error'); return; }
            this.loading = true;
            try {
                const body = { username: this.form.username, password: this.form.password };
                if (this.form.remember_me) body.remember_me = '1';
                if (this.captchaId) { body.captcha_id = this.captchaId; body.captcha_answer = this.captchaAnswer; }
                const data = await App.post('/login', body, { silent: true });
                if (data.success) { toast('登录成功，正在跳转...', 'success'); setTimeout(() => window.location.href = '/', 800); }
                else { toast(data.message || '登录失败', 'error'); if (this._cw) this._cw.load(); }
            } catch (e) { toast('登录失败，请重试', 'error'); }
            finally { this.loading = false; }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
