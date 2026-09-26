<?php
/**
 * VIP 报价片段
 *
 * 选择等级 / 时长后由服务端算出需要多少积分，htmx 用它替换 #vipQuote。
 * 价格表只在 VipSvc 里有一份，页面不用再往 JS 里塞一份（原来的 vipApp().prices 就是那么干的）。
 *
 * 依赖变量：$level、$months、$cost
 */
$level = (int)($level ?? 0);
$months = max(1, (int)($months ?? 1));
$cost = (int)($cost ?? 0);
?>
<div id="vipQuote" style="font-size:14px;">
    <?php if ($level > 0 && $cost > 0): ?>
    需要 <strong style="color:var(--warning);font-size:18px;"><?= number_format($cost) ?></strong> 积分
    <span style="color:var(--text-muted);">（<?= $months ?> 个月）</span>
    <?php else: ?>
    <span style="color:var(--text-muted);">请先选择等级</span>
    <?php endif; ?>
</div>
