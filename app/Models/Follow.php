<?php
/**
 * 用户关注模型
 *
 * 由原 App\Services\FollowSvc 迁移而来。
 *
 * 分层取舍：这里只放 user_follows 自身的取数与落库。
 * 「不能关注自己 / 黑名单拦截 / 关注后发通知」属于跨聚合的编排，
 * 留在调用方（Controller）里显式表达，而不是塞进模型里依赖别的 Service。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Follow extends Model
{
    protected static string $table = 'user_follows';

    /**
     * 关注 / 取消关注（只动关系表）
     *
     * @return bool true=已关注，false=已取消
     */
    public static function toggleRelation(int $userId, int $targetUserId): bool
    {
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
                self::forgetPairCaches($userId, $targetUserId);

                return false;
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

        self::forgetPairCaches($userId, $targetUserId);

        return true;
    }

    /**
     * 是否已关注（带缓存）
     */
    public static function isFollowing(int $userId, int $targetUserId): bool
    {
        return (bool)Cache::get(self::relationCacheKey($userId, $targetUserId), function () use ($userId, $targetUserId) {
            return Database::fetchOne(
                "SELECT id FROM user_follows WHERE user_id = ? AND follow_user_id = ?",
                [$userId, $targetUserId]
            ) ? 1 : 0;
        }, 300);
    }

    /**
     * 关注数（带缓存）
     */
    public static function getFollowingCount(int $userId): int
    {
        return (int)Cache::get(self::followingCountKey($userId), function () use ($userId) {
            $row = Database::fetchOne("SELECT COUNT(*) as c FROM user_follows WHERE user_id = ?", [$userId]);

            return (int)($row['c'] ?? 0);
        }, 300);
    }

    /**
     * 粉丝数（带缓存）
     */
    public static function getFollowerCount(int $userId): int
    {
        return (int)Cache::get(self::followerCountKey($userId), function () use ($userId) {
            $row = Database::fetchOne("SELECT COUNT(*) as c FROM user_follows WHERE follow_user_id = ?", [$userId]);

            return (int)($row['c'] ?? 0);
        }, 300);
    }

    /**
     * 关注列表
     */
    public static function getFollowingList(int $userId, int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color,
                    uf.created_at as followed_at
             FROM user_follows uf
             INNER JOIN users u ON uf.follow_user_id = u.id
             WHERE uf.user_id = ? AND u.deleted_at IS NULL
             ORDER BY uf.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 粉丝列表
     */
    public static function getFollowerList(int $userId, int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color,
                    uf.created_at as followed_at
             FROM user_follows uf
             INNER JOIN users u ON uf.user_id = u.id
             WHERE uf.follow_user_id = ? AND u.deleted_at IS NULL
             ORDER BY uf.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 清除这两个用户之间所有相关缓存
     *
     * 覆盖「双向关系 + 双方关注数/粉丝数」共 6 个 key。
     * 单向关注本来只需清 3 个，但多清几个无副作用，
     * 而拉黑会连带删除双向关注，少清就会留下脏数据。
     */
    public static function forgetPairCaches(int $userId, int $targetUserId): void
    {
        Cache::delete(self::relationCacheKey($userId, $targetUserId));
        Cache::delete(self::relationCacheKey($targetUserId, $userId));
        Cache::delete(self::followingCountKey($userId));
        Cache::delete(self::followingCountKey($targetUserId));
        Cache::delete(self::followerCountKey($userId));
        Cache::delete(self::followerCountKey($targetUserId));
    }

    public static function relationCacheKey(int $userId, int $targetUserId): string
    {
        return "follow:{$userId}:{$targetUserId}";
    }

    public static function followingCountKey(int $userId): string
    {
        return "follow:ing_count:{$userId}";
    }

    public static function followerCountKey(int $userId): string
    {
        return "follow:er_count:{$userId}";
    }

    /**
     * 删除与该用户相关的全部关注关系（双向，删用户级联用）
     */
    public static function deleteByUser(int $userId): int
    {
        Cache::delete(self::followingCountKey($userId));
        Cache::delete(self::followerCountKey($userId));

        return Database::execute(
            "DELETE FROM user_follows WHERE user_id = ? OR follow_user_id = ?",
            [$userId, $userId]
        );
    }
}
