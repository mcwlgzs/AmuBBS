<?php
/**
 * IP 黑名单服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class IpBlacklistService
{
    /**
     * 检查 IP 是否在黑名单中（O(1) hashmap 查找）
     */
    public static function isBlocked(string $ip): bool
    {
        $map = self::getBlacklistMap();
        if (!isset($map[$ip])) {
            return false;
        }
        // 检查是否过期
        $expireAt = (int)$map[$ip];
        if ($expireAt > 0 && $expireAt < time()) {
            return false;
        }
        return true;
    }

    /**
     * 添加 IP 到黑名单
     */
    public static function add(string $ip, string $reason = '', int $expireAt = 0): void
    {
        Database::execute(
            "INSERT INTO ip_blacklist (ip, reason, expire_at, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), expire_at = VALUES(expire_at), created_at = VALUES(created_at)",
            [$ip, $reason, $expireAt, time()]
        );

        self::clearCache();
    }

    /**
     * 从黑名单移除 IP
     */
    public static function remove(string $ip): void
    {
        Database::execute("DELETE FROM ip_blacklist WHERE ip = ?", [$ip]);
        self::clearCache();
    }

    /**
     * 获取黑名单 hashmap（ip => expire_at，带缓存）
     */
    private static function getBlacklistMap(): array
    {
        return Cache::getStale('ip_blacklist:map', function () {
            $now = time();
            $rows = Database::fetchAll(
                "SELECT ip, expire_at FROM ip_blacklist WHERE expire_at = 0 OR expire_at >= ?",
                [$now]
            );
            $map = [];
            foreach ($rows as $row) {
                $map[$row['ip']] = $row['expire_at'];
            }
            return $map;
        }, 300);
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        Cache::delete('ip_blacklist:all');
        Cache::delete('ip_blacklist:map');
    }
}
