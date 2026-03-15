<?php
/**
 * 侧边栏 - 最新动态组件
 */
$_latestMoments = \Core\Cache::getStale('sidebar:moments', function() {
    return \Core\Database::fetchAll("
        SELECT m.id, m.content, m.created_at, u.username, u.nickname, u.avatar, u.nickname_color
        FROM moments m
        LEFT JOIN users u ON m.user_id = u.id
        WHERE m.deleted_at IS NULL
        ORDER BY m.created_at DESC
        LIMIT 5
    ");
}, 300);
?>
<div class="card">
    <div class="section-title">
        最新动态
        <a href="/moments" style="font-size:12px;font-weight:400;">查看全部 →</a>
    </div>
    <?php if (!empty($_latestMoments)): ?>
        <?php foreach ($_latestMoments as $_mm): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border-light);">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                <img src="<?= htmlspecialchars($_mm['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:20px;height:20px;border-radius:50%;">
                <span style="font-size:12px;font-weight:600;"<?= \App\Services\UserSvc::nicknameStyle($_mm) ?>><?= htmlspecialchars(($_mm['nickname'] ?? '') ?: ($_mm['username'] ?? '')) ?></span>
                <span class="timeago" datetime="<?= date('c', $_mm['created_at']) ?>" style="font-size:11px;color:var(--text-muted);margin-left:auto;"><?= date('m-d H:i', $_mm['created_at']) ?></span>
            </div>
            <div style="font-size:13px;color:var(--text-secondary);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;"><?= htmlspecialchars(mb_substr($_mm['content'], 0, 60)) ?></div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:16px 0;font-size:13px;color:var(--text-muted);">还没有人发动态，<a href="/moments" style="color:var(--primary);">去发一条</a></div>
    <?php endif; ?>
</div>
