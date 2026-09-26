<?php
/**
 * 导航链接模型（nav_links 表）
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class NavLink extends Model
{
    protected static string $table = 'nav_links';

    /** 导航树的缓存键（前台导航栏用） */
    public const CACHE_ALL = 'nav_links:all';

    /**
     * 按分类批量取链接
     *
     * @param int[] $categoryIds
     */
    public static function getByCategories(array $categoryIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $categoryIds), static fn(int $i): bool => $i > 0));
        if (empty($ids)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));

        return Database::fetchAll(
            "SELECT * FROM nav_links WHERE category_id IN ({$ph}) AND deleted_at IS NULL ORDER BY `rank` DESC, id ASC",
            $ids
        );
    }

    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne("SELECT * FROM nav_links WHERE id = ? AND deleted_at IS NULL", [$id]);

        return $row ?: null;
    }

    /**
     * 只要跳转地址（导航链接点击中转用）
     */
    public static function getUrl(int $id): ?string
    {
        $row = Database::fetchOne("SELECT url FROM nav_links WHERE id = ?", [$id]);

        return $row === null ? null : (string)$row['url'];
    }

    public static function create(int $categoryId, string $name, string $url, string $description = '', string $icon = '', int $rank = 0): int
    {
        $id = Database::execute(
            "INSERT INTO nav_links (category_id, name, url, description, icon, `rank`, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$categoryId, $name, $url, $description, $icon, $rank, time()]
        );
        self::forgetCaches();

        return $id;
    }

    public static function update(int $id, int $categoryId, string $name, string $url, string $description = '', string $icon = '', int $rank = 0): int
    {
        $affected = Database::execute(
            "UPDATE nav_links SET category_id = ?, name = ?, url = ?, description = ?, icon = ?, `rank` = ? WHERE id = ?",
            [$categoryId, $name, $url, $description, $icon, $rank, $id]
        );
        self::forgetCaches();

        return $affected;
    }

    public static function deleteById(int $id): int
    {
        $affected = self::softDeleteById($id);
        self::forgetCaches();

        return $affected;
    }

    /**
     * 点击计数 +1（导航页点链接时调用，不需要清缓存）
     */
    public static function incrementClicks(int $id): int
    {
        return Database::execute("UPDATE nav_links SET clicks = clicks + 1 WHERE id = ?", [$id]);
    }

    /**
     * 按点击数取热门链接
     */
    public static function getPopular(int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT * FROM nav_links WHERE deleted_at IS NULL ORDER BY clicks DESC LIMIT ?",
            [$limit]
        );
    }
    public static function forgetCaches(): void
    {
        Cache::delete(self::CACHE_ALL);
    }
}
