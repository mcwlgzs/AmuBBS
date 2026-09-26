<?php
/**
 * 用户等级模型（user_levels 表）
 *
 * 与 LevelSvc 的分工：LevelSvc 负责「按积分算等级」并缓存 levels:all，
 * 本模型只负责这张表的取数与落库；缓存失效仍由 LevelSvc::clearCache() 负责，
 * 免得同一个 key 出现两个主人。
 */

namespace App\Models;

use Core\Database;

class UserLevel extends Model
{
    protected static string $table = 'user_levels';

    /** 本表没有软删除列（等级是硬删除） */
    protected static ?string $softDeleteColumn = null;

    /** 全部等级（按 level 升序，后台列表与 API 共用） */
    public static function all(): array
    {
        return Database::fetchAll("SELECT * FROM user_levels ORDER BY level ASC");
    }

    /**
     * 等级表（前台按积分算等级用，key = levels:all，SWR 缓存）
     *
     * 与 all() 的区别：这里按 min_credits 升序（升级进度要按积分顺序找下一级），
     * 且缓存 key 就是 LevelSvc::clearCache() 按名字删的那个，不能换。
     */
    public static function allCached(): array
    {
        return self::staleAll('levels:all', "SELECT * FROM user_levels ORDER BY min_credits ASC", [], 3600);
    }

    /** 后台表单要的行（实时，不走缓存） */
    public static function findFresh(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM user_levels WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 该等级编号是否已被别的行占用（level 列有唯一索引，先查再写才不会抛 1062）
     */
    public static function levelTakenByOther(int $level, int $exceptId = 0): bool
    {
        $row = Database::fetchOne(
            "SELECT id FROM user_levels WHERE level = ? AND id != ? LIMIT 1",
            [$level, $exceptId]
        );

        return $row !== null;
    }

    /**
     * 新增或更新等级
     *
     * created_at 是 NOT NULL 且无默认值，新增时必须显式写入。
     */
    public static function save(int $id, int $level, string $name, int $minCredits, string $color, string $icon): int
    {
        if ($id > 0) {
            return Database::execute(
                "UPDATE user_levels SET level = ?, name = ?, min_credits = ?, color = ?, icon = ? WHERE id = ?",
                [$level, $name, $minCredits, $color, $icon, $id]
            );
        }

        Database::execute(
            "INSERT INTO user_levels (level, name, min_credits, color, icon, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$level, $name, $minCredits, $color, $icon, time()]
        );

        return Database::lastInsertId();
    }

    public static function remove(int $id): int
    {
        return Database::execute("DELETE FROM user_levels WHERE id = ?", [$id]);
    }
}
