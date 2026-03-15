<?php
/**
 * 全站运行时统计缓存
 * 避免每次请求都执行 COUNT(*) 查询
 *
 * 优化：采用内存累积 + shutdown 批量写入模式
 * 参考 Xiuno BBS 的 register_shutdown_function 设计
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class RuntimeSvc
{
    private static ?array $runtime = null;

    /** 本次请求的待写入增量变更 */
    private static array $pendingChanges = [];

    /** shutdown 回调是否已注册 */
    private static bool $shutdownRegistered = false;

    /**
     * 获取全站统计（带缓存）
     */
    public static function getStats(): array
    {
        if (self::$runtime !== null) {
            return self::$runtime;
        }

        $base = Cache::getStale('runtime:stats', function () {
            return self::buildStats();
        }, 300);

        // 合并原子计数器增量
        $counterKeys = ['runtime:c:users', 'runtime:c:threads', 'runtime:c:posts', 'runtime:c:forums'];
        Cache::preload($counterKeys);
        foreach (['users', 'threads', 'posts', 'forums'] as $field) {
            $delta = (int)Cache::get("runtime:c:{$field}");
            if ($delta !== 0 && isset($base[$field])) {
                $base[$field] = max(0, $base[$field] + $delta);
            }
        }

        self::$runtime = $base;
        return self::$runtime;
    }

    /**
     * 获取今日统计（带缓存，60秒刷新，单条 SQL）
     */
    public static function getTodayStats(): array
    {
        return Cache::getStale('runtime:today', function () {
            $todayStart = strtotime('today');
            $row = Database::fetchOne("
                SELECT
                    (SELECT COUNT(*) FROM threads WHERE created_at >= ? AND deleted_at IS NULL) as threads,
                    (SELECT COUNT(*) FROM posts WHERE created_at >= ? AND deleted_at IS NULL) as posts,
                    (SELECT COUNT(*) FROM users WHERE created_at >= ? AND deleted_at IS NULL) as users
            ", [$todayStart, $todayStart, $todayStart]);
            return [
                'threads' => (int)($row['threads'] ?? 0),
                'posts' => (int)($row['posts'] ?? 0),
                'users' => (int)($row['users'] ?? 0),
            ];
        }, 60);
    }

    /**
     * 获取在线人数（委托给 OnlineSvc，避免重复查询 sessions 表）
     */
    public static function getOnlineCount(): int
    {
        return \App\Services\OnlineSvc::getOnlineCount();
    }

    /**
     * 从数据库重新构建统计（单条 SQL）
     */
    private static function buildStats(): array
    {
        $row = Database::fetchOne("
            SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as users,
                (SELECT COUNT(*) FROM threads WHERE deleted_at IS NULL) as threads,
                (SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL) as posts,
                (SELECT COUNT(*) FROM forums WHERE deleted_at IS NULL) as forums
        ");
        return [
            'users' => (int)($row['users'] ?? 0),
            'threads' => (int)($row['threads'] ?? 0),
            'posts' => (int)($row['posts'] ?? 0),
            'forums' => (int)($row['forums'] ?? 0),
        ];
    }

    /**
     * 增量更新（内存累积，请求结束时批量写入）
     */
    public static function increment(string $key, int $delta = 1): void
    {
        // 累积到 pendingChanges
        self::$pendingChanges[$key] = (self::$pendingChanges[$key] ?? 0) + $delta;

        // 先注册 shutdown 回调，确保即使 getStats 失败也能在请求结束时写入
        self::registerShutdown();

        // 同步更新内存中的 runtime（保证本次请求内读取一致）
        try {
            $stats = self::getStats();
            if (isset($stats[$key])) {
                $stats[$key] += $delta;
                self::$runtime = $stats;
            }
        } catch (\Throwable $e) {
            error_log('[RuntimeSvc] increment getStats failed: ' . $e->getMessage());
        }
    }

    /**
     * 减量更新
     */
    public static function decrement(string $key, int $delta = 1): void
    {
        self::increment($key, -$delta);
    }

    /**
     * 注册 shutdown 回调
     */
    private static function registerShutdown(): void
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'save']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Shutdown 回调：批量写入所有待保存的变更
     * 使用独立原子计数器 key（runtime:c:threads 等），无锁、无丢失
     * getStats() 读取时合并基础值 + 计数器增量
     */
    public static function save(): void
    {
        if (empty(self::$pendingChanges)) {
            return;
        }

        try {
            // 构建原子递增 map：runtime:c:threads => delta, runtime:c:posts => delta ...
            $increments = [];
            foreach (self::$pendingChanges as $key => $delta) {
                if ($delta !== 0) {
                    $increments["runtime:c:{$key}"] = $delta;
                }
            }

            if (!empty($increments)) {
                Cache::batchIncrement($increments);
            }

            self::$pendingChanges = [];
        } catch (\Throwable $e) {
            error_log('[RuntimeSvc::save] ' . $e->getMessage());
        }
    }

    /**
     * 强制刷新缓存
     */
    public static function refresh(): void
    {
        self::$runtime = null;
        self::$pendingChanges = [];
        Cache::delete('runtime:stats');
        Cache::delete('runtime:today');
        // 清除原子计数器
        foreach (['users', 'threads', 'posts', 'forums'] as $field) {
            Cache::delete("runtime:c:{$field}");
        }
    }
}
