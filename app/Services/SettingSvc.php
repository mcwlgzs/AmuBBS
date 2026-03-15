<?php
/**
 * 系统设置服务 - 统一读取设置（带缓存）
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class SettingSvc
{
    private static ?array $cache = null;

    /**
     * 获取所有设置（内存 + Redis 双缓存）
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            self::$cache = Cache::getStale('settings:all', function () {
                $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
                $settings = [];
                foreach ($rows as $row) {
                    $settings[$row['key']] = $row['value'];
                }
                return $settings;
            }, 300);
        } catch (\Throwable $e) {
            // 未安装时 DB/表不存在，返回空数组使调用方使用默认值
            self::$cache = [];
        }

        return self::$cache;
    }

    /**
     * 获取单个设置值
     */
    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    /**
     * 获取布尔设置
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, '');
        if ($val === '') {
            return $default;
        }
        return $val === '1' || $val === 'true';
    }

    /**
     * 获取整数设置
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, '');
        if ($val === '') {
            return $default;
        }
        return (int)$val;
    }

    /**
     * 清除缓存（设置保存后调用）
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        Cache::delete('settings:all');
    }
}
