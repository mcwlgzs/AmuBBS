<?php
/**
 * 缓存类
 *
 * 双驱动设计，面向「共享虚拟主机优先」的部署场景：
 *  - file  ：零扩展依赖，只需一个可写目录（默认，共享主机可用）
 *  - redis ：有 Redis 且连得上时启用，性能更好
 *  - auto  ：有 Redis 就用 Redis，否则自动落到文件缓存
 *
 * 通过 CACHE_DRIVER 选择。任何情况下都不会因为缓存不可用而中断请求：
 * 逐级降级顺序为 驱动后端 → 进程内缓存 → 直接执行回调。
 *
 * L1 = 进程内数组（带 TTL，跟随请求生命周期）
 * L2 = 驱动后端（Redis 或文件）
 * L3 = 未命中时执行回调并回填
 */

namespace Core;

use Core\Cache\FileStore;

class Cache
{
    /** L2 后端驱动：redis | file */
    private static ?string $driver = null;

    /** 文件驱动实例 */
    private static ?FileStore $fileStore = null;

    /** Redis 连接（不可用为 null） */
    private static $redis = null;
    private static bool $redisAvailable = true;

    /** L1 进程内缓存 */
    private static array $localCache = [];
    /** L1 过期时间表（key => 到期时间戳），让进程内缓存同样遵守 TTL */
    private static array $localExpiry = [];

    private static ?array $configCache = null;
    private const LOCAL_CACHE_MAX = 500;
    private static ?string $cacheVersion = null;

    // ------------------------------------------------------------------
    // 驱动选择
    // ------------------------------------------------------------------

    private static function config(): array
    {
        if (self::$configCache === null) {
            self::$configCache = require APP_PATH . 'config/cache.php';
        }

        return self::$configCache;
    }

    /**
     * 解析当前驱动，只解析一次
     */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $configured = strtolower(trim((string)(self::config()['default'] ?? 'auto')));
        if (!in_array($configured, ['redis', 'file', 'auto'], true)) {
            $configured = 'auto';
        }

        if ($configured === 'file') {
            return self::$driver = 'file';
        }

        // 显式要求 redis 但环境不具备时，降级而不是白屏
        if (!class_exists('Redis')) {
            if ($configured === 'redis') {
                error_log('[Cache] 未安装 redis 扩展，CACHE_DRIVER=redis 无法生效，已降级为文件缓存');
            }
            return self::$driver = 'file';
        }

        if (self::connectRedis() !== null) {
            return self::$driver = 'redis';
        }

        if ($configured === 'redis') {
            error_log('[Cache] Redis 连接失败，已降级为文件缓存');
        }

        return self::$driver = 'file';
    }

    private static function useFile(): bool
    {
        return self::driver() === 'file';
    }

    /**
     * 获取文件驱动实例（目录不可写时返回 null）
     */
    private static function fileStore(): ?FileStore
    {
        if (self::$fileStore === null) {
            $fileConfig = self::config()['file'] ?? [];
            $path = $fileConfig['path'] ?? (APP_PATH . 'storage/cache/');
            $store = new FileStore($path, (int)($fileConfig['gc_divisor'] ?? 200));

            if (!$store->isReady()) {
                error_log('[Cache] 缓存目录不可写: ' . $path . '（已降级为进程内缓存）');
                return null;
            }

            self::$fileStore = $store;
        }

        return self::$fileStore;
    }

    /**
     * 真正建立 Redis 连接（内部使用，不做驱动判断，避免递归）
     */
    private static function connectRedis()
    {
        if (!self::$redisAvailable) {
            return null;
        }

        if (self::$redis !== null) {
            return self::$redis;
        }

        if (!class_exists('Redis')) {
            self::$redisAvailable = false;
            return null;
        }

        try {
            $redisConfig = self::config()['redis'];

            self::$redis = new \Redis();
            self::$redis->pconnect($redisConfig['host'], $redisConfig['port'], $redisConfig['timeout']);

            if (!empty($redisConfig['password'])) {
                self::$redis->auth($redisConfig['password']);
            }

            self::$redis->select($redisConfig['database']);

            // 优先 igbinary（体积减少 30-50%），其次 PHP 序列化
            if (defined('\Redis::SERIALIZER_IGBINARY') && extension_loaded('igbinary')) {
                self::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
            } else {
                self::$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            }
        } catch (\Exception $e) {
            error_log('[Cache] Redis 连接失败: ' . $e->getMessage());
            self::$redis = null;
            self::$redisAvailable = false;
            return null;
        }

        return self::$redis;
    }

    /**
     * 供外部（队列等）取用 Redis 连接
     * 文件驱动模式下固定返回 null，调用方据此走自己的降级路径
     */
    public static function getRedis()
    {
        return self::useFile() ? null : self::connectRedis();
    }

    // ------------------------------------------------------------------
    // key 版本化 & L1 读写
    // ------------------------------------------------------------------

    private static function getCacheVersion(): string
    {
        if (self::$cacheVersion === null) {
            // 优先读「运行时版本号」：后台「清除全部缓存」会递增它，
            // 一次写文件就让全站旧 key 立即失效，不必再全目录扫一遍。
            $runtime = self::runtimeVersion();
            if ($runtime !== '') {
                self::$cacheVersion = $runtime;
            } else {
                $appConfig = require APP_PATH . 'config/app.php';
                self::$cacheVersion = $appConfig['cache_version'] ?? 'v1';
            }
        }

        return self::$cacheVersion;
    }

    /** 运行时版本号文件路径 */
    private static function versionFile(): string
    {
        return APP_PATH . 'storage/cache/.version';
    }

    /** 读取运行时版本号（不存在/不可读时返回空串） */
    private static function runtimeVersion(): string
    {
        $file = self::versionFile();
        if (!is_readable($file)) {
            return '';
        }
        $value = trim((string)@file_get_contents($file));

        return preg_match('/^v\d+$/', $value) ? $value : '';
    }

    /**
     * 递增缓存版本号：O(1) 让全部旧 key 立即失效（后台「清除全部缓存」用）
     */
    public static function bumpVersion(): string
    {
        $version = 'v' . time() . random_int(10, 99);
        $file = self::versionFile();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (@file_put_contents($file, $version, LOCK_EX) === false) {
            error_log('[Cache] 缓存版本号写入失败（将退回目录清理）: ' . $file);

            return self::getCacheVersion();
        }

        @clearstatcache(true, $file);
        self::$cacheVersion = $version;
        self::$localCache = [];
        self::$localExpiry = [];

        return $version;
    }

    /**
     * 为缓存键添加版本前缀
     * 通配符模式同样适用：'forums:*' -> 'v1:forums:*'
     */
    private static function versionKey(string $key): string
    {
        if (preg_match('/^v\d+:/', $key)) {
            return $key;
        }

        return self::getCacheVersion() . ':' . $key;
    }

    /**
     * getStale 写入的记录带 _v/_exp 元数据；普通 set 写入的是裸值
     * 两者共用同一命名空间，靠这个判断区分
     */
    private static function isStaleMeta($value): bool
    {
        return is_array($value) && array_key_exists('_v', $value) && array_key_exists('_exp', $value);
    }

    /**
     * 统一解包：元数据记录 -> 真实值
     */
    private static function unwrap($value)
    {
        return self::isStaleMeta($value) ? $value['_v'] : $value;
    }

    /**
     * L1 写入（含上限淘汰与 TTL 记录）
     */
    private static function localSet(string $versionedKey, $value, int $ttl = 0): void
    {
        if (count(self::$localCache) >= self::LOCAL_CACHE_MAX && !array_key_exists($versionedKey, self::$localCache)) {
            $half = (int)(self::LOCAL_CACHE_MAX / 2);
            self::$localCache = array_slice(self::$localCache, -$half, $half, true);
            // 同步裁剪过期表，避免其无限增长
            self::$localExpiry = array_intersect_key(self::$localExpiry, self::$localCache);
        }

        self::$localCache[$versionedKey] = $value;

        if ($ttl > 0) {
            self::$localExpiry[$versionedKey] = time() + $ttl;
        } else {
            unset(self::$localExpiry[$versionedKey]);
        }
    }

    /**
     * L1 读取（遵守 TTL）
     * $found 由引用回传是否命中
     */
    private static function localGet(string $versionedKey, ?bool &$found)
    {
        $found = false;

        if (!array_key_exists($versionedKey, self::$localCache)) {
            return null;
        }

        if (isset(self::$localExpiry[$versionedKey]) && self::$localExpiry[$versionedKey] <= time()) {
            unset(self::$localCache[$versionedKey], self::$localExpiry[$versionedKey]);
            return null;
        }

        $found = true;

        return self::$localCache[$versionedKey];
    }

    private static function localForget(string ...$versionedKeys): void
    {
        foreach ($versionedKeys as $vk) {
            unset(self::$localCache[$vk], self::$localExpiry[$vk]);
        }
    }

    // ------------------------------------------------------------------
    // 读取
    // ------------------------------------------------------------------

    /**
     * 批量预热：把多个 key 一次性读入 L1，减少后续 L2 往返
     */
    public static function preload(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        $missing = [];
        foreach ($keys as $key) {
            $vk = self::versionKey($key);
            if (!array_key_exists($vk, self::$localCache)) {
                $missing[$vk] = $vk;
            }
        }

        if (empty($missing)) {
            return;
        }

        $missing = array_values($missing);

        if (self::useFile()) {
            $store = self::fileStore();
            if ($store === null) {
                return;
            }
            foreach ($store->preload($missing) as $vk => $value) {
                self::localSet($vk, $value);
            }
            return;
        }

        $redis = self::connectRedis();
        if ($redis === null) {
            return;
        }

        try {
            $values = $redis->mGet($missing);
            if (is_array($values)) {
                foreach ($missing as $i => $vk) {
                    if ($values[$i] !== false) {
                        self::localSet($vk, $values[$i]);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('[Cache] Redis MGET 预热错误: ' . $e->getMessage());
        }
    }

    /**
     * 获取缓存
     */
    public static function get(string $key, callable $callback = null, int $ttl = 3600)
    {
        $versionedKey = self::versionKey($key);

        // L1
        $cached = self::localGet($versionedKey, $found);
        if ($found) {
            return self::unwrap($cached);
        }

        // L2
        $value = self::backendGet($versionedKey);
        if ($value !== null) {
            self::localSet($versionedKey, $value, $ttl);
            return self::unwrap($value);
        }

        // L3
        if ($callback !== null) {
            $value = $callback();
            // 不缓存 null/false，避免与「未命中」混淆
            if ($value !== null && $value !== false) {
                self::set($key, $value, $ttl);
            }
            return $value;
        }

        return null;
    }

    /**
     * 从 L2 后端读取原始记录（null = 未命中）
     */
    private static function backendGet(string $versionedKey)
    {
        if (self::useFile()) {
            $store = self::fileStore();
            return $store !== null ? $store->get($versionedKey) : null;
        }

        $redis = self::connectRedis();
        if ($redis === null) {
            // Redis 中途挂掉：退回文件缓存，避免整站失去缓存
            $store = self::fileStore();
            return $store !== null ? $store->get($versionedKey) : null;
        }

        try {
            $value = $redis->get($versionedKey);
            return $value === false ? null : $value;
        } catch (\Exception $e) {
            error_log('[Cache] Redis 读取错误: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * L2 后端写入
     */
    private static function backendSet(string $versionedKey, $value, int $ttl): bool
    {
        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    // TTL 随机化 ±10%，防止大量 key 同时过期造成缓存雪崩
                    $jitter = (int)($ttl * 0.1);
                    $randomTtl = $jitter > 0 ? $ttl + random_int(-$jitter, $jitter) : $ttl;

                    return (bool)$redis->setex($versionedKey, max(1, $randomTtl), $value);
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 写入错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->set($versionedKey, $value, $ttl) : false;
    }

    /**
     * L2 后端删除
     */
    private static function backendDelete(string $versionedKey): bool
    {
        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    return $redis->del($versionedKey) > 0;
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 删除错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->delete($versionedKey) : false;
    }

    /**
     * Stampede 防护的缓存获取（stale-while-revalidate）
     * 热 key 逻辑过期时只放行一个请求重建，其余请求继续返回旧值
     */
    public static function getStale(string $key, callable $callback, int $ttl = 300, int $staleTtl = 0)
    {
        if ($staleTtl <= 0) {
            $staleTtl = max($ttl, 60); // 默认 stale 窗口 = ttl，至少 60s
        }

        $versionedKey = self::versionKey($key);
        $lockKey = $versionedKey . ':lock';

        $rebuild = function () use ($versionedKey, $lockKey, $callback, $ttl, $staleTtl) {
            if (self::add($lockKey, 1, 30)) {
                try {
                    $value = $callback();
                    self::setStale($versionedKey, $value, $ttl, $staleTtl);
                    return $value;
                } finally {
                    self::delete($lockKey);
                }
            }

            return null; // 没抢到锁
        };

        // L1 命中
        $cached = self::localGet($versionedKey, $found);
        if ($found) {
            if (!self::isStaleMeta($cached)) {
                return $cached; // 普通 set 写入的裸值，视为新鲜
            }

            if ($cached['_exp'] > time()) {
                return $cached['_v'];
            }

            $fresh = $rebuild();
            return $fresh !== null ? $fresh : $cached['_v'];
        }

        // L2 命中
        $cached = self::backendGet($versionedKey);
        if ($cached !== null) {
            self::localSet($versionedKey, $cached);

            if (!self::isStaleMeta($cached)) {
                return $cached;
            }

            if ($cached['_exp'] > time()) {
                return $cached['_v'];
            }

            $fresh = $rebuild();
            return $fresh !== null ? $fresh : $cached['_v'];
        }

        // 完全 miss
        $fresh = $rebuild();
        if ($fresh !== null) {
            return $fresh;
        }

        // 没抢到锁，短暂等待后重试一次
        usleep(50000); // 50ms
        $cached = self::backendGet($versionedKey);
        if ($cached !== null) {
            return self::unwrap($cached);
        }

        // 仍然 miss，直接执行回调（宁可慢，不可空）
        $value = $callback();
        self::setStale($versionedKey, $value, $ttl, $staleTtl);

        return $value;
    }

    /**
     * 写入带 stale 元数据的记录
     * 物理 TTL = ttl + staleTtl，逻辑过期时间 = now + ttl
     */
    private static function setStale(string $versionedKey, $value, int $ttl, int $staleTtl): void
    {
        $meta = ['_v' => $value, '_exp' => time() + $ttl];
        self::localSet($versionedKey, $meta, $ttl + $staleTtl);
        self::backendSet($versionedKey, $meta, $ttl + $staleTtl);
    }

    // ------------------------------------------------------------------
    // 写入 / 删除
    // ------------------------------------------------------------------

    /**
     * 设置缓存
     */
    public static function set(string $key, $value, int $ttl = 3600): bool
    {
        $versionedKey = self::versionKey($key);
        self::localSet($versionedKey, $value, $ttl);

        return self::backendSet($versionedKey, $value, $ttl);
    }

    /**
     * 删除缓存
     */
    public static function delete(string $key): bool
    {
        $versionedKey = self::versionKey($key);
        self::localForget($versionedKey);

        return self::backendDelete($versionedKey);
    }

    /**
     * 批量删除多个 key
     * 修复：原实现未做 key 版本化，导致 v1: 前缀的缓存永远删不掉
     */
    public static function deleteMulti(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        $versionedKeys = [];
        foreach ($keys as $key) {
            $vk = self::versionKey((string)$key);
            $versionedKeys[] = $vk;
            self::localForget($vk);
        }

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    return (int)$redis->del(...$versionedKeys);
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 批量删除错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->deleteMulti($versionedKeys) : 0;
    }

    /**
     * 原子设置：仅当 key 不存在时才成功（防并发抢锁）
     */
    public static function add(string $key, $value, int $ttl = 3600): bool
    {
        $versionedKey = self::versionKey($key);

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    $result = $redis->set($versionedKey, $value, ['nx', 'ex' => $ttl]);
                    if ($result) {
                        self::localSet($versionedKey, $value, $ttl);
                        return true;
                    }
                    return false;
                } catch (\Exception $e) {
                    error_log('[Cache] Redis SETNX 错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();
        if ($store !== null) {
            // 文件驱动用 'x' 创建模式，内核保证原子性
            $ok = $store->add($versionedKey, $value, $ttl);
            if ($ok) {
                self::localSet($versionedKey, $value, $ttl);
            }
            return $ok;
        }

        // 连缓存目录都不可写时，退化为进程内判断
        $existing = self::localGet($versionedKey, $found);
        if ($found) {
            return false;
        }
        self::localSet($versionedKey, $value, $ttl);

        return true;
    }

    /**
     * 原子递增
     * @param int $ttl 仅在该 key 首次创建时生效（<=0 表示不设过期）
     */
    public static function increment(string $key, int $step = 1, int $ttl = 0): int
    {
        $versionedKey = self::versionKey($key);

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    $newVal = (int)$redis->incrBy($versionedKey, $step);
                    // 首次创建时补设过期时间
                    if ($ttl > 0 && $newVal === $step) {
                        $redis->expire($versionedKey, $ttl);
                    }
                    self::localSet($versionedKey, $newVal, $ttl);

                    return $newVal;
                } catch (\Exception $e) {
                    error_log('[Cache] Redis INCRBY 错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();
        if ($store !== null) {
            $newVal = $store->increment($versionedKey, $step, $ttl);
            self::localSet($versionedKey, $newVal, $ttl);

            return $newVal;
        }

        // 无后端：退化为进程内计数
        $current = (int)(self::localGet($versionedKey, $found) ?? 0) + $step;
        self::localSet($versionedKey, $current, $ttl);

        return $current;
    }

    /**
     * 执行 Redis Lua 脚本
     * 文件驱动下固定返回 false，调用方需自行降级
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
            error_log('[Cache] Redis EVAL 错误: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 原子操作：读取并递增，带上限检查（频率限制）
     * 返回递增后的值，超限返回 -1
     */
    public static function incrementWithLimit(string $key, int $limit, int $ttl): int
    {
        $versionedKey = self::versionKey($key);

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
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
                try {
                    $result = $redis->eval($script, [$versionedKey, $limit, $ttl], 1);
                    if ($result !== false) {
                        self::localSet($versionedKey, (int)$result, $ttl);
                        return (int)$result;
                    }
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 频率限制错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();
        if ($store !== null) {
            // flock 内完成递增，返回值即新计数，超限则拒绝
            $value = $store->increment($versionedKey, 1, $ttl);
            self::localSet($versionedKey, $value, $ttl);

            return $value > $limit ? -1 : $value;
        }

        // 后端完全不可用：必须 fail-closed。
        // 这里以前是「放行 + 记一行日志」，等于磁盘满/权限错/只读挂载时
        // 登录爆破、注册、搜索、API 限流一次性全部失效 —— 可用性不能拿安全换。
        error_log('[Cache] 频率限制后端不可用，已拒绝本次请求(fail-closed): ' . $key);

        return -1;
    }

    /**
     * 原子操作：获取并删除（一次性令牌、队列消费）
     */
    public static function getAndDelete(string $key)
    {
        $versionedKey = self::versionKey($key);
        self::localForget($versionedKey);

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                $script = <<<'LUA'
local val = redis.call('GET', KEYS[1])
if val ~= false then
    redis.call('DEL', KEYS[1])
end
return val
LUA;
                try {
                    $result = $redis->eval($script, [$versionedKey], 1);
                    return $result === false ? null : $result;
                } catch (\Exception $e) {
                    error_log('[Cache] Redis GETDEL 错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->getAndDelete($versionedKey) : null;
    }

    /**
     * 批量递增多个 key（统计计数器）
     * $increments = ['key1' => 5, 'key2' => 3]
     */
    public static function batchIncrement(array $increments): bool
    {
        if (empty($increments)) {
            return true;
        }

        $versioned = [];
        foreach ($increments as $k => $v) {
            $versioned[self::versionKey((string)$k)] = (int)$v;
        }

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                $script = <<<'LUA'
local n = #KEYS
for i = 1, n do
    redis.call('INCRBY', KEYS[i], tonumber(ARGV[i]))
end
return n
LUA;
                try {
                    $result = $redis->eval(
                        $script,
                        array_merge(array_keys($versioned), array_values($versioned)),
                        count($versioned)
                    );
                    if ($result !== false) {
                        foreach ($versioned as $vk => $v) {
                            self::localSet($vk, (int)(self::$localCache[$vk] ?? 0) + $v);
                        }
                        return true;
                    }
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 批量递增错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();
        if ($store === null) {
            return false;
        }

        $ok = true;
        foreach ($versioned as $vk => $step) {
            if ($store->increment($vk, $step, 0) === 0) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * 按通配符批量删除
     * 修复：原实现未做 key 版本化，'forums:*' 匹配不到 'v1:forums:*'
     */
    public static function deletePattern(string $pattern): int
    {
        // 支持调用方传裸模式或已带版本前缀的模式
        $versionedPattern = self::versionKey($pattern);

        // L1：按版本化后的模式匹配
        $regex = '/^' . str_replace('\*', '.*', preg_quote($versionedPattern, '/')) . '$/';
        foreach (array_keys(self::$localCache) as $key) {
            if (preg_match($regex, $key)) {
                self::localForget($key);
            }
        }

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    $deleted = 0;
                    $iterator = null;
                    // SCAN 而非 KEYS，避免大 key 空间下阻塞 Redis
                    while (($keys = $redis->scan($iterator, $versionedPattern, 100)) !== false) {
                        if (!empty($keys)) {
                            $deleted += (int)$redis->del($keys);
                        }
                        if ($iterator === 0) {
                            break;
                        }
                    }

                    return $deleted;
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 批量删除错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->deletePattern($versionedPattern) : 0;
    }

    /**
     * 清理已过期的缓存文件（仅文件驱动有意义）
     */
    public static function gc(): int
    {
        $store = self::fileStore();

        return $store !== null ? $store->gc() : 0;
    }

    /**
     * 清空全部缓存
     */
    public static function flush(): int
    {
        self::$localCache = [];
        self::$localExpiry = [];

        if (!self::useFile()) {
            $redis = self::connectRedis();
            if ($redis !== null) {
                try {
                    return self::deletePattern('*');
                } catch (\Exception $e) {
                    error_log('[Cache] Redis 清空错误: ' . $e->getMessage());
                }
            }
        }

        $store = self::fileStore();

        return $store !== null ? $store->clear() : 0;
    }
}
