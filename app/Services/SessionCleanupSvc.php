<?php
/**
 * 会话清理服务
 * 定期清理过期会话，防止 sessions 表无限增长
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class SessionCleanupSvc
{
    /**
     * 清理过期会话
     *
     * @param int $expireTime 过期时间（秒），默认 7200（2小时）
     * @return int 清理的会话数量
     */
    public static function cleanExpiredSessions(int $expireTime = 7200): int
    {
        $threshold = time() - $expireTime;

        try {
            $result = Database::execute(
                "DELETE FROM sessions WHERE last_activity < ?",
                [$threshold]
            );

            $count = $result->rowCount();

            if ($count > 0) {
                error_log("[SessionCleanup] Cleaned {$count} expired sessions (older than " . date('Y-m-d H:i:s', $threshold) . ")");
            }

            return $count;
        } catch (\Throwable $e) {
            error_log("[SessionCleanup] Failed to clean sessions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 清理指定用户的旧会话（保留最新的 N 个）
     *
     * @param int $userId 用户 ID
     * @param int $keepCount 保留数量，默认 3
     * @return int 清理的会话数量
     */
    public static function cleanUserOldSessions(int $userId, int $keepCount = 3): int
    {
        if ($userId <= 0 || $keepCount < 1) {
            return 0;
        }

        try {
            // 获取该用户最新的 N 个会话 ID
            $keepSessions = Database::fetchAll(
                "SELECT id FROM sessions WHERE user_id = ? ORDER BY last_activity DESC LIMIT ?",
                [$userId, $keepCount]
            );

            if (empty($keepSessions)) {
                return 0;
            }

            $keepIds = array_column($keepSessions, 'id');
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));

            $result = Database::execute(
                "DELETE FROM sessions WHERE user_id = ? AND id NOT IN ({$placeholders})",
                array_merge([$userId], $keepIds)
            );

            return $result->rowCount();
        } catch (\Throwable $e) {
            error_log("[SessionCleanup] Failed to clean user sessions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 获取会话统计信息
     *
     * @return array
     */
    public static function getSessionStats(): array
    {
        try {
            $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM sessions")['cnt'] ?? 0);
            $active = (int)(Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM sessions WHERE last_activity >= ?",
                [time() - 1800] // 30分钟内活跃
            )['cnt'] ?? 0);
            $loggedIn = (int)(Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM sessions WHERE user_id > 0"
            )['cnt'] ?? 0);

            return [
                'total' => $total,
                'active' => $active,
                'logged_in' => $loggedIn,
                'guest' => $total - $loggedIn,
            ];
        } catch (\Throwable $e) {
            error_log("[SessionCleanup] Failed to get session stats: " . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'logged_in' => 0,
                'guest' => 0,
            ];
        }
    }
}
