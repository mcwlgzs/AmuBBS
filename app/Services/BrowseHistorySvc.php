<?php
/**
 * 浏览历史服务
 */

namespace App\Services;

use App\Models\BrowseHistory;
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
            BrowseHistory::record($userId, $threadId, time());

            // 浏览后清除缓存，下次加载侧边栏时刷新
            Cache::delete("browse_history:{$userId}:8");
            Cache::delete("browse_history:{$userId}:10");

            // 1% 概率清理多余记录，避免每次都 COUNT
            if (mt_rand(1, 100) === 1) {
                // 取第100条的 id 作为阈值，避免 created_at 时间戳冲突导致误删
                $cutoff = BrowseHistory::cutoffId($userId, 100);
                if ($cutoff !== null) {
                    BrowseHistory::pruneBefore($userId, $cutoff);
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
            return BrowseHistory::listForUser($userId, $limit);
        }, 30);
    }

    /**
     * 清空浏览历史
     */
    public static function clear(int $userId): void
    {
        BrowseHistory::clearForUser($userId);
    }
}
