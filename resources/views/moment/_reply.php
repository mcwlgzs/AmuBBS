<?php
/**
 * 一条子回复（片段，只出现在顶层评论的 .moment-replies 里）
 *
 * 「回复」按钮指向本线程的回复框（#momentReplyForm-{顶层评论id}，即 $parentId），
 * 所以回复子评论仍然落在同一线程内，不会产生第三层。
 *
 * 依赖变量：$r（回复行）、$momentId、$parentId（所属顶层评论 id）
 */
$replyId    = (int)$r['id'];
$parentId   = (int)($parentId ?? $r['parent_id'] ?? 0);
$momentId   = (int)($momentId ?? ($r['moment_id'] ?? 0));
$authorName = ($r['nickname'] ?? '') ?: $r['username'];
?><div class="moment-comment moment-comment-child" id="momentComment-<?= $replyId ?>">
    <div class="moment-comment-head">
        <a href="/user/<?= (int)$r['user_id'] ?>" class="moment-comment-author"<?= \App\Services\UserSvc::nicknameStyle($r) ?>><?= htmlspecialchars($authorName) ?></a>
        <span class="moment-comment-time timeago" datetime="<?= date('c', (int)$r['created_at']) ?>"><?= date('Y-m-d H:i', (int)$r['created_at']) ?></span>
        <?php if (!empty($r['reply_username'])): ?>
        <span class="moment-comment-replyto">回复 <a href="/user/<?= (int)$r['reply_user_id'] ?>"<?= \App\Services\UserSvc::nicknameStyle(['nickname_color' => $r['reply_nickname_color'] ?? null]) ?>>@<?= htmlspecialchars(($r['reply_nickname'] ?? '') ?: $r['reply_username']) ?></a></span>
        <?php endif; ?>
    </div>
    <div class="moment-comment-text"><?= htmlspecialchars($r['content']) ?></div>
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="moment-comment-actions">
        <button type="button" class="moment-comment-reply"
                data-reply-to="<?= (int)$r['user_id'] ?>"
                data-reply-name="<?= htmlspecialchars($authorName, ENT_QUOTES) ?>"
                data-reply-form="#momentReplyForm-<?= $parentId ?>">回复</button>
    </div>
    <?php endif; ?>
</div>
