<?php
/**
 * 通知服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class NotificationSvc
{
    /**
     * 发送通知
     * 同时递增 users 表的 unread_notifications 字段，避免每次 COUNT
     */
    public static function notify(int $userId, int $fromUserId, string $type, string $title, string $content, string $targetType, int $targetId): void
    {
        try {
            Database::beginTransaction();
            Database::execute(
                "INSERT INTO notifications (user_id, from_user_id, type, title, content, target_type, target_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, $fromUserId, $type, $title, $content, $targetType, $targetId, time()]
            );
            Database::execute(
                "UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?",
                [$userId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[NotificationSvc] ' . $e->getMessage());
        }
    }

    /**
     * 获取未读通知数（优先从 users 表读取，避免 COUNT）
     */
    public static function getUnreadCount(int $userId): int
    {
        return (int)Cache::get("unread_notif:{$userId}", function() use ($userId) {
            try {
                $row = Database::fetchOne(
                    "SELECT unread_notifications FROM users WHERE id = ?",
                    [$userId]
                );
                if ($row && isset($row['unread_notifications'])) {
                    return (int)$row['unread_notifications'];
                }
            } catch (\Throwable $e) {
                // 字段不存在时回退到 COUNT
            }
            $row = Database::fetchOne(
                "SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
            return (int)($row['c'] ?? 0);
        }, 15);
    }

    /**
     * 标记全部已读，同时清零 users 表计数（事务保证一致性）
     */
    public static function markAllRead(int $userId): void
    {
        try {
            Database::beginTransaction();
            Database::execute(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
            Database::execute(
                "UPDATE users SET unread_notifications = 0 WHERE id = ?",
                [$userId]
            );
            Database::commit();
            Cache::delete("unread_notif:{$userId}");
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[NotificationSvc] markAllRead failed for user {$userId}: " . $e->getMessage());
            throw new \RuntimeException('操作失败，请重试');
        }
    }

    /**
     * 标记单条已读（事务保证一致性）
     */
    public static function markOneRead(int $notificationId, int $userId): void
    {
        try {
            Database::beginTransaction();
            // 加锁查询防止并发重复扣减
            $notif = Database::fetchOne(
                "SELECT is_read FROM notifications WHERE id = ? AND user_id = ? FOR UPDATE",
                [$notificationId, $userId]
            );
            if ($notif && !(int)$notif['is_read']) {
                Database::execute(
                    "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
                    [$notificationId, $userId]
                );
                Database::execute(
                    "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                    [$userId]
                );
            }
            Database::commit();
            Cache::delete("unread_notif:{$userId}");
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[NotificationSvc] markOneRead failed for notification {$notificationId}: " . $e->getMessage());
            throw new \RuntimeException('操作失败，请重试');
        }
    }

    /**
     * 删除单条通知（事务保证一致性）
     */
    public static function delete(int $notificationId, int $userId): void
    {
        try {
            Database::beginTransaction();
            $notif = Database::fetchOne(
                "SELECT is_read FROM notifications WHERE id = ? AND user_id = ? FOR UPDATE",
                [$notificationId, $userId]
            );
            if (!$notif) {
                Database::commit();
                return;
            }
            // 如果是未读通知，递减计数
            if (!(int)$notif['is_read']) {
                Database::execute(
                    "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                    [$userId]
                );
            }
            Database::execute(
                "DELETE FROM notifications WHERE id = ? AND user_id = ?",
                [$notificationId, $userId]
            );
            Database::commit();
            Cache::delete("unread_notif:{$userId}");
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log("[NotificationSvc] delete failed for notification {$notificationId}: " . $e->getMessage());
        }
    }
}
