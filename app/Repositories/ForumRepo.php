<?php
/**
 * 板块数据访问层
 */

namespace App\Repositories;

use Core\Database;

class ForumRepo
{
    public function findById(int $id): ?array
    {
        return Database::fetchOneCached("
            SELECT * FROM forums
            WHERE id = ? AND deleted_at IS NULL
        ", [$id], 600);
    }

    public function getTopForums(): array
    {
        return Database::fetchAllCached("
            SELECT * FROM forums
            WHERE parent_id = 0 AND deleted_at IS NULL
            ORDER BY `rank` DESC
        ", [], 600);
    }

    public function getChildren(int $parentId): array
    {
        return Database::fetchAllCached("
            SELECT * FROM forums
            WHERE parent_id = ? AND deleted_at IS NULL
            ORDER BY `rank` DESC
        ", [$parentId], 600);
    }

    public function incrementThreadCount(int $forumId, int $threadId): int
    {
        return Database::execute("
            UPDATE forums
            SET thread_count = thread_count + 1,
                today_threads = today_threads + 1,
                last_thread_id = ?,
                last_post_time = ?
            WHERE id = ?
        ", [$threadId, time(), $forumId]);
    }

    public function incrementPostCount(int $forumId): int
    {
        return Database::execute("
            UPDATE forums
            SET post_count = post_count + 1,
                today_posts = today_posts + 1,
                last_post_time = ?
            WHERE id = ?
        ", [time(), $forumId]);
    }

    public function decrementThreadCount(int $forumId): int
    {
        return Database::execute("
            UPDATE forums
            SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END,
                today_threads = CASE WHEN today_threads > 0 THEN today_threads - 1 ELSE 0 END
            WHERE id = ?
        ", [$forumId]);
    }

    public function decrementPostCount(int $forumId): int
    {
        return Database::execute("
            UPDATE forums
            SET post_count = CASE WHEN post_count > 0 THEN post_count - 1 ELSE 0 END,
                today_posts = CASE WHEN today_posts > 0 THEN today_posts - 1 ELSE 0 END
            WHERE id = ?
        ", [$forumId]);
    }

    public function softDelete(int $forumId): int
    {
        $now = time();
        Database::beginTransaction();
        try {
            // 级联软删除子板块（含多级嵌套）
            $this->softDeleteChildren($forumId, $now);
            $result = Database::execute("
                UPDATE forums SET deleted_at = ? WHERE id = ?
            ", [$now, $forumId]);
            Database::commit();
            return $result;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 递归软删除子板块
     */
    private function softDeleteChildren(int $parentId, int $now): void
    {
        $children = Database::fetchAll(
            "SELECT id FROM forums WHERE parent_id = ? AND deleted_at IS NULL",
            [$parentId]
        );
        foreach ($children as $child) {
            $this->softDeleteChildren((int)$child['id'], $now);
        }
        Database::execute("
            UPDATE forums SET deleted_at = ? WHERE parent_id = ? AND deleted_at IS NULL
        ", [$now, $parentId]);
    }
}
