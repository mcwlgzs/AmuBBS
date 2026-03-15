<?php $pageTitle = '通知'; $pageCss = ['auth', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>通知</span>
</div>

<div class="card" x-data="notificationPage()">
    <div class="section-title">
        <span>通知中心</span>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <?php if (!empty($notifications)): ?>
                <?php if (($unreadCount ?? 0) > 0): ?>
                <button class="btn btn-ghost btn-sm" @click="markAllRead()" :disabled="loading">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <span>全部已读</span>
                </button>
                <?php endif; ?>
                <button class="btn btn-ghost btn-sm" @click="deleteRead()" :disabled="loading">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span>删除已读</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($notifications)): ?>
        <div class="notification-stats">
            <div class="notification-stat-item">
                <span class="stat-label">全部</span>
                <span class="stat-value"><?= count($notifications) ?></span>
            </div>
            <?php if (($unreadCount ?? 0) > 0): ?>
            <div class="notification-stat-item unread">
                <span class="stat-label">未读</span>
                <span class="stat-value"><?= $unreadCount ?></span>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
        <?php $emptyIcon = 'notify'; $emptyText = '暂无通知'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $n): ?>
            <div class="notification-item <?= !$n['is_read'] ? 'unread' : '' ?>" x-data="{ showActions: false }">
                <div class="notification-icon">
                    <?php if ($n['type'] === 'reply'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php elseif ($n['type'] === 'like'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <?php elseif ($n['type'] === 'mention'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 8A6 6 0 1 1 4 8c0 7-3 9-3 9h22s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    <?php endif; ?>
                </div>
                <div class="notification-body">
                    <div class="notification-title">
                        <?php if ($n['target_type'] === 'thread' && $n['target_id']): ?>
                            <a href="/thread/<?= (int)$n['target_id'] ?>"><?= htmlspecialchars($n['title']) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars($n['title']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($n['content'])): ?>
                    <div class="notification-content"><?= htmlspecialchars(mb_substr($n['content'], 0, 100)) ?></div>
                    <?php endif; ?>
                    <div class="notification-time"><?= date('Y-m-d H:i', $n['created_at']) ?></div>
                </div>
                <div class="notification-actions">
                    <?php if (!$n['is_read']): ?>
                    <button class="notification-action-btn" @click="markRead(<?= $n['id'] ?>)" title="标记已读">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                    <?php endif; ?>
                    <button class="notification-action-btn delete" @click="deleteNotification(<?= $n['id'] ?>)" title="删除">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
    <?php
        $paginationUrl = '/notifications?page={page}';
        $paginationPage = $page;
        $paginationTotal = $totalPages;
        include APP_PATH . 'resources/views/layout/pagination.php';
    ?>
    <?php endif; ?>
</div>

<script>
function notificationPage() {
    return {
        loading: false,
        async markAllRead() {
            if (this.loading) return;
            this.loading = true;
            try {
                const data = await App.post('/notifications/read-all', {}, { silent: true });
                if (data.success) {
                    toast('已全部标记为已读', 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(data.message || '操作失败', 'error');
                }
            } catch (e) {
                toast('操作失败', 'error');
            } finally {
                this.loading = false;
            }
        },
        async deleteRead() {
            if (this.loading) return;
            if (!confirm('确定要删除所有已读通知吗？')) return;
            this.loading = true;
            try {
                const data = await App.post('/notifications/delete-read', {}, { silent: true });
                if (data.success) {
                    toast('已删除所有已读通知', 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(data.message || '操作失败', 'error');
                }
            } catch (e) {
                toast('操作失败', 'error');
            } finally {
                this.loading = false;
            }
        },
        async markRead(id) {
            if (this.loading) return;
            this.loading = true;
            try {
                const data = await App.post('/notifications/read', { id }, { silent: true });
                if (data.success) {
                    location.reload();
                } else {
                    toast(data.message || '操作失败', 'error');
                }
            } catch (e) {
                toast('操作失败', 'error');
            } finally {
                this.loading = false;
            }
        },
        async deleteNotification(id) {
            if (this.loading) return;
            if (!confirm('确定要删除这条通知吗？')) return;
            this.loading = true;
            try {
                const data = await App.post('/notifications/delete', { id }, { silent: true });
                if (data.success) {
                    location.reload();
                } else {
                    toast(data.message || '操作失败', 'error');
                }
            } catch (e) {
                toast('操作失败', 'error');
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
