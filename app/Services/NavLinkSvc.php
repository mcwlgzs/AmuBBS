<?php
namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * 网址导航服务
 */
class NavLinkSvc
{
    /**
     * 获取所有分类及链接
     */
    public static function getAll(): array
    {
        return Cache::get('nav_links:all', function () {
            $categories = Database::fetchAll(
                "SELECT * FROM nav_categories WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC"
            );

            // 一次查出所有链接，PHP 端按 category_id 分组，避免 N+1
            $links = Database::fetchAll(
                "SELECT * FROM nav_links WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC"
            );
            $linkMap = [];
            foreach ($links as $link) {
                $linkMap[$link['category_id']][] = $link;
            }
            foreach ($categories as &$cat) {
                $cat['links'] = $linkMap[$cat['id']] ?? [];
            }
            unset($cat);

            return $categories;
        }, 600);
    }

    /**
     * 记录点击
     */
    public static function recordClick(int $linkId): void
    {
        Database::execute("UPDATE nav_links SET clicks = clicks + 1 WHERE id = ?", [$linkId]);
    }

    /**
     * 创建分类
     */
    public static function createCategory(string $name, string $icon = '', int $rank = 0): int
    {
        Database::execute(
            "INSERT INTO nav_categories (name, icon, `rank`, created_at) VALUES (?, ?, ?, ?)",
            [$name, $icon, $rank, time()]
        );
        Cache::delete('nav_links:all');
        return (int)Database::getConnection()->lastInsertId();
    }

    /**
     * 创建链接
     */
    public static function createLink(int $categoryId, string $name, string $url, string $desc = '', string $icon = '', int $rank = 0): int
    {
        Database::execute(
            "INSERT INTO nav_links (category_id, name, url, description, icon, `rank`, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$categoryId, $name, $url, $desc, $icon, $rank, time()]
        );
        Cache::delete('nav_links:all');
        return (int)Database::getConnection()->lastInsertId();
    }

    /**
     * 删除链接
     */
    public static function deleteLink(int $linkId): void
    {
        Database::execute("UPDATE nav_links SET deleted_at = ? WHERE id = ?", [time(), $linkId]);
        Cache::delete('nav_links:all');
    }

    /**
     * 获取热门链接
     */
    public static function getPopular(int $limit = 10): array
    {
        return Database::fetchAll(
            "SELECT * FROM nav_links WHERE deleted_at IS NULL ORDER BY clicks DESC LIMIT ?",
            [$limit]
        );
    }
}
