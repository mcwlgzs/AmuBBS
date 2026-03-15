<?php
/**
 * 用户关注服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class FollowSvc
{
    /**
     * 关注/取消关注
     */
    public static function toggle(int $userId, int $targetUserId): array
    {
        if ($userId === $targetUserId) {
            throw new \RuntimeException('不能关注自己');
        }

        // 黑名单检查：任一方拉黑则禁止关注
        if (BlacklistSvc::isEitherBlocked($userId, $targetUserId)) {
            throw new \RuntimeException('无法关注该用户');
        }

        // 验证目标用户存在
        $target = Database::fetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$targetUserId]);
        if (!$target) {
            throw new \RuntimeException('用户不存在');
        }

        Database::beginTransaction();

        try {
            // 加锁查询防止并发重复插入
            $existing = Database::fetchOne(
                "SELECT id FROM user_follows WHERE user_id = ? AND follow_user_id = ? FOR UPDATE",
                [$userId, $targetUserId]
            );

            if ($existing) {
                Database::execute("DELETE FROM user_follows WHERE id = ?", [$existing['id']]);
                Database::commit();
                Cache::delete("follow:{$userId}:{$targetUserId}");
                Cache::delete("follow:ing_count:{$userId}");
                Cache::delete("follow:er_count:{$targetUserId}");
                return ['followed' => false];
            }

            Database::execute(
                "INSERT INTO user_follows (user_id, follow_user_id, created_at) VALUES (?, ?, ?)",
                [$userId, $targetUserId, time()]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 通知放在事务外，避免长事务
        $userRow = Database::fetchOne("SELECT username FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        if ($userRow) {
            $username = $userRow['username'] ?? '用户';
            NotificationSvc::notify(
                $targetUserId, $userId, 'follow',
                $username . ' 关注了你', '',
                'user', $userId
            );
        }

        // 清除关注缓存
        Cache::delete("follow:{$userId}:{$targetUserId}");
        Cache::delete("follow:ing_count:{$userId}");
        Cache::delete("follow:er_count:{$targetUserId}");

        return ['followed' => true];
    }

    /**
     * 检查是否已关注（带缓存）
     */
    public static function isFollowing(int $userId, int $targetUserId): bool
    {
        return (bool)Cache::get("follow:{$userId}:{$targetUserId}", function() use ($userId, $targetUserId) {
            return Database::fetchOne(
                "SELECT id FROM user_follows WHERE user_id = ? AND follow_user_id = ?",
                [$userId, $targetUserId]
            ) ? 1 : 0;
        }, 300);
    }

    /**
     * 获取关注数（带缓存）
     */
    public static function getFollowingCount(int $userId): int
    {
        return (int)Cache::get("follow:ing_count:{$userId}", function() use ($userId) {
            $r = Database::fetchOne("SELECT COUNT(*) as c FROM user_follows WHERE user_id = ?", [$userId]);
            return (int)($r['c'] ?? 0);
        }, 300);
    }

    /**
     * 获取粉丝数（带缓存）
     */
    public static function getFollowerCount(int $userId): int
    {
        return (int)Cache::get("follow:er_count:{$userId}", function() use ($userId) {
            $r = Database::fetchOne("SELECT COUNT(*) as c FROM user_follows WHERE follow_user_id = ?", [$userId]);
            return (int)($r['c'] ?? 0);
        }, 300);
    }

    /**
     * 获取关注列表
     */
    public static function getFollowingList(int $userId, int $limit = 20): array
    {
        return Database::fetchAll("
            SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color, uf.created_at as followed_at
            FROM user_follows uf
            INNER JOIN users u ON uf.follow_user_id = u.id
            WHERE uf.user_id = ? AND u.deleted_at IS NULL
            ORDER BY uf.created_at DESC
            LIMIT ?
        ", [$userId, $limit]);
    }

    /**
     * 获取粉丝列表
     */
    public static function getFollowerList(int $userId, int $limit = 20): array
    {
        return Database::fetchAll("
            SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color, uf.created_at as followed_at
            FROM user_follows uf
            INNER JOIN users u ON uf.user_id = u.id
            WHERE uf.follow_user_id = ? AND u.deleted_at IS NULL
            ORDER BY uf.created_at DESC
            LIMIT ?
        ", [$userId, $limit]);
    }
}
