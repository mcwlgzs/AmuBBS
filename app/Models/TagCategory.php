<?php
/**
 * 标签分类模型
 *
 * 由原 App\Services\TagSvc 迁移而来（其中 tag_categories 相关的部分）。
 *
 * 单独成模型而不是并进 Tag：tag_categories 有自己的后台 CRUD（TagController）
 * 和自己的缓存键，是一个独立实体。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class TagCategory extends Model
{
    protected static string $table = 'tag_categories';

    /**
     * 分类列表（forum_id = 0 的全局分类 + 指定板块的分类，带缓存）
     */
    public static function getCategories(int $forumId = 0): array
    {
        return Cache::get(self::cacheKey($forumId), function () use ($forumId) {
            return Database::fetchAll(
                "SELECT * FROM tag_categories WHERE forum_id IN (0, ?) ORDER BY sort_order ASC, id ASC",
                [$forumId]
            );
        }, 600);
    }

    /**
     * 后台分类列表（带所属板块名与标签数，实时不过缓存）
     */
    public static function adminList(): array
    {
        return Database::fetchAll(
            "SELECT tc.*, f.name as forum_name,
                    (SELECT COUNT(*) FROM tags WHERE category_id = tc.id) as tag_count
             FROM tag_categories tc
             LEFT JOIN forums f ON tc.forum_id = f.id
             ORDER BY tc.sort_order ASC, tc.id ASC"
        );
    }

    /**
     * 创建分类
     */
    public static function createCategory(string $name, int $forumId = 0, int $sortOrder = 0): int
    {
        Database::execute(
            "INSERT INTO tag_categories (name, forum_id, sort_order, created_at) VALUES (?, ?, ?, ?)",
            [$name, $forumId, $sortOrder, time()]
        );

        // 全局列表（forum_id = 0）也要失效，否则新分类不会出现在各处
        Cache::delete(self::cacheKey($forumId));
        Cache::delete(self::cacheKey(0));

        return Database::lastInsertId();
    }

    /**
     * 删除分类，其下标签归入「未分类」（category_id = 0）
     */
    public static function deleteCategory(int $id): void
    {
        Database::beginTransaction();
        try {
            Database::execute("UPDATE tags SET category_id = 0 WHERE category_id = ?", [$id]);
            Database::execute("DELETE FROM tag_categories WHERE id = ?", [$id]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Cache::delete(self::cacheKey(0));
    }

    public static function cacheKey(int $forumId): string
    {
        return "tag_categories:{$forumId}";
    }
}
