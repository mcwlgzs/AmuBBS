<?php
/**
 * 动态点赞模型
 *
 * 由原 App\Services\MomentSvc 迁移而来（其中 moment_likes 表的数据访问部分）。
 *
 * toggle() 里「关系行」和「moments.likes 计数」必须同事务更新，
 * 否则计数会永久跑偏，所以事务留在模型里。
 */

namespace App\Models;

use Core\Database;

class MomentLike extends Model
{
    protected static string $table = 'moment_likes';

    /**
     * 点赞 / 取消点赞
     *
     * @return bool true=已点赞，false=已取消
     */
    public static function toggle(int $momentId, int $userId): bool
    {
        Database::beginTransaction();

        try {
            // 加锁查询，防止并发重复点赞
            $existing = Database::fetchOne(
                "SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ? FOR UPDATE",
                [$momentId, $userId]
            );

            if ($existing) {
                Database::execute("DELETE FROM moment_likes WHERE id = ?", [$existing['id']]);
                Moment::adjustLikeCount($momentId, -1);
                $liked = false;
            } else {
                Database::execute(
                    "INSERT INTO moment_likes (moment_id, user_id, created_at) VALUES (?, ?, ?)",
                    [$momentId, $userId, time()]
                );
                Moment::adjustLikeCount($momentId, 1);
                $liked = true;
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $liked;
    }

    public static function isLiked(int $momentId, int $userId): bool
    {
        return Database::fetchOne(
            "SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ?",
            [$momentId, $userId]
        ) !== null;
    }

    /**
     * 批量取「当前用户已点赞」的动态 ID（列表页用，避免逐条查询）
     *
     * @param int[] $momentIds
     * @return int[] 已点赞的 moment_id
     */
    public static function likedMomentIds(array $momentIds, int $userId): array
    {
        if (empty($momentIds) || $userId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($momentIds), '?'));
        $rows = Database::fetchAll(
            "SELECT moment_id FROM moment_likes
             WHERE moment_id IN ({$placeholders}) AND user_id = ?",
            array_merge($momentIds, [$userId])
        );

        return array_map('intval', array_column($rows, 'moment_id'));
    }
}
