<?php
/**
 * 侧边栏 - 签到排行组件
 * 显示今日签到排行和连续签到天数
 */
// 签到排行 + 今日签到人数（合并为一次缓存，减少 2 次裸查询）
$_checkinData = \Core\Cache::get('sidebar:checkin_rank', function() {
    $rank = [];
    $count = 0;
    try {
        $rank = \Core\Database::fetchAll("
            SELECT uc.user_id, uc.consecutive_days, uc.credits, uc.created_at, u.username, u.nickname, u.avatar, u.nickname_color
            FROM user_checkins uc
            INNER JOIN users u ON uc.user_id = u.id
            WHERE uc.checkin_date = CURDATE()
            ORDER BY uc.created_at ASC
            LIMIT 10
        ");
        $_r = \Core\Database::fetchOne("SELECT COUNT(*) as c FROM user_checkins WHERE checkin_date = CURDATE()");
        $count = (int)($_r['c'] ?? 0);
    } catch (\Throwable $e) {
        error_log('[sidebar] checkin rank: ' . $e->getMessage());
    }
    return ['rank' => $rank, 'count' => $count];
}, 120);
$_checkinRank = $_checkinData['rank'] ?? [];
$_todayCheckinCount = $_checkinData['count'] ?? 0;
?>
<div class="card">
    <div class="section-title">
        <span><svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:#10b981;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>签到排行</span>
        <span style="font-size:12px;font-weight:400;color:var(--text-muted);">今日 <?= $_todayCheckinCount ?> 人</span>
    </div>
    <?php if (!empty($_checkinRank)): ?>
        <?php foreach ($_checkinRank as $_ci => $_ck): ?>
        <div class="checkin-rank-item">
            <span class="checkin-rank-num <?= $_ci < 3 ? 'top3' : '' ?>"><?= $_ci + 1 ?></span>
            <img src="<?= htmlspecialchars($_ck['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-xs" loading="lazy">
            <a href="/user/<?= (int)$_ck['user_id'] ?>" class="checkin-rank-name"<?= \App\Services\UserSvc::nicknameStyle($_ck) ?>><?= htmlspecialchars(($_ck['nickname'] ?? '') ?: ($_ck['username'] ?? '')) ?></a>
            <span class="checkin-rank-days">连续<?= (int)$_ck['consecutive_days'] ?>天</span>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:16px 0;font-size:13px;color:var(--text-muted);">今日还没有人签到，快来抢第一吧</div>
    <?php endif; ?>
</div>
