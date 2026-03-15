<?php
/**
 * 帖子编辑历史服务
 */

namespace App\Services;

use Core\Database;

class PostEditLogSvc
{
    /**
     * 记录编辑历史（30分钟内首次编辑不记录，10分钟内连续编辑合并）
     */
    public static function log(int $threadId, int $postId, int $userId, string $oldContent, string $newContent, string $reason = ''): void
    {
        if ($oldContent === $newContent) return;

        $now = time();

        // 发帖30分钟内的编辑不记录
        $createdAt = 0;
        if ($postId > 0) {
            $post = Database::fetchOne("SELECT created_at FROM posts WHERE id = ?", [$postId]);
            $createdAt = (int)($post['created_at'] ?? 0);
        } else {
            $thread = Database::fetchOne("SELECT created_at FROM threads WHERE id = ?", [$threadId]);
            $createdAt = (int)($thread['created_at'] ?? 0);
        }
        if ($createdAt > 0 && ($now - $createdAt) < 1800) {
            return;
        }

        // 10分钟内同一用户的连续编辑合并
        $recent = Database::fetchOne(
            "SELECT id, old_content FROM post_edit_logs WHERE thread_id = ? AND post_id = ? AND user_id = ? AND created_at > ? ORDER BY id DESC LIMIT 1",
            [$threadId, $postId, $userId, $now - 600]
        );

        if ($recent) {
            // 合并：保留最早的 old_content，更新 new_content
            Database::execute(
                "UPDATE post_edit_logs SET new_content = ?, reason = ?, created_at = ? WHERE id = ?",
                [$newContent, $reason, $now, $recent['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO post_edit_logs (thread_id, post_id, user_id, old_content, new_content, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$threadId, $postId, $userId, $oldContent, $newContent, $reason, $now]
            );
        }
    }

    /**
     * 获取编辑历史列表
     */
    public static function getByThread(int $threadId, int $postId = 0): array
    {
        $where = $postId > 0
            ? "WHERE l.post_id = ?"
            : "WHERE l.thread_id = ? AND l.post_id = 0";
        $param = $postId > 0 ? $postId : $threadId;

        return Database::fetchAll("
            SELECT l.*, u.username
            FROM post_edit_logs l
            LEFT JOIN users u ON l.user_id = u.id
            {$where}
            ORDER BY l.created_at DESC
            LIMIT 50
        ", [$param]);
    }

    /**
     * 获取帖子/回复的编辑次数
     */
    public static function countEdits(int $threadId, int $postId = 0): int
    {
        if ($postId > 0) {
            $row = Database::fetchOne("SELECT COUNT(*) as c FROM post_edit_logs WHERE post_id = ?", [$postId]);
        } else {
            $row = Database::fetchOne("SELECT COUNT(*) as c FROM post_edit_logs WHERE thread_id = ? AND post_id = 0", [$threadId]);
        }
        return (int)($row['c'] ?? 0);
    }
}
