<?php
/**
 * 帖子收藏模型
 *
 * 由原 App\Services\FavoriteSvc 迁移而来（该 Service 只做 user_favorites 的取数/落库，
 * 没有额外业务逻辑，因此整体并入模型，不再保留一层转发）。
 */

namespace App\Models;

use Core\Database;
use Core\Cache;

class Favorite extends Model
{
    protected static string $table = 'user_favorites';

    /**
     * 收藏 / 取消收藏
     *
     * @return array{favorited: bool}
     * @throws \RuntimeException 帖子不存在时
     */
    public static function toggle(int $userId, int $threadId): array
    {
        if (!Thread::exists($threadId)) {
            throw new \RuntimeException('帖子不存在');
        }

        Database::beginTransaction();

        try {
            // 加锁查询，避免并发下重复插入
            $existing = Database::fetchOne(
                "SELECT id FROM user_favorites WHERE user_id = ? AND thread_id = ? FOR UPDATE",
                [$userId, $threadId]
            );

            if ($existing) {
                Database::execute("DELETE FROM user_favorites WHERE id = ?", [$existing['id']]);
                Database::commit();
                Cache::delete(self::cacheKey($userId, $threadId));

                return ['favorited' => false];
            }

            Database::execute(
                "INSERT INTO user_favorites (user_id, thread_id, created_at) VALUES (?, ?, ?)",
                [$userId, $threadId, time()]
            );
            Database::commit();
            Cache::delete(self::cacheKey($userId, $threadId));

            return ['favorited' => true];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 是否已收藏
     */
    public static function isFavorited(int $userId, int $threadId): bool
    {
        return Database::fetchOne(
            "SELECT id FROM user_favorites WHERE user_id = ? AND thread_id = ?",
            [$userId, $threadId]
        ) !== null;
    }

    /**
     * 用户的收藏列表（带主题、作者、板块信息）
     */
    public static function getUserFavorites(int $userId, int $limit = 20, int $offset = 0): array
    {
        $offset = min($offset, 10000);

        return Database::fetchAll(
            "SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color,
                    f.name as forum_name, uf.created_at as favorited_at
             FROM user_favorites uf
             INNER JOIN threads t ON uf.thread_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             LEFT JOIN forums f ON t.forum_id = f.id
             WHERE uf.user_id = ? AND t.deleted_at IS NULL
             ORDER BY uf.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    /**
     * 用户的收藏数
     */
    public static function countUserFavorites(int $userId): int
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) as c FROM user_favorites uf
             INNER JOIN threads t ON uf.thread_id = t.id
             WHERE uf.user_id = ? AND t.deleted_at IS NULL",
            [$userId]
        );

        return (int)($row['c'] ?? 0);
    }

    /** 调用方（如主题详情页）用来读写同一份缓存键 */
    public static function cacheKey(int $userId, int $threadId): string
    {
        return "user:fav:{$userId}:{$threadId}";
    }

    /**
     * 清空某用户的收藏（删用户级联用）
     */
    public static function deleteByUser(int $userId): int
    {
        return Database::execute("DELETE FROM user_favorites WHERE user_id = ?", [$userId]);
    }
}
