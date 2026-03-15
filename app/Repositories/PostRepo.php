<?php
/**
 * 回复数据访问层
 */

namespace App\Repositories;

use Core\Database;
use Core\Cache;

class PostRepo
{
    public function getByThread(int $threadId, int $limit, int $offset, string $order = 'asc', int $authorOnly = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        // 前3页默认排序缓存30秒，减少4表JOIN开销
        $page = $offset > 0 ? (int)($offset / $limit) + 1 : 1;
        $useCache = $page <= 3 && $authorOnly === 0;
        if ($useCache) {
            $cacheKey = "posts:thread:{$threadId}:{$order}:p{$page}";
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $orderSql = $order === 'desc' ? 'p.created_at DESC' : 'p.created_at ASC';
        $authorWhere = $authorOnly > 0 ? 'AND p.user_id = ?' : '';
        $params = [$threadId];
        if ($authorOnly > 0) {
            $params[] = $authorOnly;
        }
        $params[] = $limit;
        $params[] = $offset;

        $result = Database::fetchAll("
            SELECT
                p.*,
                u.username,
                u.nickname,
                u.avatar,
                u.group_id,
                u.nickname_color,
                u.post_count as user_post_count,
                qp.content as quote_content,
                qu.username as quote_username,
                qu.nickname as quote_nickname,
                qp.floor as quote_floor,
                qp.created_at as quote_created_at
            FROM posts p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN posts qp ON p.quote_post_id = qp.id AND qp.deleted_at IS NULL
            LEFT JOIN users qu ON qp.user_id = qu.id
            WHERE p.thread_id = ? AND p.deleted_at IS NULL {$authorWhere}
            ORDER BY {$orderSql}
            LIMIT ? OFFSET ?
        ", $params);

        if ($useCache) {
            Cache::set($cacheKey, $result, 30);
        }

        return $result;
    }

    public function countByThread(int $threadId, int $authorOnly = 0): int
    {
        $cacheKey = "posts:count:{$threadId}";
        // 无筛选时使用缓存
        if ($authorOnly === 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return (int)$cached;
            }
        }

        $authorWhere = $authorOnly > 0 ? ' AND user_id = ?' : '';
        $params = [$threadId];
        if ($authorOnly > 0) {
            $params[] = $authorOnly;
        }
        $row = Database::fetchOne("
            SELECT COUNT(*) as count FROM posts
            WHERE thread_id = ? AND deleted_at IS NULL{$authorWhere}
        ", $params);
        $count = (int) ($row['count'] ?? 0);

        if ($authorOnly === 0) {
            Cache::set($cacheKey, $count, 30);
        }

        return $count;
    }

    /**
     * 创建回复（必须在事务内调用，FOR UPDATE 依赖事务上下文）
     */
    public function create(int $threadId, int $userId, string $username, string $content, int $quotePostId = 0): int
    {
        $now = time();
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '';

        // 原子计算楼层号：SELECT FOR UPDATE 防止并发重复楼层
        $maxFloor = Database::fetchOne(
            "SELECT MAX(floor) as mf FROM posts WHERE thread_id = ? FOR UPDATE",
            [$threadId]
        );
        $floor = ((int)($maxFloor['mf'] ?? 0)) + 1;

        Database::execute("
            INSERT INTO posts (thread_id, user_id, username, user_ip, content, content_fmt, quote_post_id, floor, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$threadId, $userId, $username, $userIp, $content, null, $quotePostId, $floor, $now, $now]);
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::fetchOne("
            SELECT p.*, u.username, u.nickname, u.avatar, u.group_id, u.nickname_color
            FROM posts p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND p.deleted_at IS NULL
        ", [$id]);
    }

    public function update(int $id, string $content): void
    {
        Database::execute(
            "UPDATE posts SET content = ?, content_fmt = NULL, updated_at = ? WHERE id = ?",
            [$content, time(), $id]
        );
    }

    public function softDelete(int $id): void
    {
        Database::execute(
            "UPDATE posts SET deleted_at = ? WHERE id = ?",
            [time(), $id]
        );
    }
}
