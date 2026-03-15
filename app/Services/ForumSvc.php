<?php
/**
 * 板块业务逻辑层
 */

namespace App\Services;

use App\Repositories\ForumRepo;
use Core\Cache;

class ForumSvc
{
    private ForumRepo $forumRepo;

    /** 请求级实体缓存 */
    private static array $entityCache = [];

    public function __construct()
    {
        $this->forumRepo = new ForumRepo();
    }

    /**
     * 获取板块信息（请求级缓存 + Redis 缓存）
     */
    public function getForum(int $forumId): ?array
    {
        if (isset(self::$entityCache[$forumId])) {
            return self::$entityCache[$forumId];
        }

        $forum = Cache::get("forum:{$forumId}", function () use ($forumId) {
            return $this->forumRepo->findById($forumId);
        }, 600);

        if ($forum) {
            self::$entityCache[$forumId] = $forum;
        }
        return $forum;
    }

    /**
     * 清除板块请求级缓存
     */
    public static function clearEntityCache(int $forumId = 0): void
    {
        if ($forumId > 0) {
            unset(self::$entityCache[$forumId]);
        } else {
            self::$entityCache = [];
        }
    }

    /**
     * 获取首页板块列表（含子板块）
     */
    public function getForumsWithChildren(): array
    {
        return Cache::getStale('forums:list', function () {
            $forums = $this->forumRepo->getTopForums();
            // 一次性查询所有子板块，避免 N+1
            $allChildren = \Core\Database::fetchAll(
                "SELECT * FROM forums WHERE parent_id > 0 AND deleted_at IS NULL ORDER BY `rank` DESC"
            );
            $childrenMap = [];
            foreach ($allChildren as $child) {
                $childrenMap[$child['parent_id']][] = $child;
            }
            foreach ($forums as &$forum) {
                $fid = $forum['id'] ?? $forum['forum_id'];
                $forum['children'] = $childrenMap[$fid] ?? [];
            }
            return $forums;
        }, 3600) ?? [];
    }

    /**
     * 获取所有板块（扁平列表，用于选择器）
     */
    public function getAllForums(): array
    {
        return \Core\Database::fetchAllCached(
            "SELECT id, parent_id, name FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC",
            [],
            600
        );
    }

    /**
     * 发帖后更新板块统计
     */
    public function onThreadCreated(int $forumId, int $threadId): void
    {
        $this->forumRepo->incrementThreadCount($forumId, $threadId);
        $this->clearForumCache($forumId);
    }

    /**
     * 回复后更新板块统计
     */
    public function onPostCreated(int $forumId): void
    {
        $this->forumRepo->incrementPostCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 删帖后更新板块统计
     */
    public function onThreadDeleted(int $forumId): void
    {
        $this->forumRepo->decrementThreadCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 删回复后更新板块统计
     */
    public function onPostDeleted(int $forumId): void
    {
        $this->forumRepo->decrementPostCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 批量扣减板块回复数（删帖级联用）
     */
    public function onPostsDeleted(int $forumId, int $count): void
    {
        if ($count <= 0) return;
        \Core\Database::execute(
            "UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
            [$count, $count, $forumId]
        );
        $this->clearForumCache($forumId);
    }

    /**
     * 删除板块（软删除 + 级联清理）
     * 软删除板块下所有帖子和回复，清理 forum_access，更新全站统计
     */
    public function deleteForum(int $forumId): void
    {
        $forum = $this->forumRepo->findById($forumId);
        if (!$forum) {
            throw new \RuntimeException('板块不存在');
        }

        // 检查子板块
        $children = \Core\Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM forums WHERE parent_id = ? AND deleted_at IS NULL",
            [$forumId]
        );
        if (($children['cnt'] ?? 0) > 0) {
            throw new \RuntimeException('请先删除子板块');
        }

        $now = time();

        // 1. 从数据库获取实际帖子和回复数（避免缓存中的旧值不准确）
        $stats = \Core\Database::fetchOne(
            "SELECT COUNT(*) as thread_count, COALESCE(SUM(reply_count), 0) as post_count FROM threads WHERE forum_id = ? AND deleted_at IS NULL",
            [$forumId]
        );
        $threadCount = (int)($stats['thread_count'] ?? 0);
        $postCount = (int)($stats['post_count'] ?? 0);

        \Core\Database::beginTransaction();
        try {
            // 2. 扣减回复作者的 post_count
            $postAuthors = \Core\Database::fetchAll(
                "SELECT user_id, COUNT(*) as cnt FROM posts WHERE thread_id IN (SELECT id FROM threads WHERE forum_id = ? AND deleted_at IS NULL) AND deleted_at IS NULL GROUP BY user_id",
                [$forumId]
            );
            foreach ($postAuthors as $row) {
                \Core\Database::execute(
                    "UPDATE users SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
                    [(int)$row['cnt'], (int)$row['cnt'], (int)$row['user_id']]
                );
            }

            // 3. 扣减帖子作者的 thread_count
            $threadAuthors = \Core\Database::fetchAll(
                "SELECT user_id, COUNT(*) as cnt FROM threads WHERE forum_id = ? AND deleted_at IS NULL GROUP BY user_id",
                [$forumId]
            );
            foreach ($threadAuthors as $row) {
                \Core\Database::execute(
                    "UPDATE users SET thread_count = CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END WHERE id = ?",
                    [(int)$row['cnt'], (int)$row['cnt'], (int)$row['user_id']]
                );
            }

            // 4. 软删除板块下所有帖子的回复
            \Core\Database::execute(
                "UPDATE posts SET deleted_at = ? WHERE thread_id IN (SELECT id FROM threads WHERE forum_id = ? AND deleted_at IS NULL) AND deleted_at IS NULL",
                [$now, $forumId]
            );

            // 5. 软删除板块下所有帖子
            \Core\Database::execute(
                "UPDATE threads SET deleted_at = ? WHERE forum_id = ? AND deleted_at IS NULL",
                [$now, $forumId]
            );

            // 6. 清理板块访问控制
            \Core\Database::execute("DELETE FROM forum_access WHERE forum_id = ?", [$forumId]);

            // 7. 软删除板块
            $this->forumRepo->softDelete($forumId);

            \Core\Database::commit();
        } catch (\Throwable $e) {
            \Core\Database::rollBack();
            throw new \RuntimeException('删除板块失败: ' . $e->getMessage());
        }

        // 更新全站统计（事务外，非关键操作）
        if ($threadCount > 0) {
            RuntimeSvc::decrement('threads', $threadCount);
        }
        if ($postCount > 0) {
            RuntimeSvc::decrement('posts', $postCount);
        }

        $this->clearForumCache($forumId);
        // 清除该板块所有用户组的访问权限缓存
        Cache::deletePattern("forum_access:{$forumId}:*");
    }

    /**
     * 检查用户是否为指定板块的版主
     */
    public function isModerator(int $forumId, int $userId): bool
    {
        $forum = $this->getForum($forumId);
        if (!$forum || empty($forum['moderators'])) {
            return false;
        }
        $modIds = array_map('intval', array_filter(explode(',', $forum['moderators'])));
        return in_array($userId, $modIds, true);
    }

    /**
     * 检查板块级访问权限
     */
    public static function checkAccess(int $forumId, int $groupId, string $permission): bool
    {
        // 管理员始终有权限
        if ($groupId === \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            return true;
        }

        // 白名单验证权限字段名，防止字段名注入
        $allowedPermissions = ['read', 'thread', 'post', 'attach', 'down'];
        if (!in_array($permission, $allowedPermissions, true)) {
            return false;
        }

        $field = 'allow_' . $permission;
        $cacheKey = "forum_access:{$forumId}:{$groupId}";
        $access = \Core\Cache::get($cacheKey, function () use ($forumId, $groupId) {
            return \Core\Database::fetchOne(
                "SELECT * FROM forum_access WHERE forum_id = ? AND group_id = ?",
                [$forumId, $groupId]
            ) ?: ['_empty' => true];
        }, 600);
        // 无记录 = 全部允许
        if (!$access || !empty($access['_empty'])) {
            return true;
        }
        return (bool)(int)($access[$field] ?? 1);
    }

    /**
     * 清除板块相关缓存
     */
    public function clearForumCache(int $forumId): void
    {
        Cache::delete('forums:list');
        Cache::delete('forums:children:all');
        Cache::delete("forum:{$forumId}");
        self::clearEntityCache($forumId);
    }
}
