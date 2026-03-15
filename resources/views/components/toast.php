<?php
/**
 * 全局 Toast 通知组件（Alpine.js）
 * 在 layout 中 include 一次即可
 *
 * 触发方式:
 *   $dispatch('toast', { message: '操作成功', type: 'success' })
 *   type: 'success' | 'error' | 'info'
 */
?>
<div x-data="toastManager()" x-on:toast.window="add($event.detail)"
     style="position:fixed;top:60px;right:16px;z-index:1200;display:flex;flex-direction:column;gap:8px;">
    <template x-for="t in toasts" :key="t.id">
        <div x-show="t.show" x-transition.opacity.duration.300ms
             :class="'toast-item toast-' + t.type"
             x-text="t.message"></div>
    </template>
</div>
<style>
.toast-item {
    padding: 10px 18px; border-radius: var(--radius, 4px); font-size: 13px;
    box-shadow: 0 4px 12px rgba(0,0,0,.1); min-width: 200px; max-width: 360px;
}
.toast-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.toast-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.toast-info { background: rgba(0,102,255,.04); color: #1e40af; border: 1px solid rgba(0,102,255,.15); }
html.dark .toast-success { background: rgba(16,185,129,.15); color: #6ee7b7; border-color: rgba(16,185,129,.3); }
html.dark .toast-error { background: rgba(239,68,68,.15); color: #fca5a5; border-color: rgba(239,68,68,.3); }
html.dark .toast-info { background: rgba(59,130,246,.15); color: #93c5fd; border-color: rgba(59,130,246,.3); }
</style>
<script>
function toastManager() {
    return {
        toasts: [], _id: 0,
        add({ message, type = 'info', duration = 3000 }) {
            const id = ++this._id;
            this.toasts.push({ id, message, type, show: true });
            setTimeout(() => {
                const t = this.toasts.find(x => x.id === id);
                if (t) t.show = false;
                setTimeout(() => { this.toasts = this.toasts.filter(x => x.id !== id); }, 300);
            }, duration);
        }
    }
}
</script>
