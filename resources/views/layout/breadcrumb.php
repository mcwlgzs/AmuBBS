<?php
/**
 * 通用面包屑组件
 * 变量: $breadcrumbs = [['label' => '首页', 'url' => '/'], ['label' => '板块', 'url' => '/forum/1'], ...]
 * 最后一项不带 url 表示当前页
 */
if (empty($breadcrumbs)) return;
?>
<div class="breadcrumb">
    <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <?php if ($i > 0): ?><span class="breadcrumb-sep">/</span><?php endif; ?>
        <?php if (!empty($crumb['url']) && $i < count($breadcrumbs) - 1): ?>
            <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
        <?php else: ?>
            <span><?= htmlspecialchars($crumb['label']) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
