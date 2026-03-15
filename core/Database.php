<?php
/**
 * 数据库类 - 支持读写分离
 */

namespace Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $writeConnection = null;
    private static ?PDO $readConnection = null;
    private static array $queryLog = [];
    private static int $queryLogLimit = 1000;
    private static bool $forceWrite = false;
    private static ?array $configCache = null;

    /**
     * 获取数据库配置（缓存，避免重复读文件）
     */
    private static function getConfig(): array
    {
        if (self::$configCache === null) {
            self::$configCache = require APP_PATH . 'config/database.php';
        }
        return self::$configCache;
    }

    /**
     * 获取写连接（主库）
     */
    public static function getConnection(): PDO
    {
        if (self::$writeConnection === null) {
            $config = self::getConfig();
            $dbConfig = $config['connections'][$config['default']];
            self::$writeConnection = self::createConnection($dbConfig);
        }
        return self::$writeConnection;
    }

    /**
     * 获取读连接（从库，无配置时回退到主库）
     */
    public static function getReadConnection(): PDO
    {
        // 强制走主库（事务中或刚写入后）
        if (self::$forceWrite) {
            return self::getConnection();
        }

        if (self::$readConnection === null) {
            $config = self::getConfig();
            $dbConfig = $config['connections'][$config['default']];
            $readHost = $dbConfig['read']['host'] ?? '';

            if ($readHost === '') {
                // 无从库配置，使用主库
                return self::getConnection();
            }

            // 支持多个从库，随机选一个
            $hosts = array_map('trim', explode(',', $readHost));
            $host = $hosts[array_rand($hosts)];

            $readConfig = $dbConfig;
            $readConfig['host'] = $host;
            $readConfig['port'] = $dbConfig['read']['port'] ?? $dbConfig['port'];

            try {
                self::$readConnection = self::createConnection($readConfig);
            } catch (\RuntimeException $e) {
                // 从库连接失败，回退到主库
                error_log('[Database] 从库连接失败，回退到主库: ' . $e->getMessage());
                return self::getConnection();
            }
        }

        return self::$readConnection;
    }

    /**
     * 创建 PDO 连接
     */
    private static function createConnection(array $dbConfig): PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['driver'],
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['database'],
            $dbConfig['charset']
        );

        try {
            // 强制异常模式，防止 SQL 错误被静默吞掉
            $options = ($dbConfig['options'] ?? []) + [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            return new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                $options
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 检测是否为连接断开错误（MySQL gone away / Lost connection）
     */
    private static function isConnectionLost(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'Lost connection')
            || str_contains($msg, 'Connection timed out')
            || ($e instanceof PDOException && in_array($e->getCode(), ['HY000', '08S01', '08006'], true));
    }

    /**
     * 重置连接（断线重连）
     */
    private static function reconnect(bool $isWrite): void
    {
        if ($isWrite) {
            self::$writeConnection = null;
        } else {
            self::$readConnection = null;
        }
    }

    /**
     * 查询单条记录（走从库）
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        if (DEBUG) {
            $startTime = microtime(true);
        }

        try {
            $stmt = self::getReadConnection()->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            // 非事务中且为连接断开，重连一次
            if (self::$transactionDepth === 0 && self::isConnectionLost($e)) {
                self::reconnect(false);
                $stmt = self::getReadConnection()->prepare($sql);
                $stmt->execute($params);
            } else {
                throw $e;
            }
        }
        $result = $stmt->fetch();

        if (DEBUG && count(self::$queryLog) < self::$queryLogLimit) {
            self::$queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => round((microtime(true) - ($startTime ?? 0)) * 1000, 2) . 'ms',
                'type' => 'read',
            ];
        }

        return $result ?: null;
    }

    /**
     * 查询多条记录（走从库）
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        if (DEBUG) {
            $startTime = microtime(true);
        }

        try {
            $stmt = self::getReadConnection()->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            if (self::$transactionDepth === 0 && self::isConnectionLost($e)) {
                self::reconnect(false);
                $stmt = self::getReadConnection()->prepare($sql);
                $stmt->execute($params);
            } else {
                throw $e;
            }
        }
        $result = $stmt->fetchAll();

        if (DEBUG && count(self::$queryLog) < self::$queryLogLimit) {
            self::$queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => round((microtime(true) - ($startTime ?? 0)) * 1000, 2) . 'ms',
                'type' => 'read',
            ];
        }

        return $result;
    }

    /**
     * 执行写操作（走主库）
     */
    public static function execute(string $sql, array $params = []): int
    {
        if (DEBUG) {
            $startTime = microtime(true);
        }

        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            if (self::$transactionDepth === 0 && self::isConnectionLost($e)) {
                self::reconnect(true);
                $stmt = self::getConnection()->prepare($sql);
                $stmt->execute($params);
            } elseif (self::$transactionDepth > 0 && self::isConnectionLost($e)) {
                // 连接已断开，事务已隐式回滚，重置状态
                self::$transactionDepth = 0;
                self::$forceWrite = false;
                throw $e;
            } else {
                throw $e;
            }
        }
        $affectedRows = $stmt->rowCount();

        if (DEBUG && count(self::$queryLog) < self::$queryLogLimit) {
            self::$queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => round((microtime(true) - ($startTime ?? 0)) * 1000, 2) . 'ms',
                'type' => 'write',
            ];
        }

        return $affectedRows;
    }

    /**
     * 获取最后插入的 ID
     */
    public static function lastInsertId(): int
    {
        return (int) self::getConnection()->lastInsertId();
    }

    private static int $transactionDepth = 0;

    /**
     * 开启事务（强制走主库），支持嵌套（内层使用 SAVEPOINT）
     */
    public static function beginTransaction(): void
    {
        self::$forceWrite = true;
        if (self::$transactionDepth === 0) {
            self::getConnection()->beginTransaction();
        } else {
            self::getConnection()->exec('SAVEPOINT sp_' . self::$transactionDepth);
        }
        self::$transactionDepth++;
    }

    /**
     * 提交事务
     */
    public static function commit(): void
    {
        if (self::$transactionDepth <= 0) {
            self::$transactionDepth = 0;
            return;
        }
        self::$transactionDepth--;
        if (self::$transactionDepth === 0) {
            self::getConnection()->commit();
            self::$forceWrite = false;
        } else {
            self::getConnection()->exec('RELEASE SAVEPOINT sp_' . self::$transactionDepth);
        }
    }

    /**
     * 回滚事务
     */
    public static function rollBack(): void
    {
        if (self::$transactionDepth <= 0) {
            self::$transactionDepth = 0;
            return;
        }
        self::$transactionDepth--;
        if (self::$transactionDepth === 0) {
            self::getConnection()->rollBack();
            self::$forceWrite = false;
        } else {
            self::getConnection()->exec('ROLLBACK TO SAVEPOINT sp_' . self::$transactionDepth);
        }
    }

    /**
     * 事务处理（强制走主库）
     */
    public static function transaction(callable $callback)
    {
        self::beginTransaction();

        try {
            $result = $callback();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    /**
     * 强制后续读操作走主库（写入后立即读取场景）
     */
    public static function useMaster(): void
    {
        self::$forceWrite = true;
    }

    /**
     * 恢复读写分离
     */
    public static function restoreReadWrite(): void
    {
        self::$forceWrite = false;
    }

    /**
     * 带缓存的单条查询（透明缓存层）
     * 适用于高频只读查询，如用户信息、帖子详情等
     */
    public static function fetchOneCached(string $sql, array $params = [], int $ttl = 300): ?array
    {
        $key = 'dbq1:' . md5($sql . serialize($params));
        return Cache::getStale($key, function () use ($sql, $params) {
            return self::fetchOne($sql, $params);
        }, $ttl);
    }

    /**
     * 带缓存的多条查询（透明缓存层）
     */
    public static function fetchAllCached(string $sql, array $params = [], int $ttl = 300): array
    {
        $key = 'dbqn:' . md5($sql . serialize($params));
        return Cache::getStale($key, function () use ($sql, $params) {
            return self::fetchAll($sql, $params);
        }, $ttl) ?? [];
    }

    /**
     * 获取查询日志
     */
    public static function getQueryLog(): array
    {
        return self::$queryLog;
    }
}
