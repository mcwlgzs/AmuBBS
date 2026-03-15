<?php
/**
 * 私信控制器
 */

namespace App\Controllers;

use Core\Database;

class Message extends Base
{
    /**
     * 会话列表
     */
    public function index(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        // 获取所有会话（按最新消息排序）
        $conversations = Database::fetchAll("
            SELECT
                m.*,
                CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END as other_user_id
            FROM messages m
            INNER JOIN (
                SELECT
                    LEAST(from_user_id, to_user_id) as u1,
                    GREATEST(from_user_id, to_user_id) as u2,
                    MAX(id) as max_id
                FROM messages
                WHERE (from_user_id = ? AND deleted_by_from = 0)
                   OR (to_user_id = ? AND deleted_by_to = 0)
                GROUP BY u1, u2
            ) latest ON m.id = latest.max_id
            ORDER BY m.created_at DESC
            LIMIT 50
        ", [$userId, $userId, $userId]);

        // 获取对方用户信息（批量查询）
        $otherUserIds = array_unique(array_column($conversations, 'other_user_id'));
        $otherUsers = [];
        $unreadCounts = [];
        if ($otherUserIds) {
            $placeholders = implode(',', array_fill(0, count($otherUserIds), '?'));
            $users = Database::fetchAll(
                "SELECT id, username, nickname, avatar, nickname_color FROM users WHERE id IN ({$placeholders})",
                array_values($otherUserIds)
            );
            foreach ($users as $u) {
                $otherUsers[$u['id']] = $u;
            }
            // 批量查询未读数
            $unreads = Database::fetchAll(
                "SELECT from_user_id, COUNT(*) as c FROM messages WHERE from_user_id IN ({$placeholders}) AND to_user_id = ? AND is_read = 0 GROUP BY from_user_id",
                [...array_values($otherUserIds), $userId]
            );
            foreach ($unreads as $ur) {
                $unreadCounts[$ur['from_user_id']] = (int)$ur['c'];
            }
        }
        foreach ($conversations as &$conv) {
            $conv['other_user'] = $otherUsers[$conv['other_user_id']] ?? null;
            $conv['unread'] = $unreadCounts[$conv['other_user_id']] ?? 0;
        }

        $this->render('message/index', [
            'conversations' => $conversations,
        ]);
    }

    /**
     * 对话详情
     */
    public function conversation(string $otherUserId): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $otherUserId = (int)$otherUserId;

        $otherUser = Database::fetchOne(
            "SELECT id, username, nickname, avatar, nickname_color FROM users WHERE id = ? AND deleted_at IS NULL",
            [$otherUserId]
        );

        if (!$otherUser) {
            $this->redirect('/messages');
            return;
        }

        // 标记为已读
        Database::useMaster();
        try {
            Database::execute(
                "UPDATE messages SET is_read = 1 WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0",
                [$otherUserId, $userId]
            );
        } finally {
            Database::restoreReadWrite();
        }

        // 获取消息列表
        $messages = Database::fetchAll("
            SELECT m.*, u.username, u.avatar
            FROM messages m
            LEFT JOIN users u ON m.from_user_id = u.id
            WHERE ((m.from_user_id = ? AND m.to_user_id = ? AND m.deleted_by_from = 0)
                OR (m.from_user_id = ? AND m.to_user_id = ? AND m.deleted_by_to = 0))
            ORDER BY m.created_at ASC
            LIMIT 100
        ", [$userId, $otherUserId, $otherUserId, $userId]);

        $now = time();
        foreach ($messages as &$m) {
            $m['can_recall'] = ((int)$m['from_user_id'] === $userId && empty($m['is_recalled']) && ($now - (int)$m['created_at']) <= 120);
        }
        unset($m);

        $this->render('message/conversation', [
            'otherUser' => $otherUser,
            'messages' => $messages,
        ]);
    }

    /**
     * 发送私信
     */
    public function send(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        // 频率限制：10秒内只能发一条私信
        $floodKey = "flood:message:{$userId}";
        if (\Core\Cache::get($floodKey) !== null) {
            $this->error('发送过于频繁，请稍后再试');
            return;
        }

        $toUserId = (int)($_POST['to_user_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($toUserId <= 0) {
            $this->error('请指定收信人');
            return;
        }

        if ($toUserId === $userId) {
            $this->error('不能给自己发私信');
            return;
        }

        if (empty($content)) {
            $this->error('请输入消息内容');
            return;
        }

        if (mb_strlen($content) > 2000) {
            $this->error('消息内容不能超过 2000 字');
            return;
        }

        // 验证收信人存在
        $toUser = Database::fetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$toUserId]);
        if (!$toUser) {
            $this->error('用户不存在');
            return;
        }

        // 黑名单检查：任一方拉黑则禁止发私信
        if (\App\Services\BlacklistSvc::isEitherBlocked($userId, $toUserId)) {
            $this->error('无法向该用户发送私信');
            return;
        }

        // 敏感词过滤
        $filter = \App\Services\SensitiveWordService::filter($content);
        if ($filter['blocked']) {
            $this->error('消息包含违禁词');
            return;
        }
        $content = $filter['text'];

        Database::useMaster();
        try {
            Database::execute(
                "INSERT INTO messages (from_user_id, to_user_id, content, is_read, created_at, deleted_by_from, deleted_by_to) VALUES (?, ?, ?, 0, ?, 0, 0)",
                [$userId, $toUserId, $content, time()]
            );
        } finally {
            Database::restoreReadWrite();
        }

        // 发送通知
        $fromUser = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$userId]);
        $username = $fromUser['username'] ?? '用户';
        \App\Services\NotificationSvc::notify(
            $toUserId,
            $userId,
            'message',
            $username . ' 给你发了一条私信',
            mb_substr($content, 0, 50),
            'message',
            0
        );

        \Core\Cache::set($floodKey, time(), 10);
        $this->success('发送成功');
    }

    /**
     * 撤回消息（仅限发送者，2分钟内）
     */
    public function recall(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $msgId = (int)($_POST['message_id'] ?? 0);

        if ($msgId <= 0) {
            $this->error('参数错误');
            return;
        }

        $msg = Database::fetchOne(
            "SELECT id, from_user_id, created_at, is_recalled FROM messages WHERE id = ?",
            [$msgId]
        );

        if (!$msg) {
            $this->error('消息不存在');
            return;
        }

        if ((int)$msg['from_user_id'] !== $userId) {
            $this->error('只能撤回自己的消息');
            return;
        }

        if (!empty($msg['is_recalled'])) {
            $this->error('消息已撤回');
            return;
        }

        // 2分钟内可撤回
        if (time() - (int)$msg['created_at'] > 120) {
            $this->error('超过2分钟无法撤回');
            return;
        }

        Database::useMaster();
        try {
            Database::execute("UPDATE messages SET is_recalled = 1 WHERE id = ?", [$msgId]);
        } finally {
            Database::restoreReadWrite();
        }

        $this->success('已撤回');
    }
}
