<?php
/**
 * IP 黑名单服务
 */

namespace App\Services;

use App\Models\IpBlacklist;
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
        IpBlacklist::upsert($ip, $reason, $expireAt);
        self::clearCache();
    }

    /**
     * 从黑名单移除 IP
     */
    public static function remove(string $ip): void
    {
        IpBlacklist::deleteByIp($ip);
        self::clearCache();
    }

    /**
     * 获取黑名单 hashmap（ip => expire_at，带缓存）
     */
    private static function getBlacklistMap(): array
    {
        return Cache::getStale('ip_blacklist:map', function () {
            return IpBlacklist::activeMap();
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
