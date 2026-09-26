<?php
/**
 * 用户主页的关注 / 拉黑按钮（片段）
 *
 * 两个动作都是 htmx POST，成功后服务端重新渲染本片段替换 #profileActions，
 * 所以按钮文案、样式、以及「拉黑后隐藏关注/私信」的联动都由服务端一次算清，
 * 前端不再需要「改状态变量 + 必要时 location.reload()」。
 *
 * 依赖变量：$targetId（被访问者 id）、$isFollowing、$isBlocked
 */
$targetId = (int)($targetId ?? 0);
$isFollowing = (bool)($isFollowing ?? false);
$isBlocked = (bool)($isBlocked ?? false);
?>
<div id="profileActions" style="display:flex;align-items:center;gap:8px;margin-top:12px;">
    <?php if (!$isBlocked): ?>
    <button type="button" class="btn btn-sm <?= $isFollowing ? 'btn-ghost' : 'btn-primary' ?>"
            hx-post="/user/follow" hx-vals='{"user_id":<?= $targetId ?>}'
            hx-target="#profileActions" hx-swap="outerHTML" hx-disabled-elt="this"><?= $isFollowing ? '已关注' : '+ 关注' ?></button>
    <a href="/messages/<?= $targetId ?>" class="btn btn-ghost btn-sm">发私信</a>
    <?php endif; ?>
    <button type="button" class="btn btn-sm <?= $isBlocked ? 'btn-danger' : 'btn-ghost' ?>"
            hx-post="/user/blacklist" hx-vals='{"user_id":<?= $targetId ?>}'
            hx-target="#profileActions" hx-swap="outerHTML" hx-disabled-elt="this"
            <?php /* 只有「拉黑」这一步需要二次确认，跟原来的行为一致 */ ?>
            <?php if (!$isBlocked): ?>hx-confirm="确定拉黑该用户？拉黑后将自动取消互相关注，对方无法给你发私信、回复你的帖子。"<?php endif; ?>><?= $isBlocked ? '已拉黑 (点击解除)' : '拉黑' ?></button>
</div>
