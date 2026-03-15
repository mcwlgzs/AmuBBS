<?php
/**
 * 帖子流项组件
 * 变量: $threadItem - 帖子数组，需包含 id, title, username, user_id, avatar, created_at, views, reply_count
 *       $showAvatar  - 是否显示头像，默认 true
 *       $showForum   - 是否显示板块标签，默认 true
 */
$_t = $threadItem ?? [];
if (empty($_t)) return;
$_showAvatar = $showAvatar ?? true;
$_showForum = $showForum ?? true;
?>
<div class="thread-flow-item">
    <?php if ($_showAvatar): ?>
    <img src="<?= htmlspecialchars($_t['avatar'] ?? '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm">
    <?php endif; ?>
    <div class="thread-flow-body">
        <a href="/thread/<?= (int)$_t['id'] ?>" class="thread-flow-title">
            <?php if ($_t['is_top'] ?? false): ?><span class="tag tag-top">置顶</span><?php endif; ?>
            <?php if ($_t['is_highlight'] ?? false): ?><span class="tag tag-highlight">精华</span><?php endif; ?>
            <?= htmlspecialchars($_t['title']) ?>
        </a>
        <div class="thread-flow-meta">
            <a href="/user/<?= (int)$_t['user_id'] ?>" style="display:inline-flex;align-items:center;gap:4px;"<?= \App\Services\UserSvc::nicknameStyle($_t) ?>>
                <?= htmlspecialchars(($_t['nickname'] ?? '') ?: ($_t['username'] ?? '')) ?>
                <?php if (isset($_t['credits'])): ?>
                <?php try { echo \App\Services\LevelSvc::getLevelBadge($_t['credits']); } catch (\Throwable $e) { error_log('[thread-item] level badge: ' . $e->getMessage()); } ?>
                <?php endif; ?>
                <?php if (isset($_t['user_id'])): ?>
                <?php try { echo \App\Services\VipSvc::getVipBadge($_t['user_id']); } catch (\Throwable $e) { error_log('[thread-item] vip badge: ' . $e->getMessage()); } ?>
                <?php endif; ?>
            </a>
            <span class="timeago" datetime="<?= date('c', $_t['created_at']) ?>"><?= date('m-d H:i', $_t['created_at']) ?></span>
            <?php if ($_showForum && !empty($_t['forum_name'])): ?>
            <a href="/forum/<?= (int)$_t['forum_id'] ?>" class="tag tag-forum"><?= htmlspecialchars($_t['forum_name']) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="thread-flow-stats">
        <span title="评论">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <?= number_format($_t['reply_count'] ?? 0) ?>
        </span>
        <span title="浏览">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <?= number_format($_t['views'] ?? 0) ?>
        </span>
        <?php if (isset($_t['likes']) && $_t['likes'] > 0): ?>
        <span title="点赞" style="color:var(--danger);">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            <?= number_format($_t['likes']) ?>
        </span>
        <?php endif; ?>
    </div>
</div>
