<?php $pageTitle = '积分排行榜'; $pageCss = ['auth']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">首页</a>
        <span class="breadcrumb-sep">›</span>
        <span>积分排行榜</span>
    </div>

    <div class="card">
        <div class="section-title">
            积分排行榜
            <svg style="width:18px;height:18px;color:var(--warning);" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
        </div>

        <?php if (empty($ranking)): ?>
            <?php $emptyIcon = 'list'; $emptyText = '暂无排行数据'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($ranking as $index => $user): ?>
                <div class="follow-list-item">
                    <div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <?php if ($index < 3): ?>
                            <div style="width:28px;height:28px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;
                                background:<?= $index === 0 ? 'linear-gradient(135deg, #FFD700, #FFA500)' : ($index === 1 ? 'linear-gradient(135deg, #C0C0C0, #A8A8A8)' : 'linear-gradient(135deg, #CD7F32, #B8860B)') ?>;
                                color:#fff;">
                                <?= $index + 1 ?>
                            </div>
                        <?php else: ?>
                            <span style="font-size:14px;font-weight:600;color:var(--text-muted);"><?= $index + 1 ?></span>
                        <?php endif; ?>
                    </div>
                    <img src="<?= htmlspecialchars($user['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-md" loading="lazy">
                    <div class="follow-list-body">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <a href="/user/<?= (int)$user['id'] ?>" class="follow-list-name"<?= \App\Services\UserSvc::nicknameStyle($user) ?>><?= htmlspecialchars(($user['nickname'] ?? '') ?: $user['username']) ?></a>
                            <?= \App\Services\LevelSvc::getLevelBadge($user['credits']) ?>
                        </div>
                        <div class="follow-list-sig">
                            主题 <?= number_format($user['thread_count']) ?> · 评论 <?= number_format($user['post_count']) ?>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:18px;font-weight:700;color:var(--primary);"><?= number_format($user['credits']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">积分</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
