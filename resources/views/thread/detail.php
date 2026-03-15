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
            <?php if ($thread['is_top'] ?? false): ?><span class="tag tag-top" title="置顶"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg></span><?php endif; ?>
            <?php
                $_hl = (int)($thread['is_highlight'] ?? 0);
                if ($_hl > 0): $hlLabels = [1=>'精华', 2=>'精华II', 3=>'精华III'];
            ?><span class="tag tag-highlight" title="<?= $hlLabels[$_hl] ?? '精华' ?>"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span><?php endif; ?>
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
    <div class="thread-actions" x-data="threadActions(<?= (int)$thread['id'] ?>)">
        <?php if (isset($_SESSION['user_id'])): ?>
        <button class="like-btn" :class="{ liked: isLiked }" @click="toggleLike()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            <span x-text="likeCount"><?= (int)($thread['likes'] ?? 0) ?></span>
        </button>
        <button class="btn btn-ghost btn-sm" :class="{ 'text-primary': isFavorited }" @click="toggleFavorite()" style="gap:4px;">
            <svg viewBox="0 0 24 24" :fill="isFavorited ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
            <span x-text="isFavorited ? '已收藏' : '收藏'"></span>
        </button>
        <?php if ($_SESSION['user_id'] != $thread['user_id']): ?>
        <button class="btn btn-ghost btn-sm" @click="$dispatch('open-reward')" style="gap:4px;color:var(--warning);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            打赏
        </button>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $thread['user_id'] || ($_SESSION['group_id'] ?? 1) >= 2)): ?>
            <button class="btn btn-ghost btn-sm" @click="editThread()">编辑</button>
            <button class="btn btn-ghost btn-sm text-danger" @click="deleteThread()">删除</button>
        <?php endif; ?>
        <?php if (isset($_SESSION['user_id']) && ($_SESSION['group_id'] ?? 1) >= 2): ?>
            <button class="btn btn-ghost btn-sm" @click="toggleTop()"><?= ($thread['is_top'] ?? false) ? '取消置顶' : '置顶' ?></button>
            <div style="position:relative;display:inline-block;" x-data="{ showDigest: false }">
                <button class="btn btn-ghost btn-sm" @click="showDigest = !showDigest"><?= (($thread['is_highlight'] ?? 0) > 0) ? '精华 ▾' : '设精华 ▾' ?></button>
                <div x-show="showDigest" @click.outside="showDigest = false" x-cloak style="position:absolute;top:100%;left:0;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:4px 0;z-index:10;min-width:120px;box-shadow:0 2px 8px rgba(0,0,0,.12);">
                    <?php if (($thread['is_highlight'] ?? 0) > 0): ?>
                    <button class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" @click="setDigest(0); showDigest=false">取消精华</button>
                    <?php endif; ?>
                    <button class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" @click="setDigest(1); showDigest=false">精华 I</button>
                    <button class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" @click="setDigest(2); showDigest=false">精华 II</button>
                    <button class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:left;border-radius:0;" @click="setDigest(3); showDigest=false">精华 III</button>
                </div>
            </div>
            <button class="btn btn-ghost btn-sm" @click="toggleLock()"><?= ($thread['is_locked'] ?? false) ? '解锁' : '锁定' ?></button>
            <button class="btn btn-ghost btn-sm" @click="$dispatch('open-move')">移动</button>
        <?php endif; ?>
        <span class="action-msg" x-show="message" x-text="message"></span>
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
<div x-data="rewardModal()" @open-reward.window="showRewardModal = true" x-show="showRewardModal" style="position:fixed;top:0;left:0;right:0;bottom:0;z-index:1000;" x-cloak>
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);" @click.self="showRewardModal = false">
    <div class="card" style="width:90%;max-width:420px;margin:0;" @click.stop>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;">打赏作者</h3>
            <button @click="showRewardModal = false" style="background:none;border:none;font-size:24px;color:var(--text-muted);cursor:pointer;padding:0;line-height:1;">&times;</button>
        </div>
        <div style="text-align:center;margin-bottom:20px;">
            <img src="<?= htmlspecialchars($thread['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:60px;height:60px;border-radius:50%;margin-bottom:8px;" loading="lazy">
            <div style="font-size:15px;font-weight:600;"<?= \App\Services\UserSvc::nicknameStyle($thread) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($thread)) ?></div>
        </div>
        <form @submit.prevent="submitReward">
            <div class="form-group">
                <label class="form-label">打赏金额（积分）</label>
                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <button type="button" class="btn btn-ghost btn-sm" @click="rewardAmount = 10" :class="{ 'btn-primary': rewardAmount === 10 }">10</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="rewardAmount = 50" :class="{ 'btn-primary': rewardAmount === 50 }">50</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="rewardAmount = 100" :class="{ 'btn-primary': rewardAmount === 100 }">100</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="rewardAmount = 500" :class="{ 'btn-primary': rewardAmount === 500 }">500</button>
                </div>
                <input type="number" x-model.number="rewardAmount" class="form-input" placeholder="或输入自定义金额" min="1" max="10000" required>
            </div>
            <div class="form-group">
                <label class="form-label">留言（可选）</label>
                <input type="text" x-model="rewardMessage" class="form-input" placeholder="说点什么..." maxlength="100">
            </div>
            <div x-show="rewardError" style="color:var(--danger);font-size:13px;margin-bottom:12px;" x-text="rewardError"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;" :disabled="rewardLoading">
                <span x-show="!rewardLoading">确认打赏</span>
                <span x-show="rewardLoading">处理中...</span>
            </button>
        </form>
    </div>
    </div>
</div>
<?php endif; ?>

<!-- 移动帖子弹窗 -->
<?php if (isset($_SESSION['user_id']) && (($_SESSION['group_id'] ?? 1) >= 2)): ?>
<?php $allForums = (new \App\Services\ForumSvc())->getAllForums(); ?>
<div x-data="moveModal()" @open-move.window="showMoveModal = true" x-show="showMoveModal" @click.self="showMoveModal = false" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;" x-cloak>
    <div class="card" style="width:90%;max-width:420px;margin:0;" @click.stop>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;">移动帖子</h3>
            <button @click="showMoveModal = false" style="background:none;border:none;font-size:24px;color:var(--text-muted);cursor:pointer;padding:0;line-height:1;">&times;</button>
        </div>
        <form @submit.prevent="submitMove">
            <div class="form-group">
                <label class="form-label">选择目标板块</label>
                <select x-model="targetForumId" class="form-input" required>
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($allForums as $af): ?>
                    <?php if ((int)$af['id'] !== (int)$thread['forum_id']): ?>
                    <option value="<?= (int)$af['id'] ?>"><?= $af['parent_id'] > 0 ? '└ ' : '' ?><?= htmlspecialchars($af['name']) ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div x-show="moveError" style="color:var(--danger);font-size:13px;margin-bottom:12px;" x-text="moveError"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;" :disabled="moveLoading">
                <span x-show="!moveLoading">确认移动</span>
                <span x-show="moveLoading">处理中...</span>
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
                $emptyExtra = '<div style="margin-top:12px;"><a href="/login" class="btn btn-primary btn-sm" @click.prevent="$dispatch(\'auth-show\', \'login\')">登录</a><a href="/register" class="btn btn-ghost btn-sm" style="margin-left:8px;" @click.prevent="$dispatch(\'auth-show\', \'register\')">注册</a><p style="color:var(--text-secondary);margin-top:8px;font-size:13px;">登录后才能参与讨论</p></div>';
            } else {
                $emptyIcon = 'reply'; $emptyText = '暂无评论，快来抢沙发吧';
            }
            include APP_PATH . 'resources/views/components/empty-state.php';
        ?>
    <?php else: ?>
        <?php foreach ($posts as $index => $post): ?>
        <div class="post-item" x-data="postActions(<?= (int)$post['id'] ?>, <?= (int)$post['thread_id'] ?>)">
            <div class="post-author-info">
                <img src="<?= htmlspecialchars($post['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-md" loading="lazy">
                <div class="post-author-name"><a href="/user/<?= (int)$post['user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($post) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($post)) ?></a></div>
            </div>
            <div class="post-body">
                <?php if (!empty($post['quote_post_id']) && !empty($post['quote_username'])): ?>
                <div class="quote-block" style="background:var(--bg-secondary);border-left:3px solid var(--primary);padding:8px 12px;margin-bottom:10px;border-radius:var(--radius);font-size:13px;color:var(--text-secondary);overflow:hidden;overflow-wrap:break-word;word-break:break-all;">
                    <div style="font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($post['quote_nickname'] ?: $post['quote_username']) ?> #<?= (int)($post['quote_floor'] ?? 0) ?>：</div>
                    <div><?= htmlspecialchars(mb_substr($post['quote_content'] ?? '', 0, 200)) ?><?= mb_strlen($post['quote_content'] ?? '') > 200 ? '...' : '' ?></div>
                </div>
                <?php endif; ?>
                <div class="post-content markdown-body" x-show="!editing"><?= \Core\Markdown::render($post['content_fmt'] ?? null, $post['content']) ?></div>
                <!-- 内联编辑表单 -->
                <div x-show="editing" x-cloak>
                    <textarea x-ref="editArea" class="form-input" style="min-height:100px;margin-bottom:8px;" x-model="editContent"></textarea>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-primary btn-sm" @click="saveEdit()" :disabled="editLoading">保存</button>
                        <button class="btn btn-ghost btn-sm" @click="editing = false">取消</button>
                    </div>
                    <div x-show="editError" style="color:var(--danger);font-size:13px;margin-top:4px;" x-text="editError"></div>
                </div>
                <div class="post-footer">
                    <span class="post-floor">#<?= ($page - 1) * $_perPage + $index + 1 ?></span>
                    <span class="timeago" datetime="<?= date('c', $post['created_at']) ?>"><?= date('Y-m-d H:i', $post['created_at']) ?></span>
                    <?php if (($post['updated_at'] ?? 0) > $post['created_at']): ?>
                    <span style="color:var(--text-muted);font-size:12px;cursor:pointer;" onclick="viewEditLog(<?= (int)$thread['id'] ?>, <?= (int)$post['id'] ?>)">（已编辑）</span>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="btn-quote" onclick="quoteReply(this)" data-post-id="<?= (int)$post['id'] ?>" data-username="<?= htmlspecialchars($post['username'], ENT_QUOTES) ?>" data-floor="<?= ($page - 1) * $_perPage + $index + 1 ?>" data-content="<?= htmlspecialchars($post['content'], ENT_QUOTES) ?>">引用</button>
                    <?php if ($_SESSION['user_id'] == $post['user_id'] || ($_SESSION['group_id'] ?? 1) >= 2): ?>
                    <button class="btn-quote" @click="startEdit(<?= htmlspecialchars(json_encode($post['content'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)">编辑</button>
                    <button class="btn-quote" style="color:var(--danger);" @click="deletePost()">删除</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
    $editorType = \App\Services\EditorService::getEditorType();
    $useTinymceReply = \App\Services\EditorService::shouldUseInReplyEditor();
    $emojiEnabled = \App\Services\EmojiService::isEnabled();
?>
<div class="card reply-form" x-data="replyForm()">
    <h3>发表评论</h3>
    <form @submit.prevent="submit">
        <div x-show="quotePostId > 0" x-cloak style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;margin-bottom:8px;background:var(--bg-secondary);border-left:3px solid var(--primary);border-radius:var(--radius);font-size:13px;color:var(--text-secondary);">
            <span>引用 <strong x-text="quoteUsername"></strong> #<span x-text="quoteFloor"></span> 的评论</span>
            <button type="button" @click="cancelQuote()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;line-height:1;">&times;</button>
        </div>
        <?php if ($useTinymceReply): ?>
        <?php
            $editorId = 'reply-tinymce-' . mt_rand(1000, 9999);
            $editorRows = 4;
            $editorPlaceholder = '输入评论内容';
            $editorModel = 'content';
            $editorMode = 'simple';
            include APP_PATH . 'resources/views/components/tinymce-editor.php';
        ?>
        <?php else: ?>
        <textarea x-model="content" x-ref="commentArea" class="form-input" rows="4" placeholder="输入评论内容" style="resize:vertical;"></textarea>
        <?php include APP_PATH . 'resources/views/components/alert.php'; ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
            <?php if ($emojiEnabled): ?>
            <button type="button" class="btn btn-ghost btn-sm" @click="showEmojiPanel($event)" title="表情" style="padding:4px 8px;font-size:18px;line-height:1;">😀</button>
            <?php endif; ?>
            <?php if ($_captchaReplyRequired): ?>
            <div class="captcha-widget" x-ref="captchaContainer" x-init="$nextTick(() => { _cw = new CaptchaWidget($refs.captchaContainer, { scene: 'reply', onVerified: (ok, id, ans) => { captchaId = id; captchaAnswer = ans; } }); })"></div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" :disabled="loading" style="margin-left:auto;">
                <span x-show="!loading">发表评论</span>
                <span x-show="loading">提交中...</span>
            </button>
        </div>
        <?php endif; ?>
        <?php if ($useTinymceReply): ?>
        <?php include APP_PATH . 'resources/views/components/alert.php'; ?>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
            <?php if ($_captchaReplyRequired): ?>
            <div class="captcha-widget" x-ref="captchaContainer" x-init="$nextTick(() => { _cw = new CaptchaWidget($refs.captchaContainer, { scene: 'reply', onVerified: (ok, id, ans) => { captchaId = id; captchaAnswer = ans; } }); })"></div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" :disabled="loading" style="margin-left:auto;">
                <span x-show="!loading">发表评论</span>
                <span x-show="loading">提交中...</span>
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php else: ?>
<?php if (!empty($posts)): ?>
<div class="card text-center" style="padding:24px;">
    <p style="color:var(--text-secondary);margin-bottom:12px;">登录后才能参与讨论</p>
    <a href="/login" class="btn btn-primary btn-sm" @click.prevent="$dispatch('auth-show', 'login')">登录</a>
    <a href="/register" class="btn btn-ghost btn-sm" style="margin-left:8px;" @click.prevent="$dispatch('auth-show', 'register')">注册</a>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
<?php if ($emojiEnabled ?? false): ?>
window.__AMUBBS_EMOJI = window.__AMUBBS_EMOJI || <?= json_encode(\App\Services\EmojiService::getEmojiMap(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
<?php endif; ?>
function replyForm() {
    return {
        content: '', errorMessage: '', successMessage: '', loading: false,
        quotePostId: 0, quoteUsername: '', quoteFloor: 0,
        captchaId: '', captchaAnswer: '', _cw: null,
        quote(postId, username, floor, text) {
            this.quotePostId = postId;
            this.quoteUsername = username;
            this.quoteFloor = floor;
            var editorEl = document.querySelector('.reply-form');
            if (editorEl) editorEl.scrollIntoView({ behavior: 'smooth' });
        },
        cancelQuote() {
            this.quotePostId = 0;
            this.quoteUsername = '';
            this.quoteFloor = 0;
        },
        showEmojiPanel(event) {
            var self = this;
            var emojiMap = window.__AMUBBS_EMOJI;
            if (!emojiMap) return;
            // 移除已有面板
            document.querySelectorAll('.amubbs-comment-emoji-panel,.amubbs-comment-emoji-backdrop').forEach(function(el) { el.remove(); });
            var items = '';
            for (var name in emojiMap) {
                items += '<span class="amubbs-ce-item" data-code=":' + name + ':" title=":' + name + ':" style="cursor:pointer;font-size:20px;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:4px;transition:background .15s;">' + emojiMap[name] + '</span>';
            }
            // 注入隐藏滚动条样式
            if (!document.getElementById('amubbs-ce-style')) {
                var s = document.createElement('style');
                s.id = 'amubbs-ce-style';
                s.textContent = '.amubbs-comment-emoji-panel{scrollbar-width:none;-ms-overflow-style:none;}.amubbs-comment-emoji-panel::-webkit-scrollbar{display:none;}';
                document.head.appendChild(s);
            }
            var panel = document.createElement('div');
            panel.className = 'amubbs-comment-emoji-panel';
            panel.style.cssText = 'position:fixed;z-index:10001;background:var(--bg,#fff);border:1px solid var(--border-light,#e2e8f0);border-radius:8px;padding:8px;box-shadow:0 4px 12px rgba(0,0,0,.12);width:296px;max-height:260px;overflow-y:auto;display:flex;flex-wrap:wrap;gap:2px;';
            panel.innerHTML = items;
            var btn = event.currentTarget;
            var rect = btn.getBoundingClientRect();
            panel.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            panel.style.left = Math.max(8, rect.left) + 'px';
            panel.addEventListener('mouseover', function(e) { if (e.target.classList.contains('amubbs-ce-item')) e.target.style.background = 'var(--bg-hover,#f1f5f9)'; });
            panel.addEventListener('mouseout', function(e) { if (e.target.classList.contains('amubbs-ce-item')) e.target.style.background = ''; });
            panel.addEventListener('click', function(e) {
                var item = e.target.closest('.amubbs-ce-item');
                if (item) {
                    var ta = self.$refs.commentArea;
                    var code = item.getAttribute('data-code');
                    var start = ta.selectionStart, end = ta.selectionEnd;
                    self.content = self.content.substring(0, start) + code + self.content.substring(end);
                    self.$nextTick(function() { ta.focus(); ta.selectionStart = ta.selectionEnd = start + code.length; });
                    panel.remove(); backdrop.remove();
                }
            });
            var backdrop = document.createElement('div');
            backdrop.className = 'amubbs-comment-emoji-backdrop';
            backdrop.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;';
            backdrop.addEventListener('click', function() { panel.remove(); backdrop.remove(); });
            document.body.appendChild(backdrop);
            document.body.appendChild(panel);
        },
        async submit() {
            this.errorMessage = ''; this.successMessage = '';
            if (this.content.trim().length < 2) { this.errorMessage = '评论内容至少2个字符'; return; }
            this.loading = true;
            const body = { thread_id: '<?= (int)$thread['id'] ?>', content: this.content, quote_post_id: this.quotePostId };
            if (this.captchaId) { body.captcha_id = this.captchaId; body.captcha_answer = this.captchaAnswer; }
            const data = await App.post('/thread/reply', body, { silent: true });
            if (data.success) { this.successMessage = '评论成功'; toast('评论成功', 'success'); setTimeout(() => location.reload(), 800); }
            else { this.errorMessage = data.error || data.message || '评论失败'; if (this._cw) this._cw.load(); }
            this.loading = false;
        }
    }
}

function threadActions(threadId) {
    return {
        message: '', isLiked: <?= !empty($liked) ? 'true' : 'false' ?>, likeCount: <?= (int)($thread['likes'] ?? 0) ?>,
        isFavorited: <?= !empty($favorited) ? 'true' : 'false' ?>,
        async postAction(url, body = {}) {
            body.thread_id = threadId;
            return App.post(url, body, { silent: true });
        },
        async toggleLike() {
            const data = await this.postAction('/thread/like');
            if (data.success) { this.isLiked = data.liked; this.likeCount = data.likes; }
        },
        async toggleFavorite() {
            const data = await this.postAction('/thread/favorite');
            if (data.success) { this.isFavorited = data.favorited; }
        },
        async deleteThread() {
            if (!confirm('确定要删除这个帖子吗？')) return;
            const data = await this.postAction('/thread/delete');
            if (data.success) { this.message = '已删除'; setTimeout(() => location.href = '/forum/<?= (int)$thread['forum_id'] ?>', 800); }
            else { this.message = data.error || '操作失败'; }
        },
        async toggleTop() {
            const data = await this.postAction('/thread/toggle-top');
            if (data.success) { this.message = data.message; setTimeout(() => location.reload(), 800); }
            else { this.message = data.error || '操作失败'; }
        },
        async toggleHighlight() {
            const data = await this.postAction('/thread/toggle-highlight');
            if (data.success) { this.message = data.message; setTimeout(() => location.reload(), 800); }
            else { this.message = data.error || '操作失败'; }
        },
        async setDigest(level) {
            const data = await this.postAction('/thread/toggle-highlight', { level });
            if (data.success) { this.message = data.message; setTimeout(() => location.reload(), 800); }
            else { this.message = data.error || '操作失败'; }
        },
        async toggleLock() {
            const data = await this.postAction('/thread/toggle-lock');
            if (data.success) { this.message = data.message; setTimeout(() => location.reload(), 800); }
            else { this.message = data.error || '操作失败'; }
        },
        editThread() { location.href = '/thread/edit/' + threadId; }
    }
}

function rewardModal() {
    return {
        showRewardModal: false,
        rewardAmount: 10,
        rewardMessage: '',
        rewardError: '',
        rewardLoading: false,
        async submitReward() {
            this.rewardError = '';
            if (this.rewardAmount < 1) { this.rewardError = '打赏金额至少为1积分'; return; }
            if (this.rewardAmount > 10000) { this.rewardError = '单次打赏不能超过10000积分'; return; }
            this.rewardLoading = true;
            const data = await App.post('/thread/reward', {
                target_type: 'thread', target_id: '<?= (int)$thread['id'] ?>',
                to_user_id: '<?= (int)$thread['user_id'] ?>', amount: this.rewardAmount, message: this.rewardMessage
            }, { silent: true });
            if (data.success) { toast('打赏成功', 'success'); setTimeout(() => location.reload(), 800); }
            else { this.rewardError = data.error || data.message || '打赏失败'; }
            this.rewardLoading = false;
        }
    }
}

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

function quoteReply(btn) {
    const postId = parseInt(btn.getAttribute('data-post-id')) || 0;
    const username = btn.getAttribute('data-username');
    const floor = btn.getAttribute('data-floor');
    const content = btn.getAttribute('data-content');
    const replyEl = document.querySelector('[x-data="replyForm()"]');
    if (replyEl && replyEl.__x) {
        replyEl.__x.$data.quote(postId, username, floor, content);
    } else if (replyEl && replyEl._x_dataStack) {
        const data = replyEl._x_dataStack[0];
        if (data.quote) data.quote(postId, username, floor, content);
    }
}

function buyHiddenContent(threadId, price) {
    if (!confirm('确定支付 ' + price + ' 积分解锁此内容？')) return;
    App.postReload('/thread/buy-content', { thread_id: threadId, price: price });
}

function moveModal() {
    return {
        showMoveModal: false,
        targetForumId: '',
        moveError: '',
        moveLoading: false,
        async submitMove() {
            this.moveError = '';
            if (!this.targetForumId) { this.moveError = '请选择目标板块'; return; }
            this.moveLoading = true;
            const data = await App.post('/thread/move', { thread_id: '<?= (int)$thread['id'] ?>', target_forum_id: this.targetForumId }, { silent: true });
            if (data.success) { toast('移动成功', 'success'); setTimeout(() => location.reload(), 800); }
            else { this.moveError = data.error || data.message || '移动失败'; }
            this.moveLoading = false;
        }
    }
}

function postActions(postId, threadId) {
    return {
        editing: false,
        editContent: '',
        editError: '',
        editLoading: false,
        startEdit(content) {
            this.editContent = content;
            this.editing = true;
            this.editError = '';
        },
        async saveEdit() {
            this.editError = '';
            if (this.editContent.length < 2) { this.editError = '评论内容至少2个字符'; return; }
            this.editLoading = true;
            const data = await App.post('/post/edit', { post_id: postId, content: this.editContent }, { silent: true });
            if (data.success) { toast('编辑成功', 'success'); setTimeout(() => location.reload(), 800); }
            else { this.editError = data.error || data.message || '编辑失败'; }
            this.editLoading = false;
        },
        async deletePost() {
            if (!confirm('确定要删除这条评论吗？')) return;
            const data = await App.post('/post/delete', { post_id: postId }, { silent: true });
            if (data.success) { toast('删除成功', 'success'); setTimeout(() => location.reload(), 800); }
            else { toast(data.error || '删除失败', 'error'); }
        }
    }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
