<?php
/**
 * 空状态组件
 * 变量:
 *   $emptyIcon   - 图标类型: 'post'(默认), 'reply', 'user', 'search', 'notify'
 *   $emptyText   - 提示文字
 *   $emptyAction - 可选，操作按钮 ['url' => '/xxx', 'label' => '按钮文字']
 */
$_icon = $emptyIcon ?? 'post';
$_text = $emptyText ?? '暂无内容';
$_action = $emptyAction ?? null;
$_extra = $emptyExtra ?? null;

$_icons = [
    'post'   => '<path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>',
    'reply'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'user'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'notify' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
];
$_svg = $_icons[$_icon] ?? $_icons['post'];
?>
<div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><?= $_svg ?></svg>
    <p><?= htmlspecialchars($_text) ?></p>
    <?php if ($_action): ?>
    <a href="<?= htmlspecialchars($_action['url']) ?>" class="btn btn-primary btn-sm" style="margin-top:10px;"><?= htmlspecialchars($_action['label']) ?></a>
    <?php endif; ?>
    <?php if ($_extra): ?>
    <?= $_extra ?>
    <?php endif; ?>
</div>
