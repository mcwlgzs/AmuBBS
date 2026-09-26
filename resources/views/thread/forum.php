<?php
$pageTitle = !empty($forum['seo_title']) ? htmlspecialchars($forum['seo_title']) : htmlspecialchars($forum['name'] ?? '板块');
$pageCss = ['thread', 'index'];
$pageKeywords = !empty($forum['seo_keywords']) ? htmlspecialchars($forum['seo_keywords']) : '';
include APP_PATH . 'resources/views/layout/header.php';
?>

<!-- 面包屑 -->
<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span><?= htmlspecialchars($forum['name'] ?? '') ?></span>
</div>

<!-- 板块信息 -->
<?php
    $modIds = [];
    $_modList = [];
    $_isAdmin = false;
    if (($forum['id'] ?? 0) > 0) {
        $modIds = !empty($forum['moderators']) ? array_filter(explode(',', $forum['moderators'])) : [];
        if (!empty($modIds)) {
            $_modRows = \Core\Database::fetchAllCached("SELECT id, username, nickname, avatar FROM users WHERE id IN (" . implode(',', array_map('intval', $modIds)) . ")", [], 300);
            foreach ($_modRows as $m) {
                $_modList[] = ['id' => (int)$m['id'], 'name' => $m['username'], 'avatar' => $m['avatar'] ?: '/assets/images/default-avatar.png'];
            }
        }
        $_isAdmin = isset($_SESSION['group_id']) && (int)$_SESSION['group_id'] >= 3;
    }
?>
<div>
<div class="card forum-header">
    <div class="forum-header-top">
        <div class="forum-header-icon"><?= htmlspecialchars(mb_substr($forum['name'] ?? '', 0, 1)) ?></div>
        <div class="forum-header-info">
            <h1><?= htmlspecialchars($forum['name'] ?? '') ?></h1>
            <?php if (!empty($forum['description'])): ?>
            <p class="forum-description"><?= htmlspecialchars($forum['description']) ?></p>
            <?php endif; ?>
        </div>
        <?php if (($forum['id'] ?? 0) > 0 && isset($_SESSION['user_id'])): ?>
        <a href="/thread/create?forum_id=<?= (int)$forum['id'] ?>" class="btn btn-primary btn-sm forum-header-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M12 5v14M5 12h14"/></svg>
            发新帖
        </a>
        <?php endif; ?>
    </div>
    <?php if (($forum['id'] ?? 0) > 0): ?>
    <div class="forum-stats-bar">
        <div class="forum-stat-item">
            <span class="forum-stat-value"><?= number_format($forum['thread_count'] ?? 0) ?></span>
            <span class="forum-stat-label">主题</span>
        </div>
        <div class="forum-stat-item">
            <span class="forum-stat-value"><?= number_format($forum['post_count'] ?? 0) ?></span>
            <span class="forum-stat-label">评论</span>
        </div>
        <div class="forum-stat-item forum-stat-mods">
            <span class="mod-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
            <div style="display:flex;flex-direction:column;gap:2px;">
                <span class="forum-stat-label">版主<?php if ($_isAdmin): ?> <button type="button" data-toggle-target="#forumModPanel" title="管理版主" style="cursor:pointer;opacity:.7;font-size:10px;background:none;border:none;padding:0;color:inherit;">✎</button><?php endif; ?></span>
                <?php if (!empty($_modList)): ?>
                <div style="display:flex;align-items:center;">
                    <?php foreach ($_modList as $i => $m): ?>
                    <a href="/user/<?= (int)$m['id'] ?>" style="display:inline-flex;text-decoration:none;margin-left:<?= $i > 0 ? '-8px' : '0' ?>;position:relative;z-index:<?= count($_modList) - $i ?>;" title="<?= htmlspecialchars($m['name']) ?>">
                        <img src="<?= htmlspecialchars($m['avatar']) ?>" alt="" style="width:24px;height:24px;border-radius:50%;border:2px solid rgba(255,255,255,.5);background:#fff;" loading="lazy">
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <span class="forum-stat-value">暂无</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php if ($_isAdmin): ?>
<!-- 版主管理弹窗（显隐由 [data-toggle-target] 打开、[data-overlay-close] 点遮罩关闭） -->
<div id="forumModPanel" hidden data-overlay-close style="position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.4);">
    <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--bg-card,#fff);border-radius:12px;padding:20px;width:90%;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.18);z-index:1000;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div style="font-size:15px;font-weight:700;color:var(--text);">管理版主</div>
            <button type="button" data-toggle-target="#forumModPanel" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);line-height:1;">&times;</button>
        </div>
        <?php $modList = $_modList; $forumId = (int)$forum['id']; include APP_PATH . 'resources/views/thread/_mod_list.php'; ?>
        <form hx-post="/forum/moderators" hx-target="#forumModList" hx-swap="outerHTML"
              hx-indicator="this" style="display:flex;gap:6px;">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="forum_id" value="<?= (int)$forum['id'] ?>">
            <input type="text" name="username" placeholder="用户名或ID" required style="flex:1;padding:7px 10px;font-size:13px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card,#fff);color:var(--text);">
            <button type="submit" style="padding:7px 14px;font-size:13px;background:var(--primary);color:#fff;border:none;border-radius:6px;cursor:pointer;white-space:nowrap;">添加</button>
        </form>
    </div>
</div>
<?php endif; ?>
</div>

<!-- 板块公告 -->
<?php if (!empty($forum['announcement'])): ?>
<div class="card" style="padding:12px 16px;background:var(--bg-secondary);border-left:3px solid var(--primary);">
    <div style="font-size:13px;color:var(--text-secondary);">
        <strong style="color:var(--primary);">公告</strong>
        <?= nl2br(htmlspecialchars($forum['announcement'])) ?>
    </div>
</div>
<?php endif; ?>

<?php $_sort = $sort ?? ''; $_orderBy = $orderBy ?? 'lastpost'; $_filter = $filter ?? ''; ?>
<?php if (($forum['id'] ?? 0) === 0): ?>
<div class="card" style="padding:10px 16px;">
    <div style="display:flex;gap:4px;">
        <a href="/forum/all" class="btn btn-sm <?= $_sort === 'latest' || $_sort === '' ? 'btn-primary' : 'btn-ghost' ?>">最新</a>
        <a href="/forum/all?sort=hot" class="btn btn-sm <?= $_sort === 'hot' ? 'btn-primary' : 'btn-ghost' ?>">最热</a>
        <a href="/forum/all?sort=highlight" class="btn btn-sm <?= $_sort === 'highlight' ? 'btn-primary' : 'btn-ghost' ?>">精华</a>
    </div>
</div>
<?php else: ?>
<div class="card" style="padding:10px 16px;">
    <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=lastpost<?= $_filter ? '&filter=' . htmlspecialchars($_filter) : '' ?>" class="btn btn-sm <?= $_orderBy === 'lastpost' ? 'btn-primary' : 'btn-ghost' ?>">最后评论</a>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=tid<?= $_filter ? '&filter=' . htmlspecialchars($_filter) : '' ?>" class="btn btn-sm <?= $_orderBy === 'tid' ? 'btn-primary' : 'btn-ghost' ?>">最新发帖</a>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=replies<?= $_filter ? '&filter=' . htmlspecialchars($_filter) : '' ?>" class="btn btn-sm <?= $_orderBy === 'replies' ? 'btn-primary' : 'btn-ghost' ?>">最多评论</a>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=views<?= $_filter ? '&filter=' . htmlspecialchars($_filter) : '' ?>" class="btn btn-sm <?= $_orderBy === 'views' ? 'btn-primary' : 'btn-ghost' ?>">最多浏览</a>
        <span style="border-left:1px solid var(--border);height:16px;margin:0 4px;"></span>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=<?= htmlspecialchars($_orderBy) ?>" class="btn btn-sm <?= $_filter === '' ? 'btn-primary' : 'btn-ghost' ?>">全部</a>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=<?= htmlspecialchars($_orderBy) ?>&filter=highlight" class="btn btn-sm <?= $_filter === 'highlight' ? 'btn-primary' : 'btn-ghost' ?>">精华</a>
        <a href="/forum/<?= (int)$forum['id'] ?>?orderby=<?= htmlspecialchars($_orderBy) ?>&filter=top" class="btn btn-sm <?= $_filter === 'top' ? 'btn-primary' : 'btn-ghost' ?>">置顶</a>
    </div>
</div>
<?php endif; ?>

<?php $_isMod = isset($_SESSION['group_id']) && (int)$_SESSION['group_id'] >= 2; ?>

<div class="home-layout">
    <div class="home-main">
        <!-- 帖子列表 -->
        <div class="card">
            <?php if ($_isMod && !empty($threads)): ?>
            <!-- 批量操作工具栏 -->
            <!-- 批量操作工具栏（版主）：勾选后才显示，计数/全选交给 app.js 的 [data-check-toolbar] -->
            <div data-check-toolbar="[data-thread-check]" hidden
                 style="padding:10px 16px;background:var(--bg-secondary);border-bottom:1px solid var(--border);align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:13px;color:var(--text-secondary);">已选 <strong data-check-count>0</strong> 项</span>
                <button type="button" class="btn btn-sm btn-primary" hx-post="/mod/batch" hx-vals='{"action":"top"}' hx-include="[data-thread-check]:checked" hx-swap="none">置顶</button>
                <button type="button" class="btn btn-sm btn-ghost" hx-post="/mod/batch" hx-vals='{"action":"lock"}' hx-include="[data-thread-check]:checked" hx-swap="none">锁定</button>
                <button type="button" class="btn btn-sm btn-ghost" hx-post="/mod/batch" hx-vals='{"action":"unlock"}' hx-include="[data-thread-check]:checked" hx-swap="none">解锁</button>
                <select id="modMoveTarget" name="target_forum_id" style="font-size:12px;padding:4px 8px;border:1px solid var(--border);border-radius:var(--radius);">
                    <option value="">移动到...</option>
                    <?php
                    $allForums = \Core\Database::fetchAllCached("SELECT id, name FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC", [], 600);
                    foreach ($allForums as $af): ?>
                    <option value="<?= (int)$af['id'] ?>"><?= htmlspecialchars($af['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-ghost" hx-post="/mod/batch" hx-vals='{"action":"move"}' hx-include="[data-thread-check]:checked, #modMoveTarget" hx-swap="none">确认移动</button>
                <button type="button" class="btn btn-sm" style="color:var(--danger);" hx-post="/mod/batch" hx-vals='{"action":"delete"}' hx-include="[data-thread-check]:checked" hx-swap="none" hx-confirm="确定批量删除？">删除</button>
                <label style="margin-left:auto;font-size:12px;cursor:pointer;color:var(--text-muted);">
                    <input type="checkbox" data-check-all style="margin-right:4px;">全选
                </label>
            </div>
            <?php endif; ?>

            <?php if (empty($threads)): ?>
                <?php $emptyIcon = 'post'; $emptyText = '暂无帖子，快来发布第一个帖子吧'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
            <?php else: ?>
                <?php foreach ($threads as $thread): ?>
                <div class="thread-list-item <?= ($thread['is_top'] ?? false) ? 'is-top' : '' ?>">
                    <?php if ($_isMod): ?>
                    <input type="checkbox" name="tids[]" value="<?= (int)$thread['id'] ?>" data-thread-check style="margin-right:8px;cursor:pointer;">
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
                    <div class="thread-list-body">
                        <a href="/thread/<?= (int)$thread['id'] ?>" class="thread-list-title" data-tid="<?= (int)$thread['id'] ?>" data-lpt="<?= (int)($thread['last_post_time'] ?? $thread['created_at']) ?>">
                            <?= htmlspecialchars($thread['title']) ?>
                            <?php $badgeThread = $thread; include APP_PATH . 'resources/views/components/thread-badges.php'; ?>
                            <?php if ($thread['is_locked'] ?? false): ?><span class="tag tag-locked" title="已锁定"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span><?php endif; ?>
                        </a>
                        <div class="thread-list-info">
                            <a href="/user/<?= (int)$thread['user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($thread) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($thread)) ?></a>
                            <span class="timeago" datetime="<?= date('c', $thread['created_at']) ?>"><?= date('Y-m-d H:i', $thread['created_at']) ?></span>
                            <?php if (!empty($thread['tags'])): ?>
                                <?php foreach ($thread['tags'] as $t): ?>
                                <a href="/tag/<?= urlencode($t['name']) ?>" class="tag tag-forum"><?= htmlspecialchars($t['name']) ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="thread-list-counts">
                        <div class="count-item">
                            <span class="count-value"><?= number_format($thread['reply_count'] ?? $thread['reply_count_calc'] ?? 0) ?></span>
                            <span class="count-label">评论</span>
                            <?php
                                $_rc = (int)($thread['reply_count'] ?? $thread['reply_count_calc'] ?? 0);
                                $_tp = (int)ceil(max(1, $_rc) / 20);
                                if ($_tp > 1):
                            ?>
                            <span class="thread-pages">
                                <?php for ($pi = 1; $pi <= min(3, $_tp); $pi++): ?>
                                    <a href="/thread/<?= (int)$thread['id'] ?>?page=<?= $pi ?>"><?= $pi ?></a>
                                <?php endfor; ?>
                                <?php if ($_tp > 3): ?>
                                    <span>...</span>
                                    <a href="/thread/<?= (int)$thread['id'] ?>?page=<?= $_tp ?>"><?= $_tp ?></a>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="count-item hide-mobile">
                            <span class="count-value"><?= number_format($thread['views'] ?? 0) ?></span>
                            <span class="count-label">浏览</span>
                        </div>
                    </div>
                    <div class="thread-list-last">
                        <?php if ($thread['last_post_username'] ?? null): ?>
                            <span><?= htmlspecialchars($thread['last_post_nickname'] ?: $thread['last_post_username']) ?></span>
                            <span class="timeago" datetime="<?= $thread['last_post_time'] ? date('c', $thread['last_post_time']) : '' ?>"><?= $thread['last_post_time'] ? date('m-d H:i', $thread['last_post_time']) : '' ?></span>
                        <?php else: ?>
                            <span class="text-muted">暂无评论</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
            <?php
                if (($forum['id'] ?? 0) === 0) {
                    $paginationUrl = '/forum/all?sort=' . urlencode($_sort) . '&page={page}';
                } else {
                    $paginationUrl = '/forum/' . (int)$forum['id'] . '?orderby=' . urlencode($_orderBy) . ($_filter ? '&filter=' . urlencode($_filter) : '') . '&page={page}';
                }
                $paginationPage = $page;
                $paginationTotal = $totalPages;
                include APP_PATH . 'resources/views/layout/pagination.php';
            ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="home-sidebar">
        <?php include APP_PATH . 'resources/views/components/sidebar-checkin-rank.php'; ?>
        <?php include APP_PATH . 'resources/views/components/sidebar-credit-rank.php'; ?>
        <?php include APP_PATH . 'resources/views/components/sidebar-active-users.php'; ?>
    </div>
</div>



<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
