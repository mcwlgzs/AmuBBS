<?php
/**
 * 系统设置服务 - 统一读取设置（带缓存）
 */

namespace App\Services;

use App\Models\Setting;
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
                return Setting::all();
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
     * 批量写入设置（后台设置页用），写完自动失效缓存
     *
     * @param array<string, string> $values
     */
    public static function setMany(array $values): void
    {
        Setting::setMany($values);
        self::clearCache();
    }

    /**
     * 按前缀读取设置（带缓存的全量数据里筛，避免再查一次库）
     */
    public static function allWithPrefix(string $prefix): array
    {
        $all = self::all();
        $out = [];
        foreach ($all as $key => $value) {
            if (str_starts_with((string)$key, $prefix)) {
                $out[$key] = $value;
            }
        }

        return $out;
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
