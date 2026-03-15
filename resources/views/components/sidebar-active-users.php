<?php
/**
 * 侧边栏 - 活跃用户组件
 * 按最近发帖/回复活跃度排序
 */
$_activeUsers = \Core\Cache::get('sidebar:active_users', function() {
    return \Core\Database::fetchAll("
        SELECT u.id, u.username, u.nickname, u.avatar, u.credits, u.nickname_color,
            (u.thread_count + u.post_count) as activity
        FROM users u
        WHERE u.deleted_at IS NULL AND u.login_at > ?
        ORDER BY u.login_at DESC
        LIMIT 12
    ", [time() - 86400 * 7]);
}, 120);
?>
<div class="card">
    <div class="section-title">活跃用户</div>
    <?php if (!empty($_activeUsers)): ?>
    <div class="active-users-grid">
        <?php foreach ($_activeUsers as $_au): ?>
        <a href="/user/<?= (int)$_au['id'] ?>" class="active-user-item" title="<?= htmlspecialchars(($_au['nickname'] ?? '') ?: ($_au['username'] ?? '')) ?>">
            <img src="<?= htmlspecialchars($_au['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm">
            <span class="active-user-name"<?= \App\Services\UserSvc::nicknameStyle($_au) ?>><?= htmlspecialchars(mb_substr(($_au['nickname'] ?? '') ?: ($_au['username'] ?? ''), 0, 4)) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div style="text-align:center;padding:16px 0;font-size:13px;color:var(--text-muted);">暂无活跃用户</div>
    <?php endif; ?>
</div>
