<?php
/**
 * 主题点赞按钮（片段）
 *
 * 点赞后服务端重渲染本片段替换自己（hx-target="this"），
 * 所以「已赞高亮 + 数量」都由服务端算，前端不用维护 likeCount / isLiked。
 *
 * 依赖变量：$threadId、$likes、$isLiked
 */
$threadId = (int)($threadId ?? 0);
$likes = (int)($likes ?? 0);
$isLiked = !empty($isLiked);
?>
<button type="button" class="like-btn<?= $isLiked ? ' liked' : '' ?>"
        hx-post="/thread/like" hx-vals='{"thread_id":<?= $threadId ?>}'
        hx-target="this" hx-swap="outerHTML" hx-disabled-elt="this"
        title="<?= $isLiked ? '取消点赞' : '点赞' ?>">
    <svg viewBox="0 0 24 24" fill="<?= $isLiked ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
    <span><?= $likes ?></span>
</button>
