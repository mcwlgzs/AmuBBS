<?php
/**
 * 统一权限检查服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class PermissionSvc
{
    /** 管理员用户组 ID */
    public const ADMIN_GROUP_ID = 3;

    /**
     * 检查用户是否拥有指定权限
     *
     * @param int $userId 用户ID
     * @param string $permission 权限名称 (read/thread/post/attach/down/top/update/delete/move/ban_user/delete_user/view_ip)
     * @param int|null $forumId 板块ID（可选，用于板块级权限检查）
     * @return bool
     */
    /** 允许的权限名称白名单 */
    private const VALID_PERMISSIONS = [
        'read', 'thread', 'post', 'attach', 'down',
        'top', 'update', 'delete', 'move',
        'ban_user', 'delete_user', 'view_ip',
    ];

    public static function can(int $userId, string $permission, ?int $forumId = null): bool
    {
        if (!in_array($permission, self::VALID_PERMISSIONS, true)) {
            return false;
        }

        $groupId = self::getUserGroupId($userId);
        if ($groupId === null) {
            return false;
        }

        // 管理员拥有全部权限
        if ($groupId === self::ADMIN_GROUP_ID) {
            return true;
        }

        // 版主对其管理的板块拥有管理权限
        if ($forumId !== null && self::isModerator($forumId, $userId)) {
            $modPerms = ['top', 'update', 'delete', 'move'];
            if (in_array($permission, $modPerms, true)) {
                return true;
            }
        }

        // 检查用户组权限
        $column = 'allow_' . $permission;
        $group = self::getGroupPermissions($groupId);
        if ($group === null || !isset($group[$column])) {
            return false;
        }
        if (!(int)$group[$column]) {
            return false;
        }

        // 检查板块级权限（如果指定了板块）
        if ($forumId !== null) {
            $forumPerm = self::getForumAccess($forumId, $groupId);
            if ($forumPerm !== null) {
                $forumColumn = 'allow_' . $permission;
                if (isset($forumPerm[$forumColumn]) && !(int)$forumPerm[$forumColumn]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 检查用户是否为指定板块的版主
     */
    public static function isModerator(int $forumId, int $userId): bool
    {
        static $forumSvc = null;
        if ($forumSvc === null) {
            $forumSvc = new ForumSvc();
        }
        $forum = $forumSvc->getForum($forumId);
        if (!$forum || empty($forum['moderators'])) {
            return false;
        }
        $modIds = array_map('intval', array_filter(explode(',', $forum['moderators'])));
        return in_array($userId, $modIds, true);
    }

    /**
     * 检查用户是否为管理员或版主（对指定板块有管理权限）
     */
    public static function isAdminOrMod(int $userId, ?int $forumId = null): bool
    {
        $groupId = self::getUserGroupId($userId);
        if ($groupId === self::ADMIN_GROUP_ID) {
            return true;
        }
        if ($forumId !== null && self::isModerator($forumId, $userId)) {
            return true;
        }
        return false;
    }

    /**
     * 检查用户是否有版主级操作权限（置顶/编辑/删除/移动等）
     * 管理员始终有权限，版主对其管理的板块有权限
     */
    public static function canModerate(int $userId, string $permission, ?int $forumId = null): bool
    {
        $groupId = self::getUserGroupId($userId);
        if ($groupId === null) {
            return false;
        }
        // 管理员
        if ($groupId === self::ADMIN_GROUP_ID) {
            return true;
        }
        // 版主对其管理的板块
        if ($forumId !== null && self::isModerator($forumId, $userId)) {
            return true;
        }
        // 检查用户组权限字段
        return self::can($userId, $permission, $forumId);
    }

    /** 请求内缓存，避免同一请求多次查询 */
    private static array $groupIdCache = [];

    /**
     * 获取用户的 group_id
     */
    public static function getUserGroupId(int $userId): ?int
    {
        if (isset(self::$groupIdCache[$userId])) {
            return self::$groupIdCache[$userId];
        }
        // 短 TTL 缓存 group_id，兼顾性能和管理员修改后的及时生效
        $user = Database::fetchOneCached("SELECT group_id FROM users WHERE id = ? AND deleted_at IS NULL", [$userId], 60);
        $result = $user ? (int)$user['group_id'] : null;
        self::$groupIdCache[$userId] = $result;
        return $result;
    }

    /**
     * 获取用户组权限（带缓存）
     */
    private static function getGroupPermissions(int $groupId): ?array
    {
        return Cache::get("group_perms:{$groupId}", function () use ($groupId) {
            return Database::fetchOne("SELECT * FROM user_groups WHERE id = ?", [$groupId]);
        }, 600);
    }

    /**
     * 获取板块级访问控制
     */
    private static function getForumAccess(int $forumId, int $groupId): ?array
    {
        return Cache::get("forum_access:{$forumId}:{$groupId}", function () use ($forumId, $groupId) {
            return Database::fetchOne(
                "SELECT * FROM forum_access WHERE forum_id = ? AND group_id = ?",
                [$forumId, $groupId]
            );
        }, 600);
    }

    /**
     * 清除权限缓存
     */
    public static function clearCache(int $groupId = 0, int $forumId = 0): void
    {
        if ($groupId > 0) {
            Cache::delete("group_perms:{$groupId}");
        }
        if ($forumId > 0 && $groupId > 0) {
            Cache::delete("forum_access:{$forumId}:{$groupId}");
        }
        // 同时清除请求级 groupId 缓存，确保长驻进程中权限变更即时生效
        self::$groupIdCache = [];
    }
}
