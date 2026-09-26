<?php
/**
 * 一条顶层动态评论 + 它的回复（片段）
 *
 * 对齐 GitHub Discussions 的「一层嵌套」：顶层评论一块，回复成组缩进在下面；
 * 回复里的「回复」按钮指向**本线程的回复框**（#momentReplyForm-{顶层id}），
 * 也就是回复子评论＝回到同一个线程，永远不会出现第三层。
 *
 * 整页、htmx 追加（beforeend 到 #momentComments-*）共用本文件。
 *
 * 依赖变量：$c（评论行，含 username/nickname/reply_* 等）、$momentId
 * 可选：   $c['replies'] / $c['replies_total'] / $c['replies_truncated']
 *          （不传时按「没有回复」渲染，供 htmx 追加新顶层评论时使用）
 */
$momentId   = (int)($momentId ?? ($c['moment_id'] ?? 0));
$commentId  = (int)$c['id'];
$authorName = ($c['nickname'] ?? '') ?: $c['username'];
$replies         = $c['replies'] ?? [];
$repliesTotal    = (int)($c['replies_total'] ?? count($replies));
$repliesTruncated = !empty($c['replies_truncated']);
$loggedIn   = isset($_SESSION['user_id']);
?>
<div class="moment-comment" id="momentComment-<?= $commentId ?>">
    <div class="moment-comment-head">
        <a href="/user/<?= (int)$c['user_id'] ?>" class="moment-comment-author"<?= \App\Services\UserSvc::nicknameStyle($c) ?>><?= htmlspecialchars($authorName) ?></a>
        <span class="moment-comment-time timeago" datetime="<?= date('c', (int)$c['created_at']) ?>"><?= date('Y-m-d H:i', (int)$c['created_at']) ?></span>
        <?php if (!empty($c['reply_username'])): ?>
        <span class="moment-comment-replyto">回复 <a href="/user/<?= (int)$c['reply_user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle(['nickname_color' => $c['reply_nickname_color'] ?? null]) ?>>@<?= htmlspecialchars(($c['reply_nickname'] ?? '') ?: $c['reply_username']) ?></a></span>
        <?php endif; ?>
    </div>
    <div class="moment-comment-text"><?= htmlspecialchars($c['content']) ?></div>
    <?php if ($loggedIn): ?>
    <div class="moment-comment-actions">
        <button type="button" class="moment-comment-reply"
                data-reply-to="<?= (int)$c['user_id'] ?>"
                data-reply-name="<?= htmlspecialchars($authorName, ENT_QUOTES) ?>"
                data-reply-form="#momentReplyForm-<?= $commentId ?>">回复</button>
    </div>
    <?php endif; ?>
    <div class="moment-replies" id="momentReplies-<?= $commentId ?>"><?php foreach ($replies as $r) { $parentId = $commentId; include APP_PATH . 'resources/views/moment/_reply.php'; } ?></div>
    <?php if ($repliesTruncated): ?>
    <div class="moment-comments-more"><button type="button" class="moment-more-btn"
            hx-get="/moments/comments?moment_id=<?= $momentId ?>"
            hx-target="#momentComments-<?= $momentId ?>" hx-swap="innerHTML"
            hx-disabled-elt="this">查看全部 <?= $repliesTotal ?> 条回复</button></div>
    <?php endif; ?>
    <?php if ($loggedIn): ?>
    <div class="moment-comment-form moment-reply-form" id="momentReplyForm-<?= $commentId ?>" hidden>
        <div data-reply-indicator hidden class="moment-reply-indicator">
            回复 <span data-reply-name></span>
            <a href="javascript:;" data-reply-cancel>取消</a>
        </div>
        <form hx-post="/moments/comment" hx-target="#momentReplies-<?= $commentId ?>" hx-swap="beforeend" data-reply-form-target>
            <input type="hidden" name="moment_id" value="<?= $momentId ?>">
            <input type="hidden" name="parent_id" value="<?= $commentId ?>">
            <input type="hidden" name="reply_user_id" value="" data-reply-input>
            <input type="text" name="content" class="form-input" placeholder="回复 <?= htmlspecialchars($authorName, ENT_QUOTES) ?>..." maxlength="500" required>
        </form>
    </div>
    <?php endif; ?>
</div>
