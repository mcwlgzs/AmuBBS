<?php
/**
 * 侧边栏 - 在线用户（增强版：显示用户当前位置）
 * 优先使用控制器预加载的数据，避免重复查询
 */
$_onlineUsers = $onlineUsers ?? [];
$_onlineGuestCount = 0;
$_onlineUserCount = 0;
if (isset($onlineSummary)) {
    $_onlineUserCount = $onlineSummary['members'] ?? 0;
    $_onlineGuestCount = $onlineSummary['guests'] ?? 0;
} else {
    // 回退：其他页面未预加载时才查询
    try {
        $summary = \App\Services\OnlineSvc::getSummary();
        $_onlineUserCount = $summary['members'] ?? 0;
        $_onlineGuestCount = $summary['guests'] ?? 0;
        if (empty($_onlineUsers)) {
            $_onlineUsers = \App\Services\OnlineSvc::getOnlineUsers(10);
        }
    } catch (\Throwable $e) {
        error_log('[sidebar] online users: ' . $e->getMessage());
    }
}

// 板块在线人数（板块页面使用）
$_forumOnlineCount = 0;
if (!empty($forum['id'])) {
    try {
        $_forumOnlineCount = \App\Services\OnlineSvc::getForumOnlineCount((int)$forum['id']);
    } catch (\Throwable $e) {
        error_log('[sidebar] forum online: ' . $e->getMessage());
    }
}

if ($_onlineUserCount > 0 || $_onlineGuestCount > 0):
?>
<div class="card">
    <div class="section-title">
        <span><svg style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;color:#3b82f6;" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zm5 2a2 2 0 11-4 0 2 2 0 014 0zm-4 7a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zm10 10v-1a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 17v1h-3zM4.75 14.094A5.973 5.973 0 004 17v1H1v-1a3 3 0 013.75-2.906z"/></svg>在线用户</span>
        <span style="font-size:12px;color:var(--text-muted);font-weight:normal;">
            <?= $_onlineUserCount ?> 会员 / <?= $_onlineGuestCount ?> 游客
            <?php if ($_forumOnlineCount > 0): ?>
                · 本版 <?= $_forumOnlineCount ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!empty($_onlineUsers)): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;padding:0 4px;">
        <?php foreach ($_onlineUsers as $_ou):
            $_ouUrl = $_ou['current_url'] ?? '';
            $_ouLocation = '浏览中';
            if (preg_match('#^/forum/\d+#', $_ouUrl)) $_ouLocation = '浏览板块';
            elseif (preg_match('#^/thread/\d+#', $_ouUrl)) $_ouLocation = '看帖中';
            elseif ($_ouUrl === '/') $_ouLocation = '首页';
            elseif (str_starts_with($_ouUrl, '/admin')) $_ouLocation = '后台';
        ?>
        <a href="/user/<?= (int)$_ou['user_id'] ?>" title="<?= htmlspecialchars($_ou['username'] ?? '') ?> - <?= $_ouLocation ?>" style="display:inline-block;">
            <img src="<?= htmlspecialchars($_ou['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="<?= htmlspecialchars($_ou['username'] ?? '') ?>" style="width:28px;height:28px;border-radius:50%;border:2px solid var(--success);" loading="lazy">
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:var(--text-muted);">暂无在线会员</div>
    <?php endif; ?>
</div>
<?php endif; ?>
