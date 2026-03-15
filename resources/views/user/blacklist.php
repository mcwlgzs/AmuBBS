<?php $pageTitle = '我的黑名单'; $pageCss = ['auth', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/profile">个人中心</a> <span class="breadcrumb-sep">/</span>
    <span>黑名单</span>
</div>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px;">
        <h3 style="margin:0;font-size:16px;">黑名单 (<?= (int)$total ?>)</h3>
    </div>

    <?php if (empty($list)): ?>
        <div class="empty-state" style="padding:48px 16px;text-align:center;">
            <p style="color:var(--text-muted);">黑名单为空</p>
        </div>
    <?php else: ?>
        <?php foreach ($list as $item): ?>
        <div class="thread-flow-item" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;" id="bl-<?= (int)$item['id'] ?>">
            <div style="display:flex;align-items:center;gap:12px;">
                <a href="/user/<?= (int)$item['id'] ?>">
                    <img src="<?= htmlspecialchars($item['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm">
                </a>
                <div>
                    <a href="/user/<?= (int)$item['id'] ?>" style="font-weight:500;"<?= \App\Services\UserSvc::nicknameStyle($item) ?>><?= htmlspecialchars(($item['nickname'] ?? '') ?: $item['username']) ?></a>
                    <?php if (!empty($item['signature'])): ?>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars(mb_substr($item['signature'] ?? '', 0, 30)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:12px;color:var(--text-muted);"><?= date('Y-m-d', $item['blocked_at']) ?></span>
                <button class="btn btn-ghost btn-sm" onclick="unblock(<?= (int)$item['id'] ?>, this)">取消拉黑</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <?php
        $paginationUrl = '/user/blacklist?page={page}';
        $paginationPage = $page;
        $paginationTotal = $totalPages;
        include APP_PATH . 'resources/views/layout/pagination.php';
    ?>
    <?php endif; ?>
</div>

<script>
async function unblock(userId, btn) {
    if (!confirm('确定取消拉黑该用户？')) return;
    btn.disabled = true;
    btn.textContent = '处理中...';
    try {
        const data = await App.post('/user/blacklist', {user_id: userId}, {silent: true});
        if (data.success && !data.blocked) {
            const row = document.getElementById('bl-' + userId);
            if (row) row.style.display = 'none';
            toast('已取消拉黑', 'success');
        } else {
            toast(data.message || '操作失败', 'error');
            btn.disabled = false;
            btn.textContent = '取消拉黑';
        }
    } catch (e) {
        toast('网络错误', 'error');
        btn.disabled = false;
        btn.textContent = '取消拉黑';
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
