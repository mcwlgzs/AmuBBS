<?php
$pageTitle = '与 ' . htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) . ' 的对话';
$pageCss = ['message', 'index'];
include APP_PATH . 'resources/views/layout/header.php';

/**
 * 私信会话
 *
 * 迁移说明（原来是 conversationApp() + 一个全局 recallMsg()）：
 *   - 发送：表单 hx-post，服务端只回「新消息那一行」，append 到 #messageList，页面不刷新
 *   - 撤回：每行的撤回按钮自己带 message_id，服务端回替换后的那一行
 *     （原来动态插入的消息没有 id，撤回按钮会提示「请刷新页面后撤回」——现在没有这个毛病了）
 *   - 自动滚到底：#messageList 上声明 data-scroll-bottom，由 app.js 在加载后和片段换入后处理
 */
$selfId = (int)($_SESSION['user_id'] ?? 0);
?>
<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/messages">私信</a> <span class="breadcrumb-sep">/</span>
    <span><?= htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) ?></span>
</div>

<div class="card conversation-card">
    <div class="conversation-header">
        <img src="<?= htmlspecialchars($otherUser['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
        <a href="/user/<?= (int)$otherUser['id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($otherUser) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) ?></a>
    </div>

    <div class="conversation-messages" id="messageList" data-scroll-bottom>
        <?php if (empty($messages)): ?>
            <div class="empty-state"><p>暂无消息，发送第一条吧</p></div>
        <?php else: ?>
            <?php foreach ($messages as $msg): ?>
            <?php include APP_PATH . 'resources/views/message/_row.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php /* 发送成功后清空输入框：hx-on 是 htmx 自带的事件钩子，不用额外写 JS */ ?>
    <form class="conversation-input" hx-post="/messages/send"
          hx-target="#messageList" hx-swap="beforeend"
          hx-indicator="this" hx-disabled-elt="find button[type=submit]"
          hx-on::after-request="if (event.detail.successful) this.reset();">
        <input type="hidden" name="to_user_id" value="<?= (int)$otherUser['id'] ?>">
        <textarea name="content" rows="2" placeholder="输入消息..." class="form-textarea" required maxlength="2000"></textarea>
        <button type="submit" class="btn btn-primary">
            <span class="hx-idle">发送</span>
            <span class="hx-busy">发送中...</span>
        </button>
    </form>
</div>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
