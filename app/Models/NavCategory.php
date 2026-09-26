<?php
/**
 * 导航分类模型（nav_categories 表）
 */

namespace App\Models;

use Core\Database;

class NavCategory extends Model
{
    protected static string $table = 'nav_categories';

    /**
     * 全部分类（按权重）
     */
    public static function allOrdered(): array
    {
        return Database::fetchAll("SELECT * FROM nav_categories WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC");
    }

    /**
     * 下拉框用的精简列表
     */
    public static function options(): array
    {
        return Database::fetchAll("SELECT id, name FROM nav_categories WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC");
    }

    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM nav_categories WHERE id = ? AND deleted_at IS NULL", [$id]);

        return $row ?: null;
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    public static function create(string $name, string $icon = '', int $rank = 0): int
    {
        $id = Database::execute(
            "INSERT INTO nav_categories (name, icon, `rank`, created_at) VALUES (?, ?, ?, ?)",
            [$name, $icon, $rank, time()]
        );
        NavLink::forgetCaches();

        return $id;
    }

    public static function update(int $id, string $name, string $icon = '', int $rank = 0): int
    {
        $affected = Database::execute(
            "UPDATE nav_categories SET name = ?, icon = ?, `rank` = ? WHERE id = ?",
            [$name, $icon, $rank, $id]
        );
        NavLink::forgetCaches();

        return $affected;
    }

    /**
     * 删除分类并连带软删除其下链接
     *
     * 两张表要一起改，所以事务放在这里；调用方不再自己管连接与事务。
     */
    public static function deleteWithLinks(int $id): void
    {
        Database::useMaster();
        try {
            Database::beginTransaction();
            Database::execute("UPDATE nav_categories SET deleted_at = ? WHERE id = ?", [time(), $id]);
            Database::execute("UPDATE nav_links SET deleted_at = ? WHERE category_id = ?", [time(), $id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        } finally {
            Database::restoreReadWrite();
        }

        NavLink::forgetCaches();
    }
}
