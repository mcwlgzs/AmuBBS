<?php
/**
 * 动态（说说）模型
 *
 * 由原 App\Services\MomentSvc 迁移而来（其中 moments 表的数据访问部分）。
 *
 * 分层说明：发动态/评论时的频率限制、敏感词过滤、通知、积分奖励、权限判断
 * 属于「一次请求的编排」，已移到 app/Controllers/Moment.php；
 * 这里只保留取数与落库，以及必须和关系表同事务更新的计数。
 */

namespace App\Models;

use Core\Database;

class Moment extends Model
{
    protected static string $table = 'moments';

    /** 列表分页上限（防止深翻页拖垮数据库） */
    private const MAX_PAGE = 500;
    private const MAX_PER_PAGE = 100;

    /** 每条动态在列表里附带展示的评论条数（PHP 层截断，兼容 MySQL 5.7） */
    private const INLINE_COMMENTS = 3;

    public static function create(int $userId, string $content, ?string $imagesJson): int
    {
        Database::execute(
            "INSERT INTO moments (user_id, content, images, created_at) VALUES (?, ?, ?, ?)",
            [$userId, $content, $imagesJson, time()]
        );

        return Database::lastInsertId();
    }

    public static function exists(int $id): bool
    {
        return self::existsWhere('id = ?', [$id]);
    }

    /**
     * 动态作者 ID（不存在返回 null）
     */
    public static function ownerId(int $id): ?int
    {
        $row = Database::fetchOne(
            "SELECT user_id FROM moments WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $row === null ? null : (int)$row['user_id'];
    }

    /**
     * 单条动态（images 已解码；不含评论，评论用 MomentComment::getByMoment）
     */
    public static function findById(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT m.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color
             FROM moments m
             LEFT JOIN users u ON m.user_id = u.id
             WHERE m.id = ? AND m.deleted_at IS NULL",
            [$id]
        );

        if ($row !== null) {
            $row['images'] = self::decodeImages($row['images'] ?? null);
        }

        return $row;
    }

    /**
     * 最新动态（侧边栏暖缓存用，带作者展示信息）
     */
    public static function latestWithUser(int $limit = 5): array
    {
        return Database::fetchAll(
            "SELECT m.*, u.username, u.nickname, u.avatar, u.nickname_color
             FROM moments m
             LEFT JOIN users u ON m.user_id = u.id
             WHERE m.deleted_at IS NULL
             ORDER BY m.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * 动态列表（含图片解码与批量评论）
     *
     * @return array{moments: array, total: int, page: int, totalPages: int}
     */
    public static function getList(int $page = 1, int $perPage = 20, ?int $userId = null): array
    {
        $page = max(1, min(self::MAX_PAGE, $page));
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'm.deleted_at IS NULL';
        $params = [];

        if ($userId) {
            $where .= ' AND m.user_id = ?';
            $params[] = $userId;
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as c FROM moments m WHERE {$where}",
            $params
        )['c'] ?? 0);

        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;

        $moments = Database::fetchAll(
            "SELECT m.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color
             FROM moments m
             LEFT JOIN users u ON m.user_id = u.id
             WHERE {$where}
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?",
            $queryParams
        );

        foreach ($moments as &$m) {
            $m['images'] = self::decodeImages($m['images'] ?? null);
        }
        unset($m);

        // 批量取评论线程（顶层评论 + 其回复，只一层嵌套），避免 N+1
        $momentIds = array_column($moments, 'id');
        $threadsByMoment = $momentIds
            ? MomentComment::getBatchForMoments($momentIds, self::INLINE_COMMENTS)
            : [];

        foreach ($moments as &$m) {
            $thread = $threadsByMoment[$m['id']] ?? ['comments' => [], 'total' => 0, 'total_top' => 0, 'has_more' => false];
            $m['comments']           = $thread['comments'];
            $m['comment_total']      = $thread['total'];
            $m['comments_has_more']  = $thread['has_more'];
        }
        unset($m);

        return [
            'moments' => $moments,
            'total' => $total,
            'page' => $page,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * 软删除动态
     */
    public static function softDelete(int $id): void
    {
        Database::execute("UPDATE moments SET deleted_at = ? WHERE id = ?", [time(), $id]);
    }

    /**
     * 调整点赞数（供 MomentLike 在自己的事务里调用）
     *
     * GREATEST(..., 0) 保证计数不会被减成负数。
     */
    public static function adjustLikeCount(int $id, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            Database::execute("UPDATE moments SET likes = likes + ? WHERE id = ?", [$delta, $id]);

            return;
        }

        Database::execute(
            "UPDATE moments SET likes = GREATEST(likes - ?, 0) WHERE id = ?",
            [abs($delta), $id]
        );
    }

    /**
     * 评论数 +1（供 MomentComment 在自己的事务里调用）
     */
    public static function incrementCommentCount(int $id): void
    {
        Database::execute("UPDATE moments SET comment_count = comment_count + 1 WHERE id = ?", [$id]);
    }

    /**
     * 点赞数（用于点赞后回显）
     */
    public static function likeCount(int $id): int
    {
        return (int)(Database::fetchOne("SELECT likes FROM moments WHERE id = ?", [$id])['likes'] ?? 0);
    }

    /**
     * images 列 → 数组
     */
    public static function decodeImages(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
