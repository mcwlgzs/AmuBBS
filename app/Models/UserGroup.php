<?php
/**
 * 用户组模型（user_groups 表）
 *
 * 之前只有 SystemController 与 UserController 各自裸查这张表；
 * 「禁止用户组」还是控制器里 find-or-create 出来的，收进模型后调用方不用再管。
 */

namespace App\Models;

use Core\Cache;
use Core\Database;

class UserGroup extends Model
{
    protected static string $table = 'user_groups';

    /** user_groups 是硬删除 */
    protected static ?string $softDeleteColumn = null;

    /** 封禁用户所在的组名 */
    public const BANNED_NAME = '禁止用户组';

    /** 权限判断用的整行缓存键（PermissionSvc 按这个名字删） */
    public static function permsCacheKey(int $groupId): string
    {
        return "group_perms:{$groupId}";
    }

    /**
     * 整行缓存（权限判断用，key = group_perms:{id}）
     *
     * key 名是与 PermissionSvc::clearCache() 的契约，不能换成 SQL 推导键。
     */
    public static function findFullCached(int $groupId): ?array
    {
        return Cache::get(self::permsCacheKey($groupId), static function () use ($groupId): ?array {
            return self::findFresh($groupId);
        }, 600);
    }

    /**
     * 按积分区间匹配用户组（自动升级用）
     *
     * 刻意不缓存：dbq 缓存键里含参数（积分值），按前缀删不掉，
     * 一改用户组区间就要等 10 分钟才生效；而这条查询只在积分变动后偶尔跑一次，
     * 表也只有几个组，直接读库更划算。
     *
     * credits_from / credits_to 在部分历史库里可能不存在，调用方需要容忍异常。
     */
    public static function matchByCredits(int $credits): ?array
    {
        $row = Database::fetchOne(
            "SELECT id FROM user_groups
             WHERE credits_from IS NOT NULL AND credits_to IS NOT NULL
               AND credits_from <= ? AND credits_to > ?
               AND is_admin = 0
             ORDER BY credits_from DESC
             LIMIT 1",
            [$credits, $credits]
        );

        return $row ?: null;
    }

    /**
     * 用户组相关缓存统一失效（整行权限缓存按组删，或整片删）
     */
    public static function forgetCaches(int $groupId = 0): void
    {
        if ($groupId > 0) {
            Cache::delete(self::permsCacheKey($groupId));
            return;
        }

        Cache::deletePattern('group_perms:*');
    }

    /**
     * 全部用户组（后台下拉用）
     */
    public static function all(): array
    {
        return Database::fetchAll("SELECT id, name FROM user_groups ORDER BY id");
    }

    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT id, name FROM user_groups WHERE id = ?", [$id]);

        return $row ?: null;
    }

    public static function exists(int $id): bool
    {
        return Database::fetchOne("SELECT id FROM user_groups WHERE id = ?", [$id]) !== null;
    }

    public static function findByName(string $name): ?array
    {
        $row = Database::fetchOne("SELECT * FROM user_groups WHERE name = ? LIMIT 1", [$name]);

        return $row ?: null;
    }

    public static function create(string $name, string $permissionsJson = '[]', int $isAdmin = 0): int
    {
        Database::execute(
            "INSERT INTO user_groups (name, permissions, is_admin, created_at) VALUES (?, ?, ?, ?)",
            [$name, $permissionsJson, $isAdmin, time()]
        );

        return Database::lastInsertId();
    }

    /**
     * 按名字取，没有就建（封禁用户需要这么一个组）
     */
    public static function ensureByName(string $name): int
    {
        $group = self::findByName($name);
        if ($group) {
            return (int)$group['id'];
        }

        return self::create($name, '[]', 0);
    }

    // ------------------------------------------------------------------
    // 后台管理
    // ------------------------------------------------------------------

    /** 后台列表（带每组用户数） */
    public static function adminList(): array
    {
        return Database::fetchAll(
            "SELECT g.*, (SELECT COUNT(*) FROM users WHERE group_id = g.id AND deleted_at IS NULL) as user_count
             FROM user_groups g ORDER BY g.id"
        );
    }

    /** 后台表单要的行（实时，不走缓存） */
    public static function findFresh(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM user_groups WHERE id = ?", [$id]);

        return $row ?: null;
    }

    /**
     * 组内用户数（删除前判断能不能删）
     */
    public static function userCount(int $id): int
    {
        return (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM users WHERE group_id = ? AND deleted_at IS NULL",
            [$id]
        )['c'] ?? 0);
    }

    /**
     * 后台新增/编辑用户组
     *
     * 12 个权限位由调用方按 PERMISSION_LABELS 的键顺序传进来（缺失即 0）。
     *
     * @param array<string, int> $flags 权限位，键顺序必须与表字段一致
     * @return int 受影响行数；新增时无意义（用 lastInsertId 拿 id 的场景请自行扩展）
     */
    public static function adminSave(int $id, string $name, string $permJson, int $isAdmin, array $flags): int
    {
        $columns = array_keys($flags);

        if ($id > 0) {
            $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", $columns));

            return Database::execute(
                "UPDATE user_groups SET name = ?, permissions = ?, is_admin = ?, {$set} WHERE id = ?",
                array_merge([$name, $permJson, $isAdmin], array_values($flags), [$id])
            );
        }

        $cols = implode(', ', $columns);
        $marks = implode(', ', array_fill(0, count($columns), '?'));

        return Database::execute(
            "INSERT INTO user_groups (name, permissions, is_admin, {$cols}, created_at)
             VALUES (?, ?, ?, {$marks}, ?)",
            array_merge([$name, $permJson, $isAdmin], array_values($flags), [time()])
        );
    }

    public static function remove(int $id): int
    {
        return Database::execute("DELETE FROM user_groups WHERE id = ?", [$id]);
    }
}
