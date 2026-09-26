<?php
/**
 * 每日签到卡片（片段）
 *
 * 整页和 htmx 两条路径共用：
 *   - 首页：Index 控制器传入 $checkedIn / $checkinCredits / $checkinDays
 *   - 签到成功：Checkin 控制器直接渲染它，htmx 用它替换整个 #checkinCard
 *
 * 状态由服务端决定（原来靠前端变量切两个 template），
 * 所以也不需要任何防闪烁处理（服务端一次渲染到位）。
 *
 * 依赖变量：$checkedIn、$checkinCredits、$checkinDays
 */
$checkedIn = (bool)($checkedIn ?? false);
$checkinCredits = (int)($checkinCredits ?? 0);
$checkinDays = (int)($checkinDays ?? 0);
?>
<div class="card checkin-card" id="checkinCard">
    <?php if ($checkedIn): ?>
    <div class="checkin-inner checkin-done">
        <div class="checkin-icon">✅</div>
        <div class="checkin-text">
            <div class="checkin-label">今日已签到</div>
            <?php if ($checkinCredits > 0): ?>
            <div class="checkin-hint">+<?= $checkinCredits ?> 积分，连续 <?= $checkinDays ?> 天</div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="checkin-inner">
        <div class="checkin-icon">📅</div>
        <div class="checkin-text">
            <div class="checkin-label">每日签到</div>
            <div class="checkin-hint">签到领取积分奖励</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm"
                hx-post="/checkin" hx-target="#checkinCard" hx-swap="outerHTML" hx-disabled-elt="this">
            <span class="hx-idle">签到</span>
            <span class="hx-busy">...</span>
        </button>
    </div>
    <?php endif; ?>
</div>
