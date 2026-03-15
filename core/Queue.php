<?php
/**
 * 简易队列系统
 * Redis 可用时用 LPUSH/RPOP，不可用时降级 MySQL queue_jobs 表
 */

namespace Core;

class Queue
{
    /** Redis 是否可用 */
    private static ?bool $redisAvailable = null;

    /** Redis 实例 */
    private static ?\Redis $redis = null;

    /** Redis key 前缀 */
    private const PREFIX = 'queue:';

    /**
     * 推送任务到队列
     *
     * @param string $queue 队列名
     * @param array $data 任务数据
     * @param int $delay 延迟秒数（0 = 立即可用）
     */
    public static function push(string $queue, array $data, int $delay = 0): bool
    {
        $availableAt = time() + $delay;

        if (self::isRedisAvailable() && $delay === 0) {
            // Redis: 无延迟任务用 LPUSH
            $payload = json_encode([
                'data' => $data,
                'created_at' => time(),
            ], JSON_UNESCAPED_UNICODE);

            return self::getRedis()->lPush(self::PREFIX . $queue, $payload) !== false;
        }

        // MySQL 降级（或延迟任务）
        return self::pushToDb($queue, $data, $availableAt);
    }

    /**
     * 从队列取出一个任务
     *
     * @param string $queue 队列名
     * @return array|null 任务数据，无任务返回 null
     */
    public static function pop(string $queue): ?array
    {
        // 优先从 Redis 取
        if (self::isRedisAvailable()) {
            $payload = self::getRedis()->rPop(self::PREFIX . $queue);
            if ($payload !== false) {
                $decoded = json_decode($payload, true);
                if ($decoded === null) {
                    error_log("[Queue] json_decode failed for payload in queue: {$queue}");
                    return null;
                }
                return $decoded['data'] ?? $decoded;
            }
        }

        // 从 MySQL 取（含延迟任务）
        return self::popFromDb($queue);
    }

    /**
     * 获取队列长度
     */
    public static function size(string $queue): int
    {
        $count = 0;

        if (self::isRedisAvailable()) {
            $count += (int)self::getRedis()->lLen(self::PREFIX . $queue);
        }

        // 加上 MySQL 中的待处理任务
        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) as c FROM queue_jobs WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL",
                [$queue, time()]
            );
            $count += (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            // 表不存在时忽略
        }

        return $count;
    }

    /**
     * 清空队列
     */
    public static function clear(string $queue): void
    {
        if (self::isRedisAvailable()) {
            self::getRedis()->del(self::PREFIX . $queue);
        }

        try {
            Database::execute("DELETE FROM queue_jobs WHERE queue = ?", [$queue]);
        } catch (\Throwable $e) {
            // 表不存在时忽略
        }
    }

    /**
     * MySQL 写入
     */
    private static function pushToDb(string $queue, array $data, int $availableAt): bool
    {
        try {
            Database::execute(
                "INSERT INTO queue_jobs (queue, payload, available_at, created_at) VALUES (?, ?, ?, ?)",
                [$queue, json_encode($data, JSON_UNESCAPED_UNICODE), $availableAt, time()]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[Queue] MySQL push failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * MySQL 取出（原子操作：SELECT + UPDATE reserved_at，然后 DELETE）
     */
    private static function popFromDb(string $queue): ?array
    {
        try {
            Database::beginTransaction();

            $job = Database::fetchOne(
                "SELECT id, payload FROM queue_jobs WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE",
                [$queue, time()]
            );

            if (!$job) {
                Database::rollBack();
                return null;
            }

            Database::execute("DELETE FROM queue_jobs WHERE id = ?", [(int)$job['id']]);
            Database::commit();

            return json_decode($job['payload'], true) ?: null;
        } catch (\Throwable $e) {
            try { Database::rollBack(); } catch (\Throwable $e2) { error_log('[Queue] rollBack failed: ' . $e2->getMessage()); }
            error_log('[Queue] MySQL pop failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查 Redis 是否可用
     */
    private static function isRedisAvailable(): bool
    {
        if (self::$redisAvailable !== null) {
            return self::$redisAvailable;
        }

        if (!extension_loaded('redis')) {
            self::$redisAvailable = false;
            return false;
        }

        try {
            self::getRedis();
            self::$redisAvailable = true;
        } catch (\Throwable $e) {
            self::$redisAvailable = false;
        }

        return self::$redisAvailable;
    }

    /**
     * 获取 Redis 实例
     */
    private static function getRedis(): \Redis
    {
        if (self::$redis !== null) {
            return self::$redis;
        }

        $config = [];
        $configFile = APP_PATH . 'config/cache.php';
        if (file_exists($configFile)) {
            $config = (require $configFile)['redis'] ?? [];
        }

        $redis = new \Redis();
        $redis->connect(
            $config['host'] ?? '127.0.0.1',
            (int)($config['port'] ?? 6379),
            $config['timeout'] ?? 3
        );

        $password = $config['password'] ?? '';
        if ($password !== '') {
            $redis->auth($password);
        }

        // 队列使用 db 2，与 session(db1) 和 cache(db0) 分开
        $redis->select((int)($config['queue_db'] ?? 2));

        self::$redis = $redis;
        return $redis;
    }
}
