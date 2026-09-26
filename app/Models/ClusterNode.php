<?php
/**
 * 集群节点模型（cluster_nodes 表）
 *
 * 只存「节点怎么配」，至于某台机器现在通不通，是 SystemController 里探测的事。
 */

namespace App\Models;

use Core\Database;

class ClusterNode extends Model
{
    protected static string $table = 'cluster_nodes';

    /** cluster_nodes 是硬删除 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 节点连通性探测结果的缓存键
     *
     * 探测本身在后台控制器里（要发网络请求），但键的格式放模型里，
     * 这样写入方与失效方不会各写一份字符串导致对不上。
     */
    public static function statusCacheKey(int $id): string
    {
        return "cluster:node:status:{$id}";
    }

    /**
     * 全部节点（按类型 + 创建时间）
     */
    public static function allOrdered(): array
    {
        return Database::fetchAll("SELECT * FROM cluster_nodes ORDER BY type, created_at DESC");
    }

    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM cluster_nodes WHERE id = ?", [$id]);

        return $row ?: null;
    }

    public static function create(string $type, string $name, string $host, int $port, int $weight, string $config): int
    {
        return Database::execute(
            "INSERT INTO cluster_nodes (type, name, host, port, weight, config, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            [$type, $name, $host, $port, $weight, $config, time()]
        );
    }

    public static function setStatus(int $id, int $status): int
    {
        return Database::execute(
            "UPDATE cluster_nodes SET status = ?, updated_at = ? WHERE id = ?",
            [$status, time(), $id]
        );
    }

    public static function update(int $id, string $name, string $host, int $port, int $weight, string $config): int
    {
        return Database::execute(
            "UPDATE cluster_nodes SET name = ?, host = ?, port = ?, weight = ?, config = ?, updated_at = ? WHERE id = ?",
            [$name, $host, $port, $weight, $config, time(), $id]
        );
    }

    public static function deleteById(int $id): int
    {
        return Database::execute("DELETE FROM cluster_nodes WHERE id = ?", [$id]);
    }
}
