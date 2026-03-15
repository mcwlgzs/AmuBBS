<?php
/**
 * 侧边栏 - 积分排行组件
 */
// 积分排行（缓存 300 秒，排行榜实时性要求低）
$_creditRank = \Core\Cache::get('sidebar:credit_rank', function() {
    try {
        return \Core\Database::fetchAll("
            SELECT id, username, nickname, avatar, credits, nickname_color
            FROM users
            WHERE deleted_at IS NULL
            ORDER BY credits DESC
            LIMIT 10
        ");
    } catch (\Throwable $e) {
        error_log('[sidebar] credit rank: ' . $e->getMessage());
        return [];
    }
}, 300);
?>
<div class="card">
    <div class="section-title">
        <span><svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:#f59e0b;" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>积分排行</span>
        <a href="/user/credit-ranking" style="font-size:12px;font-weight:400;">查看全部 →</a>
    </div>
    <?php if (!empty($_creditRank)): ?>
        <?php foreach ($_creditRank as $_ri => $_ru): ?>
        <div class="credit-rank-item">
            <span class="credit-rank-num <?= $_ri < 3 ? 'top3' : '' ?>"><?= $_ri + 1 ?></span>
            <img src="<?= htmlspecialchars($_ru['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-xs">
            <a href="/user/<?= (int)$_ru['id'] ?>" class="credit-rank-name"<?= \App\Services\UserSvc::nicknameStyle($_ru) ?>><?= htmlspecialchars(($_ru['nickname'] ?? '') ?: ($_ru['username'] ?? '')) ?></a>
            <span class="credit-rank-value"><?= number_format($_ru['credits']) ?></span>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:16px 0;font-size:13px;color:var(--text-muted);">暂无排行数据</div>
    <?php endif; ?>
</div>
