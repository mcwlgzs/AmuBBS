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
<div <?php if ($_isAdmin): ?>x-data="modManager()"<?php endif; ?>>
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
                <span class="forum-stat-label">版主<?php if ($_isAdmin): ?> <span style="cursor:pointer;opacity:.7;font-size:10px;" @click.stop="showPanel = !showPanel" title="管理版主">✎</span><?php endif; ?></span>
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
<!-- 版主管理弹窗 -->
<div x-show="showPanel" x-cloak x-transition.opacity style="position:fixed;inset:0;z-index:999;background:rgba(0,0,0,.4);" @click.self="showPanel=false">
    <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--bg-card,#fff);border-radius:12px;padding:20px;width:90%;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.18);z-index:1000;" @click.stop>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div style="font-size:15px;font-weight:700;color:var(--text);">管理版主</div>
            <button @click="showPanel=false" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);line-height:1;">&times;</button>
        </div>
        <div style="margin-bottom:12px;">
            <template x-for="m in mods" :key="m.id">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;font-size:13px;color:var(--text);border-bottom:1px solid var(--border-light,#f1f5f9);">
                    <a :href="'/user/' + m.id" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text);">
                        <img :src="m.avatar || '/assets/images/default-avatar.png'" alt="" style="width:24px;height:24px;border-radius:50%;" loading="lazy">
                        <span x-text="m.name"></span>
                        <span style="color:var(--text-muted);font-size:11px;">#<span x-text="m.id"></span></span>
                    </a>
                    <button @click="removeMod(m.id)" style="background:none;border:none;cursor:pointer;color:var(--danger,#ef4444);padding:2px 6px;font-size:12px;border-radius:4px;" title="移除">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>
            <div x-show="mods.length === 0" style="font-size:12px;color:var(--text-muted);padding:8px 0;text-align:center;">暂无版主</div>
        </div>
        <div style="display:flex;gap:6px;">
            <input type="text" x-model="newMod" @keydown.enter.prevent="addMod()" placeholder="用户名或ID" style="flex:1;padding:7px 10px;font-size:13px;border:1px solid var(--border);border-radius:6px;background:var(--bg-card,#fff);color:var(--text);">
            <button @click="addMod()" :disabled="adding" style="padding:7px 14px;font-size:13px;background:var(--primary);color:#fff;border:none;border-radius:6px;cursor:pointer;white-space:nowrap;">添加</button>
        </div>
        <div x-show="modMsg" x-text="modMsg" style="font-size:12px;margin-top:6px;" :style="modErr ? 'color:var(--danger,#ef4444)' : 'color:var(--success,#22c55e)'"></div>
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
        <div class="card" <?php if ($_isMod): ?>x-data="batchMod()"<?php endif; ?>>
            <?php if ($_isMod && !empty($threads)): ?>
            <!-- 批量操作工具栏 -->
            <div x-show="selected.length > 0" x-transition style="padding:10px 16px;background:var(--bg-secondary);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:13px;color:var(--text-secondary);">已选 <strong x-text="selected.length"></strong> 项</span>
                <button class="btn btn-sm btn-primary" @click="exec('top')">置顶</button>
                <button class="btn btn-sm btn-ghost" @click="exec('lock')">锁定</button>
                <button class="btn btn-sm btn-ghost" @click="exec('unlock')">解锁</button>
                <select x-model="moveTarget" style="font-size:12px;padding:4px 8px;border:1px solid var(--border);border-radius:var(--radius);">
                    <option value="">移动到...</option>
                    <?php
                    $allForums = \Core\Database::fetchAllCached("SELECT id, name FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC", [], 600);
                    foreach ($allForums as $af): ?>
                    <option value="<?= (int)$af['id'] ?>"><?= htmlspecialchars($af['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-ghost" @click="exec('move')" x-show="moveTarget">确认移动</button>
                <button class="btn btn-sm" style="color:var(--danger);" @click="if(confirm('确定批量删除？'))exec('delete')">删除</button>
                <label style="margin-left:auto;font-size:12px;cursor:pointer;color:var(--text-muted);">
                    <input type="checkbox" @change="toggleAll($event.target.checked)" style="margin-right:4px;">全选
                </label>
            </div>
            <?php endif; ?>

            <?php if (empty($threads)): ?>
                <?php $emptyIcon = 'post'; $emptyText = '暂无帖子，快来发布第一个帖子吧'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
            <?php else: ?>
                <?php foreach ($threads as $thread): ?>
                <div class="thread-list-item <?= ($thread['is_top'] ?? false) ? 'is-top' : '' ?>">
                    <?php if ($_isMod): ?>
                    <input type="checkbox" value="<?= (int)$thread['id'] ?>" @change="toggle(<?= (int)$thread['id'] ?>)" :checked="selected.includes(<?= (int)$thread['id'] ?>)" style="margin-right:8px;cursor:pointer;">
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
                    <div class="thread-list-body">
                        <a href="/thread/<?= (int)$thread['id'] ?>" class="thread-list-title" data-tid="<?= (int)$thread['id'] ?>" data-lpt="<?= (int)($thread['last_post_time'] ?? $thread['created_at']) ?>">
                            <?= htmlspecialchars($thread['title']) ?>
                            <?php if ($thread['is_top'] ?? false): ?><span class="tag tag-top" title="置顶"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span><?php endif; ?>
                            <?php $_hl = (int)($thread['is_highlight'] ?? 0); if ($_hl > 0): ?><span class="tag tag-highlight" title="精华"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span><?php endif; ?>
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

<?php if ($_isAdmin): ?>
<script>
function modManager() {
    return {
        showPanel: false, newMod: '', adding: false, modMsg: '', modErr: false,
        mods: <?= json_encode($_modList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        async addMod() {
            if (!this.newMod.trim() || this.adding) return;
            this.adding = true; this.modMsg = '';
            try {
                const d = await App.postJSON('/forum/moderators', { forum_id: <?= (int)$forum['id'] ?>, action: 'add', username: this.newMod.trim() });
                if (d.success) {
                    this.mods.push(d.data.user);
                    this.newMod = '';
                    this.modMsg = '已添加'; this.modErr = false;
                } else {
                    this.modMsg = d.message || '添加失败'; this.modErr = true;
                }
            } catch(e) { this.modMsg = '网络错误'; this.modErr = true; }
            this.adding = false;
            setTimeout(() => this.modMsg = '', 3000);
        },
        async removeMod(userId) {
            if (!confirm('确定移除该版主？')) return;
            try {
                const d = await App.postJSON('/forum/moderators', { forum_id: <?= (int)$forum['id'] ?>, action: 'remove', user_id: userId });
                if (d.success) {
                    this.mods = this.mods.filter(m => m.id !== userId);
                    this.modMsg = '已移除'; this.modErr = false;
                } else {
                    this.modMsg = d.message || '移除失败'; this.modErr = true;
                }
            } catch(e) { this.modMsg = '网络错误'; this.modErr = true; }
            setTimeout(() => this.modMsg = '', 3000);
        }
    };
}
</script>
<?php endif; ?>

<?php if ($_isMod): ?>
<script>
function batchMod() {
    return {
        selected: [], moveTarget: '',
        toggle(id) {
            const i = this.selected.indexOf(id);
            if (i >= 0) this.selected.splice(i, 1);
            else this.selected.push(id);
        },
        toggleAll(checked) {
            if (checked) {
                this.selected = <?= json_encode(array_map(fn($t) => (int)$t['id'], $threads ?? [])) ?>;
            } else {
                this.selected = [];
            }
            document.querySelectorAll('.thread-list-item input[type=checkbox]').forEach(cb => cb.checked = checked);
        },
        async exec(action) {
            const body = { action, tids: this.selected };
            if (action === 'move') {
                if (!this.moveTarget) return;
                body.target_forum_id = parseInt(this.moveTarget);
            }
            const d = await App.postJSON('/mod/batch', body);
            if (d.success) { setTimeout(() => location.reload(), 800); }
        }
    };
}
</script>
<?php endif; ?>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
