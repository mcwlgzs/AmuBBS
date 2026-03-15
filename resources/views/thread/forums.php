<?php $pageTitle = '板块分类'; $pageCss = ['index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>板块分类</span>
</div>

<div class="forums-page">
    <?php if (empty($forums)): ?>
        <?php $emptyIcon = 'post'; $emptyText = '暂无板块'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
    <?php else: ?>
        <?php
        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#f97316'];
        $colorIdx = 0;
        ?>
        <?php foreach ($forums as $forum): ?>
        <?php $color = $colors[$colorIdx % count($colors)]; $colorIdx++; ?>
        <div class="card forum-category-card">
            <a href="/forum/<?= (int)$forum['id'] ?>" class="forum-category-main">
                <div class="forum-category-avatar" style="background:<?= $color ?>15;color:<?= $color ?>;">
                    <?php if (!empty($forum['icon'])): ?>
                        <?= htmlspecialchars($forum['icon']) ?>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php endif; ?>
                </div>
                <div class="forum-category-info">
                    <h3><?= htmlspecialchars($forum['name']) ?></h3>
                    <?php if (!empty($forum['description'])): ?>
                    <p><?= htmlspecialchars($forum['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="forum-category-stat">
                    <strong><?= number_format($forum['thread_count'] ?? 0) ?></strong>
                    <span>帖子</span>
                </div>
            </a>
            <?php if (!empty($forum['children'])): ?>
            <div class="forum-children-grid">
                <?php foreach ($forum['children'] as $child): ?>
                <a href="/forum/<?= (int)$child['id'] ?>" class="forum-child-item">
                    <?php if (!empty($child['icon'])): ?>
                    <span class="forum-child-icon"><?= htmlspecialchars($child['icon']) ?></span>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($child['name']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
