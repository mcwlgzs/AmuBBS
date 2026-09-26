<?php
/**
 * 帖子/回复编辑历史模型
 *
 * 由原 App\Services\PostEditLogSvc 迁移而来（3 个方法全是 post_edit_logs 的取数与落库）。
 *
 * log() 里的两条规则属于「怎么记历史」的数据规则，跟着表走：
 *   - 发布 30 分钟内的编辑不记录（作者快速自我修正不算改动）
 *   - 10 分钟内同一用户的连续编辑合并成一条（保留最早的旧内容）
 */

namespace App\Models;

use Core\Database;

class PostEditLog extends Model
{
    protected static string $table = 'post_edit_logs';

    /** 发布后多久内的编辑不记录（秒） */
    private const SKIP_WITHIN = 1800;

    /** 连续编辑合并窗口（秒） */
    private const MERGE_WITHIN = 600;

    /**
     * 记录一次编辑
     *
     * @param int $postId 为 0 表示编辑的是主题正文
     */
    public static function log(
        int $threadId,
        int $postId,
        int $userId,
        string $oldContent,
        string $newContent,
        string $reason = ''
    ): void {
        if ($oldContent === $newContent) {
            return;
        }

        $now = time();

        // 计算原始发布时间：为 0 表示查不到，此时不做「30 分钟内跳过」判断
        if ($postId > 0) {
            $row = Database::fetchOne("SELECT created_at FROM posts WHERE id = ?", [$postId]);
        } else {
            $row = Database::fetchOne("SELECT created_at FROM threads WHERE id = ?", [$threadId]);
        }
        $createdAt = (int)($row['created_at'] ?? 0);

        if ($createdAt > 0 && ($now - $createdAt) < self::SKIP_WITHIN) {
            return;
        }

        $recent = Database::fetchOne(
            "SELECT id FROM post_edit_logs
             WHERE thread_id = ? AND post_id = ? AND user_id = ? AND created_at > ?
             ORDER BY id DESC LIMIT 1",
            [$threadId, $postId, $userId, $now - self::MERGE_WITHIN]
        );

        if ($recent) {
            // 合并：保留最早的 old_content，只更新 new_content
            Database::execute(
                "UPDATE post_edit_logs SET new_content = ?, reason = ?, created_at = ? WHERE id = ?",
                [$newContent, $reason, $now, $recent['id']]
            );

            return;
        }

        Database::execute(
            "INSERT INTO post_edit_logs (thread_id, post_id, user_id, old_content, new_content, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$threadId, $postId, $userId, $oldContent, $newContent, $reason, $now]
        );
    }

    /**
     * 编辑历史列表（主题正文或某条回复）
     */
    public static function getByThread(int $threadId, int $postId = 0): array
    {
        if ($postId > 0) {
            $where = 'WHERE l.post_id = ?';
            $param = $postId;
        } else {
            $where = 'WHERE l.thread_id = ? AND l.post_id = 0';
            $param = $threadId;
        }

        return Database::fetchAll(
            "SELECT l.*, u.username
             FROM post_edit_logs l
             LEFT JOIN users u ON l.user_id = u.id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT 50",
            [$param]
        );
    }

    /**
     * 编辑次数
     */
    public static function countEdits(int $threadId, int $postId = 0): int
    {
        if ($postId > 0) {
            $row = Database::fetchOne("SELECT COUNT(*) as c FROM post_edit_logs WHERE post_id = ?", [$postId]);
        } else {
            $row = Database::fetchOne(
                "SELECT COUNT(*) as c FROM post_edit_logs WHERE thread_id = ? AND post_id = 0",
                [$threadId]
            );
        }

        return (int)($row['c'] ?? 0);
    }
}
