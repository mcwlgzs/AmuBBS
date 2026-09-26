<?php
$pageTitle = 'VIP 会员';
$pageCss = ['index'];
include APP_PATH . 'resources/views/layout/header.php';

/**
 * VIP 会员
 *
 * 迁移说明（原来是 vipApp() 组件 + 一个和卡片重复的 <select>）：
 *   - 等级卡片改成 <label> + 原生 radio：点卡片就是选等级，不需要 JS；
 *     高亮用 CSS 的「radio:checked + 卡片」兄弟选择器（不用 :has()，兼容面更广）
 *   - 原来那个 <select> 和卡片绑的是同一个变量、功能重复，这次去掉了
 *   - 报价「需要 N 积分」由服务端算（GET /vip/quote），页面不再内嵌价格表
 *   - 购买成功：服务端回 HX-Refresh，整页刷新（积分、等级、当前状态都要跟着变）
 */
$initialLevel = 0;   // 默认不预选，和迁移前一致（必须先选等级才能提交）
$initialMonths = 1;
?>
<style>
/* 隐藏原生 radio，但保留在 DOM 里（可聚焦、可用方向键切换） */
.vip-level-radio { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
<?php foreach ($levels as $lv): ?>
<?php if ($lv['level'] < 1) continue; ?>
.vip-card-wrap[data-level="<?= (int)$lv['level'] ?>"] .vip-level-radio:checked + .vip-card {
    border-color: <?= htmlspecialchars($lv['color']) ?>;
    box-shadow: 0 0 0 2px <?= htmlspecialchars($lv['color']) ?>33;
}
<?php endforeach; ?>
.vip-card-wrap { position: relative; }
</style>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>VIP 会员</span>
</div>

<?php if (empty($vipEnabled)): ?>
<div class="card" style="text-align:center;padding:48px 16px;">
    <div style="font-size:48px;margin-bottom:16px;">🚫</div>
    <div style="font-size:18px;font-weight:600;margin-bottom:8px;">VIP 功能暂未开放</div>
    <div style="font-size:14px;color:var(--text-muted);">管理员尚未开启 VIP 会员功能，请稍后再来</div>
    <a href="/" class="btn btn-ghost" style="margin-top:16px;">返回首页</a>
</div>
<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
<?php return; endif; ?>

<!-- 当前状态 -->
<?php if ($userVip && $userVip['level'] > 0): ?>
<div class="card" style="background:linear-gradient(135deg,<?= htmlspecialchars($userVip['color']) ?> 0%,<?= htmlspecialchars($userVip['color']) ?>88 100%);color:#fff;border:none;">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <span style="font-size:40px;"><?= htmlspecialchars($userVip['icon']) ?></span>
        <div>
            <div style="font-size:20px;font-weight:700;"><?= htmlspecialchars($userVip['name']) ?></div>
            <div style="font-size:13px;opacity:.8;margin-top:4px;">到期时间：<?= date('Y-m-d', $userVip['expire_at']) ?></div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:13px;opacity:.8;">当前积分</div>
            <div style="font-size:24px;font-weight:800;"><?= number_format($userCredits) ?></div>
        </div>
    </div>
</div>
<?php elseif (isset($_SESSION['user_id'])): ?>
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div style="font-size:15px;font-weight:600;">你还不是 VIP 会员</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">开通 VIP 享受更多特权</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:13px;color:var(--text-muted);">当前积分</div>
            <div style="font-size:20px;font-weight:700;color:var(--warning);"><?= number_format($userCredits) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['user_id'])): ?>
<form id="vipForm" hx-post="/vip/purchase" hx-swap="none" hx-indicator="this" hx-disabled-elt="#vipBuyBtn">
<?php endif; ?>

<!-- VIP 等级卡片（点卡片 = 选等级） -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(240px,100%),1fr));gap:16px;margin-bottom:20px;">
    <?php foreach ($levels as $lv): ?>
    <?php if ($lv['level'] < 1) continue; ?>
    <div class="vip-card-wrap" data-level="<?= (int)$lv['level'] ?>">
        <?php if (isset($_SESSION['user_id'])): ?>
        <input type="radio" class="vip-level-radio" id="vipLv<?= (int)$lv['level'] ?>" name="level"
               value="<?= (int)$lv['level'] ?>" required>
        <?php endif; ?>
        <label class="card vip-card"<?= isset($_SESSION['user_id']) ? ' for="vipLv' . (int)$lv['level'] . '"' : '' ?>
               style="cursor:<?= isset($_SESSION['user_id']) ? 'pointer' : 'default' ?>;transition:all .2s;position:relative;overflow:hidden;display:block;">
            <div style="text-align:center;padding:8px 0;">
                <div style="font-size:36px;margin-bottom:8px;"><?= htmlspecialchars($lv['icon']) ?></div>
                <div style="font-size:16px;font-weight:700;color:<?= htmlspecialchars($lv['color']) ?>;"><?= htmlspecialchars($lv['name']) ?></div>
                <div style="font-size:24px;font-weight:800;margin:8px 0;color:var(--text);">
                    <?= (int)$lv['price'] ?> <span style="font-size:12px;font-weight:400;color:var(--text-muted);">积分/月</span>
                </div>
            </div>
            <div style="border-top:1px solid var(--border-light);padding-top:12px;margin-top:4px;">
                <?php foreach ($lv['benefits'] as $b): ?>
                <div style="font-size:12px;color:var(--text-secondary);padding:3px 0;display:flex;align-items:center;gap:6px;">
                    <span style="color:<?= htmlspecialchars($lv['color']) ?>;">✓</span> <?= htmlspecialchars($b) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($userVip && $userVip['level'] === $lv['level']): ?>
            <div style="position:absolute;top:8px;right:-24px;background:<?= htmlspecialchars($lv['color']) ?>;color:#fff;font-size:10px;padding:2px 28px;transform:rotate(45deg);font-weight:600;">当前</div>
            <?php endif; ?>
        </label>
    </div>
    <?php endforeach; ?>
</div>

<!-- 购买面板 -->
<?php if (isset($_SESSION['user_id'])): ?>
<div class="card">
    <div class="section-title">开通/续费 VIP</div>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div>
            <label class="form-label" for="vipMonths">购买时长</label>
            <select class="form-select" id="vipMonths" name="months" style="width:120px;">
                <option value="1">1 个月</option>
                <option value="3">3 个月</option>
                <option value="6">6 个月</option>
                <option value="12">12 个月</option>
            </select>
        </div>
        <?php /* 选了等级或时长就向服务端要一次报价（hx-include 把整个表单的字段带上） */ ?>
        <div style="margin-top:20px;"
             hx-get="/vip/quote" hx-trigger="change from:#vipForm" hx-include="#vipForm"
             hx-target="#vipQuote" hx-swap="outerHTML">
            <?php $level = $initialLevel; $months = $initialMonths; $cost = \App\Services\VipSvc::quoteCost($level, $months); ?>
            <?php include APP_PATH . 'resources/views/user/_vip_quote.php'; ?>
        </div>
        <div style="margin-top:20px;margin-left:auto;">
            <button type="submit" id="vipBuyBtn" class="btn btn-primary">
                <span class="hx-idle">立即开通</span>
                <span class="hx-busy">处理中...</span>
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:32px;">
    <p style="color:var(--text-muted);margin-bottom:12px;">登录后即可开通 VIP 会员</p>
    <a href="/login" class="btn btn-primary" data-auth-open="login">立即登录</a>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['user_id'])): ?>
</form>
<?php endif; ?>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
