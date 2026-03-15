<?php
/**
 * 侧边栏 - 浏览历史组件
 */
$_browseHistory = [];
if (isset($_SESSION['user_id'])) {
    try {
        $_browseHistory = \App\Services\BrowseHistorySvc::getHistory($_SESSION['user_id'], 8);
    } catch (\Throwable $e) {
        error_log('[sidebar] browse history: ' . $e->getMessage());
    }
}
?>
<?php if (isset($_SESSION['user_id'])): ?>
<div class="card">
    <div class="section-title">
        浏览历史
        <?php if (!empty($_browseHistory)): ?>
        <a href="javascript:void(0)" style="font-size:12px;font-weight:400;color:var(--text-muted);" onclick="
            if(confirm('确定清空浏览历史？')) App.postReload('/user/clear-history', {})
        ">清空</a>
        <?php endif; ?>
    </div>
    <?php if (!empty($_browseHistory)): ?>
        <?php foreach ($_browseHistory as $_bh): ?>
        <div style="padding:6px 0;border-bottom:1px solid var(--border-light);">
            <a href="/thread/<?= (int)$_bh['id'] ?>" style="font-size:13px;color:var(--text);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($_bh['title']) ?></a>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                <span<?= \App\Services\UserSvc::nicknameStyle($_bh) ?>><?= htmlspecialchars($_bh['username'] ?? '') ?></span> · <span class="timeago" datetime="<?= date('c', $_bh['viewed_at']) ?>"><?= date('m-d H:i', $_bh['viewed_at']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="text-align:center;padding:16px 0;font-size:13px;color:var(--text-muted);">还没有浏览记录</div>
    <?php endif; ?>
</div>
<?php endif; ?>
