<?php
/**
 * 板块权限模型（forum_access 表）
 *
 * 表里存的是「非默认权限」：某组在某个板块上有一项被收紧时才落一行，
 * 全允许的组不落行（默认即允许）。所以保存是「先清空再按需插入」。
 *
 * 读缓存的 key 由 PermissionSvc 写入/读取（forum_access:{forumId}:{groupId}），
 * 这里的失效必须按同样的 key 前缀删，不能只删自己写的那一份。
 */

namespace App\Models;

use Core\Cache;
use Core\Database;

class ForumAccess extends Model
{
    protected static string $table = 'forum_access';

    /** 硬删除表：保存时整批替换 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 某板块的全部权限行（后台权限弹窗用）
     */
    public static function rowsForForum(int $forumId): array
    {
        return Database::fetchAll(
            "SELECT * FROM forum_access WHERE forum_id = ?",
            [$forumId]
        );
    }

    /** 板块级权限的缓存键（PermissionSvc 读、forgetCaches 按前缀删，必须同名） */
    public static function cacheKey(int $forumId, int $groupId): string
    {
        return "forum_access:{$forumId}:{$groupId}";
    }

    /**
     * 某组在某板块上的权限收紧项（没有收紧则返回 null = 全部允许）
     *
     * 「无记录」也要缓存：forum_access 里只存被收紧的组合，绝大多数组合都没有行，
     * 若不缓存空结果，每次权限判断都会打一次库。缓存里用 ['_empty' => true] 当哨兵，
     * 对外仍然返回 null，调用方语义不变。
     */
    public static function forGroupCached(int $forumId, int $groupId): ?array
    {
        $row = Cache::get(self::cacheKey($forumId, $groupId), static function () use ($forumId, $groupId): array {
            $found = Database::fetchOne(
                "SELECT * FROM forum_access WHERE forum_id = ? AND group_id = ?",
                [$forumId, $groupId]
            );

            return $found ?: ['_empty' => true];
        }, 600);

        if (!is_array($row) || !empty($row['_empty'])) {
            return null;
        }

        return $row;
    }

    /**
     * 删除某板块的全部权限行（删板块时级联）
     */
    public static function deleteForForum(int $forumId): int
    {
        $affected = Database::execute("DELETE FROM forum_access WHERE forum_id = ?", [$forumId]);
        self::forgetCaches($forumId);

        return $affected;
    }

    /**
     * 整批替换某板块的权限行
     *
     * @param array<int, array{allow_read: int, allow_thread: int, allow_post: int, allow_attach: int, allow_down: int}> $rows
     *        按 group_id 索引；五项全为 1 的组会被跳过（等于不落行）
     * @return int 实际写入的行数
     */
    public static function replaceForForum(int $forumId, array $rows): int
    {
        if ($forumId <= 0) {
            return 0;
        }

        $inserted = 0;
        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM forum_access WHERE forum_id = ?", [$forumId]);

            foreach ($rows as $groupId => $perms) {
                $gid = (int)$groupId;
                if ($gid <= 0) {
                    continue;
                }

                $allowRead   = (int)($perms['allow_read'] ?? 1);
                $allowThread = (int)($perms['allow_thread'] ?? 1);
                $allowPost   = (int)($perms['allow_post'] ?? 1);
                $allowAttach = (int)($perms['allow_attach'] ?? 1);
                $allowDown   = (int)($perms['allow_down'] ?? 1);

                if ($allowRead && $allowThread && $allowPost && $allowAttach && $allowDown) {
                    continue;
                }

                Database::execute(
                    "INSERT INTO forum_access (forum_id, group_id, allow_read, allow_thread, allow_post, allow_attach, allow_down)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$forumId, $gid, $allowRead, $allowThread, $allowPost, $allowAttach, $allowDown]
                );
                $inserted++;
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        self::forgetCaches($forumId);

        return $inserted;
    }

    /**
     * 清掉某板块所有用户组的权限缓存（key 前缀与 PermissionSvc 一致）
     */
    public static function forgetCaches(int $forumId): void
    {
        Cache::deletePattern("forum_access:{$forumId}:*");
    }
}
