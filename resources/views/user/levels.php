<?php $pageTitle = '等级体系'; $pageCss = ['auth']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">首页</a>
        <span class="breadcrumb-sep">›</span>
        <span>等级体系</span>
    </div>

    <div class="card">
        <div class="section-title">
            用户等级体系
            <svg style="width:18px;height:18px;color:var(--primary);" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
        <?php
        $userSvc = new \App\Services\UserSvc();
        $currentUser = $userSvc->getProfile($_SESSION['user_id']);
        $levelInfo = $currentUser['level_info'] ?? null;
        ?>
        <div style="padding:20px;background:linear-gradient(135deg, rgba(59,130,246,.06), var(--bg-secondary));border-radius:var(--radius);margin-bottom:24px;">
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                <img src="<?= htmlspecialchars($currentUser['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" style="width:60px;height:60px;border-radius:50%;" loading="lazy">
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-size:18px;font-weight:600;"<?= \App\Services\UserSvc::nicknameStyle($currentUser) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($currentUser)) ?></span>
                        <?= \App\Services\LevelSvc::getLevelBadge($currentUser['credits'] ?? 0) ?>
                    </div>
                    <div style="font-size:14px;color:var(--text-secondary);">当前积分：<strong style="color:var(--primary);"><?= number_format($currentUser['credits'] ?? 0) ?></strong></div>
                </div>
            </div>
            <?php if ($levelInfo && $levelInfo['next_level']): ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span style="font-size:13px;color:var(--text-secondary);">
                        距离 <strong style="color:<?= htmlspecialchars($levelInfo['next_level']['color']) ?>;"><?= htmlspecialchars($levelInfo['next_level']['name']) ?></strong> 还需 <strong><?= number_format($levelInfo['credits_needed']) ?></strong> 积分
                    </span>
                    <span style="font-size:14px;font-weight:600;color:var(--primary);"><?= (int)$levelInfo['progress'] ?>%</span>
                </div>
                <div style="height:8px;background:var(--border-light);border-radius:4px;overflow:hidden;">
                    <div style="height:100%;background:<?= htmlspecialchars($currentUser['level_color'] ?? 'var(--primary)') ?>;width:<?= (int)$levelInfo['progress'] ?>%;transition:width 0.3s;"></div>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:12px;background:var(--warning)15;border-radius:var(--radius);color:var(--warning);font-weight:600;">
                恭喜！您已达到最高等级
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(280px,100%),1fr));gap:16px;">
            <?php
            $levels = \App\Services\LevelSvc::getAllLevels();
            foreach ($levels as $level):
                $color = htmlspecialchars($level['color']);
                $name = htmlspecialchars($level['name']);
                $levelNum = (int)$level['level'];
                $minCredits = (int)$level['min_credits'];
                $isCurrentLevel = isset($currentUser) && $currentUser['level_num'] == $levelNum;
            ?>
            <div style="padding:20px;border:2px solid <?= $isCurrentLevel ? $color : 'var(--border-light)' ?>;border-radius:var(--radius);background:<?= $isCurrentLevel ? $color.'10' : 'var(--bg)' ?>;transition:all 0.3s;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:40px;height:40px;border-radius:50%;background:<?= $color ?>15;border:2px solid <?= $color ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;color:<?= $color ?>;">
                            <?= $levelNum ?>
                        </div>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:<?= $color ?>;"><?= $name ?></div>
                            <div style="font-size:12px;color:var(--text-muted);">Lv<?= $levelNum ?></div>
                        </div>
                    </div>
                    <?php if ($isCurrentLevel): ?>
                    <svg style="width:24px;height:24px;color:<?= $color ?>;" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    <?php endif; ?>
                </div>
                <div style="padding:12px;background:var(--bg-secondary);border-radius:var(--radius);margin-bottom:12px;">
                    <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;">所需积分</div>
                    <div style="font-size:20px;font-weight:700;color:<?= $color ?>;"><?= number_format($minCredits) ?></div>
                </div>
                <?php
                $nextLevel = \App\Services\LevelSvc::getNextLevel($levelNum);
                if ($nextLevel):
                    $nextMin = (int)$nextLevel['min_credits'];
                    $needed = $nextMin - $minCredits;
                ?>
                <div style="font-size:12px;color:var(--text-muted);">
                    升级到 <strong style="color:<?= htmlspecialchars($nextLevel['color']) ?>;"><?= htmlspecialchars($nextLevel['name']) ?></strong> 需再获得 <strong><?= number_format($needed) ?></strong> 积分
                </div>
                <?php else: ?>
                <div style="font-size:12px;color:var(--warning);font-weight:600;">最高等级</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:24px;padding:20px;background:var(--bg-secondary);border-radius:var(--radius);">
            <h3 style="margin:0 0 12px 0;font-size:16px;color:var(--text);">如何获得积分？</h3>
            <div style="display:grid;gap:8px;font-size:14px;color:var(--text-secondary);">
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg style="width:16px;height:16px;color:var(--success);" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    每日签到：10-20 积分（连续签到奖励更多）
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg style="width:16px;height:16px;color:var(--success);" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    发布帖子：5 积分
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg style="width:16px;height:16px;color:var(--success);" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    发表评论：2 积分
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg style="width:16px;height:16px;color:var(--success);" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    获得点赞：1 积分
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg style="width:16px;height:16px;color:var(--success);" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    收到打赏：根据打赏金额获得
                </div>
            </div>
        </div>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
