<?php
namespace App\Services;

use App\Models\NavCategory;
use App\Models\NavLink;
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
        return Cache::get(NavLink::CACHE_ALL, function () {
            $categories = NavCategory::allOrdered();
            $catIds = array_column($categories, 'id');

            // 一次查出所有链接，PHP 端按 category_id 分组，避免 N+1
            $links = NavLink::getByCategories($catIds);
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
        NavLink::incrementClicks($linkId);
    }

    /**
     * 创建分类
     */
    public static function createCategory(string $name, string $icon = '', int $rank = 0): int
    {
        return NavCategory::create($name, $icon, $rank);
    }

    /**
     * 创建链接
     */
    public static function createLink(int $categoryId, string $name, string $url, string $desc = '', string $icon = '', int $rank = 0): int
    {
        return NavLink::create($categoryId, $name, $url, $desc, $icon, $rank);
    }

    /**
     * 删除链接
     */
    public static function deleteLink(int $linkId): void
    {
        NavLink::deleteById($linkId);
    }

    /**
     * 获取热门链接
     */
    public static function getPopular(int $limit = 10): array
    {
        return NavLink::getPopular($limit);
    }
}
