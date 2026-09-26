<?php
/**
 * 通知入口（导航栏铃铛 + 下拉面板）
 *
 * 迁移说明（原来是一个 Alpine 通知面板组件）：
 *   - 显隐交给通用下拉机制（[data-dropdown] / [data-dropdown-toggle] / [data-dropdown-menu]）
 *   - 面板内容由 htmx 从 /notifications/popup 取回，服务端渲染，不再有 x-template 拼 HTML
 *   - 未读角标 id="notifBadge"：标记已读后的片段会带一个 hx-swap-oob 的同名元素，
 *     顺便把角标一起更新（不用再单独发一次请求）
 */
$_unread = (int)($_unreadCount ?? 0);
?>
<div class="dropdown notif-dropdown" data-dropdown>
    <button class="nav-icon" title="通知" type="button" data-dropdown-toggle
            hx-get="/notifications/popup" hx-target="#notifPopup" hx-swap="innerHTML">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="badge" id="notifBadge"<?= $_unread > 0 ? '' : ' style="display:none"' ?>><?= $_unread > 99 ? '99+' : $_unread ?></span>
    </button>
    <div class="notif-popup" id="notifPopup" data-dropdown-menu hidden>
        <div class="notif-popup-empty">加载中...</div>
    </div>
</div>
