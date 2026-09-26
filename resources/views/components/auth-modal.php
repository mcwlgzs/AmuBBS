<?php
/**
 * 全局登录 / 注册弹窗
 * 仅未登录时渲染。
 *
 * 迁移说明（原来是 Alpine 组件，内含登录/注册/找回密码/重置密码四套表单）：
 *   - 现在只保留一个空壳，表单由 htmx 从 /login?modal=1 或 /register?modal=1 取回，
 *     独立页面与弹窗共用同一份标记，不再各维护一套。
 *   - 找回密码独立成页面 /forgot-password（以前只能在弹窗里用，没有 URL）。
 *   - 触发方式：任意元素加 data-auth-open="login|register"，
 *     或 JS 调 window.openAuth('login') / window.closeAuth()。
 *   - 移动端由 CSS（@media max-width:767px 隐藏 .auth-overlay）决定走独立页面，
 *     前端会先探测弹窗是否真的可见，不可见就不拦截点击、直接跳 /login。
 */
if (isset($_SESSION['user_id'])) {
    return;
}
?>
<div id="authModal" class="auth-overlay" hidden style="z-index:1200;">
    <div class="auth-modal" role="dialog" aria-modal="true" aria-label="登录或注册">
        <button type="button" class="auth-modal-close" data-auth-close aria-label="关闭">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <div id="authModalBody">
            <!-- htmx 把 /login?modal=1 或 /register?modal=1 的内容换到这里 -->
        </div>
    </div>
</div>
