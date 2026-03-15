<?php
$pageTitle = '网址导航';
$pageCss = ['index'];
include APP_PATH . 'resources/views/layout/header.php';
?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>网址导航</span>
</div>

<!-- 热门链接 -->
<?php if (!empty($popular)): ?>
<div class="card">
    <div class="section-title">
        热门推荐
        <svg style="width:16px;height:16px;color:var(--danger);" viewBox="0 0 24 24" fill="currentColor"><path d="M13.5.67s.74 2.65.74 4.8c0 2.06-1.35 3.73-3.41 3.73-2.07 0-3.63-1.67-3.63-3.73l.03-.36C5.21 7.51 4 10.62 4 14c0 4.42 3.58 8 8 8s8-3.58 8-8C20 8.61 17.41 3.8 13.5.67z"/></svg>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;">
        <?php foreach ($popular as $link): ?>
        <a href="/navigation/go?id=<?= (int)$link['id'] ?>" target="_blank" rel="noopener" class="nav-link-card" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border-light);border-radius:var(--radius);transition:all .2s;text-decoration:none;">
            <?php if (!empty($link['icon'])): ?>
            <img src="<?= htmlspecialchars($link['icon']) ?>" alt="" style="width:24px;height:24px;border-radius:4px;flex-shrink:0;" loading="lazy">
            <?php else: ?>
            <div style="width:24px;height:24px;border-radius:4px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">
                <?= htmlspecialchars(mb_substr($link['name'], 0, 1)) ?>
            </div>
            <?php endif; ?>
            <div style="min-width:0;">
                <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($link['name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= number_format($link['clicks']) ?> 次访问</div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 分类导航 -->
<?php if (empty($categories)): ?>
<div class="card">
    <div class="empty-state"><p>暂无导航链接</p></div>
</div>
<?php else: ?>
    <?php foreach ($categories as $cat): ?>
    <div class="card">
        <div class="section-title">
            <?php if (!empty($cat['icon'])): ?>
            <span><?= htmlspecialchars($cat['icon']) ?></span>
            <?php endif; ?>
            <?= htmlspecialchars($cat['name']) ?>
        </div>
        <?php if (empty($cat['links'])): ?>
            <div style="font-size:13px;color:var(--text-muted);padding:8px 0;">暂无链接</div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
            <?php foreach ($cat['links'] as $link): ?>
            <a href="/navigation/go?id=<?= (int)$link['id'] ?>" target="_blank" rel="noopener" style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid var(--border-light);border-radius:var(--radius);transition:all .2s;text-decoration:none;">
                <?php if (!empty($link['icon'])): ?>
                <img src="<?= htmlspecialchars($link['icon']) ?>" alt="" style="width:32px;height:32px;border-radius:6px;flex-shrink:0;" loading="lazy">
                <?php else: ?>
                <div style="width:32px;height:32px;border-radius:6px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0;">
                    <?= htmlspecialchars(mb_substr($link['name'], 0, 1)) ?>
                </div>
                <?php endif; ?>
                <div style="min-width:0;flex:1;">
                    <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:3px;"><?= htmlspecialchars($link['name']) ?></div>
                    <?php if (!empty($link['description'])): ?>
                    <div style="font-size:12px;color:var(--text-muted);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($link['description']) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
