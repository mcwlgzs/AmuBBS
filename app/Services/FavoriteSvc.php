<?php
/**
 * 帖子收藏服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class FavoriteSvc
{
    /**
     * 收藏/取消收藏
     */
    public static function toggle(int $userId, int $threadId): array
    {
        // 验证帖子存在
        $thread = Database::fetchOne("SELECT id FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        Database::beginTransaction();

        try {
            $existing = Database::fetchOne(
                "SELECT id FROM user_favorites WHERE user_id = ? AND thread_id = ? FOR UPDATE",
                [$userId, $threadId]
            );

            if ($existing) {
                Database::execute("DELETE FROM user_favorites WHERE id = ?", [$existing['id']]);
                Database::commit();
                Cache::delete("user:fav:{$userId}:{$threadId}");
                return ['favorited' => false];
            }

            Database::execute(
                "INSERT INTO user_favorites (user_id, thread_id, created_at) VALUES (?, ?, ?)",
                [$userId, $threadId, time()]
            );
            Database::commit();
            Cache::delete("user:fav:{$userId}:{$threadId}");
            return ['favorited' => true];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 检查是否已收藏
     */
    public static function isFavorited(int $userId, int $threadId): bool
    {
        return (bool)Database::fetchOne(
            "SELECT id FROM user_favorites WHERE user_id = ? AND thread_id = ?",
            [$userId, $threadId]
        );
    }

    /**
     * 获取用户收藏列表
     */
    public static function getUserFavorites(int $userId, int $limit = 20, int $offset = 0): array
    {
        $offset = min($offset, 10000);
        return Database::fetchAll("
            SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color, f.name as forum_name, uf.created_at as favorited_at
            FROM user_favorites uf
            INNER JOIN threads t ON uf.thread_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN forums f ON t.forum_id = f.id
            WHERE uf.user_id = ? AND t.deleted_at IS NULL
            ORDER BY uf.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $limit, $offset]);
    }

    /**
     * 获取用户收藏数
     */
    public static function countUserFavorites(int $userId): int
    {
        $r = Database::fetchOne(
            "SELECT COUNT(*) as c FROM user_favorites uf INNER JOIN threads t ON uf.thread_id = t.id WHERE uf.user_id = ? AND t.deleted_at IS NULL",
            [$userId]
        );
        return (int)($r['c'] ?? 0);
    }
}
