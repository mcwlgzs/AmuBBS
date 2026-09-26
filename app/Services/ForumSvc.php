<?php
/**
 * 板块业务逻辑层
 */

namespace App\Services;

use App\Models\Forum;
use App\Models\ForumAccess;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use Core\Cache;

class ForumSvc
{
    /** 请求级实体缓存 */
    private static array $entityCache = [];

    /**
     * 获取板块信息（请求级缓存 + Redis 缓存）
     */
    public function getForum(int $forumId): ?array
    {
        if (isset(self::$entityCache[$forumId])) {
            return self::$entityCache[$forumId];
        }

        $forum = Cache::get("forum:{$forumId}", function () use ($forumId) {
            return Forum::findById($forumId);
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
            $forums = Forum::getTopForums();
            // 一次性查询所有子板块，避免 N+1
            // （这里必须用 childrenRows()，不能用 childrenCached()：本闭包已经持有
            //   forums 相关的 SWR 锁，再套一层同 key 的缓存会拿到空值）
            $map = Forum::groupByParent(Forum::childrenRows());
            foreach ($forums as &$forum) {
                $fid = (int)($forum['id'] ?? $forum['forum_id'] ?? 0);
                $forum['children'] = $map[$fid] ?? [];
            }
            unset($forum);

            return $forums;
        }, 3600) ?? [];
    }

    /**
     * 获取所有板块（扁平列表，用于选择器）
     */
    public function getAllForums(): array
    {
        return Forum::pickerCached();
    }

    /**
     * 发帖后更新板块统计
     */
    public function onThreadCreated(int $forumId, int $threadId): void
    {
        Forum::incrementThreadCount($forumId, $threadId);
        $this->clearForumCache($forumId);
    }

    /**
     * 回复后更新板块统计
     */
    public function onPostCreated(int $forumId): void
    {
        Forum::incrementPostCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 删帖后更新板块统计
     */
    public function onThreadDeleted(int $forumId): void
    {
        Forum::decrementThreadCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 删回复后更新板块统计
     */
    public function onPostDeleted(int $forumId): void
    {
        Forum::decrementPostCount($forumId);
        $this->clearForumCache($forumId);
    }

    /**
     * 批量扣减板块回复数（删帖级联用）
     */
    public function onPostsDeleted(int $forumId, int $count): void
    {
        if ($count <= 0) return;
        Forum::adjustPostCount($forumId, -$count);
        $this->clearForumCache($forumId);
    }

    /**
     * 删除板块（软删除 + 级联清理）
     * 软删除板块下所有帖子和回复，清理 forum_access，更新全站统计
     */
    public function deleteForum(int $forumId): void
    {
        $forum = Forum::findById($forumId);
        if (!$forum) {
            throw new \RuntimeException('板块不存在');
        }

        // 检查子板块
        if (Forum::hasChildren($forumId)) {
            throw new \RuntimeException('请先删除子板块');
        }

        $now = time();

        // 1. 从数据库获取实际帖子和回复数（避免缓存中的旧值不准确）
        $stats = Thread::statsForForum($forumId);
        $threadCount = $stats['thread_count'];
        $postCount = $stats['post_count'];

        \Core\Database::beginTransaction();
        try {
            // 2. 扣减回复作者的 post_count
            foreach (Post::authorCountsInForum($forumId) as $row) {
                User::adjustPostCount((int)$row['user_id'], -(int)$row['cnt']);
            }

            // 3. 扣减帖子作者的 thread_count
            foreach (Thread::authorCountsInForum($forumId) as $row) {
                User::adjustThreadCount((int)$row['user_id'], -(int)$row['cnt']);
            }

            // 4. 软删除板块下所有帖子的回复
            Post::softDeleteByForum($forumId, $now);

            // 5. 软删除板块下所有帖子
            Thread::softDeleteByForum($forumId, $now);

            // 6. 清理板块访问控制
            ForumAccess::deleteForForum($forumId);

            // 7. 软删除板块
            Forum::softDelete($forumId);

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
        $access = ForumAccess::forGroupCached($forumId, $groupId);
        // 无记录 = 全部允许
        if ($access === null) {
            return true;
        }
        return (bool)(int)($access[$field] ?? 1);
    }

    /**
     * 清除板块相关缓存
     */
    public function clearForumCache(int $forumId): void
    {
        // 键名统一交给模型，避免这里和 Forum::forgetCaches 各记一份名单
        Forum::forgetCaches($forumId);
        self::clearEntityCache($forumId);
    }

    /**
     * 当前访客（含游客）能否浏览指定板块
     *
     * 论坛原来只有「板块列表页」做了 read 检查，帖子详情页 / REST API / sitemap
     * 都能直接读到受限板块的内容；统一走这里。
     *
     * @param int      $forumId 板块 ID
     * @param int|null $userId  登录用户 ID；游客传 null
     */
    public static function canRead(int $forumId, ?int $userId = null): bool
    {
        if ($forumId <= 0) {
            return true; // 无板块归属（历史数据）不拦
        }

        if ($userId === null || $userId <= 0) {
            // 游客沿用项目既有约定：未登录时按内置最低用户组（1）判定
            $groupId = (int)($_SESSION['group_id'] ?? 1);

            return self::checkAccess($forumId, $groupId, 'read');
        }

        return \App\Services\PermissionSvc::can($userId, 'read', $forumId);
    }
}
