<?php
/**
 * 浏览历史服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class BrowseHistorySvc
{
    /**
     * 记录浏览（优化：去掉每次 COUNT，改为 1% 概率清理）
     */
    public static function record(int $userId, int $threadId): void
    {
        if ($userId <= 0 || $threadId <= 0) return;

        try {
            // REPLACE INTO 会更新已有记录的时间
            Database::execute("
                REPLACE INTO browse_history (user_id, thread_id, created_at)
                VALUES (?, ?, ?)
            ", [$userId, $threadId, time()]);

            // 浏览后清除缓存，下次加载侧边栏时刷新
            Cache::delete("browse_history:{$userId}:8");
            Cache::delete("browse_history:{$userId}:10");

            // 1% 概率清理多余记录，避免每次都 COUNT
            if (mt_rand(1, 100) === 1) {
                // 取第100条的 id 作为阈值，避免 created_at 时间戳冲突导致误删
                $cutoff = Database::fetchOne(
                    "SELECT id FROM browse_history WHERE user_id = ? ORDER BY id DESC LIMIT 1 OFFSET 100",
                    [$userId]
                );
                if ($cutoff) {
                    Database::execute(
                        "DELETE FROM browse_history WHERE user_id = ? AND id < ?",
                        [$userId, $cutoff['id']]
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[BrowseHistorySvc] record failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取浏览历史
     */
    public static function getHistory(int $userId, int $limit = 10): array
    {
        return Cache::get("browse_history:{$userId}:{$limit}", function () use ($userId, $limit) {
            return Database::fetchAll("
                SELECT bh.created_at as viewed_at, t.id, t.title, t.views, t.reply_count, t.user_id, u.username, u.nickname_color
                FROM browse_history bh
                INNER JOIN threads t ON bh.thread_id = t.id
                LEFT JOIN users u ON t.user_id = u.id
                WHERE bh.user_id = ? AND t.deleted_at IS NULL
                ORDER BY bh.created_at DESC
                LIMIT ?
            ", [$userId, $limit]);
        }, 30);
    }

    /**
     * 清空浏览历史
     */
    public static function clear(int $userId): void
    {
        Database::execute("DELETE FROM browse_history WHERE user_id = ?", [$userId]);
    }
}
