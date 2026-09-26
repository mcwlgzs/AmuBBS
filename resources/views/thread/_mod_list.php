<?php
/**
 * 版主列表（片段）
 *
 * 整页和 htmx 共用：添加/移除版主后，服务端重新渲染本片段替换 #forumModList。
 * 「已添加 / 已移除 / 失败原因」统一走 frontFlash 提示，
 * 页面不用再维护 modMsg / modErr 这些前端状态。
 *
 * 依赖变量：$modList（[{id,name,avatar}]）、$forumId
 */
$modList = $modList ?? [];
$forumId = (int)($forumId ?? 0);
?>
<div id="forumModList">
    <?php if (empty($modList)): ?>
    <div style="font-size:12px;color:var(--text-muted);padding:8px 0;text-align:center;">暂无版主</div>
    <?php else: ?>
        <?php foreach ($modList as $m): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;font-size:13px;color:var(--text);border-bottom:1px solid var(--border-light,#f1f5f9);">
            <a href="/user/<?= (int)$m['id'] ?>" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--text);">
                <img src="<?= htmlspecialchars($m['avatar']) ?>" alt="" style="width:24px;height:24px;border-radius:50%;" loading="lazy">
                <span><?= htmlspecialchars($m['name']) ?></span>
                <span style="color:var(--text-muted);font-size:11px;">#<?= (int)$m['id'] ?></span>
            </a>
            <button type="button"
                    hx-post="/forum/moderators"
                    hx-vals='{"action":"remove","forum_id":<?= $forumId ?>,"user_id":<?= (int)$m['id'] ?>}'
                    hx-target="#forumModList" hx-swap="outerHTML" hx-disabled-elt="this"
                    hx-confirm="确定移除该版主？"
                    style="background:none;border:none;cursor:pointer;color:var(--danger,#ef4444);padding:2px 6px;font-size:12px;border-radius:4px;" title="移除">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
