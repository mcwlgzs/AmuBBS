<?php
/**
 * 友情链接组件（页脚显示）
 */
try {
    // 取数与缓存都在模型里（以前是视图里写 SQL + Cache::get）
    $_friendLinks = \App\Models\FriendLink::active();
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
