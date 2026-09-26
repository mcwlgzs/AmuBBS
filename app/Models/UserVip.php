<?php
/**
 * 用户 VIP 模型（user_vip 表）
 *
 * 一个用户可能有历史等级的多行记录，有效的那行是「expire_at 未过期、等级最高」。
 * 续费与新建都必须在购买事务里完成，加锁读取由 lockActive() 提供。
 */

namespace App\Models;

use Core\Database;

class UserVip extends Model
{
    protected static string $table = 'user_vip';

    /** 本表没有软删除列（过期即失效） */
    protected static ?string $softDeleteColumn = null;

    /**
     * 当前有效的 VIP 记录（等级最高的一条）
     */
    public static function activeFor(int $userId, int $now): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM user_vip WHERE user_id = ? AND expire_at > ? ORDER BY vip_level DESC LIMIT 1",
            [$userId, $now]
        );

        return $row ?: null;
    }

    /**
     * 事务内加锁读某个等级的记录（防并发重复续费）
     *
     * 必须在事务里调用：行锁只在事务期间有效。
     */
    public static function lockActive(int $userId, int $level, int $now): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM user_vip WHERE user_id = ? AND vip_level = ? AND expire_at > ? FOR UPDATE",
            [$userId, $level, $now]
        );

        return $row ?: null;
    }

    /**
     * 事务内加锁读该用户的 VIP 行（不论等级、不论是否过期）
     *
     * 表上 user_id 是唯一的，所以「已过期」或「换等级」时不能再 INSERT，
     * 必须复用这一行改写等级与到期时间。
     */
    public static function lockByUser(int $userId): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM user_vip WHERE user_id = ? FOR UPDATE",
            [$userId]
        );

        return $row ?: null;
    }

    /** 续费：把到期时间往后推 */
    public static function extend(int $id, int $expireAt, int $updatedAt): int
    {
        return Database::execute(
            "UPDATE user_vip SET expire_at = ?, updated_at = ? WHERE id = ?",
            [$expireAt, $updatedAt, $id]
        );
    }

    /**
     * 改写等级与到期时间（升级 / 过期后重新购买时复用同一行）
     */
    public static function switchLevel(int $id, int $level, int $expireAt, int $updatedAt): int
    {
        return Database::execute(
            "UPDATE user_vip SET vip_level = ?, expire_at = ?, updated_at = ? WHERE id = ?",
            [$level, $expireAt, $updatedAt, $id]
        );
    }

    public static function create(int $userId, int $level, int $expireAt, int $now): int
    {
        Database::execute(
            "INSERT INTO user_vip (user_id, vip_level, expire_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            [$userId, $level, $expireAt, $now, $now]
        );

        return Database::lastInsertId();
    }
}
