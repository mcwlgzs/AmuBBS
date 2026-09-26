<?php
/**
 * IP 黑名单模型（ip_blacklist 表）
 *
 * 三处以前各写一份 SQL：后台增删、IpBlacklistService 的运行时判断与更新、
 * 定时任务清理过期条目。现在都收敛到这里，服务层只留缓存与拦截策略。
 */

namespace App\Models;

use Core\Database;

class IpBlacklist extends Model
{
    protected static string $table = 'ip_blacklist';

    /** ip_blacklist 是硬删除，没有软删除列 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 生效中的黑名单（ip => expire_at，0 表示永久）
     */
    public static function activeMap(int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $rows = Database::fetchAll(
            "SELECT ip, expire_at FROM ip_blacklist WHERE expire_at = 0 OR expire_at >= ?",
            [$now]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ip']] = (int)$row['expire_at'];
        }

        return $map;
    }

    /**
     * 新增或更新一条（ip 上有唯一键）
     */
    public static function upsert(string $ip, string $reason = '', int $expireAt = 0): void
    {
        Database::execute(
            "INSERT INTO ip_blacklist (ip, reason, expire_at, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), expire_at = VALUES(expire_at), created_at = VALUES(created_at)",
            [$ip, $reason, $expireAt, time()]
        );
    }

    /**
     * 按 IP 移除
     */
    public static function deleteByIp(string $ip): int
    {
        return Database::execute("DELETE FROM ip_blacklist WHERE ip = ?", [$ip]);
    }

    /**
     * 按主键移除
     */
    public static function deleteById(int $id): int
    {
        return Database::execute("DELETE FROM ip_blacklist WHERE id = ?", [$id]);
    }

    /**
     * 找出某条记录（后台删除前要先知道 IP，好顺带清缓存）
     */
    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM ip_blacklist WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 清理过期条目，返回清理条数（定时任务用）
     */
    public static function pruneExpired(int $now = 0): int
    {
        $now = $now > 0 ? $now : time();

        return Database::execute("DELETE FROM ip_blacklist WHERE expire_at > 0 AND expire_at < ?", [$now]);
    }

    /**
     * 后台列表（带搜索与分页）
     *
     * @return array{rows: array, total: int}
     */
    public static function adminList(string $search = '', int $page = 1, int $limit = 20): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE ip LIKE ? OR reason LIKE ?';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params = [$like, $like];
        }

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM ip_blacklist {$where}", $params)['cnt'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT * FROM ip_blacklist {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, max(0, ($page - 1) * $limit)])
        );

        return ['rows' => $rows, 'total' => $total];
    }
}
