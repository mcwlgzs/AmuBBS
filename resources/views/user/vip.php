<?php
$pageTitle = 'VIP 会员';
$pageCss = ['index'];
include APP_PATH . 'resources/views/layout/header.php';
?>

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

<!-- VIP 等级卡片 -->
<div x-data="vipApp()" style="margin-bottom:0;">
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(240px,100%),1fr));gap:16px;margin-bottom:20px;">
    <?php foreach ($levels as $lv): ?>
    <?php if ($lv['level'] < 1) continue; ?>
    <div class="card vip-card" style="cursor:pointer;transition:all .2s;position:relative;overflow:hidden;"
         :style="selectedLevel === <?= $lv['level'] ?> ? 'border-color:<?= htmlspecialchars($lv['color']) ?>;box-shadow:0 0 0 2px <?= htmlspecialchars($lv['color']) ?>33' : ''"
         @click="selectedLevel = <?= $lv['level'] ?>">
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
    </div>
    <?php endforeach; ?>
</div>

<!-- 购买面板 -->
<?php if (isset($_SESSION['user_id'])): ?>
<div class="card">
    <div class="section-title">开通/续费 VIP</div>
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div>
            <label class="form-label">选择等级</label>
            <select class="form-select" x-model.number="selectedLevel" style="width:160px;">
                <option value="0">请选择</option>
                <?php foreach ($levels as $lv): ?>
                <?php if ($lv['level'] < 1) continue; ?>
                <option value="<?= (int)$lv['level'] ?>"><?= htmlspecialchars($lv['name']) ?> (<?= (int)$lv['price'] ?>积分/月)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">购买时长</label>
            <select class="form-select" x-model.number="months" style="width:120px;">
                <option value="1">1 个月</option>
                <option value="3">3 个月</option>
                <option value="6">6 个月</option>
                <option value="12">12 个月</option>
            </select>
        </div>
        <div style="margin-top:20px;">
            <template x-if="selectedLevel > 0">
                <div style="font-size:14px;">
                    需要 <strong style="color:var(--warning);font-size:18px;" x-text="prices[selectedLevel] * months"></strong> 积分
                </div>
            </template>
        </div>
        <div style="margin-top:20px;margin-left:auto;">
            <button class="btn btn-primary" :disabled="selectedLevel < 1 || purchasing" @click="
                if (!confirm('确认购买？')) return;
                purchasing = true;
                App.post('/vip/purchase', {level: selectedLevel, months: months}, {silent:true}).then(d => {
                    if (d.success) { toast(d.message, 'success'); setTimeout(() => location.reload(), 800); }
                    else { toast(d.message || '购买失败', 'error'); }
                }).finally(() => purchasing = false);
            ">
                <span x-show="!purchasing">立即开通</span>
                <span x-show="purchasing">处理中...</span>
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:32px;">
    <p style="color:var(--text-muted);margin-bottom:12px;">登录后即可开通 VIP 会员</p>
    <a href="/login" class="btn btn-primary" @click.prevent="$dispatch('auth-show', 'login')">立即登录</a>
</div>
</div><!-- /x-data vipApp -->
<?php endif; ?>

<script>
function vipApp() {
    return {
        selectedLevel: 0,
        months: 1,
        purchasing: false,
        prices: <?= json_encode(array_column($levels, 'price', 'level'), JSON_HEX_TAG) ?>,
    };
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
