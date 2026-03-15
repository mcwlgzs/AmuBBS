<?php $pageTitle = '我的收藏'; $pageCss = ['thread', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/profile">个人中心</a> <span class="breadcrumb-sep">/</span>
    <span>我的收藏</span>
</div>

<div class="card">
    <div class="section-title">我的收藏 (<?= $total ?? 0 ?>)</div>
    <?php if (empty($favorites)): ?>
        <div class="empty-state"><p>暂无收藏</p></div>
    <?php else: ?>
        <?php foreach ($favorites as $item): ?>
        <div class="thread-flow-item">
            <div class="thread-flow-body">
                <a href="/thread/<?= (int)$item['id'] ?>" class="thread-flow-title"><?= htmlspecialchars($item['title']) ?></a>
                <div class="thread-flow-meta">
                    <a href="/user/<?= (int)$item['user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($item) ?>><?= htmlspecialchars(($item['nickname'] ?? '') ?: ($item['username'] ?? '')) ?></a>
                    <span><?= htmlspecialchars($item['forum_name'] ?? '') ?></span>
                    <span>浏览 <?= number_format($item['views'] ?? 0) ?></span>
                    <span>评论 <?= number_format($item['reply_count'] ?? 0) ?></span>
                    <span>收藏于 <?= date('Y-m-d', $item['favorited_at']) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (($totalPages ?? 1) > 1): ?>
    <?php
        $paginationUrl = '/favorites?page={page}';
        $paginationPage = $page;
        $paginationTotal = $totalPages;
        include APP_PATH . 'resources/views/layout/pagination.php';
    ?>
    <?php endif; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
