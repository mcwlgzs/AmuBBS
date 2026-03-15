<?php $pageTitle = '私信'; $pageCss = ['message', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <span>私信</span>
</div>

<div class="card">
    <div class="section-title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>私信会话</span>
    </div>

    <?php if (empty($conversations)): ?>
        <div class="empty-state"><p>暂无私信</p></div>
    <?php else: ?>
        <div class="message-list">
        <?php foreach ($conversations as $conv): ?>
            <?php $other = $conv['other_user'] ?? null; if (!$other) continue; ?>
            <a href="/messages/<?= (int)$other['id'] ?>" class="message-item <?= ($conv['unread'] ?? 0) > 0 ? 'unread' : '' ?>">
                <img src="<?= htmlspecialchars($other['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
                <div class="message-item-body">
                    <div class="message-item-header">
                        <span class="message-item-name"<?= \App\Services\UserSvc::nicknameStyle($other) ?>><?= htmlspecialchars(($other['nickname'] ?? '') ?: ($other['username'] ?? '')) ?></span>
                        <span class="message-item-time timeago" datetime="<?= date('c', $conv['created_at']) ?>"><?= date('m-d H:i', $conv['created_at']) ?></span>
                    </div>
                    <div class="message-item-preview">
                        <?= htmlspecialchars(mb_substr($conv['content'] ?? '', 0, 60)) ?>
                    </div>
                </div>
                <?php if (($conv['unread'] ?? 0) > 0): ?>
                    <span class="badge"><?= (int)$conv['unread'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
