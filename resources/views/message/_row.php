<?php
/**
 * 单条私信（片段）
 *
 * 三处共用同一份标记，避免行结构漂移：
 *   1. 整页：conversation.php 逐条 include
 *   2. 发送成功：服务端只回这一行，htmx append 到 #messageList 末尾
 *   3. 撤回成功：服务端只回这一行，htmx 用它替换原来那一行（outerHTML）
 *
 * 依赖变量：$msg（id/from_user_id/content/is_recalled/created_at/can_recall）、$selfId
 */
$isSelf = (int)$msg['from_user_id'] === (int)$selfId;
$msgId = (int)$msg['id'];
?>
<div class="msg-row <?= $isSelf ? 'msg-self' : 'msg-other' ?>" id="msg-<?= $msgId ?>">
    <div>
        <?php if (!empty($msg['is_recalled'])): ?>
            <div class="msg-recalled">消息已撤回</div>
        <?php else: ?>
            <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['content'])) ?></div>
            <div class="msg-meta">
                <span class="msg-time timeago" datetime="<?= date('c', $msg['created_at']) ?>"><?= date('m-d H:i', $msg['created_at']) ?></span>
                <?php if ($isSelf && !empty($msg['can_recall'])): ?>
                <button type="button" class="msg-recall-btn"
                        hx-post="/messages/recall" hx-vals='{"message_id":<?= $msgId ?>}'
                        hx-target="#msg-<?= $msgId ?>" hx-swap="outerHTML" hx-disabled-elt="this"
                        hx-confirm="确定撤回这条消息？">撤回</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
