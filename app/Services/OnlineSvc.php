<?php
/**
 * 在线用户服务
 * 基于 session 表统计在线用户，支持按版块统计
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class OnlineSvc
{
    /**
     * 活跃阈值（秒）：15分钟内视为在线
     */
    private const ACTIVE_THRESHOLD = 900;

    /**
     * 获取在线人数（复用 getSummary 缓存，不再单独查询）
     */
    public static function getOnlineCount(): int
    {
        $summary = self::getSummary();
        return $summary['total'] ?? 0;
    }

    /**
     * 获取在线用户列表
     */
    public static function getOnlineUsers(int $limit = 20): array
    {
        return Cache::getStale("online:users:{$limit}", function () use ($limit) {
            $threshold = time() - self::ACTIVE_THRESHOLD;
            return Database::fetchAll("
                SELECT DISTINCT s.user_id, u.username, u.avatar, s.last_activity,
                       s.current_forum_id, s.current_url
                FROM sessions s
                INNER JOIN users u ON s.user_id = u.id
                WHERE s.user_id > 0 AND s.last_activity >= ? AND u.deleted_at IS NULL
                ORDER BY s.last_activity DESC
                LIMIT ?
            ", [$threshold, $limit]);
        }, 60);
    }

    /**
     * 获取在线统计摘要（用于首页/后台）
     * 合并为单条 SQL，减少 2 次查询为 1 次
     */
    public static function getSummary(): array
    {
        return Cache::getStale('online:summary', function () {
            $threshold = time() - self::ACTIVE_THRESHOLD;

            $row = Database::fetchOne(
                "SELECT COUNT(*) as total, COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) as members FROM sessions WHERE last_activity >= ?",
                [$threshold]
            );
            $total = (int)($row['total'] ?? 0);
            $members = (int)($row['members'] ?? 0);

            return [
                'total' => $total,
                'members' => $members,
                'guests' => max(0, $total - $members),
            ];
        }, 60);
    }

    /**
     * 获取指定板块的在线人数
     */
    public static function getForumOnlineCount(int $forumId): int
    {
        return (int)Cache::get("online:forum:{$forumId}", function () use ($forumId) {
            $threshold = time() - self::ACTIVE_THRESHOLD;
            return (int)(Database::fetchOne(
                "SELECT COUNT(*) as c FROM sessions WHERE current_forum_id = ? AND last_activity >= ?",
                [$forumId, $threshold]
            )['c'] ?? 0);
        }, 60);
    }

    /**
     * 获取指定板块的在线用户列表
     */
    public static function getForumOnlineUsers(int $forumId, int $limit = 20): array
    {
        return Cache::get("online:forum_users:{$forumId}:{$limit}", function () use ($forumId, $limit) {
            $threshold = time() - self::ACTIVE_THRESHOLD;
            return Database::fetchAll("
                SELECT DISTINCT s.user_id, u.username, u.avatar, s.last_activity
                FROM sessions s
                INNER JOIN users u ON s.user_id = u.id
                WHERE s.current_forum_id = ? AND s.user_id > 0
                  AND s.last_activity >= ? AND u.deleted_at IS NULL
                ORDER BY s.last_activity DESC
                LIMIT ?
            ", [$forumId, $threshold, $limit]);
        }, 60);
    }

    /**
     * 更新当前 session 的浏览上下文（节流：30秒内不重复写入）
     * 使用 UPSERT 确保 session 行存在
     */
    public static function updateContext(int $forumId = 0, string $url = ''): void
    {
        $sessionId = session_id();
        if (empty($sessionId)) {
            return;
        }

        // 节流：30秒内不重复更新
        $now = time();
        $lastUpdate = $_SESSION['_ctx_updated'] ?? 0;
        if ($now - $lastUpdate < 30) {
            return;
        }

        $url = mb_substr($url, 0, 255);
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        try {
            Database::execute("
                INSERT INTO sessions (id, user_id, ip, user_agent, last_activity, current_forum_id, current_url)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    ip = VALUES(ip),
                    last_activity = VALUES(last_activity),
                    current_forum_id = VALUES(current_forum_id),
                    current_url = VALUES(current_url)
            ", [$sessionId, $userId, $ip, $ua, $now, $forumId, $url]);
            $_SESSION['_ctx_updated'] = $now;
        } catch (\Throwable $e) {
            error_log('[OnlineSvc] updateContext failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取在线游客数
     */
    public static function getGuestCount(): int
    {
        $summary = self::getSummary();
        return $summary['guests'] ?? 0;
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        Cache::delete('online:count');
        Cache::delete('online:summary');
        Cache::delete('online:users:10');
        Cache::delete('online:users:20');
    }
}
