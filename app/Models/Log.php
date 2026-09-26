<?php
/**
 * 操作日志模型（logs 表）
 *
 * 版主操作日志用 action 前缀 `mod_` 区分，列表与计数必须用完全相同的筛选条件，
 * 所以筛条件只在这里拼一次（list 用带别名的版本，count 用不带别名的版本）。
 */

namespace App\Models;

use Core\Database;

class Log extends Model
{
    protected static string $table = 'logs';

    /** 本表没有软删除列（过期日志物理清理） */
    protected static ?string $softDeleteColumn = null;

    /** 版主操作日志的 action 前缀 */
    public const MOD_PREFIX = 'mod_';

    /**
     * 写一条操作日志
     */
    public static function write(
        int $userId,
        string $action,
        string $targetType,
        int $targetId,
        ?string $detailJson,
        string $ip,
        int $createdAt
    ): void {
        Database::execute(
            "INSERT INTO logs (user_id, action, target_type, target_id, detail, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $action, $targetType, $targetId, $detailJson, $ip, $createdAt]
        );
    }

    /**
     * 版主操作日志分页（带操作人用户名）
     */
    public static function modPage(array $filters, int $page = 1, int $perPage = 30): array
    {
        [$where, $params] = self::modWhere($filters, 'l.');

        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);

        return Database::fetchAll(
            "SELECT l.*, u.username as operator_name
             FROM logs l
             LEFT JOIN users u ON l.user_id = u.id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /**
     * 版主操作日志总数（筛选条件与 modPage 完全一致）
     */
    public static function countMod(array $filters = []): int
    {
        [$where, $params] = self::modWhere($filters, '');

        $row = Database::fetchOne("SELECT COUNT(*) as c FROM logs {$where}", $params);

        return (int)($row['c'] ?? 0);
    }

    /**
     * 出现过的版主操作类型（筛选项下拉）
     */
    public static function modActionTypes(): array
    {
        return Database::fetchAll(
            "SELECT DISTINCT action FROM logs WHERE action LIKE ? ORDER BY action",
            [self::MOD_PREFIX . '%']
        );
    }

    /**
     * 分批清理过期日志（一次 5000 条，避免长事务锁表）
     */
    public static function pruneBefore(int $threshold, int $chunk = 5000): int
    {
        $total = 0;

        do {
            $affected = Database::execute(
                "DELETE FROM logs WHERE created_at < ? LIMIT " . max(1, $chunk),
                [$threshold]
            );
            $total += $affected;
        } while ($affected >= $chunk);

        return $total;
    }

    /**
     * 版主日志筛选条件
     *
     * @param string $alias 列前缀（'l.' 或 ''），两个调用方各自需要的形式
     * @return array{0: string, 1: array}
     */
    private static function modWhere(array $filters, string $alias): array
    {
        $where = "WHERE {$alias}action LIKE '" . self::MOD_PREFIX . "%'";
        $params = [];

        if (!empty($filters['action'])) {
            $where .= " AND {$alias}action = ?";
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where .= " AND {$alias}user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['target_type'])) {
            $where .= " AND {$alias}target_type = ?";
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['date_from'])) {
            $ts = strtotime((string)$filters['date_from']);
            if ($ts) { $where .= " AND {$alias}created_at >= ?"; $params[] = $ts; }
        }
        if (!empty($filters['date_to'])) {
            $ts = strtotime($filters['date_to'] . ' 23:59:59');
            if ($ts) { $where .= " AND {$alias}created_at <= ?"; $params[] = $ts; }
        }

        return [$where, $params];
    }
}
