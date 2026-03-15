<?php
/**
 * 缓存类
 * 支持 Redis（如果可用），否则降级为进程内缓存
 */

namespace Core;

class Cache
{
    private static $redis = null;
    private static bool $redisAvailable = true;
    private static array $localCache = [];
    private static ?array $configCache = null;
    private const LOCAL_CACHE_MAX = 500;
    private static ?string $cacheVersion = null;

    /**
     * 获取缓存版本前缀
     */
    private static function getCacheVersion(): string
    {
        if (self::$cacheVersion === null) {
            $appConfig = require APP_PATH . 'config/app.php';
            self::$cacheVersion = $appConfig['cache_version'] ?? 'v1';
        }
        return self::$cacheVersion;
    }

    /**
     * 为缓存键添加版本前缀
     */
    private static function versionKey(string $key): string
    {
        // 如果键已经包含版本前缀，不重复添加
        if (preg_match('/^v\d+:/', $key)) {
            return $key;
        }
        return self::getCacheVersion() . ':' . $key;
    }

    /**
     * 获取 Redis 连接（不可用时返回 null）
     */
    public static function getRedis()
    {
        if (!self::$redisAvailable) {
            return null;
        }

        if (self::$redis === null) {
            if (!class_exists('Redis')) {
                self::$redisAvailable = false;
                return null;
            }

            try {
                if (self::$configCache === null) {
                    self::$configCache = require APP_PATH . 'config/cache.php';
                }
                $redisConfig = self::$configCache['redis'];

                self::$redis = new \Redis();
                self::$redis->pconnect($redisConfig['host'], $redisConfig['port'], $redisConfig['timeout']);

                if (!empty($redisConfig['password'])) {
                    self::$redis->auth($redisConfig['password']);
                }

                self::$redis->select($redisConfig['database']);
                // 优先使用 igbinary（体积减少 30-50%，速度更快），其次 PHP 序列化
                if (defined('\\Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
                    self::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
                } else {
                    self::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                }
            } catch (\Exception $e) {
                error_log('Redis 连接失败，降级为内存缓存: ' . $e->getMessage());
                self::$redis = null;
                self::$redisAvailable = false;
                return null;
            }
        }

        return self::$redis;
    }

    /**
     * 批量预热：用 Redis MGET 一次性加载多个 key 到进程内缓存
     * 后续 Cache::get() 直接命中 L1 本地缓存，零网络开销
     */
    public static function preload(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        // 过滤掉已在本地缓存中的 key
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, self::$localCache)) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            return;
        }

        $redis = self::getRedis();
        if ($redis === null) {
            return;
        }

        try {
            $values = $redis->mGet($missing);
            if (is_array($values)) {
                foreach ($missing as $i => $key) {
                    if ($values[$i] !== false) {
                        self::$localCache[$key] = $values[$i];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Redis MGET 预热错误: ' . $e->getMessage());
        }
    }

    /**
     * 获取缓存
     */
    public static function get(string $key, callable $callback = null, int $ttl = 3600)
    {
        $versionedKey = self::versionKey($key);

        // Level 1: 进程内缓存
        if (array_key_exists($versionedKey, self::$localCache)) {
            return self::$localCache[$versionedKey];
        }

        // Level 2: Redis 缓存
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $value = $redis->get($versionedKey);
                if ($value !== false) {
                    self::$localCache[$versionedKey] = $value;
                    return $value;
                }
            } catch (\Exception $e) {
                error_log('Redis 读取错误: ' . $e->getMessage());
            }
        }

        // Level 3: 回调函数
        if ($callback !== null) {
            $value = $callback();
            // 不缓存 null/false，避免与 Redis 未命中混淆
            if ($value !== null && $value !== false) {
                self::set($key, $value, $ttl);
            }
            return $value;
        }

        return null;
    }

    /**
     * Stampede 防护的缓存获取（stale-while-revalidate）
     * 热 key 过期时只让一个请求执行回调重建，其他请求返回旧值
     * 实现方式：实际 TTL 存为 2 倍，到达逻辑 TTL 时触发单请求重建
     */
    public static function getStale(string $key, callable $callback, int $ttl = 300, int $staleTtl = 0)
    {
        if ($staleTtl <= 0) {
            $staleTtl = max($ttl, 60); // 默认 stale 窗口 = ttl，至少 60s
        }

        // L1 命中（含元数据）
        if (isset(self::$localCache[$key]) && is_array(self::$localCache[$key]) && array_key_exists('_v', self::$localCache[$key])) {
            $meta = self::$localCache[$key];
            if ($meta['_exp'] > time()) {
                return $meta['_v']; // 未过逻辑 TTL，直接返回
            }
            // 逻辑过期，尝试抢锁重建
            $lockKey = $key . ':lock';
            if (self::add($lockKey, 1, 30)) {
                // 抢到锁，执行重建
                try {
                    $value = $callback();
                    self::setStale($key, $value, $ttl, $staleTtl);
                    return $value;
                } finally {
                    self::delete($lockKey);
                }
            }
            return $meta['_v']; // 没抢到锁，返回旧值
        }

        // L2: Redis
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $raw = $redis->get($key);
                if ($raw !== false && is_array($raw) && array_key_exists('_v', $raw)) {
                    self::$localCache[$key] = $raw;
                    if ($raw['_exp'] > time()) {
                        return $raw['_v'];
                    }
                    // 逻辑过期，抢锁重建
                    $lockKey = $key . ':lock';
                    if (self::add($lockKey, 1, 30)) {
                        try {
                            $value = $callback();
                            self::setStale($key, $value, $ttl, $staleTtl);
                            return $value;
                        } finally {
                            self::delete($lockKey);
                        }
                    }
                    return $raw['_v'];
                }
            } catch (\Exception $e) {
                error_log('Redis getStale 读取错误: ' . $e->getMessage());
            }
        }

        // 完全 miss，加锁防止 stampede
        $lockKey = $key . ':lock';
        if (self::add($lockKey, 1, 30)) {
            try {
                $value = $callback();
                self::setStale($key, $value, $ttl, $staleTtl);
                return $value;
            } finally {
                self::delete($lockKey);
            }
        }

        // 没抢到锁，短暂等待后重试一次
        usleep(50000); // 50ms
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $raw = $redis->get($key);
                if ($raw !== false && is_array($raw) && array_key_exists('_v', $raw)) {
                    self::$localCache[$key] = $raw;
                    return $raw['_v'];
                }
            } catch (\Exception $e) {}
        }

        // 仍然 miss，直接执行回调
        $value = $callback();
        self::setStale($key, $value, $ttl, $staleTtl);
        return $value;
    }

    /**
     * 写入带 stale 元数据的缓存
     * 物理 TTL = ttl + staleTtl，逻辑过期时间 = now + ttl
     */
    private static function setStale(string $key, $value, int $ttl, int $staleTtl): void
    {
        $meta = ['_v' => $value, '_exp' => time() + $ttl];
        self::$localCache[$key] = $meta;

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $redis->setex($key, $ttl + $staleTtl, $meta);
            } catch (\Exception $e) {
                error_log('Redis setStale 写入错误: ' . $e->getMessage());
            }
        }
    }

    /**
     * 原子递增（不重置 TTL）
     */
    public static function increment(string $key, int $step = 1): int
    {
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $newVal = $redis->incrBy($key, $step);
                self::$localCache[$key] = $newVal;
                return $newVal;
            } catch (\Exception $e) {
                error_log('Redis INCRBY 错误: ' . $e->getMessage());
            }
        }

        // 无 Redis 时回退到本地缓存
        $current = (int)(self::$localCache[$key] ?? 0);
        $current += $step;
        self::$localCache[$key] = $current;
        return $current;
    }

    /**
     * 设置缓存（带TTL随机化防止缓存雪崩）
     */
    public static function set(string $key, $value, int $ttl = 3600): bool
    {
        $versionedKey = self::versionKey($key);

        // 本地缓存淘汰：超过上限时清除最早的一半
        if (count(self::$localCache) >= self::LOCAL_CACHE_MAX && !array_key_exists($versionedKey, self::$localCache)) {
            $half = (int)(self::LOCAL_CACHE_MAX / 2);
            self::$localCache = array_slice(self::$localCache, -$half, $half, true);
        }
        self::$localCache[$versionedKey] = $value;

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                // TTL随机化：在原TTL基础上增加±10%的随机偏移，防止大量key同时过期导致缓存雪崩
                $jitter = (int)($ttl * 0.1);
                $randomTtl = $ttl + random_int(-$jitter, $jitter);
                return $redis->setex($versionedKey, max(1, $randomTtl), $value);
            } catch (\Exception $e) {
                error_log('Redis 写入错误: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * 删除缓存
     */
    public static function delete(string $key): bool
    {
        $versionedKey = self::versionKey($key);
        unset(self::$localCache[$versionedKey]);

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                return $redis->del($versionedKey) > 0;
            } catch (\Exception $e) {
                error_log('Redis 删除错误: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * 批量删除多个 key（1 个 DEL 命令替代多次往返）
     */
    public static function deleteMulti(array $keys): int
    {
        if (empty($keys)) return 0;

        foreach ($keys as $key) {
            unset(self::$localCache[$key]);
        }

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                return $redis->del(...$keys);
            } catch (\Exception $e) {
                error_log('Redis 批量删除错误: ' . $e->getMessage());
            }
        }

        return 0;
    }

    /**
     * 原子设置：仅当 key 不存在时才设置（类似 Redis SETNX）
     * 成功返回 true，key 已存在返回 false
     */
    public static function add(string $key, $value, int $ttl = 3600): bool
    {
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $result = $redis->set($key, $value, ['nx', 'ex' => $ttl]);
                if ($result) {
                    self::$localCache[$key] = $value;
                    return true;
                }
                return false;
            } catch (\Exception $e) {
                error_log('Redis SETNX 错误: ' . $e->getMessage());
            }
        }

        // 无 Redis 时回退到本地缓存（非原子，但聊胜于无）
        if (array_key_exists($key, self::$localCache)) {
            return false;
        }
        self::$localCache[$key] = $value;
        return true;
    }

    /**
     * 执行 Redis Lua 脚本（原子操作，减少网络往返）
     * @param string $script Lua 脚本
     * @param array $keys KEYS 参数
     * @param array $args ARGV 参数
     * @return mixed
     */
    public static function eval(string $script, array $keys = [], array $args = [])
    {
        $redis = self::getRedis();
        if ($redis === null) {
            return false;
        }
        try {
            return $redis->eval($script, array_merge($keys, $args), count($keys));
        } catch (\Exception $e) {
            error_log('Redis EVAL 错误: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Lua 原子操作：读取并递增，带上限检查（用于频率限制）
     * 返回递增后的值，超过 limit 返回 -1
     */
    public static function incrementWithLimit(string $key, int $limit, int $ttl): int
    {
        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current == false then
    redis.call('SET', KEYS[1], 1, 'EX', tonumber(ARGV[2]))
    return 1
end
current = tonumber(current)
if current >= tonumber(ARGV[1]) then
    return -1
end
return redis.call('INCR', KEYS[1])
LUA;
        $result = self::eval($script, [$key], [$limit, $ttl]);
        if ($result !== false) {
            self::$localCache[$key] = $result;
            return (int)$result;
        }
        // 回退到非原子操作
        $current = (int)self::get($key);
        if ($current >= $limit) return -1;
        return self::increment($key);
    }

    /**
     * Lua 原子操作：获取并删除（用于队列消费等场景）
     */
    public static function getAndDelete(string $key)
    {
        $script = <<<'LUA'
local val = redis.call('GET', KEYS[1])
if val ~= false then
    redis.call('DEL', KEYS[1])
end
return val
LUA;
        $result = self::eval($script, [$key]);
        unset(self::$localCache[$key]);
        return $result;
    }

    /**
     * Lua 原子操作：批量递增多个 key（用于统计计数器批量更新）
     * $increments = ['key1' => 5, 'key2' => 3]
     */
    public static function batchIncrement(array $increments): bool
    {
        if (empty($increments)) return true;

        $script = <<<'LUA'
local n = #KEYS
for i = 1, n do
    redis.call('INCRBY', KEYS[i], tonumber(ARGV[i]))
end
return n
LUA;
        $keys = array_keys($increments);
        $args = array_map('intval', array_values($increments));
        $result = self::eval($script, $keys, $args);

        // 更新本地缓存
        foreach ($increments as $k => $v) {
            self::$localCache[$k] = (int)(self::$localCache[$k] ?? 0) + $v;
        }

        return $result !== false;
    }

    /**
     * 批量删除缓存
     */
    public static function deletePattern(string $pattern): int
    {
        // 清除本地缓存中匹配的 key
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        foreach (array_keys(self::$localCache) as $key) {
            if (preg_match($regex, $key)) {
                unset(self::$localCache[$key]);
            }
        }

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $deleted = 0;
                $iterator = null;
                // 使用 SCAN 迭代器替代 KEYS，避免大数据量下阻塞 Redis
                while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
                    if (!empty($keys)) {
                        $deleted += $redis->del($keys);
                    }
                }
                return $deleted;
            } catch (\Exception $e) {
                error_log('Redis 批量删除错误: ' . $e->getMessage());
            }
        }

        return 0;
    }
}
