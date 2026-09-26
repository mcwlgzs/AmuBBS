<?php
/**
 * 模型基类
 *
 * 分层约定（Controller → Model）：
 *   - Model 只负责「取数 + 落库」，不放业务规则；
 *     业务规则留在 Controller 需要时可抽到 app/Support 里的纯逻辑类。
 *   - 所有数据库访问都经由 Core\Database，Model 不自己开连接。
 *
 * 方法一律用 static：模型本身无状态（没有实例字段），
 * 这样调用方不需要 new，也不会有人误以为模型对象持有数据。
 */

namespace App\Models;

use Core\Cache;
use Core\Database;

abstract class Model
{
    /** 表名（子类必须覆盖） */
    protected static string $table = '';

    /** 软删除列名；null 表示该表没有软删除 */
    protected static ?string $softDeleteColumn = 'deleted_at';

    /**
     * 未删除条件片段
     *
     * @param string $alias 表别名，如 'u' → u.deleted_at IS NULL
     */
    protected static function notDeleted(string $alias = ''): string
    {
        if (static::$softDeleteColumn === null) {
            return '1 = 1';
        }

        $col = $alias !== ''
            ? $alias . '.' . static::$softDeleteColumn
            : static::$softDeleteColumn;

        return "{$col} IS NULL";
    }

    /**
     * 按天统计新增行数（近 7 天趋势图用）
     *
     * 表名与软删条件都来自子类，所以一份实现能服务 Thread / Post / User 三张表，
     * 不必在每个模型里各抄一遍 GROUP BY。
     *
     * @return array<int, array{d: string, c: string}> d = 'm-d'，c = 当天条数
     */
    public static function dailyCounts(int $from, int $to): array
    {
        // SQL 里的 '%m-%d' 必须用单引号：双引号在 ANSI_QUOTES 模式下会被当成标识符
        return Database::fetchAll(
            "SELECT FROM_UNIXTIME(created_at, '%m-%d') as d, COUNT(*) as c
             FROM `" . static::$table . "`
             WHERE created_at >= ? AND created_at < ? AND " . static::notDeleted() . "
             GROUP BY d",
            [$from, $to]
        );
    }

    /**
     * 带缓存的单条查询
     */
    protected static function cachedOne(string $sql, array $params = [], int $ttl = 300): ?array
    {
        return Database::fetchOneCached($sql, $params, $ttl);
    }

    /**
     * 带缓存的多条查询
     */
    protected static function cachedAll(string $sql, array $params = [], int $ttl = 300): array
    {
        return Database::fetchAllCached($sql, $params, $ttl);
    }

    /**
     * 让某条 cachedOne 查询的缓存失效
     *
     * key 推导交给 Database，模型这边只负责「用同一份 SQL 常量」，
     * 避免手抄前缀导致失效失效。
     */
    protected static function forgetOne(string $sql, array $params = []): void
    {
        Database::forgetOneCached($sql, $params);
    }

    /**
     * 让某条 cachedAll 查询的缓存失效
     */
    protected static function forgetAll(string $sql, array $params = []): void
    {
        Database::forgetAllCached($sql, $params);
    }

    // ------------------------------------------------------------------
    // 固定 key 的缓存（SWR）
    //
    // 首页那批热 key 不能由 SQL 推导：key 名是「契约」，
    // 别处（后台、CronSvc、Checkin 控制器）是按同一个名字删缓存的，
    // 所以 key 必须写死在这里，不能换成 dbq<hash>。
    // 逻辑过期后只放行一个请求重建，其余请求继续吃旧值。
    // ------------------------------------------------------------------

    /**
     * 固定 key 的多条查询（stale-while-revalidate）
     */
    protected static function staleAll(string $key, string $sql, array $params = [], int $ttl = 300): array
    {
        return Cache::getStale($key, static fn(): array => Database::fetchAll($sql, $params), $ttl) ?? [];
    }

    /**
     * 固定 key + 自定义取数（SWR）
     *
     * 给「取数不只是一条 SQL」的场景用，例如复用别处的原始查询。
     * 注意别在回调里再调用同一 key 的缓存方法：SWR 重建时锁已被外层持有，内层会拿到空值。
     */
    protected static function staleFetch(string $key, callable $callback, int $ttl = 300): mixed
    {
        return Cache::getStale($key, $callback, $ttl);
    }

    /**
     * 固定 key 的单条查询（stale-while-revalidate）
     */
    protected static function staleOne(string $key, string $sql, array $params = [], int $ttl = 300): ?array
    {
        return Cache::getStale($key, static fn(): ?array => Database::fetchOne($sql, $params), $ttl);
    }

    /**
     * 固定 key 的 COUNT 查询（SWR）
     *
     * 拿不到旧值又没抢到重建锁时返回 0，调用方不必再防 null。
     */
    protected static function staleCount(string $key, string $sql, array $params = [], int $ttl = 300): int
    {
        $value = Cache::getStale($key, static fn(): int => (int)(Database::fetchOne($sql, $params)['c'] ?? 0), $ttl);

        return (int)($value ?? 0);
    }

    /**
     * 固定 key 的单条查询（普通 TTL，不做 SWR）
     *
     * 用于「写入方会主动 set/delete 同一个 key」的场景（如签到状态）。
     *
     * @return mixed 原样返回缓存里的值（可能是数组，也可能是写入方存的其它形态）
     */
    protected static function keyedOne(string $key, string $sql, array $params = [], int $ttl = 300): mixed
    {
        return Cache::get($key, static fn(): ?array => Database::fetchOne($sql, $params), $ttl);
    }

    /**
     * 是否存在满足条件的未删除记录
     *
     * 用来收敛散落各处的「SELECT id FROM users WHERE ...」存在性检查。
     */
    protected static function existsWhere(string $where, array $params = []): bool
    {
        $sql = 'SELECT 1 FROM `' . static::$table . '` WHERE ' . $where
             . ' AND ' . static::notDeleted() . ' LIMIT 1';

        return Database::fetchOne($sql, $params) !== null;
    }

    /**
     * 按主键软删除（无软删除的表会抛异常，避免调用方以为删掉了）
     */
    public static function softDeleteById(int $id): int
    {
        if (static::$softDeleteColumn === null) {
            throw new \RuntimeException(static::class . ' 不支持软删除');
        }

        return Database::execute(
            'UPDATE `' . static::$table . '` SET `' . static::$softDeleteColumn . '` = ? WHERE id = ?',
            [time(), $id]
        );
    }
}
