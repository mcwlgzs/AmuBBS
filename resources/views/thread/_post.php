<?php
/**
 * 单条评论（楼层）渲染片段 —— 帖子详情页「单层嵌套」用（GitHub Discussions 风格）
 *
 * 由 resources/views/thread/detail.php 通过闭包 require 调用，依赖变量：
 *   $post    array  评论行
 *   $floor   int    楼层号
 *   $isChild bool   true = 该条是某条评论的回复（缩进显示，引用关系用一行「回复 @某人 #N」表示）
 *   $thread  array  主题行（编辑历史链接用）
 *
 * 参考：GitHub Discussions 只允许一层嵌套（回复的回复归入同一组），
 * 因此子回复不再重复整段引用内容 —— 父评论就在它上方缩进块内。
 */
$postId  = (int)$post['id'];
$canEdit = isset($_SESSION['user_id']) && ($_SESSION['user_id'] == $post['user_id'] || ($_SESSION['group_id'] ?? 1) >= 2);
$_child  = !empty($isChild);
$_quoted = (int)($post['quote_post_id'] ?? 0) > 0 && !empty($post['quote_username']);
?>
<div class="post-item<?= $_child ? ' post-item-child' : '' ?>" id="post-<?= $postId ?>">
    <div class="post-author-info">
        <img src="<?= htmlspecialchars($post['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-md" loading="lazy">
        <div class="post-author-name"><a href="/user/<?= (int)$post['user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($post) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($post)) ?></a></div>
    </div>
    <div class="post-body">
        <?php if ($_quoted): ?>
            <?php if ($_child): ?>
        <div class="reply-context">
            回复 <strong><?= htmlspecialchars($post['quote_nickname'] ?: $post['quote_username']) ?></strong>
            <a href="#post-<?= (int)$post['quote_post_id'] ?>" class="reply-context-floor">#<?= (int)($post['quote_floor'] ?? 0) ?></a>
        </div>
            <?php else: ?>
        <div class="quote-block" style="background:var(--bg-secondary);border-left:3px solid var(--primary);padding:8px 12px;margin-bottom:10px;border-radius:var(--radius);font-size:13px;color:var(--text-secondary);overflow:hidden;overflow-wrap:break-word;word-break:break-all;">
            <div style="font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($post['quote_nickname'] ?: $post['quote_username']) ?> #<?= (int)($post['quote_floor'] ?? 0) ?>：</div>
            <div><?= htmlspecialchars(mb_substr($post['quote_content'] ?? '', 0, 200)) ?><?= mb_strlen($post['quote_content'] ?? '') > 200 ? '...' : '' ?></div>
        </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="post-content markdown-body" id="post-<?= $postId ?>-view"><?= \Core\Markdown::render($post['content_fmt'] ?? null, $post['content']) ?></div>
        <?php /* 内联编辑表单：与正文二选一，由 [data-toggle-target]/[data-toggle-alt] 切换，无需请求 */ ?>
        <?php if ($canEdit): ?>
        <form id="post-<?= $postId ?>-edit" hidden hx-post="/post/edit" hx-swap="none" style="margin-bottom:8px;">
            <input type="hidden" name="post_id" value="<?= $postId ?>">
            <textarea name="content" class="form-input" style="min-height:100px;margin-bottom:8px;resize:vertical;" required minlength="2"><?= htmlspecialchars($post['content'], ENT_QUOTES) ?></textarea>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><span class="hx-idle">保存</span><span class="hx-busy">保存中...</span></button>
                <button type="button" class="btn btn-ghost btn-sm" data-toggle-target="#post-<?= $postId ?>-edit" data-toggle-alt="#post-<?= $postId ?>-view">取消</button>
            </div>
        </form>
        <?php endif; ?>
        <div class="post-footer">
            <span class="post-floor">#<?= $floor ?></span>
            <span class="timeago" datetime="<?= date('c', $post['created_at']) ?>"><?= date('Y-m-d H:i', $post['created_at']) ?></span>
            <?php if (($post['updated_at'] ?? 0) > $post['created_at']): ?>
            <span style="color:var(--text-muted);font-size:12px;cursor:pointer;" onclick="viewEditLog(<?= (int)$thread['id'] ?>, <?= $postId ?>)">（已编辑）</span>
            <?php endif; ?>
            <?php if (isset($_SESSION['user_id'])): ?>
            <button class="btn-quote" data-reply-to="<?= (int)$post['user_id'] ?>" data-reply-post="<?= $postId ?>" data-reply-name="<?= htmlspecialchars(\App\Services\UserSvc::displayName($post), ENT_QUOTES) ?>" data-reply-floor="<?= $floor ?>" data-reply-form="#threadReplyForm">引用</button>
            <?php if ($canEdit): ?>
            <button class="btn-quote" data-toggle-target="#post-<?= $postId ?>-edit" data-toggle-alt="#post-<?= $postId ?>-view">编辑</button>
            <button class="btn-quote" style="color:var(--danger);" hx-post="/post/delete" hx-vals='{"post_id":<?= $postId ?>}' hx-confirm="确定要删除这条评论吗？" hx-swap="none">删除</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
