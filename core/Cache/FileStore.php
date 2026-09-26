<?php
/**
 * 文件缓存驱动（面向共享虚拟主机）
 *
 * 设计目标：
 *  - 零扩展依赖：不需要 redis / igbinary / apcu，任意 PHP 8.0+ 空间可用
 *  - 只依赖一个可写目录，无需 root、无需 shell、无需常驻进程、无需 cron
 *  - 两级分片目录，避免单目录文件数过多拖慢文件系统
 *  - 写入采用「临时文件 + rename」，读取永远看到一个完整文件，无需加锁
 *  - 惰性 GC：按概率触发过期清理，过期 key 读取时也会顺手删除
 *
 * 存储格式：6 字节头（4B 过期时间戳 + 2B key 长度）+ 明文 key + serialize(值)
 * 头部保留明文 key 是为了让 deletePattern() 能按模式匹配（Redis 靠 SCAN，文件靠扫描）。
 */

namespace Core\Cache;

class FileStore
{
    /** 文件头长度：4 字节过期时间 + 2 字节 key 长度 */
    private const HEADER_SIZE = 6;

    private string $root;
    private bool $ready;
    /** GC 概率分母：平均每 N 次写入触发一次过期清理 */
    private int $gcDivisor;

    public function __construct(string $root, int $gcDivisor = 200)
    {
        $this->root = rtrim($root, "/\\") . DIRECTORY_SEPARATOR;
        $this->gcDivisor = max(1, $gcDivisor);
        $this->ready = $this->ensureDir($this->root);
    }

    /** 目录不可写时返回 false，调用方据此降级到进程内缓存 */
    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * key -> 缓存文件路径（两级分片）
     */
    private function fileOf(string $key): string
    {
        $hash = md5($key);

        return $this->root . $hash[0] . $hash[1] . DIRECTORY_SEPARATOR
             . $hash[2] . $hash[3] . DIRECTORY_SEPARATOR
             . $hash . '.cache';
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /**
     * 读取缓存，未命中或已过期返回 null
     */
    public function get(string $key)
    {
        $file = $this->fileOf($key);
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return null;
        }

        $raw = @stream_get_contents($fh);
        fclose($fh);

        if (!is_string($raw) || strlen($raw) < self::HEADER_SIZE) {
            return null;
        }

        $expire = unpack('N', substr($raw, 0, 4))[1];
        if ($expire !== 0 && $expire < time()) {
            @unlink($file);
            return null;
        }

        $klen = unpack('n', substr($raw, 4, 2))[1];
        $payload = substr($raw, self::HEADER_SIZE + $klen);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        // 只反序列化标量/数组：缓存文件在 storage/ 下、正常不可被外部写入，
        // 这里禁止对象反序列化是纵深防御（万一 uploads 可写或 docroot 配错，
        // 也不能靠伪造缓存文件触发 POP gadget 链）。
        $value = @unserialize($payload, ['allowed_classes' => false]);

        // 区分「未命中(null)」和「真的存了 false」
        if ($value === false && $payload !== 'b:0;') {
            @unlink($file);
            return null;
        }

        return $value;
    }

    /**
     * 写入缓存；$ttl <= 0 表示永不过期
     */
    public function set(string $key, $value, int $ttl): bool
    {
        if (!$this->ready) {
            return false;
        }

        $file = $this->fileOf($key);
        if (!$this->ensureDir(dirname($file))) {
            return false;
        }

        $expire = $ttl > 0 ? time() + $ttl : 0;
        $data = pack('N', $expire) . pack('n', strlen($key)) . $key . serialize($value);

        // 临时文件 + rename：rename 在同分区是原子操作
        $tmp = $file . '.' . str_replace('.', '', uniqid('', true)) . '.tmp';
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        $this->maybeGc();

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->fileOf($key);

        return is_file($file) ? @unlink($file) : false;
    }

    public function deleteMulti(array $keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if ($this->delete((string)$key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 原子写入：仅当 key 不存在（或已过期）时成功，用于抢锁 / 防雪崩
     */
    public function add(string $key, $value, int $ttl = 3600): bool
    {
        if (!$this->ready) {
            return false;
        }

        $file = $this->fileOf($key);
        if (!$this->ensureDir(dirname($file))) {
            return false;
        }

        // 'x' 模式由内核保证「存在则失败」，是这里唯一可靠的原子原语
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $fh = @fopen($file, 'xb');
            if ($fh !== false) {
                $expire = $ttl > 0 ? time() + $ttl : 0;
                $data = pack('N', $expire) . pack('n', strlen($key)) . $key . serialize($value);
                $written = fwrite($fh, $data);
                fflush($fh);
                fclose($fh);

                return $written !== false;
            }

            // 已存在：若已过期则清掉重试一次，否则说明真的被占用
            if ($attempt === 0 && $this->isExpired($file)) {
                @unlink($file);
                continue;
            }

            return false;
        }

        return false;
    }

    private function isExpired(string $file): bool
    {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return true;
        }

        $head = fread($fh, 4);
        fclose($fh);

        if (!is_string($head) || strlen($head) < 4) {
            return true;
        }

        $expire = unpack('N', $head)[1];

        return $expire !== 0 && $expire < time();
    }

    /**
     * 原子递增：flock 独占锁内完成「读-改-写」
     * $ttl > 0 时仅在 key 不存在时设定过期时间，已有 key 保留原 TTL
     */
    public function increment(string $key, int $step = 1, int $ttl = 0): int
    {
        if (!$this->ready) {
            return 0;
        }

        $file = $this->fileOf($key);
        if (!$this->ensureDir(dirname($file))) {
            return 0;
        }

        $fh = @fopen($file, 'c+b');
        if ($fh === false) {
            return 0;
        }

        $result = 0;

        try {
            if (!flock($fh, LOCK_EX)) {
                return 0;
            }

            rewind($fh);
            $raw = stream_get_contents($fh);

            $current = 0;
            // 只有「新建 key」才起算窗口；已有 key 必须沿用原到期时间。
            // 若每次递增都写 time()+ttl，窗口会随请求不断后移：
            // 只要用户活跃（每秒都有请求）计数就永远不过期，
            // 攒够上限后被永久挡在门外 —— 限流再也解不开。
            $expire = $ttl > 0 ? time() + $ttl : 0;

            if (is_string($raw) && strlen($raw) >= self::HEADER_SIZE) {
                $oldExpire = unpack('N', substr($raw, 0, 4))[1];
                $klen = unpack('n', substr($raw, 4, 2))[1];

                // 已过期则从 0 重新计数（沿用上面新算的窗口）
                if ($oldExpire === 0 || $oldExpire >= time()) {
                    $decoded = @unserialize(substr($raw, self::HEADER_SIZE + $klen), ['allowed_classes' => false]);
                    if (is_int($decoded)) {
                        $current = $decoded;
                    }
                    // 未过期的已有 key：保留它原来的 TTL（0 = 永不过期）
                    $expire = $oldExpire;
                }
            }

            $current += $step;
            $data = pack('N', $expire) . pack('n', strlen($key)) . $key . serialize($current);

            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $data);
            fflush($fh);

            $result = $current;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        // 计数器 key（限流、浏览量累积）以前不触发抽奖式 GC，
        // 只增不减地堆在磁盘上，这里让 increment 也参与回收。
        $this->maybeGc();

        return $result;
    }

    /**
     * 读取并删除（用于一次性令牌）
     * 注意：不是严格原子（get 与 delete 之间无锁），但令牌场景下足够
     */
    public function getAndDelete(string $key)
    {
        $value = $this->get($key);
        if ($value !== null) {
            $this->delete($key);
        }

        return $value;
    }

    /**
     * 按通配符批量删除，支持 * 通配
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->ready) {
            return 0;
        }

        $regex = $this->patternToRegex($pattern);
        $deleted = 0;

        foreach ($this->scan() as $path) {
            $key = $this->readKey($path);

            if ($key === null) {
                // 半截文件，顺手清掉
                @unlink($path);
                continue;
            }

            if (preg_match($regex, $key) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 批量预热：一次性把多个 key 读进调用方的进程内缓存
     */
    public function preload(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $this->get((string)$key);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 清理所有过期文件，返回清理数量
     */
    public function gc(): int
    {
        if (!$this->ready) {
            return 0;
        }

        $now = time();
        $removed = 0;

        foreach ($this->scan() as $path) {
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                continue;
            }
            $head = fread($fh, 4);
            fclose($fh);

            if (!is_string($head) || strlen($head) < 4) {
                if (@unlink($path)) {
                    $removed++;
                }
                continue;
            }

            $expire = unpack('N', $head)[1];
            if ($expire !== 0 && $expire < $now && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 清空全部缓存
     */
    public function clear(): int
    {
        if (!$this->ready) {
            return 0;
        }

        $removed = 0;
        foreach ($this->scan() as $path) {
            if (@unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 便利方法：过期即清，命中返回值
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 遍历所有 * 结束的缓存文件路径
     *
     * @return \Generator<string>
     */
    private function scan(): \Generator
    {
        if (!is_dir($this->root)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->root,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\UnexpectedValueException $e) {
            // 目录权限异常，静默降级
            return;
        }

        foreach ($iterator as $path) {
            if (is_string($path) && str_ends_with($path, '.cache')) {
                yield $path;
            }
        }
    }

    private function readKey(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }

        $head = fread($fh, self::HEADER_SIZE);
        if (!is_string($head) || strlen($head) < self::HEADER_SIZE) {
            fclose($fh);
            return null;
        }

        $klen = unpack('n', substr($head, 4, 2))[1];
        $key = $klen > 0 ? fread($fh, $klen) : '';
        fclose($fh);

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function patternToRegex(string $pattern): string
    {
        return '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
    }

    private function maybeGc(): void
    {
        if ($this->gcDivisor <= 1) {
            $this->gc();
            return;
        }

        try {
            if (random_int(1, $this->gcDivisor) === 1) {
                $this->gc();
            }
        } catch (\Exception $e) {
            // random_int 失败时跳过本次 GC，不影响主流程
        }
    }
}
