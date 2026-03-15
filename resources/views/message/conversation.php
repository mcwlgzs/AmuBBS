<?php $pageTitle = '与 ' . htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) . ' 的对话'; $pageCss = ['message', 'index']; include APP_PATH . 'resources/views/layout/header.php'; ?>

<div class="breadcrumb">
    <a href="/">首页</a> <span class="breadcrumb-sep">/</span>
    <a href="/messages">私信</a> <span class="breadcrumb-sep">/</span>
    <span><?= htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) ?></span>
</div>

<div class="card conversation-card" x-data="conversationApp()">
    <div class="conversation-header">
        <img src="<?= htmlspecialchars($otherUser['avatar'] ?: '/assets/images/default-avatar.png') ?>" alt="" class="avatar-sm" loading="lazy">
        <a href="/user/<?= (int)$otherUser['id'] ?>"<?= \App\Services\UserSvc::nicknameStyle($otherUser) ?>><?= htmlspecialchars(\App\Services\UserSvc::displayName($otherUser)) ?></a>
    </div>

    <div class="conversation-messages" id="messageList">
        <?php if (empty($messages)): ?>
            <div class="empty-state"><p>暂无消息，发送第一条吧</p></div>
        <?php else: ?>
            <?php foreach ($messages as $msg): ?>
            <?php $isSelf = $msg['from_user_id'] == ($_SESSION['user_id'] ?? 0); ?>
            <div class="msg-row <?= $isSelf ? 'msg-self' : 'msg-other' ?>" id="msg-<?= (int)$msg['id'] ?>">
                <div>
                    <?php if (!empty($msg['is_recalled'])): ?>
                        <div class="msg-recalled">消息已撤回</div>
                    <?php else: ?>
                        <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['content'])) ?></div>
                        <div class="msg-meta">
                            <span class="msg-time timeago" datetime="<?= date('c', $msg['created_at']) ?>"><?= date('m-d H:i', $msg['created_at']) ?></span>
                            <?php if ($isSelf && !empty($msg['can_recall'])): ?>
                            <button class="msg-recall-btn" onclick="recallMsg(<?= (int)$msg['id'] ?>)">撤回</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <form class="conversation-input" @submit.prevent="send">
        <textarea x-model="content" rows="2" placeholder="输入消息..." class="form-textarea" @keydown.enter.ctrl="send" required></textarea>
        <button type="submit" class="btn btn-primary" :disabled="loading">发送</button>
    </form>
</div>

<script>
function conversationApp() {
    return {
        content: '', loading: false,
        init() {
            this.$nextTick(() => {
                const el = document.getElementById('messageList');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        scrollToBottom() {
            this.$nextTick(() => {
                const el = document.getElementById('messageList');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        async send() {
            if (this.content.trim().length < 1) return;
            this.loading = true;
            try {
                const data = await App.post('/messages/send', { to_user_id: '<?= (int)$otherUser['id'] ?>', content: this.content }, { silent: true });
                if (data.success) {
                    const list = document.getElementById('messageList');
                    const empty = list.querySelector('.empty-state');
                    if (empty) empty.remove();
                    const row = document.createElement('div');
                    row.className = 'msg-row msg-self';
                    row.innerHTML = '<div><div class="msg-bubble">' + this.escapeHtml(this.content).replace(/\n/g, '<br>') + '</div><div class="msg-meta"><span class="msg-time">刚刚</span></div></div>';
                    list.appendChild(row);
                    this.content = '';
                    this.scrollToBottom();
                } else {
                    window.toast(data.message || '发送失败', 'error');
                }
            } catch (e) { window.toast('网络错误', 'error'); }
            finally { this.loading = false; }
        },
        escapeHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }
    }
}

async function recallMsg(idOrBtn) {
    let msgId, rowEl;
    if (typeof idOrBtn === 'number') {
        msgId = idOrBtn;
        rowEl = document.getElementById('msg-' + msgId);
    } else {
        // 动态插入的消息没有 id，找最近的 msg-row
        rowEl = idOrBtn.closest('.msg-row');
        // 动态消息暂不支持撤回（无 id），刷新页面后可撤回
        window.toast('请刷新页面后撤回', 'info');
        return;
    }
    if (!confirm('确定撤回这条消息？')) return;
    try {
        const data = await App.post('/messages/recall', { message_id: msgId }, { silent: true });
        if (data.success) {
            if (rowEl) {
                const inner = rowEl.querySelector('div');
                inner.innerHTML = '<div class="msg-recalled">消息已撤回</div>';
            }
        } else {
            window.toast(data.message || '撤回失败', 'error');
        }
    } catch (e) { window.toast('网络错误', 'error'); }
}
</script>

<?php include APP_PATH . 'resources/views/layout/footer.php'; ?>
