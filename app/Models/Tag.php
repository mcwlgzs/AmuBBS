<?php
/**
 * 标签模型
 *
 * 由原 App\Services\TagSvc 迁移而来（其中 tags / thread_tags 相关的部分）。
 * 标签分类（tag_categories）另见 App\Models\TagCategory。
 *
 * 这里保留批量写法（一次查找已有标签、一次 INSERT IGNORE、一次更新计数），
 * 因为「怎么高效地存标签」本来就是数据层的事，放在模型里正合适。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Tag extends Model
{
    protected static string $table = 'tags';

    /** 单个标签名最长长度 */
    private const NAME_MAX = 30;

    /**
     * 为帖子附加标签
     *
     * 已存在的标签复用，缺失的批量创建，最后批量建立关联并更新计数。
     */
    public static function attachTags(int $threadId, string $tagNames, int $categoryId = 0): void
    {
        $names = array_filter(array_map(function ($n) {
            return mb_substr(trim($n), 0, self::NAME_MAX);
        }, explode(',', $tagNames)));

        // 去重，防止重复创建标签
        $names = array_unique($names);

        if (empty($names)) {
            return;
        }

        // 批量查找已有标签
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $existingTags = Database::fetchAll(
            "SELECT id, name FROM tags WHERE name IN ({$placeholders})",
            array_values($names)
        );
        $tagMap = [];
        foreach ($existingTags as $t) {
            $tagMap[$t['name']] = (int)$t['id'];
        }

        // 批量查找已有关联
        $existingLinks = Database::fetchAll(
            "SELECT tag_id FROM thread_tags WHERE thread_id = ?",
            [$threadId]
        );
        $linkedTagIds = array_flip(array_column($existingLinks, 'tag_id'));

        $now = time();
        $newTags = [];
        $tagIdsToLink = [];

        // 第一步：区分「已有标签待关联」和「需要新建的标签」
        foreach ($names as $name) {
            if (isset($tagMap[$name])) {
                $tagId = $tagMap[$name];
                if (!isset($linkedTagIds[$tagId])) {
                    $tagIdsToLink[] = $tagId;
                }
            } else {
                $newTags[] = $name;
            }
        }

        // 第二步：批量创建新标签
        if (!empty($newTags)) {
            $values = [];
            $params = [];
            foreach ($newTags as $name) {
                $values[] = "(?, ?, 0, ?)";
                $params[] = $name;
                $params[] = $categoryId;
                $params[] = $now;
            }
            Database::execute(
                "INSERT IGNORE INTO tags (name, category_id, thread_count, created_at) VALUES " . implode(', ', $values),
                $params
            );

            // 重新查询拿到新建标签的 ID
            $placeholders = implode(',', array_fill(0, count($newTags), '?'));
            $newTagRows = Database::fetchAll(
                "SELECT id, name FROM tags WHERE name IN ({$placeholders})",
                $newTags
            );
            foreach ($newTagRows as $row) {
                $tagMap[$row['name']] = (int)$row['id'];
                $tagIdsToLink[] = (int)$row['id'];
            }
        }

        // 第三步：批量建立关联并更新计数
        if (!empty($tagIdsToLink)) {
            $values = [];
            $params = [];
            foreach ($tagIdsToLink as $tagId) {
                if (!isset($linkedTagIds[$tagId])) {
                    $values[] = "(?, ?)";
                    $params[] = $threadId;
                    $params[] = $tagId;
                }
            }

            if (!empty($values)) {
                Database::execute(
                    "INSERT IGNORE INTO thread_tags (thread_id, tag_id) VALUES " . implode(', ', $values),
                    $params
                );

                $placeholders = implode(',', array_fill(0, count($tagIdsToLink), '?'));
                Database::execute(
                    "UPDATE tags SET thread_count = thread_count + 1 WHERE id IN ({$placeholders})",
                    $tagIdsToLink
                );
            }
        }

        self::forgetTagCaches();
    }

    /**
     * 同步帖子标签：先减去旧标签计数并清空关联，再附加新标签（整体一个事务）
     */
    public static function syncTags(int $threadId, string $tagNames): void
    {
        Database::beginTransaction();
        try {
            // 批量减少旧标签计数
            $oldTags = Database::fetchAll("SELECT tag_id FROM thread_tags WHERE thread_id = ?", [$threadId]);
            if (!empty($oldTags)) {
                $oldIds = array_column($oldTags, 'tag_id');
                $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
                Database::execute(
                    "UPDATE tags SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END WHERE id IN ({$placeholders})",
                    $oldIds
                );
            }
            Database::execute("DELETE FROM thread_tags WHERE thread_id = ?", [$threadId]);

            // 附加新标签
            self::attachTags($threadId, $tagNames);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 批量加载帖子标签（消除 N+1）
     *
     * @return array<int, array<int, array{name: string}>> [thread_id => [['name' => ...], ...]]
     */
    public static function batchLoadTags(array $threadIds): array
    {
        if (empty($threadIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $rows = Database::fetchAll(
            "SELECT tt.thread_id, tg.name
             FROM thread_tags tt
             INNER JOIN tags tg ON tg.id = tt.tag_id
             WHERE tt.thread_id IN ({$placeholders})",
            $threadIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['thread_id']][] = ['name' => $row['name']];
        }

        return $map;
    }

    /**
     * 热门标签（带缓存）
     */
    public static function getPopularTags(int $limit = 20): array
    {
        return Cache::get("tags:popular:{$limit}", function () use ($limit) {
            return Database::fetchAll(
                "SELECT * FROM tags WHERE thread_count > 0 ORDER BY thread_count DESC LIMIT ?",
                [$limit]
            );
        }, 600);
    }

    /**
     * 某分类下的标签
     */
    public static function getTagsByCategory(int $categoryId, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT * FROM tags WHERE category_id = ? ORDER BY thread_count DESC LIMIT ?",
            [$categoryId, $limit]
        );
    }

    /**
     * 某个帖子挂的标签（带缓存）
     */
    public static function getByThread(int $threadId): array
    {
        return Cache::get("thread:tags:{$threadId}", function () use ($threadId) {
            return Database::fetchAll(
                "SELECT t.* FROM tags t
                 INNER JOIN thread_tags tt ON t.id = tt.tag_id
                 WHERE tt.thread_id = ?",
                [$threadId]
            );
        }, 600);
    }

    /**
     * 按名字查标签（不区分大小写交给数据库的排序规则）
     */
    public static function findByName(string $name): ?array
    {
        return Database::fetchOne("SELECT * FROM tags WHERE name = ?", [$name]);
    }

    /**
     * 标签选择器用的列表：不筛掉 thread_count = 0 的标签，让作者也能用新标签
     */
    public static function getPickerList(int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT * FROM tags ORDER BY thread_count DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * 板块关联的标签（经标签分类），按分类分组（带缓存）
     *
     * @return array<int, array{name: string, tags: array}>
     */
    public static function getTagsForForum(int $forumId): array
    {
        $cacheKey = "tags:forum:{$forumId}";

        return Cache::get($cacheKey, function () use ($forumId) {
            $categories = TagCategory::getCategories($forumId);
            if (empty($categories)) {
                return [];
            }

            $catIds = array_column($categories, 'id');
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $tags = Database::fetchAll(
                "SELECT t.*, tc.name as category_name
                 FROM tags t
                 LEFT JOIN tag_categories tc ON t.category_id = tc.id
                 WHERE t.category_id IN ({$placeholders})
                 ORDER BY tc.sort_order ASC, t.thread_count DESC",
                $catIds
            );

            // 按分类分组
            $grouped = [];
            foreach ($categories as $cat) {
                $grouped[$cat['id']] = ['name' => $cat['name'], 'tags' => []];
            }
            foreach ($tags as $tag) {
                $cid = (int)$tag['category_id'];
                if (isset($grouped[$cid])) {
                    $grouped[$cid]['tags'][] = $tag;
                }
            }

            return array_filter($grouped, fn($g) => !empty($g['tags']));
        }, 300);
    }

    /**
     * 清除标签相关缓存（写标签后统一调用）
     */
    public static function forgetTagCaches(): void
    {
        Cache::delete('tags:popular:50');
        Cache::deletePattern('tags:forum:*');
    }
}
