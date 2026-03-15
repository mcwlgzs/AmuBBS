<?php $pageTitle = '选择板块'; $pageCss = ['thread']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>选择板块发帖</span>
</div>

<div class="card">
    <div class="section-title">请选择要发帖的板块</div>
    <?php
    $parents = [];
    $children = [];
    foreach ($forums as $f) {
        if ($f['parent_id'] == 0) { $parents[] = $f; }
        else { $children[$f['parent_id']][] = $f; }
    }
    ?>
    <?php foreach ($parents as $p): ?>
    <div class="forum-select-group">
        <h3><?= htmlspecialchars($p['name']) ?></h3>
        <?php if (!empty($children[$p['id']])): ?>
        <div class="forum-select-list">
            <?php foreach ($children[$p['id']] as $c): ?>
            <a href="/thread/create?forum_id=<?= (int)$c['id'] ?>" class="forum-select-btn"><?= htmlspecialchars($c['name']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="forum-select-list">
            <a href="/thread/create?forum_id=<?= (int)$p['id'] ?>" class="forum-select-btn">在此板块发帖</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (empty($parents)): ?>
        <div class="empty-state"><p>暂无板块，请联系管理员创建</p></div>
    <?php endif; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
