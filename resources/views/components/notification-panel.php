<div class="dropdown notif-dropdown" x-data="notifPanel()" @click.outside="open = false">
    <button class="nav-icon" title="通知" @click="toggle()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($_unreadCount > 0): ?>
        <span class="badge" x-ref="badge"><?= $_unreadCount > 99 ? '99+' : $_unreadCount ?></span>
        <?php else: ?>
        <span class="badge" x-ref="badge" style="display:none"></span>
        <?php endif; ?>
    </button>
    <div class="notif-popup" x-show="open" x-cloak x-transition>
        <div class="notif-popup-header">
            <span>通知</span>
            <button class="notif-readall" @click="readAll()" x-show="items.length > 0">全部已读</button>
        </div>
        <div class="notif-popup-body">
            <template x-if="loading">
                <div class="notif-popup-empty">加载中...</div>
            </template>
            <template x-if="!loading && items.length === 0">
                <div class="notif-popup-empty">暂无新通知</div>
            </template>
            <template x-for="item in items" :key="item.id">
                <a class="notif-popup-item" :href="getLink(item)">
                    <div class="notif-popup-item-title" x-text="item.title"></div>
                    <div class="notif-popup-item-desc" x-text="item.content || ''"></div>
                    <div class="notif-popup-item-time" x-text="timeAgo(item.created_at)"></div>
                </a>
            </template>
        </div>
        <a href="/notifications" class="notif-popup-footer">查看全部通知</a>
    </div>
</div>
