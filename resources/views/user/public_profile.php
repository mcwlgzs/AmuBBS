<?php
$pageTitle = htmlspecialchars(\App\Services\UserSvc::displayName($user)) . ' 的主页';
$pageCss = ['auth', 'index'];
$_followingCount = $followingCount ?? 0;
$_followerCount = $followerCount ?? 0;
$_isFollowing = $isFollowing ?? false;
$_isBlocked = $isBlocked ?? false;
$_userMoments = $userMoments ?? [];
include APP_PATH . 'resources/views/layout/header.php';
?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span><?= htmlspecialchars(\App\Services\UserSvc::displayName($user)) ?> 的主页</span>
</div>

<div class="card profile-card">
    <div class="profile-header">
        <div class="profile-avatar-wrap">
            <img src="<?= htmlspecialchars($user['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-lg">
        </div>
        <div class="profile-info">
            <div class="profile-name-row">
                <h2<?= \App\Services\UserSvc::nicknameStyle($user) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($user)) ?></h2>
                <span class="profile-uid" style="font-size:12px;color:var(--text-muted);margin-left:4px;">UID: <?= (int)$user['id'] ?></span>
                <?php if (!empty($user['nickname'])): ?>
                <span class="profile-username">@<?= htmlspecialchars($user['username']) ?></span>
                <?php endif; ?>
                <span class="profile-group"><?= htmlspecialchars($user['group_name'] ?? '普通用户') ?></span>
                <?= \App\Services\LevelSvc::getLevelBadge($user['credits'] ?? 0) ?>
                <?= \App\Services\VipSvc::getVipBadge((int)$user['id']) ?>
            </div>
            <?php if (!empty($user['signature'])): ?>
            <div class="profile-signature"><?= htmlspecialchars($user['signature']) ?></div>
            <?php endif; ?>
            <div class="profile-stats-row">
                <div class="profile-stat-item"><strong><?= number_format($user['thread_count'] ?? 0) ?></strong><span>主题</span></div>
                <div class="profile-stat-item"><strong><?= number_format($user['post_count'] ?? 0) ?></strong><span>评论</span></div>
                <div class="profile-stat-item"><strong><?= number_format($user['credits'] ?? 0) ?></strong><span>积分</span></div>
                <a href="/user/<?= (int)$user['id'] ?>/following" class="profile-stat-item" style="text-decoration:none;"><strong><?= (int)$_followingCount ?></strong><span>关注</span></a>
                <a href="/user/<?= (int)$user['id'] ?>/followers" class="profile-stat-item" style="text-decoration:none;"><strong><?= (int)$_followerCount ?></strong><span>粉丝</span></a>
                <div class="profile-stat-item"><strong><?= date('Y-m-d', $user['created_at']) ?></strong><span>注册</span></div>
            </div>
            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $user['id']): ?>
            <?php
            // 关注 / 拉黑按钮：两个动作都是 htmx POST，成功后服务端重渲染这个片段整体替换，
            // 所以「拉黑后隐藏关注和私信」的联动由服务端一次算清（原来靠 location.reload()）。
            $targetId = (int)$user['id'];
            $isFollowing = $_isFollowing;
            $isBlocked = $_isBlocked;
            include APP_PATH . 'resources/views/user/_profile_actions.php';
            ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($user['level_info'])): ?>
    <?php $levelInfo = $user['level_info']; ?>
    <div class="profile-level-bar">
        <div class="profile-level-info">
            <span>
                <?php if ($levelInfo['next_level']): ?>
                距离 <strong style="color:<?= htmlspecialchars($levelInfo['next_level']['color']) ?>;"><?= htmlspecialchars($levelInfo['next_level']['name']) ?></strong> 还需 <strong><?= number_format($levelInfo['credits_needed']) ?></strong> 积分
                <?php else: ?>
                已达到最高等级
                <?php endif; ?>
            </span>
            <span class="profile-level-pct"><?= (int)$levelInfo['progress'] ?>%</span>
        </div>
        <div class="profile-level-track">
            <div class="profile-level-fill" style="background:<?= htmlspecialchars($user['level_color'] ?? 'var(--primary)') ?>;width:<?= (int)$levelInfo['progress'] ?>%;"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 标签页（切换由 app.js 的 [data-tabs] 接管，面板内容仍是服务端一次渲染） -->
<div class="card" data-tabs="threads">
    <div class="tabs">
        <button type="button" class="tab-item" data-tab="threads">帖子 (<?= number_format($totalThreads ?? count($threads)) ?>)</button>
        <button type="button" class="tab-item" data-tab="replies">评论 (<?= number_format($totalReplies ?? count($replies ?? [])) ?>)</button>
        <button type="button" class="tab-item" data-tab="moments">动态 (<?= number_format($momentTotal ?? count($_userMoments)) ?>)</button>
    </div>

    <!-- 帖子 -->
    <div class="tab-panel" data-tab-panel="threads">
        <?php if (empty($threads)): ?>
            <div class="empty-state"><p>暂无帖子</p></div>
        <?php else: ?>
            <?php foreach ($threads as $thread): ?>
            <div class="thread-flow-item">
                <div class="thread-flow-body">
                    <a href="/thread/<?= (int)$thread['id'] ?>" class="thread-flow-title"><?= htmlspecialchars($thread['title']) ?></a>
                    <div class="thread-flow-meta">
                        <span class="timeago" datetime="<?= date('c', $thread['created_at']) ?>"><?= date('Y-m-d H:i', $thread['created_at']) ?></span>
                        <span>浏览 <?= number_format($thread['views']) ?></span>
                        <span>评论 <?= number_format($thread['reply_count'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (($totalPages ?? 1) > 1): ?>
        <?php
            $paginationUrl = '/user/' . (int)$user['id'] . '?page={page}';
            $paginationPage = $page;
            $paginationTotal = $totalPages;
            include APP_PATH . 'resources/views/layout/pagination.php';
        ?>
        <?php endif; ?>
    </div>

    <!-- 回复 -->
    <div class="tab-panel" data-tab-panel="replies">
        <?php if (empty($replies)): ?>
            <div class="empty-state"><p>暂无评论</p></div>
        <?php else: ?>
            <?php foreach ($replies as $reply): ?>
            <div class="thread-flow-item">
                <div class="thread-flow-body">
                    <a href="/thread/<?= (int)$reply['thread_id'] ?>" class="thread-flow-title">评论于：<?= htmlspecialchars($reply['thread_title'] ?? '') ?></a>
                    <div class="thread-flow-meta"><span class="timeago" datetime="<?= date('c', $reply['created_at']) ?>"><?= date('Y-m-d H:i', $reply['created_at']) ?></span></div>
                    <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= htmlspecialchars(mb_substr(strip_tags($reply['content']), 0, 80)) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 动态 -->
    <div class="tab-panel" data-tab-panel="moments">
        <?php if (empty($_userMoments)): ?>
            <div class="empty-state"><p>暂无动态</p></div>
        <?php else: ?>
            <?php foreach ($_userMoments as $moment): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--border-light);">
                <div class="moment-content" style="margin-bottom:6px;"><?= nl2br(htmlspecialchars($moment['content'])) ?></div>
                <?php if (!empty($moment['images'])): ?>
                <div class="moment-images moment-images-<?= min(count($moment['images']), 3) ?>" style="margin-bottom:6px;">
                    <?php foreach ($moment['images'] as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="" class="moment-img" loading="lazy">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;">
                    <span class="timeago" datetime="<?= date('c', $moment['created_at']) ?>"><?= date('Y-m-d H:i', $moment['created_at']) ?></span>
                    <span>赞 <?= (int)$moment['likes'] ?></span>
                    <span>评论 <?= (int)$moment['comment_count'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
