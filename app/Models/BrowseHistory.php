<?php
/**
 * 浏览历史模型（browse_history 表）
 *
 * (user_id, thread_id) 唯一，记录浏览用 REPLACE INTO 顺带刷新时间。
 */

namespace App\Models;

use Core\Database;

class BrowseHistory extends Model
{
    protected static string $table = 'browse_history';

    /** 本表没有软删除列 */
    protected static ?string $softDeleteColumn = null;

    /**
     * 记录一次浏览（已有记录则刷新时间）
     */
    public static function record(int $userId, int $threadId, int $now): void
    {
        Database::execute(
            "REPLACE INTO browse_history (user_id, thread_id, created_at) VALUES (?, ?, ?)",
            [$userId, $threadId, $now]
        );
    }

    /**
     * 取第 N+1 条记录的 id（当作保留阈值，用主键比较避免时间戳撞车）
     */
    public static function cutoffId(int $userId, int $keep = 100): ?int
    {
        $row = Database::fetchOne(
            "SELECT id FROM browse_history WHERE user_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?",
            [$userId, $keep]
        );

        return $row === null ? null : (int)$row['id'];
    }

    /**
     * 删掉阈值之前的记录
     */
    public static function pruneBefore(int $userId, int $id): int
    {
        return Database::execute(
            "DELETE FROM browse_history WHERE user_id = ? AND id < ?",
            [$userId, $id]
        );
    }

    /**
     * 某人的浏览历史（主题还在的才返回）
     */
    public static function listForUser(int $userId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT bh.created_at as viewed_at, t.id, t.title, t.views, t.reply_count, t.user_id,
                    u.username, u.nickname_color
             FROM browse_history bh
             INNER JOIN threads t ON bh.thread_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE bh.user_id = ? AND t.deleted_at IS NULL
             ORDER BY bh.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /**
     * 清空某人的浏览历史
     */
    public static function clearForUser(int $userId): int
    {
        return Database::execute("DELETE FROM browse_history WHERE user_id = ?", [$userId]);
    }
}
