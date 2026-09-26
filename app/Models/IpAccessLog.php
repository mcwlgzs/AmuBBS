<?php
/**
 * IP 访问计数模型（ip_access_logs 表）
 *
 * 这张表是「按天按动作」的原子计数器：(ip, action, date) 唯一，
 * 递增走 INSERT ... ON DUPLICATE KEY UPDATE，读改写都不该由服务层拼 SQL。
 */

namespace App\Models;

use Core\Database;

class IpAccessLog extends Model
{
    protected static string $table = 'ip_access_logs';

    /** 本表没有软删除列（过期记录按日期物理清理） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 原子 +1（行不存在则插入）
     */
    public static function increment(string $ip, string $action, string $date, int $updatedAt): void
    {
        Database::execute(
            "INSERT INTO ip_access_logs (ip, action, count, date, updated_at) VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE count = count + 1, updated_at = VALUES(updated_at)",
            [$ip, $action, $date, $updatedAt]
        );
    }

    /**
     * 某天某动作的计数（没有记录返回 0）
     */
    public static function countFor(string $ip, string $action, string $date): int
    {
        $row = Database::fetchOne(
            "SELECT count FROM ip_access_logs WHERE ip = ? AND action = ? AND date = ?",
            [$ip, $action, $date]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * 清理指定日期之前的记录
     */
    public static function pruneBefore(string $date): int
    {
        return Database::execute("DELETE FROM ip_access_logs WHERE date < ?", [$date]);
    }
}
