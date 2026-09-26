<?php
$pageTitle = htmlspecialchars($thread['title']);
$pageCss = ['thread'];
$_captchaReplyRequired = \App\Services\CaptchaSvc::isRequired('reply');
include APP_PATH . 'resources/views/layout/header.php';
?>

<!-- 面包屑 -->
<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/forum/<?= (int)$thread['forum_id'] ?>"><?= htmlspecialchars($thread['forum_name']) ?></a> <span class="breadcrumb-sep">/</span>
    <span><?= htmlspecialchars(mb_substr($thread['title'], 0, 30)) ?><?= mb_strlen($thread['title']) > 30 ? '...' : '' ?></span>
</div>

<!-- 帖子主体 -->
<div class="card">
    <div class="thread-detail-header">
        <h1>
            <?= htmlspecialchars($thread['title']) ?>
            <?php $badgeThread = $thread; $badgeVerboseLevel = true; include APP_PATH . 'resources/views/components/thread-badges.php'; ?>
            <?php if ($thread['is_locked'] ?? false): ?><span class="tag tag-locked" title="已锁定"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> 已锁定</span><?php endif; ?>
        </h1>
        <div class="thread-detail-meta">
            <a href="/user/<?= (int)$thread['user_id'] ?>" class="author-link"<?= \App\Services\UserSvc::nicknameStyle($thread) ?>>
                <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-xs">
                <span class="author-name"><?= htmlspecialchars(\App\Services\UserSvc::displayName($thread)) ?></span>
            </a>
            <span class="meta-tag meta-group"><?= htmlspecialchars($thread['group_name'] ?? '普通用户') ?></span>
            <?= \App\Services\LevelSvc::getLevelBadge((int)($thread['credits'] ?? 0)) ?>
            <div class="meta-stats">
                <span class="meta-stat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span class="timeago" datetime="<?= date('c', $thread['created_at']) ?>"><?= date('Y-m-d H:i', $thread['created_at']) ?></span>
                </span>
                <span class="meta-stat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <?= number_format($thread['views'] ?? 0) ?>
                </span>
                <?php if (($thread['updated_at'] ?? 0) > $thread['created_at'] + 60): ?>
                <span class="meta-stat meta-edited" onclick="viewEditLog(<?= (int)$thread['id'] ?>, 0)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    已编辑
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="thread-content markdown-body"><?= \App\Services\ContentHideSvc::parse(\Core\Markdown::render($thread['content_fmt'] ?? null, $thread['content']), $thread['id'], $_SESSION['user_id'] ?? null) ?></div>

    <!-- 标签 -->
    <?php if (!empty($tags)): ?>
    <div class="thread-tags">
        <?php foreach ($tags as $tag): ?>
        <a href="/tag/<?= urlencode($tag['name']) ?>" class="tag"><?= htmlspecialchars($tag['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 操作栏 -->
    <!-- 操作栏：每个动作各自走 htmx（点赞/收藏回按钮片段，其余改状态后整页刷新） -->
    <div class="thread-actions">
        <?php $threadId = (int)$thread['id']; ?>
        <?php if (isset($_SESSION['user_id'])): ?>
        <?php $likes = (int)($thread['likes'] ?? 0); $isLiked = !empty($liked); include APP_PATH . 'resources/views/thread/_like_btn.php'; ?>
        <?php $isFavorited = !empty($favorited); include APP_PATH . 'resources/views/thread/_favorite_btn.php'; ?>
        <?php if ($_SESSION['user_id'] != $thread['user_id']): ?>
        <button type="button" class="btn btn-ghost btn-sm" data-toggle-target="#rewardModal" style="gap:4px;color:var(--warning);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            打赏
        </button>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $thread['user_id'] || ($_SESSION['group_id'] ?? 1) >= 2)): ?>
            <a href="/thread/edit/<?= $threadId ?>" class="btn btn-ghost btn-sm">编辑</a>
            <button type="button" class="btn btn-ghost btn-sm text-danger"
                    hx-post="/thread/delete" hx-vals='{"thread_id":<?= $threadId ?>}' hx-swap="none"
                    hx-confirm="确定要删除这个帖子吗？">删除</button>
        <?php endif; ?>
        <?php if (isset($_SESSION['user_id']) && ($_SESSION['group_id'] ?? 1) >= 2): ?>
            <button type="button" class="btn btn-ghost btn-sm"
                    hx-post="/thread/toggle-top" hx-vals='{"thread_id":<?= $threadId ?>}' hx-swap="none"><?= ($thread['is_top'] ?? false) ? '取消置顶' : '置顶' ?></button>
            <div class="dropdown" data-dropdown style="display:inline-block;">
                <button type="button" class="btn btn-ghost btn-sm" data-dropdown-toggle><?= (($thread['is_highlight'] ?? 0) > 0) ? '精华 ▾' : '设精华 ▾' ?></button>
                <div class="dropdown-menu" data-dropdown-menu hidden style="left:0;right:auto;padding:4px 0;min-width:120px;">
                    <?php if (($thread['is_highlight'] ?? 0) > 0): ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" hx-post="/thread/toggle-highlight" hx-vals='{"thread_id":<?= $threadId ?>,"level":0}' hx-swap="none">取消精华</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" hx-post="/thread/toggle-highlight" hx-vals='{"thread_id":<?= $threadId ?>,"level":1}' hx-swap="none">精华 I</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" hx-post="/thread/toggle-highlight" hx-vals='{"thread_id":<?= $threadId ?>,"level":2}' hx-swap="none">精华 II</button>
                    <button type="button" class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" hx-post="/thread/toggle-highlight" hx-vals='{"thread_id":<?= $threadId ?>,"level":3}' hx-swap="none">精华 III</button>
                </div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm"
                    hx-post="/thread/toggle-lock" hx-vals='{"thread_id":<?= $threadId ?>}' hx-swap="none"><?= ($thread['is_locked'] ?? false) ? '解锁' : '锁定' ?></button>
            <button type="button" class="btn btn-ghost btn-sm" data-toggle-target="#moveModal">移动</button>
        <?php endif; ?>
    </div>

    <!-- 打赏信息 -->
    <?php if ($rewardStats['count'] > 0): ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-light);">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:18px;height:18px;color:var(--warning);"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
            <span style="font-size:14px;color:var(--text-secondary);">已获得 <strong style="color:var(--warning);"><?= number_format($rewardStats['total']) ?></strong> 积分打赏（<?= (int)$rewardStats['count'] ?> 人）</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($rewardList as $reward): ?>
            <div style="display:flex;align-items:center;gap:6px;padding:4px 10px;background:var(--bg-secondary);border-radius:var(--radius);font-size:13px;">
                <img src="<?= htmlspecialchars($reward['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:20px;height:20px;border-radius:50%;" loading="lazy">
                <span<?= \App\Services\UserSvc::nicknameStyle($reward) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($reward)) ?></span>
                <span style="color:var(--warning);font-weight:600;">+<?= number_format($reward['amount']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 打赏弹窗 -->
<?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $thread['user_id']): ?>
<div id="rewardModal" hidden data-overlay-close style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
    <div class="card" style="width:90%;max-width:420px;margin:0;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;">打赏作者</h3>
            <button type="button" data-toggle-target="#rewardModal" style="background:none;border:none;font-size:24px;color:var(--text-muted);cursor:pointer;padding:0;line-height:1;">&times;</button>
        </div>
        <div style="text-align:center;margin-bottom:20px;">
            <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:60px;height:60px;border-radius:50%;margin-bottom:8px;" loading="lazy">
            <div style="font-size:15px;font-weight:600;"<?= \App\Services\UserSvc::nicknameStyle($thread) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($thread)) ?></div>
        </div>
        <form hx-post="/thread/reward" hx-swap="none" hx-indicator="this" hx-disabled-elt="#rewardSubmit">
            <input type="hidden" name="target_type" value="thread">
            <input type="hidden" name="target_id" value="<?= (int)$thread['id'] ?>">
            <div class="form-group">
                <label class="form-label" for="rewardAmount">打赏金额（积分）</label>
                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <button type="button" class="btn btn-ghost btn-sm" data-set-value="#rewardAmount" data-value="10">10</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-set-value="#rewardAmount" data-value="50">50</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-set-value="#rewardAmount" data-value="100">100</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-set-value="#rewardAmount" data-value="500">500</button>
                </div>
                <input type="number" id="rewardAmount" name="amount" class="form-input" value="10" placeholder="或输入自定义金额" min="1" max="10000" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="rewardMessage">留言（可选）</label>
                <input type="text" id="rewardMessage" name="message" class="form-input" placeholder="说点什么..." maxlength="100">
            </div>
            <button type="submit" id="rewardSubmit" class="btn btn-primary" style="width:100%;">
                <span class="hx-idle">确认打赏</span>
                <span class="hx-busy">处理中...</span>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 移动帖子弹窗（[data-toggle-target] 打开、[data-overlay-close] 点遮罩关闭） -->
<?php if (isset($_SESSION['user_id']) && (($_SESSION['group_id'] ?? 1) >= 2)): ?>
<?php $allForums = (new \App\Services\ForumSvc())->getAllForums(); ?>
<div id="moveModal" hidden data-overlay-close style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
    <div class="card" style="width:90%;max-width:420px;margin:0;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;">移动帖子</h3>
            <button type="button" data-toggle-target="#moveModal" style="background:none;border:none;font-size:24px;color:var(--text-muted);cursor:pointer;padding:0;line-height:1;">&times;</button>
        </div>
        <form hx-post="/thread/move" hx-swap="none" hx-indicator="this" hx-disabled-elt="#moveSubmit">
            <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
            <div class="form-group">
                <label class="form-label" for="moveTargetForum">选择目标板块</label>
                <select id="moveTargetForum" name="target_forum_id" class="form-input" required>
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($allForums as $af): ?>
                    <?php if ((int)$af['id'] !== (int)$thread['forum_id']): ?>
                    <option value="<?= (int)$af['id'] ?>"><?= $af['parent_id'] > 0 ? '└ ' : '' ?><?= htmlspecialchars($af['name']) ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" id="moveSubmit" class="btn btn-primary" style="width:100%;">
                <span class="hx-idle">确认移动</span>
                <span class="hx-busy">处理中...</span>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 附件列表 -->
<?php $attachments = \App\Services\AttachmentSvc::getByThread($thread['id']); ?>
<?php if (!empty($attachments)): ?>
<div class="card">
    <div class="post-list-header">附件 (<?= count($attachments) ?>)</div>
    <div style="padding:12px 16px;">
    <?php foreach ($attachments as $att): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border-light);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <a href="/attachment/<?= (int)$att['id'] ?>" style="flex:1;color:var(--primary);"><?= htmlspecialchars($att['filename']) ?></a>
            <span style="font-size:12px;color:var(--text-muted);"><?= \App\Services\AttachmentSvc::formatSize((int)$att['filesize']) ?></span>
            <span style="font-size:12px;color:var(--text-muted);">下载 <?= (int)$att['downloads'] ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 回复列表 -->
<?php $_postOrder = in_array(($postOrder ?? 'asc'), ['asc', 'desc']) ? $postOrder : 'asc'; $_authorOnly = (int)($authorOnly ?? 0); $_baseUrl = '/thread/' . (int)$thread['id']; $_perPage = 20; ?>
<div class="card">
    <div class="post-list-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span>评论 (<?= (int)($total ?? 0) ?>)</span>
        <div style="display:flex;gap:4px;font-size:13px;">
            <a href="<?= $_baseUrl ?>?order=asc<?= $_authorOnly ? '&author_only='.$_authorOnly : '' ?>" class="btn btn-sm <?= $_postOrder === 'asc' ? 'btn-primary' : 'btn-ghost' ?>">正序</a>
            <a href="<?= $_baseUrl ?>?order=desc<?= $_authorOnly ? '&author_only='.$_authorOnly : '' ?>" class="btn btn-sm <?= $_postOrder === 'desc' ? 'btn-primary' : 'btn-ghost' ?>">倒序</a>
            <?php if ($_authorOnly > 0): ?>
            <a href="<?= $_baseUrl ?>?order=<?= $_postOrder ?>" class="btn btn-sm btn-ghost" style="color:var(--danger);">取消只看楼主</a>
            <?php else: ?>
            <a href="<?= $_baseUrl ?>?order=<?= $_postOrder ?>&author_only=<?= (int)$thread['user_id'] ?>" class="btn btn-sm btn-ghost">只看楼主</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($posts)): ?>
        <?php
            if ($thread['is_locked'] ?? false) {
                $emptyIcon = 'lock'; $emptyText = '帖子已锁定，无法评论';
            } elseif (empty($_SESSION['user_id'])) {
                $emptyIcon = 'reply'; $emptyText = '';
                $emptyExtra = '<div style="margin-top:12px;"><a href="/login" class="btn btn-primary btn-sm" data-auth-open="login">登录</a><a href="/register" class="btn btn-ghost btn-sm" style="margin-left:8px;" data-auth-open="register">注册</a><p style="color:var(--text-secondary);margin-top:8px;font-size:13px;">登录后才能参与讨论</p></div>';
            } else {
                $emptyIcon = 'reply'; $emptyText = '暂无评论，快来抢沙发吧';
            }
            include APP_PATH . 'resources/views/components/empty-state.php';
        ?>
    <?php else: ?>
        <?php
        /* ---- 单层嵌套分组（GitHub Discussions 风格：回复的回复归入同一组，永不出现第三层）---- */
        $_byId = [];
        foreach ($posts as $_p) { $_byId[(int)$_p['id']] = $_p; }

        $_childRoot = [];   // 子回复 postId => 顶层 postId
        foreach ($posts as $_p) {
            $_pid = (int)$_p['id'];
            $_q   = (int)($_p['quote_post_id'] ?? 0);
            if ($_q > 0 && $_q !== $_pid && isset($_byId[$_q])) {
                $_root = $_childRoot[$_q] ?? $_q;           // 引用的是子回复 → 归一到同一顶层
                if ($_root !== $_pid) { $_childRoot[$_pid] = $_root; }
            }
        }

        $_children = [];
        foreach ($_childRoot as $_cid => $_root) { $_children[$_root][] = $_cid; }

        $_floorOf = [];
        foreach ($posts as $_i => $_p) { $_floorOf[(int)$_p['id']] = ($page - 1) * $_perPage + $_i + 1; }

        $_renderPost = function (array $_row, bool $_child) use ($thread, $_floorOf): void {
            $post    = $_row;
            $floor   = $_floorOf[(int)$_row['id']] ?? 0;
            $isChild = $_child;
            require APP_PATH . 'resources/views/thread/_post.php';
        };
        ?>
        <?php foreach ($posts as $_index => $_p): ?>
        <?php if (isset($_childRoot[(int)$_p['id']])) { continue; } ?>
        <?php $_renderPost($_p, false); ?>
        <?php if (!empty($_children[(int)$_p['id']])): ?>
        <div class="post-replies" id="postReplies-<?= (int)$_p['id'] ?>">
            <?php foreach ($_children[(int)$_p['id']] as $_cid): ?>
            <?php $_renderPost($_byId[$_cid], true); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <?php
        $paginationUrl = '/thread/' . (int)$thread['id'] . '?page={page}' . ($_postOrder !== 'asc' ? '&order=' . urlencode($_postOrder) : '') . ($_authorOnly ? '&author_only=' . (int)$_authorOnly : '');
        $paginationPage = $page;
        $paginationTotal = $totalPages;
        include APP_PATH . 'resources/views/layout/pagination.php';
    ?>
    <?php endif; ?>
</div>

<!-- 回复表单 -->
<?php if ($thread['is_locked'] ?? false): ?>
    <?php if (!empty($posts)): ?>
    <div class="card text-center" style="padding:36px;">
        <p style="color:var(--text-muted);"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px;margin-right:4px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>帖子已锁定，无法评论</p>
    </div>
    <?php endif; ?>
<?php elseif (isset($_SESSION['user_id'])): ?>
<?php
    $emojiEnabled = \App\Services\EmojiService::isEnabled();
?>
<div class="card reply-form">
    <h3>发表评论</h3>
    <?php /* 引用状态由 [data-reply-*] 那一套通用机制维护，失败时表单原样保留（422 不换目标） */ ?>
    <form id="threadReplyForm" hx-post="/thread/reply" hx-swap="none" data-draft-key="thread-reply-<?= (int)$thread['id'] ?>">
        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
        <input type="hidden" name="quote_post_id" value="" data-reply-input>
        <div data-reply-indicator hidden style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;margin-bottom:8px;background:var(--bg-secondary);border-left:3px solid var(--primary);border-radius:var(--radius);font-size:13px;color:var(--text-secondary);">
            <span>引用 <strong data-reply-name></strong> #<span data-reply-floor></span> 的评论</span>
            <button type="button" data-reply-cancel style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;line-height:1;">&times;</button>
        </div>
        <textarea name="content" class="form-input" rows="4" placeholder="输入评论内容" style="resize:vertical;" required minlength="2"></textarea>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
            <?php if ($emojiEnabled): ?>
            <button type="button" class="btn btn-ghost btn-sm" data-emoji-picker='#threadReplyForm [name="content"]' title="表情" style="padding:4px 8px;font-size:18px;line-height:1;">😀</button>
            <?php endif; ?>
            <?php if ($_captchaReplyRequired): ?>
            <input type="hidden" name="captcha_id" value="">
            <input type="hidden" name="captcha_answer" value="">
            <div class="captcha-widget" data-captcha-scene="reply"></div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" style="margin-left:auto;">
                <span class="hx-idle">发表评论</span><span class="hx-busy">提交中...</span>
            </button>
        </div>
    </form>
</div>
<?php else: ?>
<?php if (!empty($posts)): ?>
<div class="card text-center" style="padding:24px;">
    <p style="color:var(--text-secondary);margin-bottom:12px;">登录后才能参与讨论</p>
    <a href="/login" class="btn btn-primary btn-sm" data-auth-open="login">登录</a>
    <a href="/register" class="btn btn-ghost btn-sm" style="margin-left:8px;" data-auth-open="register">注册</a>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
<?php if ($emojiEnabled ?? false): ?>
window.__AMUBBS_EMOJI = window.__AMUBBS_EMOJI || <?= json_encode(\App\Services\EmojiService::getEmojiMap(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
<?php endif; ?>
function escHtml(s) {
    const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

function viewEditLog(threadId, postId) {
    fetch('/thread/' + threadId + '/edit-log?post_id=' + (postId || 0))
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.logs.length) { alert('暂无编辑记录'); return; }
            let html = '<div style="max-height:400px;overflow-y:auto;">';
            d.logs.forEach(l => {
                html += '<div style="padding:8px 0;border-bottom:1px solid var(--border-light);font-size:13px;">';
                html += '<strong>' + escHtml(l.username) + '</strong> 编辑于 ' + escHtml(l.created_at);
                if (l.reason) html += ' — ' + escHtml(l.reason);
                html += '</div>';
            });
            html += '</div>';
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:1000;';
            modal.onclick = e => { if (e.target === modal) modal.remove(); };
            modal.innerHTML = '<div style="background:var(--bg);border-radius:var(--radius);padding:20px;width:90%;max-width:480px;"><div style="display:flex;justify-content:space-between;margin-bottom:12px;"><h3 style="margin:0;">编辑历史</h3><button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">&times;</button></div>' + html + '</div>';
            document.body.appendChild(modal);
        })
        .catch(() => alert('加载失败'));
}

function buyHiddenContent(threadId, price) {
    if (!confirm('确定支付 ' + price + ' 积分解锁此内容？')) return;
    App.postReload('/thread/buy-content', { thread_id: threadId, price: price });
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
