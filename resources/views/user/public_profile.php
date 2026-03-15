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
            <div style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                <?php if (!$_isBlocked): ?>
                <span x-data="{ followed: <?= $_isFollowing ? 'true' : 'false' ?>, loading: false }" style="display:inline-flex;">
                    <button class="btn btn-sm" :class="followed ? 'btn-ghost' : 'btn-primary'" :disabled="loading" @click="
                        loading = true;
                        App.post('/user/follow', {user_id:'<?= (int)$user['id'] ?>'}, {silent:true})
                        .then(d => { if (d.success) followed = d.followed; }).finally(() => loading = false);
                    " x-text="followed ? '已关注' : '+ 关注'"></button>
                </span>
                <a href="/messages/<?= (int)$user['id'] ?>" class="btn btn-ghost btn-sm">发私信</a>
                <?php endif; ?>
                <span x-data="{ blocked: <?= $_isBlocked ? 'true' : 'false' ?>, loading: false }" style="display:inline-flex;">
                    <button class="btn btn-sm" :class="blocked ? 'btn-danger' : 'btn-ghost'" :disabled="loading" @click="
                        if (!blocked && !confirm('确定拉黑该用户？拉黑后将自动取消互相关注，对方无法给你发私信、回复你的帖子。')) return;
                        loading = true;
                        App.post('/user/blacklist', {user_id:'<?= (int)$user['id'] ?>'}, {silent:true})
                        .then(d => { if (d.success) { blocked = d.blocked; if (d.blocked) location.reload(); } }).finally(() => loading = false);
                    " x-text="blocked ? '已拉黑 (点击解除)' : '拉黑'"></button>
                </span>
            </div>
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

<!-- 标签页 -->
<div class="card" x-data="{ tab: 'threads' }">
    <div class="tabs">
        <button class="tab-item" :class="{ active: tab === 'threads' }" @click="tab = 'threads'">帖子 (<?= number_format($totalThreads ?? count($threads)) ?>)</button>
        <button class="tab-item" :class="{ active: tab === 'replies' }" @click="tab = 'replies'">评论 (<?= number_format($totalReplies ?? count($replies ?? [])) ?>)</button>
        <button class="tab-item" :class="{ active: tab === 'moments' }" @click="tab = 'moments'">动态 (<?= number_format($momentTotal ?? count($_userMoments)) ?>)</button>
    </div>

    <!-- 帖子 -->
    <div class="tab-panel" :class="{ active: tab === 'threads' }">
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
    <div class="tab-panel" :class="{ active: tab === 'replies' }">
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
    <div class="tab-panel" :class="{ active: tab === 'moments' }">
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
