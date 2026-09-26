<?php $pageTitle = '通知'; $pageCss = ['auth', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>通知</span>
</div>

<?php
/**
 * 通知中心
 *
 * 卡片内容在 notification/_card.php，标记已读 / 删除等动作走 htmx：
 * 按钮带 hx-post，服务端只回刷新后的卡片内容替换 #notification-card 的 innerHTML，
 * 不再整页 reload（页面视图里因此没有任何 Alpine 指令）。
 */
?>
<div class="card" id="notification-card">
<?php include __DIR__ . '/notification/_card.php'; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
