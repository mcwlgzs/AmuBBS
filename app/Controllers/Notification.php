<?php
/**
 * 通知控制器
 */

namespace App\Controllers;

use Core\Database;
use Core\Cache;

class Notification extends Base
{
    /**
     * 通知列表页
     */
    public function index(): void
    {
        $this->requireLogin();

        $userId = $this->getCurrentUserId();
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $notifications = Database::fetchAll("
            SELECT n.*, u.username as from_username
            FROM notifications n
            LEFT JOIN users u ON n.from_user_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $perPage, $offset]);

        // 合并 total 和 unreadCount 为一条 SQL，减少 2 次查询为 1 次
        $counts = Database::fetchOne(
            "SELECT COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread FROM notifications WHERE user_id = ?",
            [$userId]
        );
        $total = (int)($counts['total'] ?? 0);
        $unreadCount = (int)($counts['unread'] ?? 0);

        $totalPages = max(1, (int)ceil($total / $perPage));

        // 标记当前页为已读，并同步扣减 users.unread_notifications 计数器
        if (!empty($notifications)) {
            $ids = array_map('intval', array_column($notifications, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // 统计当前页中实际未读的通知数量
            $unreadOnPage = 0;
            foreach ($notifications as $n) {
                if (empty($n['is_read'])) {
                    $unreadOnPage++;
                }
            }

            Database::beginTransaction();
            try {
                Database::execute(
                    "UPDATE notifications SET is_read = 1 WHERE id IN ({$placeholders}) AND user_id = ? AND is_read = 0",
                    array_merge($ids, [$userId])
                );
                if ($unreadOnPage > 0) {
                    Database::execute(
                        "UPDATE users SET unread_notifications = CASE WHEN unread_notifications >= ? THEN unread_notifications - ? ELSE 0 END WHERE id = ?",
                        [$unreadOnPage, $unreadOnPage, $userId]
                    );
                    Cache::delete("unread_notif:{$userId}");
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                error_log('[Notification] mark-read failed: ' . $e->getMessage());
            }
        }

        $this->render('notification', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * 获取未读通知数（JSON，供前端轮询）
     * ?detail=1 时同时返回最新 5 条未读通知
     */
    public function unreadCount(): void
    {
        if (!isset($_SESSION['user_id'])) {
            $this->json(['count' => 0]);
            return;
        }

        $userId = (int)$_SESSION['user_id'];
        $count = \App\Services\NotificationSvc::getUnreadCount($userId);

        $result = ['count' => $count];

        if (!empty($_GET['detail'])) {
            $items = Database::fetchAll("
                SELECT n.id, n.type, n.title, n.content, n.target_type, n.target_id, n.created_at,
                       u.username as from_username, u.avatar as from_avatar
                FROM notifications n
                LEFT JOIN users u ON n.from_user_id = u.id
                WHERE n.user_id = ? AND n.is_read = 0
                ORDER BY n.created_at DESC
                LIMIT 5
            ", [$userId]);
            $result['items'] = $items;
        }

        $this->json($result);
    }

    /**
     * 全部标记已读
     */
    public function readAll(): void
    {
        $this->requireLogin();

        \App\Services\NotificationSvc::markAllRead($this->getCurrentUserId());

        $this->success('已全部标记为已读');
    }

    /**
     * 单条标记已读
     */
    public function readOne(): void
    {
        $this->requireLogin();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }
        \App\Services\NotificationSvc::markOneRead($id, $this->getCurrentUserId());
        $this->success('已标记为已读');
    }

    /**
     * 删除单条通知
     */
    public function deleteOne(): void
    {
        $this->requireLogin();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }
        \App\Services\NotificationSvc::delete($id, $this->getCurrentUserId());
        $this->success('已删除');
    }

    /**
     * 删除所有已读通知
     */
    public function deleteRead(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        try {
            $result = Database::execute(
                "DELETE FROM notifications WHERE user_id = ? AND is_read = 1",
                [$userId]
            );

            $this->success('已删除所有已读通知');
        } catch (\Throwable $e) {
            error_log('[Notification] deleteRead failed: ' . $e->getMessage());
            $this->error('删除失败');
        }
    }
}
