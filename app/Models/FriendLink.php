<?php
/**
 * 友情链接模型（friend_links 表）
 *
 * 页脚组件原来自己在视图里写 SQL + 缓存，后台又各写一份增删。
 * 现在 SQL 与缓存键都收在这里，视图和后台都只调方法。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class FriendLink extends Model
{
    protected static string $table = 'friend_links';

    /** friend_links 是硬删除 */
    protected static ?string $softDeleteColumn = null;

    /** 前台页脚展示用的缓存键 */
    public const CACHE_ACTIVE = 'friend_links:active';

    /**
     * 后台列表：全部链接，按排序值
     */
    public static function allOrdered(): array
    {
        return Database::fetchAll("SELECT * FROM friend_links ORDER BY sort_order ASC, id ASC");
    }

    /**
     * 前台展示用的启用中链接（带缓存）
     */
    public static function active(int $limit = 30): array
    {
        return Cache::get(self::CACHE_ACTIVE, function () use ($limit) {
            return Database::fetchAll(
                "SELECT name, url, logo FROM friend_links WHERE status = 1 ORDER BY sort_order ASC, id ASC LIMIT ?",
                [$limit]
            );
        }, 600);
    }

    public static function create(string $name, string $url, string $logo = '', int $sortOrder = 0): int
    {
        $id = Database::execute(
            "INSERT INTO friend_links (name, url, logo, sort_order, status, created_at) VALUES (?, ?, ?, ?, 1, ?)",
            [$name, $url, $logo, $sortOrder, time()]
        );
        self::forgetCaches();

        return $id;
    }

    public static function deleteById(int $id): int
    {
        $affected = Database::execute("DELETE FROM friend_links WHERE id = ?", [$id]);
        self::forgetCaches();

        return $affected;
    }

    public static function forgetCaches(): void
    {
        Cache::delete(self::CACHE_ACTIVE);
    }
}
