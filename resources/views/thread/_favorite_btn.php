<?php
/**
 * 主题收藏按钮（片段）
 *
 * 与点赞同理：收藏后服务端重渲染本片段替换自己（hx-target="this"）。
 *
 * 依赖变量：$threadId、$isFavorited
 */
$threadId = (int)($threadId ?? 0);
$isFavorited = !empty($isFavorited);
?>
<button type="button" class="btn btn-ghost btn-sm<?= $isFavorited ? ' text-primary' : '' ?>"
        hx-post="/thread/favorite" hx-vals='{"thread_id":<?= $threadId ?>}'
        hx-target="this" hx-swap="outerHTML" hx-disabled-elt="this" style="gap:4px;">
    <svg viewBox="0 0 24 24" fill="<?= $isFavorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
    <span><?= $isFavorited ? '已收藏' : '收藏' ?></span>
</button>
