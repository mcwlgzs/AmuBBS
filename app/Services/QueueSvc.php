<?php
/**
 * 消息队列服务
 * 优先使用 Redis List，降级为 MySQL 表
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class QueueSvc
{
    private const REDIS_PREFIX = 'queue:';

    /**
     * 推入队列
     */
    public static function push(string $queue, array $payload, int $delay = 0): void
    {
        $item = [
            'id' => bin2hex(random_bytes(8)),
            'queue' => $queue,
            'payload' => $payload,
            'created_at' => time(),
            'available_at' => time() + $delay,
        ];

        $redis = self::getRedis();
        if ($redis) {
            if ($delay > 0) {
                // 延迟队列用 sorted set
                $redis->zAdd(self::REDIS_PREFIX . $queue . ':delayed', $item['available_at'], json_encode($item));
            } else {
                $redis->lPush(self::REDIS_PREFIX . $queue, json_encode($item));
            }
            return;
        }

        // MySQL 降级
        try {
            Database::execute(
                "INSERT INTO queue_jobs (queue, payload, available_at, created_at) VALUES (?, ?, ?, ?)",
                [$queue, json_encode($payload), $item['available_at'], $item['created_at']]
            );
        } catch (\Throwable $e) {
            error_log("Queue push failed: " . $e->getMessage());
        }
    }

    /**
     * 弹出队列（获取并删除一条）
     */
    public static function pop(string $queue): ?array
    {
        $redis = self::getRedis();
        if ($redis) {
            // 先处理到期的延迟任务
            self::migrateDelayed($redis, $queue);

            $raw = $redis->rPop(self::REDIS_PREFIX . $queue);
            if ($raw) {
                return json_decode($raw, true);
            }
            return null;
        }

        // MySQL 降级（使用原子 UPDATE 抢占任务，避免竞态）
        try {
            $now = time();
            $reserveToken = bin2hex(random_bytes(4));
            $affected = Database::execute(
                "UPDATE queue_jobs SET reserved_at = ?, reserve_token = ? WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL ORDER BY id ASC LIMIT 1",
                [$now, $reserveToken, $queue, $now]
            );
            if ($affected > 0) {
                $job = Database::fetchOne(
                    "SELECT * FROM queue_jobs WHERE queue = ? AND reserve_token = ? AND reserved_at = ?",
                    [$queue, $reserveToken, $now]
                );
                if ($job) {
                    return [
                        'id' => $job['id'],
                        'queue' => $job['queue'],
                        'payload' => json_decode($job['payload'], true),
                        'created_at' => $job['created_at'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[QueueSvc] pop failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 完成任务（MySQL 模式下删除记录）
     */
    public static function done($jobId): void
    {
        try {
            Database::execute("DELETE FROM queue_jobs WHERE id = ?", [(int)$jobId]);
        } catch (\Throwable $e) {
            error_log('[QueueSvc] done failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取队列长度
     */
    public static function size(string $queue): int
    {
        $redis = self::getRedis();
        if ($redis) {
            return (int)$redis->lLen(self::REDIS_PREFIX . $queue)
                 + (int)$redis->zCard(self::REDIS_PREFIX . $queue . ':delayed');
        }

        try {
            $row = Database::fetchOne(
                "SELECT COUNT(*) as c FROM queue_jobs WHERE queue = ? AND reserved_at IS NULL",
                [$queue]
            );
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 处理队列（消费 N 条）
     * Redis 模式下失败的任务会被推回队列尾部重试（最多 3 次）
     */
    public static function process(string $queue, callable $handler, int $maxJobs = 10): int
    {
        $processed = 0;
        for ($i = 0; $i < $maxJobs; $i++) {
            $job = self::pop($queue);
            if (!$job) break;

            try {
                $handler($job['payload']);
                if (isset($job['id']) && is_numeric($job['id'])) {
                    self::done($job['id']);
                }
                $processed++;
            } catch (\Throwable $e) {
                error_log("Queue job failed [{$queue}]: " . $e->getMessage());
                // Redis 模式下将失败任务推回队列重试
                $attempts = (int)($job['attempts'] ?? 0) + 1;
                if ($attempts < 3) {
                    $job['attempts'] = $attempts;
                    $redis = self::getRedis();
                    if ($redis) {
                        $redis->lPush(self::REDIS_PREFIX . $queue, json_encode($job));
                    }
                } else {
                    error_log("Queue job permanently failed after {$attempts} attempts [{$queue}]: " . json_encode($job['payload']));
                }
                // MySQL 模式下：超过重试次数则删除任务，否则释放（清除 reserved_at）让 releaseStale 重新调度
                if (isset($job['id']) && is_numeric($job['id'])) {
                    if ($attempts >= 3) {
                        self::done($job['id']);
                    } else {
                        try {
                            Database::execute("UPDATE queue_jobs SET reserved_at = NULL, reserve_token = NULL WHERE id = ?", [(int)$job['id']]);
                        } catch (\Throwable $ex) {
                            error_log('[QueueSvc] release failed job error: ' . $ex->getMessage());
                        }
                    }
                }
            }
        }
        return $processed;
    }

    /**
     * 将到期的延迟任务移入主队列（Lua 脚本保证原子性）
     */
    private static function migrateDelayed($redis, string $queue): void
    {
        $now = time();
        $delayedKey = self::REDIS_PREFIX . $queue . ':delayed';
        $queueKey = self::REDIS_PREFIX . $queue;

        // Lua 脚本：原子地从 sorted set 取出到期任务并推入 list
        $lua = <<<'LUA'
local items = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 50)
for _, item in ipairs(items) do
    if redis.call('ZREM', KEYS[1], item) > 0 then
        redis.call('LPUSH', KEYS[2], item)
    end
end
return #items
LUA;

        try {
            $redis->eval($lua, [$delayedKey, $queueKey, (string)$now], 2);
        } catch (\Throwable $e) {
            // Lua 不可用时回退到非原子方式
            $items = $redis->zRangeByScore($delayedKey, '-inf', (string)$now, ['limit' => [0, 50]]);
            foreach ($items as $item) {
                if ($redis->zRem($delayedKey, $item) > 0) {
                    $redis->lPush($queueKey, $item);
                }
            }
        }
    }

    /**
     * 清理超时的保留任务（MySQL 模式，由 cron 调用）
     */
    public static function releaseStale(int $timeout = 300): int
    {
        try {
            $cutoff = time() - $timeout;
            return Database::execute(
                "UPDATE queue_jobs SET reserved_at = NULL WHERE reserved_at IS NOT NULL AND reserved_at < ?",
                [$cutoff]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function getRedis(): ?\Redis
    {
        return Cache::getRedis();
    }
}
