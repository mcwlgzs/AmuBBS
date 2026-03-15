<?php
/**
 * 标签服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class TagSvc
{
    /**
     * 为帖子附加标签（优化：批量查找已有标签，减少逐条查询）
     */
    public static function attachTags(int $threadId, string $tagNames, int $categoryId = 0): void
    {
        $names = array_filter(array_map(function($n) {
            return mb_substr(trim($n), 0, 30);
        }, explode(',', $tagNames)));

        // 去重，防止重复创建标签
        $names = array_unique($names);

        if (empty($names)) return;

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

        // 第一步：收集需要创建的新标签
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

            // 重新查询获取新创建标签的 ID
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

        // 第三步：批量关联标签
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

                // 批量更新标签计数
                $placeholders = implode(',', array_fill(0, count($tagIdsToLink), '?'));
                Database::execute(
                    "UPDATE tags SET thread_count = thread_count + 1 WHERE id IN ({$placeholders})",
                    $tagIdsToLink
                );
            }
        }

        // 清除标签相关缓存
        Cache::delete('tags:popular:50');
        Cache::deletePattern('tags:forum:*');
    }

    /**
     * 同步帖子标签（优化：批量更新旧标签计数）
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
     * 返回 [thread_id => [['name' => ...], ...], ...]
     */
    public static function batchLoadTags(array $threadIds): array
    {
        if (empty($threadIds)) return [];

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $rows = Database::fetchAll("
            SELECT tt.thread_id, tg.name
            FROM thread_tags tt
            INNER JOIN tags tg ON tg.id = tt.tag_id
            WHERE tt.thread_id IN ({$placeholders})
        ", $threadIds);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['thread_id']][] = ['name' => $row['name']];
        }
        return $map;
    }

    /**
     * 获取热门标签
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
     * 获取标签分类列表
     */
    public static function getCategories(int $forumId = 0): array
    {
        $cacheKey = "tag_categories:{$forumId}";
        return Cache::get($cacheKey, function () use ($forumId) {
            return Database::fetchAll(
                "SELECT * FROM tag_categories WHERE forum_id IN (0, ?) ORDER BY sort_order ASC, id ASC",
                [$forumId]
            );
        }, 600);
    }

    /**
     * 获取分类下的标签
     */
    public static function getTagsByCategory(int $categoryId, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT * FROM tags WHERE category_id = ? ORDER BY thread_count DESC LIMIT ?",
            [$categoryId, $limit]
        );
    }

    /**
     * 获取板块关联的标签（通过分类）
     */
    public static function getTagsForForum(int $forumId): array
    {
        $cacheKey = "tags:forum:{$forumId}";
        return Cache::get($cacheKey, function () use ($forumId) {
            $categories = self::getCategories($forumId);
            if (empty($categories)) return [];

            $catIds = array_column($categories, 'id');
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $tags = Database::fetchAll(
                "SELECT t.*, tc.name as category_name FROM tags t LEFT JOIN tag_categories tc ON t.category_id = tc.id WHERE t.category_id IN ({$placeholders}) ORDER BY tc.sort_order ASC, t.thread_count DESC",
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
     * 创建标签分类
     */
    public static function createCategory(string $name, int $forumId = 0, int $sortOrder = 0): int
    {
        Database::execute(
            "INSERT INTO tag_categories (name, forum_id, sort_order, created_at) VALUES (?, ?, ?, ?)",
            [$name, $forumId, $sortOrder, time()]
        );
        Cache::delete("tag_categories:{$forumId}");
        Cache::delete("tag_categories:0");
        return Database::lastInsertId();
    }

    /**
     * 删除标签分类（标签归入未分类）
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
        Cache::delete("tag_categories:0");
    }
}
