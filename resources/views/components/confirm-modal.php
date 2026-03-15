<?php
/**
 * 确认对话框组件（Alpine.js）
 * 用法: 在页面任意位置 include 一次，然后通过 $dispatch 触发
 *
 * 触发方式:
 *   $dispatch('confirm-show', { title: '确认删除？', text: '此操作不可撤销', onConfirm: () => { ... } })
 */
?>
<div x-data="confirmModal()" x-on:confirm-show.window="show($event.detail)" x-show="open" x-cloak
     class="modal-overlay" style="z-index:1100;" @click.self="open = false">
    <div class="modal-box" style="max-width:380px;text-align:center;">
        <h3 x-text="title" style="margin-bottom:8px;"></h3>
        <p x-show="text" x-text="text" style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;"></p>
        <div style="display:flex;gap:8px;justify-content:center;">
            <button class="btn btn-ghost" @click="open = false">取消</button>
            <button class="btn btn-danger" @click="confirm()">确定</button>
        </div>
    </div>
</div>
<script>
function confirmModal() {
    return {
        open: false, title: '', text: '', _onConfirm: null,
        show({ title, text, onConfirm }) {
            this.title = title || '确认操作？';
            this.text = text || '';
            this._onConfirm = onConfirm || null;
            this.open = true;
        },
        confirm() {
            this.open = false;
            if (this._onConfirm) this._onConfirm();
        }
    }
}
</script>
