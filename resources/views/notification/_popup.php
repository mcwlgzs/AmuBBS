<?php
/**
 * 通知下拉面板的内容（片段）
 *
 * 被 /notifications/popup 用 htmx 取回，塞进 #notifPopup。
 * 开头那个 hx-swap-oob 的角标会在同一次响应里把导航栏的未读角标一起更新，
 * 所以标记已读之后不需要再单独请求一次「未读数」。
 *
 * 依赖变量：$items（未读通知，最多 5 条）、$unreadCount
 */
$_n = (int)($unreadCount ?? 0);
?>
<span class="badge" id="notifBadge" hx-swap-oob="true"<?= $_n > 0 ? '' : ' style="display:none"' ?>><?= $_n > 99 ? '99+' : $_n ?></span>

<div class="notif-popup-header">
    <span>通知</span>
    <?php if (!empty($items)): ?>
    <button class="notif-readall" type="button"
            hx-post="/notifications/read-all" hx-target="#notifPopup" hx-swap="innerHTML"
            hx-disabled-elt="this">全部已读</button>
    <?php endif; ?>
</div>

<div class="notif-popup-body">
    <?php if (empty($items)): ?>
    <div class="notif-popup-empty">暂无新通知</div>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
        <?php
            // 与 app.js 里 getLink() 的规则保持一致
            $link = '/notifications';
            if ($item['target_type'] === 'thread' && $item['target_id']) {
                $link = '/thread/' . (int)$item['target_id'];
            } elseif ($item['target_type'] === 'message') {
                $link = '/messages';
            }
        ?>
        <a class="notif-popup-item" href="<?= htmlspecialchars($link) ?>">
            <div class="notif-popup-item-title"><?= htmlspecialchars($item['title']) ?></div>
            <?php if (!empty($item['content'])): ?>
            <div class="notif-popup-item-desc"><?= htmlspecialchars($item['content']) ?></div>
            <?php endif; ?>
            <div class="notif-popup-item-time"><?= htmlspecialchars(date('Y-m-d H:i', (int)$item['created_at'])) ?></div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<a href="/notifications" class="notif-popup-footer">查看全部通知</a>
