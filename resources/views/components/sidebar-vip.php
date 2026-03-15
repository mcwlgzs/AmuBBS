<!-- VIP 推广 -->
<?php if (!\App\Services\VipSvc::isEnabled()) return; ?>
<?php
$_currentUserVip = null;
if (isset($_SESSION['user_id'])) {
    try { $_currentUserVip = \App\Services\VipSvc::getUserVip($_SESSION['user_id']); } catch (\Throwable $e) { error_log('[sidebar] vip: ' . $e->getMessage()); }
}
?>
<div class="card" style="overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0066ff 0%,#8b5cf6 100%);margin:-16px -16px 16px;padding:20px 16px;color:#fff;text-align:center;">
        <div style="margin-bottom:4px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg></div>
        <div style="font-size:15px;font-weight:700;">VIP 会员</div>
        <div style="font-size:12px;opacity:.8;margin-top:2px;">解锁更多特权</div>
    </div>
    <?php if ($_currentUserVip && $_currentUserVip['level'] > 0): ?>
    <div style="text-align:center;">
        <div style="font-size:13px;color:var(--text-secondary);">当前等级</div>
        <div style="font-size:16px;font-weight:700;color:<?= htmlspecialchars($_currentUserVip['color']) ?>;margin:4px 0;">
            <?= htmlspecialchars($_currentUserVip['icon']) ?> <?= htmlspecialchars($_currentUserVip['name']) ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);">到期：<?= date('Y-m-d', $_currentUserVip['expire_at']) ?></div>
        <a href="/vip" class="btn btn-ghost btn-sm" style="margin-top:8px;">续费/升级</a>
    </div>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">
        <div style="padding:2px 0;">✓ 签到积分翻倍</div>
        <div style="padding:2px 0;">✓ 免费查看隐藏内容</div>
        <div style="padding:2px 0;">✓ 专属标识和头像框</div>
    </div>
    <a href="/vip" class="btn btn-primary btn-sm btn-block">立即开通</a>
    <?php endif; ?>
</div>
