<?php
/**
 * 友情链接组件（页脚显示）
 */
try {
    $_friendLinks = \Core\Cache::get('friend_links:active', function () {
        return \Core\Database::fetchAll(
            "SELECT name, url, logo FROM friend_links WHERE status = 1 ORDER BY sort_order ASC, id ASC LIMIT 30"
        );
    }, 600);
} catch (\Throwable $e) { $_friendLinks = []; }
if (!empty($_friendLinks)):
?>
<div class="friend-links">
    <div class="friend-links-label">友情链接</div>
    <div class="friend-links-list">
        <?php foreach ($_friendLinks as $_fl): ?>
        <a href="<?= htmlspecialchars($_fl['url']) ?>" target="_blank" rel="noopener nofollow" class="friend-link-item"><?= htmlspecialchars($_fl['name']) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
