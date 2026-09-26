<?php
/**
 * 消息队列服务
 * 优先使用 Redis List，降级为 MySQL 表
 */

namespace App\Services;

use App\Models\QueueJob;
use Core\Cache;

class QueueSvc
{
    private const REDIS_PREFIX = 'queue:';

    /** 单条任务最大消费次数，超过即删行（避免毒任务无限重试） */
    private const MAX_ATTEMPTS = 3;

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
            QueueJob::push($queue, json_encode($payload), $item['available_at'], $item['created_at']);
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
            $job = QueueJob::reserve($queue, time(), bin2hex(random_bytes(4)));
            if ($job) {
                return [
                    'id' => $job['id'],
                    'queue' => $job['queue'],
                    'payload' => json_decode($job['payload'], true),
                    'created_at' => $job['created_at'],
                    // 租约令牌必须带出去：done()/fail() 要靠它确认「这一行还是我抢的」
                    'token' => $job['reserve_token'] ?? null,
                    'attempts' => (int)($job['attempts'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[QueueSvc] pop failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * 完成任务（MySQL 模式下删除记录）
     *
     * @param string|null $token pop() 返回的租约令牌；带上它才不会误删别的 worker 抢到的行
     */
    public static function done($jobId, ?string $token = null): void
    {
        try {
            QueueJob::remove((int)$jobId, $token);
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
            return QueueJob::unreservedCount($queue);
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
                    self::done($job['id'], $job['token'] ?? null);
                }
                $processed++;
            } catch (\Throwable $e) {
                error_log("Queue job failed [{$queue}]: " . $e->getMessage());

                // MySQL 模式：失败次数持久化在 queue_jobs.attempts，
                // 达到上限删行（毒任务不再无限重试），否则释放租约等下次调度。
                if (isset($job['id']) && is_numeric($job['id']) && !empty($job['token'])) {
                    try {
                        $outcome = QueueJob::fail((int)$job['id'], (string)$job['token'], self::MAX_ATTEMPTS);
                        if ($outcome === 'deleted') {
                            error_log("Queue job permanently failed after " . self::MAX_ATTEMPTS . " attempts [{$queue}]: " . json_encode($job['payload']));
                        } elseif ($outcome === 'lost') {
                            error_log("[QueueSvc] 任务租约已失效，跳过重试 [{$queue}] id=" . $job['id']);
                        }
                    } catch (\Throwable $ex) {
                        error_log('[QueueSvc] release failed job error: ' . $ex->getMessage());
                    }
                    continue;
                }

                // Redis 模式（或没有 id 的内存任务）：把 attempts 写回 payload 推回队列尾部重试
                $attempts = (int)($job['attempts'] ?? 0) + 1;
                if ($attempts < self::MAX_ATTEMPTS) {
                    $job['attempts'] = $attempts;
                    $redis = self::getRedis();
                    if ($redis) {
                        $redis->lPush(self::REDIS_PREFIX . $queue, json_encode($job));
                    }
                } else {
                    error_log("Queue job permanently failed after {$attempts} attempts [{$queue}]: " . json_encode($job['payload']));
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
            return QueueJob::releaseStale(time() - $timeout);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function getRedis(): ?\Redis
    {
        return Cache::getRedis();
    }
}
