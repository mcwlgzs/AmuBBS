<?php
/**
 * 动态评论区容器内容（片段）
 *
 * 三处共用：动态列表整页、htmx 追加（beforeend 到 #momentComments-*）、
 * GET /moments/comments「查看全部」（innerHTML 替换整个容器）。
 *
 * 结构对齐 GitHub Discussions：**只一层嵌套** —— 顶层评论下面跟着它自己的回复，
 * 回复用左侧竖线缩进分组；再多的回复要么内联、要么「查看全部 N 条回复」。
 *
 * 依赖变量：
 *   $comments        顶层评论数组，每条含 replies / replies_total / replies_truncated
 *   $momentId        动态 id
 *   $commentTotal    该动态的评论总数（含回复），用于「查看全部 N 条评论」文案
 *   $commentsHasMore 顶层评论是否被截断（true 才显示「查看全部」）
 *   $expanded        true = 已经是展开后的完整列表，不再显示「查看全部」
 */
$momentId        = (int)($momentId ?? 0);
$comments        = $comments ?? [];
$commentTotal    = (int)($commentTotal ?? 0);
$commentsHasMore = !empty($commentsHasMore);
$expanded        = !empty($expanded);

foreach ($comments as $c) {
    include APP_PATH . 'resources/views/moment/_comment.php';
}
?>
<?php if (!$expanded && $commentsHasMore): ?>
<div class="moment-comments-more"><button type="button" class="moment-more-btn"
        hx-get="/moments/comments?moment_id=<?= $momentId ?>"
        hx-target="#momentComments-<?= $momentId ?>" hx-swap="innerHTML"
        hx-disabled-elt="this">查看全部 <?= $commentTotal ?> 条评论</button></div>
<?php endif; ?>
