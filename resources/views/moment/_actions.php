<?php
/**
 * 动态的点赞 / 评论按钮（片段）
 *
 * 点赞是 htmx POST，成功后服务端重新渲染本片段整体替换 #momentActions-{id}，
 * 所以「已赞高亮 + 数量」都由服务端算，前端不再维护 liked/likes 两个变量。
 * 评论区入口只负责显示/隐藏评论框（[data-toggle-target]，见 app.js）。
 *
 * 依赖变量：$moment（id / likes / is_liked / comment_count）
 */
$momentId = (int)$moment['id'];
$likes = (int)($moment['likes'] ?? 0);
$commentCount = (int)($moment['comment_count'] ?? 0);
$isLiked = !empty($moment['is_liked']);
?>
<div class="moment-actions" id="momentActions-<?= $momentId ?>">
    <?php if (isset($_SESSION['user_id'])): ?>
    <button type="button" class="moment-action-btn<?= $isLiked ? ' is-liked' : '' ?>"
            hx-post="/moments/like" hx-vals='{"moment_id":<?= $momentId ?>}'
            hx-target="#momentActions-<?= $momentId ?>" hx-swap="outerHTML" hx-disabled-elt="this"
            title="<?= $isLiked ? '取消点赞' : '点赞' ?>">
        <svg viewBox="0 0 24 24" fill="<?= $isLiked ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        <span><?= $likes ?: '' ?></span>
    </button>
    <button type="button" class="moment-action-btn" data-toggle-target="#momentCommentForm-<?= $momentId ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span><?= $commentCount ?: '' ?></span>
    </button>
    <?php else: ?>
    <span style="font-size:12px;color:var(--text-muted);">
        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;vertical-align:middle;opacity:.4;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        <?= $likes ?: '' ?>
    </span>
    <?php endif; ?>
</div>
