<?php
/**
 * 帖子数据访问层
 */

namespace App\Repositories;

use Core\Database;

class ThreadRepo
{
    public function findById(int $id): ?array
    {
        return Database::fetchOneCached("
            SELECT * FROM threads
            WHERE id = ? AND deleted_at IS NULL
        ", [$id], 120);
    }

    public function getDetail(int $threadId): ?array
    {
        return Database::fetchOneCached("
            SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.group_id,
                u.nickname_color,
                u.credits,
                g.name as group_name,
                f.name as forum_name
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN user_groups g ON u.group_id = g.id
            LEFT JOIN forums f ON t.forum_id = f.id
            WHERE t.id = ? AND t.deleted_at IS NULL
        ", [$threadId], 120);
    }

    public function getByForum(int $forumId, int $limit, int $offset, string $orderBy = 'lastpost', bool $asc = false, string $filter = ''): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $orderCol = match ($orderBy) {
            'tid' => 't.created_at',
            'replies' => 't.reply_count',
            'views' => 't.views',
            default => 't.last_post_time',
        };
        $dir = $asc ? 'ASC' : 'DESC';

        // 置顶帖始终排在前面（正向查询时 DESC 在前，反向查询时不含置顶）
        $topOrder = $asc ? '' : 't.is_top DESC,';

        // 筛选条件
        $filterSql = '';
        if ($filter === 'highlight') {
            $filterSql = ' AND t.is_highlight > 0';
        } elseif ($filter === 'top') {
            $filterSql = ' AND t.is_top > 0';
        }

        return Database::fetchAll("
            SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.credits,
                u.nickname_color,
                t.reply_count as reply_count_calc,
                lpu.username as last_post_username,
                lpu.nickname as last_post_nickname
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users lpu ON t.last_post_user_id = lpu.id
            WHERE t.forum_id = ? AND t.deleted_at IS NULL{$filterSql}
            ORDER BY {$topOrder} {$orderCol} {$dir}
            LIMIT ? OFFSET ?
        ", [$forumId, $limit, $offset]);
    }

    /**
     * 获取全局置顶帖（is_top=2）
     */
    public function getGlobalTopThreads(): array
    {
        return Database::fetchAllCached("
            SELECT
                t.*,
                u.username,
                u.nickname,
                u.avatar,
                u.credits,
                u.nickname_color,
                t.reply_count as reply_count_calc,
                lpu.username as last_post_username,
                lpu.nickname as last_post_nickname
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users lpu ON t.last_post_user_id = lpu.id
            WHERE t.is_top = 2 AND t.deleted_at IS NULL
            ORDER BY t.updated_at DESC
            LIMIT 20
        ", [], 300);
    }

    public function countByForum(int $forumId, string $filter = ''): int
    {
        $filterSql = '';
        if ($filter === 'highlight') {
            $filterSql = ' AND is_highlight > 0';
        } elseif ($filter === 'top') {
            $filterSql = ' AND is_top > 0';
        }

        $row = Database::fetchOne("
            SELECT COUNT(*) as count FROM threads
            WHERE forum_id = ? AND deleted_at IS NULL{$filterSql}
        ", [$forumId]);
        return (int) ($row['count'] ?? 0);
    }

    public function getLatest(int $limit = 10): array
    {
        return Database::fetchAll("
            SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color
            FROM threads t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.deleted_at IS NULL
            ORDER BY t.is_top DESC, t.created_at DESC
            LIMIT ?
        ", [$limit]);
    }

    public function getByUser(int $userId, int $limit = 10, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        return Database::fetchAll("
            SELECT * FROM threads
            WHERE user_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $limit, $offset]);
    }

    public function create(int $forumId, int $userId, string $username, string $title, string $content): int
    {
        $now = time();
        $contentFmt = \Core\HtmlSanitizer::sanitize($content);
        $userIp = $_SERVER['REMOTE_ADDR'] ?? '';
        Database::execute("
            INSERT INTO threads (
                forum_id, user_id, username, user_ip, title, content, content_fmt,
                created_at, updated_at, last_post_time, last_post_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$forumId, $userId, $username, $userIp, $title, $content, $contentFmt, $now, $now, $now, $userId]);
        return Database::lastInsertId();
    }

    public function incrementViews(int $threadId, int $count = 1): int
    {
        return Database::execute("
            UPDATE threads SET views = views + ? WHERE id = ?
        ", [$count, $threadId]);
    }

    public function updateLastPost(int $threadId, int $userId): int
    {
        return Database::execute("
            UPDATE threads
            SET reply_count = reply_count + 1,
                last_post_time = ?,
                last_post_user_id = ?
            WHERE id = ?
        ", [time(), $userId, $threadId]);
    }

    public function update(int $threadId, string $title, string $content): int
    {
        $contentFmt = \Core\HtmlSanitizer::sanitize($content);
        return Database::execute("
            UPDATE threads
            SET title = ?, content = ?, content_fmt = ?, updated_at = ?
            WHERE id = ?
        ", [$title, $content, $contentFmt, time(), $threadId]);
    }

    public function softDelete(int $threadId): int
    {
        return Database::execute("
            UPDATE threads SET deleted_at = ? WHERE id = ?
        ", [time(), $threadId]);
    }

    public function setTopLevel(int $threadId, int $level): int
    {
        return Database::execute("
            UPDATE threads SET is_top = ? WHERE id = ?
        ", [$level, $threadId]);
    }

    public function setDigest(int $threadId, int $level): int
    {
        return Database::execute("
            UPDATE threads SET is_highlight = ? WHERE id = ?
        ", [max(0, min(3, $level)), $threadId]);
    }
}
