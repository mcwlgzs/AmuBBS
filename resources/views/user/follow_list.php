<?php
$isFollowers = ($type ?? '') === 'followers';
$pageTitle = htmlspecialchars(\App\Services\UserSvc::displayName($user)) . ' 的' . ($isFollowers ? '粉丝' : '关注');
$pageCss = ['auth', 'index'];
include APP_PATH . 'resources/views/layout/header.php';
?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/user/<?= (int)$user['id'] ?>"><?= htmlspecialchars(\App\Services\UserSvc::displayName($user)) ?></a> <span class="breadcrumb-sep">/</span>
    <span><?= $isFollowers ? '粉丝' : '关注' ?></span>
</div>

<div class="card">
    <div class="section-title"><?= $isFollowers ? '粉丝' : '关注' ?> (<?= count($list) ?>)</div>
    <?php if (empty($list)): ?>
        <?php $emptyIcon = 'user'; $emptyText = $isFollowers ? '暂无粉丝' : '暂未关注任何人'; include APP_PATH . 'resources/views/components/empty-state.php'; ?>
    <?php else: ?>
        <?php foreach ($list as $item): ?>
        <div class="follow-list-item">
            <a href="/user/<?= (int)$item['id'] ?>">
                <img src="<?= htmlspecialchars($item['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-md" loading="lazy">
            </a>
            <div class="follow-list-body">
                <a href="/user/<?= (int)$item['id'] ?>" class="follow-list-name"<?= \App\Services\UserSvc::nicknameStyle($item) ?>><?= htmlspecialchars(($item['nickname'] ?? '') ?: ($item['username'] ?? '')) ?></a>
                <?php if (!empty($item['signature'])): ?>
                <div class="follow-list-sig"><?= htmlspecialchars(mb_substr($item['signature'] ?? '', 0, 50)) ?></div>
                <?php endif; ?>
            </div>
            <span class="follow-list-time"><?= date('Y-m-d', $item['followed_at']) ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
