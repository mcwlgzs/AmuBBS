<?php
/**
 * Redis Session Handler
 * 支持分布式部署的 Session 共享
 */

namespace Core;

class RedisSessionHandler implements \SessionHandlerInterface
{
    private \Redis $redis;
    private int $lifetime;
    private string $prefix = 'session:';
    private array $config;

    public function __construct(array $config, int $lifetime = 7200)
    {
        $this->lifetime = $lifetime;
        $this->config = $config;
        $this->connect();
    }

    private function connect(): void
    {
        $this->redis = new \Redis();
        try {
            $this->redis->pconnect(
                $this->config['host'] ?? '127.0.0.1',
                (int) ($this->config['port'] ?? 6379),
                $this->config['timeout'] ?? 5,
                'session'
            );

            $password = $this->config['password'] ?? '';
            if ($password !== '') {
                $this->redis->auth($password);
            }

            $db = (int) ($this->config['database'] ?? 1);
            if ($db > 0) {
                $this->redis->select($db);
            }
        } catch (\RedisException $e) {
            error_log('[RedisSessionHandler] Redis 连接失败: ' . $e->getMessage());
            throw new \RuntimeException('Session 存储不可用，请稍后重试');
        }
    }

    /**
     * 执行 Redis 操作，断线时自动重连一次
     */
    private function withReconnect(callable $fn): mixed
    {
        try {
            return $fn($this->redis);
        } catch (\RedisException $e) {
            error_log('[RedisSessionHandler] Redis 操作失败，尝试重连: ' . $e->getMessage());
            try {
                $this->connect();
                return $fn($this->redis);
            } catch (\RedisException $e2) {
                error_log('[RedisSessionHandler] 重连后仍失败: ' . $e2->getMessage());
                return false;
            }
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $result = $this->withReconnect(fn($r) => $r->get($this->prefix . $id));
        return $result !== false ? $result : '';
    }

    public function write(string $id, string $data): bool
    {
        return (bool)$this->withReconnect(fn($r) => $r->setex($this->prefix . $id, $this->lifetime, $data));
    }

    public function destroy(string $id): bool
    {
        $this->withReconnect(fn($r) => $r->del($this->prefix . $id));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis 自动过期，无需 GC
        return 0;
    }
}
