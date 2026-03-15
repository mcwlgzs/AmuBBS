<?php
$pageTitle = '搜索' . ($keyword ? ' - ' . $keyword : '');
$pageDescription = $keyword ? '搜索"' . mb_substr($keyword, 0, 50) . '"的结果' : '搜索帖子和评论';
$pageCss = ['thread', 'index'];
include APP_PATH . 'resources/views/layout/header.php';
?>

<!-- 搜索框 -->
<div class="card" style="margin-bottom:16px;">
    <form action="/search" method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="搜索帖子标题或内容..." class="form-input" style="flex:1;min-width:200px;">
        <select name="type" class="form-select" style="width:auto;">
            <option value="thread" <?= $type === 'thread' ? 'selected' : '' ?>>搜索帖子</option>
            <option value="post" <?= $type === 'post' ? 'selected' : '' ?>>搜索评论</option>
        </select>
        <button type="submit" class="btn btn-primary">搜索</button>
    </form>
</div>

<!-- 搜索结果 -->
<?php if ($keyword !== ''): ?>
<div class="card">
    <div class="section-title" style="font-size:15px;">
        搜索"<?= htmlspecialchars($keyword) ?>"，找到 <?= number_format($total) ?> 条结果
    </div>

    <?php if (empty($results)): ?>
        <?php $emptyIcon = 'search'; $emptyText = '没有找到相关内容，换个关键词试试？'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
    <?php elseif ($type === 'thread'): ?>
        <?php foreach ($results as $t): ?>
        <div class="search-result-item">
            <a href="/thread/<?= (int)$t['id'] ?>" class="search-result-title">
                <?php if ($t['is_top'] ?? false): ?><span class="tag tag-top">置顶</span> <?php endif; ?>
                <?php if ($t['is_highlight'] ?? false): ?><span class="tag tag-highlight">精华</span> <?php endif; ?>
                <?= \App\Controllers\Search::highlight($t['title'], $keyword) ?>
            </a>
            <div class="search-result-meta">
                <span<?= \App\Services\UserSvc::nicknameStyle($t) ?>><?= htmlspecialchars(($t['nickname'] ?? '') ?: ($t['username'] ?? '')) ?></span> · <?= htmlspecialchars($t['forum_name'] ?? '') ?> · <?= date('Y-m-d H:i', $t['created_at']) ?> · 浏览 <?= number_format($t['views']) ?> · 评论 <?= number_format($t['reply_count'] ?? 0) ?>
            </div>
            <div class="search-result-excerpt"><?= \App\Controllers\Search::highlight(mb_substr(strip_tags($t['content']), 0, 120) . '...', $keyword) ?></div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <?php foreach ($results as $p): ?>
        <div class="search-result-item">
            <a href="/thread/<?= (int)$p['thread_id'] ?>" class="search-result-title">评论于：<?= \App\Controllers\Search::highlight($p['thread_title'] ?? '帖子', $keyword) ?></a>
            <div class="search-result-meta"><span<?= \App\Services\UserSvc::nicknameStyle($p) ?>><?= htmlspecialchars(($p['nickname'] ?? '') ?: ($p['username'] ?? '')) ?></span> · <?= date('Y-m-d H:i', $p['created_at']) ?></div>
            <div class="search-result-excerpt"><?= \App\Controllers\Search::highlight(mb_substr(strip_tags($p['content']), 0, 120) . '...', $keyword) ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <?php
        $paginationUrl = '/search?q=' . urlencode($keyword) . '&type=' . $type . '&page={page}';
        $paginationPage = $page;
        $paginationTotal = $totalPages;
        include APP_PATH . 'resources/views/layout/pagination.php';
    ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
