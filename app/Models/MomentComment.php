<?php
/**
 * 动态评论模型
 *
 * 由原 App\Services\MomentSvc 迁移而来（其中 moment_comments 表的数据访问部分）。
 * 频率限制、敏感词、黑名单、通知等编排在 app/Controllers/Moment.php。
 */

namespace App\Models;

use Core\Database;

class MomentComment extends Model
{
    protected static string $table = 'moment_comments';

    /** 评论列表的公共 SELECT（含作者与被回复人信息） */
    private const BASE_SELECT =
        "SELECT mc.*, u.username, u.nickname, u.avatar, u.nickname_color,
                ru.username as reply_username, ru.nickname as reply_nickname,
                ru.nickname_color as reply_nickname_color
         FROM moment_comments mc
         LEFT JOIN users u ON mc.user_id = u.id
         LEFT JOIN users ru ON mc.reply_user_id = ru.id";

    /**
     * 插入评论，并在**同一事务**里把动态的 comment_count +1
     *
     * 计数与评论行必须成对更新，所以事务放在模型里，不能拆到控制器。
     *
     * @param int $parentId 要回复的那条评论 id（0 = 顶层评论）。
     *                      传子评论 id 时会归一到它所属的顶层评论，保证只有一层嵌套。
     */
    public static function create(int $momentId, int $userId, int $replyUserId, string $content, int $parentId = 0): int
    {
        $parentId = self::normalizeParentId($momentId, $parentId);

        Database::beginTransaction();

        try {
            Database::execute(
                "INSERT INTO moment_comments (moment_id, user_id, reply_user_id, parent_id, content, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$momentId, $userId, $replyUserId, $parentId, $content, time()]
            );
            $commentId = Database::lastInsertId();

            Moment::incrementCommentCount($momentId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return (int)$commentId;
    }

    /**
     * 把「要回复的评论 id」归一成合法的父评论 id
     *
     * - 父评论不存在 / 不属于这条动态 / 已删除 → 0（退化成顶层评论，不报错）
     * - 目标是子评论 → 返回它所属的顶层评论 id（回复子评论 = 回到同一个线程，只有一层）
     */
    public static function normalizeParentId(int $momentId, int $parentId): int
    {
        if ($momentId <= 0 || $parentId <= 0) {
            return 0;
        }

        $row = Database::fetchOne(
            "SELECT id, parent_id FROM moment_comments
              WHERE id = ? AND moment_id = ? AND deleted_at IS NULL
              LIMIT 1",
            [$parentId, $momentId]
        );

        if (!$row) {
            return 0;
        }

        $parent = (int)($row['parent_id'] ?? 0);

        return $parent > 0 ? $parent : (int)$row['id'];
    }

    /**
     * 某条动态的评论列表（拍平，含作者与被回复人）
     */
    public static function getByMoment(int $momentId, int $limit = 20): array
    {
        return Database::fetchAll(
            self::BASE_SELECT . "
             WHERE mc.moment_id = ? AND mc.deleted_at IS NULL
             ORDER BY mc.created_at ASC, mc.id ASC
             LIMIT ?",
            [$momentId, $limit]
        );
    }

    /**
     * 某条动态的**线程化**评论（顶层评论 + 其回复，只一层嵌套）
     *
     * @param int $topLimit   最多返回几个顶层评论；<=0 表示不限（「查看全部」用）
     * @param int $replyLimit 每个顶层评论内联附带几条回复；<=0 表示不限
     * @return array{comments: array, total: int, total_top: int, has_more: bool}
     */
    public static function getThreadsByMoment(int $momentId, int $topLimit = 0, int $replyLimit = 3): array
    {
        $rows = self::getByMoment($momentId, 2000);

        return self::buildThreads($rows, $topLimit, $replyLimit);
    }

    /**
     * 单条评论
     *
     * 字段与列表完全一致（同一个 BASE_SELECT），用于 htmx 追加评论后回填那一行。
     */
    public static function getById(int $commentId): ?array
    {
        return Database::fetchOne(
            self::BASE_SELECT . "
             WHERE mc.id = ? AND mc.deleted_at IS NULL
             LIMIT 1",
            [$commentId]
        );
    }

    /**
     * 批量取多条动态的评论线程（消除 N+1）
     *
     * @param int[] $momentIds
     * @return array<int, array{comments: array, total: int, total_top: int, has_more: bool}>
     */
    public static function getBatchForMoments(array $momentIds, int $perMoment = 3, int $replyLimit = 3): array
    {
        if (empty($momentIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($momentIds), '?'));
        $rows = Database::fetchAll(
            self::BASE_SELECT . "
             WHERE mc.moment_id IN ({$placeholders}) AND mc.deleted_at IS NULL
             ORDER BY mc.created_at ASC, mc.id ASC",
            $momentIds
        );

        $byMoment = [];
        foreach ($rows as $row) {
            $byMoment[(int)$row['moment_id']][] = $row;
        }

        $grouped = [];
        foreach ($byMoment as $mid => $momentRows) {
            $grouped[$mid] = self::buildThreads($momentRows, $perMoment, $replyLimit);
        }

        return $grouped;
    }

    /**
     * 把拍平的评论行组装成「顶层 + 回复」两层结构
     *
     * parent_id 指向已删除（或不存在）的评论时，该行既不是谁也挂不上，
     * 就当成顶层评论显示——宁可多显示一条，也不要让评论凭空消失。
     *
     * @param array $rows 同一动态的评论行，已按 created_at,id 升序
     */
    private static function buildThreads(array $rows, int $topLimit, int $replyLimit): array
    {
        $tops = [];
        $replies = [];
        foreach ($rows as $row) {
            $pid = (int)($row['parent_id'] ?? 0);
            if ($pid > 0) {
                $replies[$pid][] = $row;
            } else {
                $tops[] = $row;
            }
        }

        // 父评论不在结果集里（已删除/被截断）的子回复提升为顶层，避免丢评论
        foreach ($replies as $pid => $items) {
            $found = false;
            foreach ($tops as $t) {
                if ((int)$t['id'] === (int)$pid) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                continue;
            }
            foreach ($items as $orphan) {
                $orphan['parent_id'] = 0;
                $tops[] = $orphan;
            }
            unset($replies[$pid]);
        }

        $shown = $topLimit > 0 ? array_slice($tops, 0, $topLimit) : $tops;

        $threads = [];
        foreach ($shown as $top) {
            $tid  = (int)$top['id'];
            $all  = $replies[$tid] ?? [];
            $inline = $replyLimit > 0 ? array_slice($all, 0, $replyLimit) : $all;

            $top['replies']           = $inline;
            $top['replies_total']     = count($all);
            $top['replies_truncated'] = count($inline) < count($all);

            $threads[] = $top;
        }

        return [
            'comments'  => $threads,
            'total'     => count($rows),
            'total_top' => count($tops),
            'has_more'  => count($shown) < count($tops),
        ];
    }
}
