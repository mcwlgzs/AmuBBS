<?php
/**
 * 用户黑名单服务（类似 XiunoBBS 黑名单）
 * 拉黑后：禁止对方发私信、回复你的帖子、评论你的动态；自动取消互相关注
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class BlacklistSvc
{
    /**
     * 拉黑/取消拉黑（toggle）
     */
    public static function toggle(int $userId, int $targetUserId): array
    {
        if ($userId === $targetUserId) {
            throw new \RuntimeException('不能拉黑自己');
        }

        // 验证目标用户存在
        $target = Database::fetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$targetUserId]);
        if (!$target) {
            throw new \RuntimeException('用户不存在');
        }

        Database::beginTransaction();

        try {
            $existing = Database::fetchOne(
                "SELECT id FROM user_blacklist WHERE user_id = ? AND block_user_id = ? FOR UPDATE",
                [$userId, $targetUserId]
            );

            if ($existing) {
                // 取消拉黑
                Database::execute("DELETE FROM user_blacklist WHERE id = ?", [$existing['id']]);
                Database::commit();
                self::clearCache($userId, $targetUserId);
                return ['blocked' => false];
            }

            // 拉黑
            Database::execute(
                "INSERT INTO user_blacklist (user_id, block_user_id, created_at) VALUES (?, ?, ?)",
                [$userId, $targetUserId, time()]
            );

            // 拉黑时自动取消双向关注
            Database::execute(
                "DELETE FROM user_follows WHERE (user_id = ? AND follow_user_id = ?) OR (user_id = ? AND follow_user_id = ?)",
                [$userId, $targetUserId, $targetUserId, $userId]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 清除关注相关缓存
        self::clearCache($userId, $targetUserId);
        Cache::delete("follow:{$userId}:{$targetUserId}");
        Cache::delete("follow:{$targetUserId}:{$userId}");
        Cache::delete("follow:ing_count:{$userId}");
        Cache::delete("follow:ing_count:{$targetUserId}");
        Cache::delete("follow:er_count:{$userId}");
        Cache::delete("follow:er_count:{$targetUserId}");

        return ['blocked' => true];
    }

    /**
     * 检查是否已拉黑（带缓存）
     * @param int $userId 拉黑者
     * @param int $targetUserId 被拉黑者
     */
    public static function isBlocked(int $userId, int $targetUserId): bool
    {
        if ($userId <= 0 || $targetUserId <= 0) {
            return false;
        }
        return (bool)Cache::get("blacklist:{$userId}:{$targetUserId}", function() use ($userId, $targetUserId) {
            return Database::fetchOne(
                "SELECT id FROM user_blacklist WHERE user_id = ? AND block_user_id = ?",
                [$userId, $targetUserId]
            ) ? 1 : 0;
        }, 300);
    }

    /**
     * 检查双向拉黑（任一方拉黑了对方）
     */
    public static function isEitherBlocked(int $userA, int $userB): bool
    {
        return self::isBlocked($userA, $userB) || self::isBlocked($userB, $userA);
    }

    /**
     * 获取黑名单列表
     */
    public static function getList(int $userId, int $limit = 20, int $offset = 0): array
    {
        return Database::fetchAll("
            SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color, ub.created_at as blocked_at
            FROM user_blacklist ub
            INNER JOIN users u ON ub.block_user_id = u.id
            WHERE ub.user_id = ? AND u.deleted_at IS NULL
            ORDER BY ub.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $limit, $offset]);
    }

    /**
     * 获取黑名单总数
     */
    public static function getCount(int $userId): int
    {
        $r = Database::fetchOne("SELECT COUNT(*) as c FROM user_blacklist WHERE user_id = ?", [$userId]);
        return (int)($r['c'] ?? 0);
    }

    /**
     * 清除缓存
     */
    private static function clearCache(int $userId, int $targetUserId): void
    {
        Cache::delete("blacklist:{$userId}:{$targetUserId}");
        Cache::delete("blacklist:{$targetUserId}:{$userId}");
    }
}
