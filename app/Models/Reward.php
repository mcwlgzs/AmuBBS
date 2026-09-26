<?php
/**
 * 打赏记录模型
 *
 * 由原 App\Services\RewardSvc 的读查询迁移而来。
 *
 * 分层说明：打赏（扣发送方积分 → 加接收方积分 → 两条流水 → 打赏记录 → 通知）
 * 是一个跨 4 张表的领域用例，仍留在 App\Services\RewardSvc；
 * 这里只负责 rewards 表自身的取数与写入。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Reward extends Model
{
    protected static string $table = 'rewards';

    /** 打赏列表缓存时长 */
    private const CACHE_TTL = 300;

    /**
     * 某对象的打赏列表（带缓存）
     */
    public static function getRewards(string $targetType, int $targetId, int $limit = 10): array
    {
        return Cache::get(self::listCacheKey($targetType, $targetId, $limit), function () use ($targetType, $targetId, $limit) {
            return Database::fetchAll(
                "SELECT r.*, u.username, u.nickname, u.avatar, u.nickname_color
                 FROM rewards r
                 LEFT JOIN users u ON r.from_user_id = u.id
                 WHERE r.target_type = ? AND r.target_id = ?
                 ORDER BY r.created_at DESC
                 LIMIT ?",
                [$targetType, $targetId, $limit]
            );
        }, self::CACHE_TTL);
    }

    /**
     * 某对象的打赏笔数与总额（带缓存）
     *
     * @return array{count: int, total: int}
     */
    public static function getTotalRewards(string $targetType, int $targetId): array
    {
        return Cache::get(self::totalCacheKey($targetType, $targetId), function () use ($targetType, $targetId) {
            $row = Database::fetchOne(
                "SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
                 FROM rewards
                 WHERE target_type = ? AND target_id = ?",
                [$targetType, $targetId]
            );

            return [
                'count' => (int)($row['count'] ?? 0),
                'total' => (int)($row['total'] ?? 0),
            ];
        }, self::CACHE_TTL);
    }

    /**
     * 写一条打赏记录，返回打赏 id
     */
    public static function create(
        int $fromUserId,
        int $toUserId,
        int $amount,
        string $targetType,
        int $targetId,
        string $message,
        int $createdAt
    ): int {
        Database::execute(
            "INSERT INTO rewards (from_user_id, to_user_id, amount, target_type, target_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$fromUserId, $toUserId, $amount, $targetType, $targetId, $message, $createdAt]
        );

        return Database::lastInsertId();
    }

    /**
     * 用户是否已打赏过该对象
     */
    public static function hasRewarded(int $userId, string $targetType, int $targetId): bool
    {
        return Database::fetchOneCached(
            "SELECT id FROM rewards
             WHERE from_user_id = ? AND target_type = ? AND target_id = ?
             LIMIT 1",
            [$userId, $targetType, $targetId],
            120
        ) !== null;
    }

    /**
     * 清除某对象的打赏缓存（打赏成功后调用）
     */
    public static function forgetTargetCaches(string $targetType, int $targetId): void
    {
        Cache::delete(self::totalCacheKey($targetType, $targetId));
        Cache::deletePattern("rewards:list:{$targetType}:{$targetId}:*");
    }

    public static function listCacheKey(string $targetType, int $targetId, int $limit): string
    {
        return "rewards:list:{$targetType}:{$targetId}:{$limit}";
    }

    public static function totalCacheKey(string $targetType, int $targetId): string
    {
        return "rewards:total:{$targetType}:{$targetId}";
    }
}
