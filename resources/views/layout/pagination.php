<?php
/**
 * 通用分页组件
 * 变量: $paginationUrl (带 page= 占位), $paginationPage, $paginationTotal
 */
$_p = $paginationPage ?? 1;
$_t = $paginationTotal ?? 1;
$_url = $paginationUrl ?? '?page=';

if ($_t <= 1) return;

// 计算显示范围
$_range = 2;
$_start = max(1, $_p - $_range);
$_end = min($_t, $_p + $_range);
?>
<div class="pagination-full">
    <?php if ($_p > 1): ?>
        <a href="<?= htmlspecialchars(str_replace('{page}', $_p - 1, $_url)) ?>">‹</a>
    <?php endif; ?>

    <?php if ($_start > 1): ?>
        <a href="<?= htmlspecialchars(str_replace('{page}', 1, $_url)) ?>">1</a>
        <?php if ($_start > 2): ?><span class="page-dots">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $_start; $i <= $_end; $i++): ?>
        <?php if ($i === $_p): ?>
            <span class="page-current"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= htmlspecialchars(str_replace('{page}', $i, $_url)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($_end < $_t): ?>
        <?php if ($_end < $_t - 1): ?><span class="page-dots">…</span><?php endif; ?>
        <a href="<?= htmlspecialchars(str_replace('{page}', $_t, $_url)) ?>"><?= $_t ?></a>
    <?php endif; ?>

    <?php if ($_p < $_t): ?>
        <a href="<?= htmlspecialchars(str_replace('{page}', $_p + 1, $_url)) ?>">›</a>
    <?php endif; ?>
</div>
