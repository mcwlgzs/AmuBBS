<?php
/**
 * IP 访问频率限制服务（防灌水）
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class IpAccessSvc
{
    /**
     * 检查 IP 是否超过操作限额
     * @throws \RuntimeException 超限时抛出
     */
    public static function check(string $ip, string $action): void
    {
        $limits = self::getLimits();
        $key = "ip_limit_{$action}";
        $limit = (int)($limits[$key] ?? 0);
        if ($limit <= 0) return; // 0 = 不限制

        $count = self::getCount($ip, $action);
        if ($count >= $limit) {
            $labels = [
                'thread' => '发帖',
                'post' => '回帖',
                'register' => '注册',
                'upload' => '上传',
            ];
            $label = $labels[$action] ?? $action;
            throw new \RuntimeException("今日{$label}次数已达上限（{$limit}次），请明天再试");
        }
    }

    /**
     * 增加计数
     */
    public static function increment(string $ip, string $action): void
    {
        $today = date('Y-m-d');
        $now = time();

        try {
            // 使用 INSERT ... ON DUPLICATE KEY UPDATE 原子操作
            Database::execute(
                "INSERT INTO ip_access_logs (ip, action, count, date, updated_at) VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE count = count + 1, updated_at = VALUES(updated_at)",
                [$ip, $action, $today, $now]
            );
            // 清除缓存使下次 getCount 读取最新值
            Cache::delete("ipaccess:{$ip}:{$action}:{$today}");
        } catch (\Throwable $e) {
            // 表不存在时静默失败（错误码 1146），其他异常记录日志
            if (!str_contains($e->getMessage(), '1146')) {
                error_log('[IpAccessSvc] increment failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * 检查并增加（原子递增后检查，避免 TOCTOU 竞态）
     */
    public static function checkAndIncrement(string $ip, string $action): void
    {
        $limits = self::getLimits();
        $key = "ip_limit_{$action}";
        $limit = (int)($limits[$key] ?? 0);
        if ($limit <= 0) return;

        // 先原子递增
        self::increment($ip, $action);

        // 从 DB 读取递增后的最新值（increment 已清除缓存）
        $today = date('Y-m-d');
        try {
            $row = Database::fetchOne(
                "SELECT count FROM ip_access_logs WHERE ip = ? AND action = ? AND date = ?",
                [$ip, $action, $today]
            );
            $count = (int)($row['count'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[IpAccessSvc] checkAndIncrement query failed: ' . $e->getMessage());
            return;
        }

        if ($count > $limit) {
            $labels = [
                'thread' => '发帖',
                'post' => '回帖',
                'register' => '注册',
                'upload' => '上传',
            ];
            $label = $labels[$action] ?? $action;
            throw new \RuntimeException("今日{$label}次数已达上限（{$limit}次），请明天再试");
        }
    }

    /**
     * 获取当日计数
     */
    public static function getCount(string $ip, string $action): int
    {
        $today = date('Y-m-d');
        $cacheKey = "ipaccess:{$ip}:{$action}:{$today}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return (int)$cached;

        try {
            $row = Database::fetchOne(
                "SELECT count FROM ip_access_logs WHERE ip = ? AND action = ? AND date = ?",
                [$ip, $action, $today]
            );
            $count = (int)($row['count'] ?? 0);
            Cache::set($cacheKey, $count, 60);
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取限额配置
     */
    private static function getLimits(): array
    {
        return Cache::get('settings:ip_limits', function () {
            $limits = [];
            try {
                $rows = Database::fetchAll(
                    "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'ip_limit_%'"
                );
                foreach ($rows as $row) {
                    $limits[$row['key']] = $row['value'];
                }
            } catch (\Throwable $e) {
                error_log('[IpAccessSvc] loadLimits failed: ' . $e->getMessage());
            }
            return $limits;
        }, 300);
    }

    /**
     * 清理过期记录（由 CronSvc 调用）
     */
    public static function cleanOldLogs(int $keepDays = 7): int
    {
        try {
            $cutoff = date('Y-m-d', strtotime("-{$keepDays} days"));
            return Database::execute("DELETE FROM ip_access_logs WHERE date < ?", [$cutoff]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
