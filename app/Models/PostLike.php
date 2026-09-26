<?php
/**
 * 帖子点赞模型
 *
 * 点赞涉及两张表：post_likes（谁赞了谁）和 threads.likes（冗余计数）。
 * 两者必须一起改，所以放在同一个模型方法里，调用方不要各自拼 SQL——
 * 之前 ThreadSvc 与控制器各写了一份，很容易只改一处导致计数漂移。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class PostLike extends Model
{
    protected static string $table = 'post_likes';

    /** 点赞状态的缓存键（详情页用它包一层 Cache::get） */
    public static function cacheKey(int $userId, int $threadId): string
    {
        return "user:liked:{$userId}:{$threadId}";
    }

    /**
     * 是否已点赞
     */
    public static function isLiked(int $userId, int $threadId): bool
    {
        return Database::fetchOne(
            "SELECT id FROM post_likes WHERE user_id = ? AND thread_id = ?",
            [$userId, $threadId]
        ) !== null;
    }

    /**
     * 点赞 / 取消点赞，并同步 threads.likes 计数
     *
     * @return bool 操作之后的点赞状态（true = 已赞）
     */
    public static function toggle(int $userId, int $threadId): bool
    {
        Database::beginTransaction();

        try {
            // 加锁查询，避免并发下重复插入
            $existing = Database::fetchOne(
                "SELECT id FROM post_likes WHERE user_id = ? AND thread_id = ? FOR UPDATE",
                [$userId, $threadId]
            );

            if ($existing) {
                Database::execute("DELETE FROM post_likes WHERE id = ?", [$existing['id']]);
                Database::execute(
                    "UPDATE threads SET likes = CASE WHEN likes > 0 THEN likes - 1 ELSE 0 END WHERE id = ?",
                    [$threadId]
                );
                $liked = false;
            } else {
                Database::execute(
                    "INSERT INTO post_likes (user_id, thread_id, created_at) VALUES (?, ?, ?)",
                    [$userId, $threadId, time()]
                );
                Database::execute("UPDATE threads SET likes = likes + 1 WHERE id = ?", [$threadId]);
                $liked = true;
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 行缓存（threads 那一行）也失效，否则详情页最多 120 秒还显示旧点赞数
        Thread::forgetRowCachesFor([$threadId]);
        Cache::delete(self::cacheKey($userId, $threadId));

        return $liked;
    }

    /**
     * 某个用户在某段时间内点赞的次数（任务中心用）
     */
    public static function countByUserBetween(int $userId, int $from, int $to): int
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) as c FROM post_likes WHERE user_id = ? AND created_at >= ? AND created_at < ?",
            [$userId, $from, $to]
        );

        return (int)($row['c'] ?? 0);
    }

    /**
     * 删除某个用户的全部点赞（注销账号时用）
     */
    public static function deleteByUser(int $userId): int
    {
        return Database::execute("DELETE FROM post_likes WHERE user_id = ?", [$userId]);
    }
}
