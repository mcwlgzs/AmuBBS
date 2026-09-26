<?php
/**
 * 用户黑名单模型
 *
 * 由原 App\Services\BlacklistSvc 迁移而来（其全部方法都是 user_blacklist 自身的
 * 取数与落库，没有额外编排，因此整体并入模型，不再保留一层转发）。
 *
 * 注意 toggle() 里的跨表操作：拉黑时要在**同一个事务**内删掉双向关注，
 * 否则会出现「已拉黑但仍互相关注」的中间状态，所以这段逻辑留在模型里，
 * 不能拆到控制器去。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Blacklist extends Model
{
    protected static string $table = 'user_blacklist';

    /**
     * 拉黑 / 取消拉黑
     *
     * @return array{blocked: bool}
     * @throws \RuntimeException 拉黑自己或目标不存在
     */
    public static function toggle(int $userId, int $targetUserId): array
    {
        if ($userId === $targetUserId) {
            throw new \RuntimeException('不能拉黑自己');
        }
        if (!User::exists($targetUserId)) {
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
                self::forgetPair($userId, $targetUserId);

                return ['blocked' => false];
            }

            // 拉黑
            Database::execute(
                "INSERT INTO user_blacklist (user_id, block_user_id, created_at) VALUES (?, ?, ?)",
                [$userId, $targetUserId, time()]
            );

            // 同一事务内取消双向关注，避免出现「已拉黑但仍在互相关注」的中间状态
            Database::execute(
                "DELETE FROM user_follows WHERE (user_id = ? AND follow_user_id = ?) OR (user_id = ? AND follow_user_id = ?)",
                [$userId, $targetUserId, $targetUserId, $userId]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        self::forgetPair($userId, $targetUserId);
        // 关注关系被连带删除，双向的关注/粉丝计数缓存也必须清掉
        Follow::forgetPairCaches($userId, $targetUserId);

        return ['blocked' => true];
    }

    /**
     * 是否已拉黑（带缓存）
     *
     * @param int $userId 拉黑者
     * @param int $targetUserId 被拉黑者
     */
    public static function isBlocked(int $userId, int $targetUserId): bool
    {
        if ($userId <= 0 || $targetUserId <= 0) {
            return false;
        }

        return (bool)Cache::get(self::cacheKey($userId, $targetUserId), function () use ($userId, $targetUserId) {
            return Database::fetchOne(
                "SELECT id FROM user_blacklist WHERE user_id = ? AND block_user_id = ?",
                [$userId, $targetUserId]
            ) ? 1 : 0;
        }, 300);
    }

    /**
     * 双向拉黑检查（任一方拉黑了对方）
     */
    public static function isEitherBlocked(int $userA, int $userB): bool
    {
        return self::isBlocked($userA, $userB) || self::isBlocked($userB, $userA);
    }

    /**
     * 黑名单列表
     */
    public static function getList(int $userId, int $limit = 20, int $offset = 0): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.signature, u.nickname_color,
                    ub.created_at as blocked_at
             FROM user_blacklist ub
             INNER JOIN users u ON ub.block_user_id = u.id
             WHERE ub.user_id = ? AND u.deleted_at IS NULL
             ORDER BY ub.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    /**
     * 黑名单人数
     */
    public static function getCount(int $userId): int
    {
        $row = Database::fetchOne("SELECT COUNT(*) as c FROM user_blacklist WHERE user_id = ?", [$userId]);

        return (int)($row['c'] ?? 0);
    }

    /**
     * 清除一对用户的双向拉黑缓存
     */
    public static function forgetPair(int $userId, int $targetUserId): void
    {
        Cache::delete(self::cacheKey($userId, $targetUserId));
        Cache::delete(self::cacheKey($targetUserId, $userId));
    }

    public static function cacheKey(int $userId, int $targetUserId): string
    {
        return "blacklist:{$userId}:{$targetUserId}";
    }
}
