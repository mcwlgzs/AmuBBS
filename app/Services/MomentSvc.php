<?php
/**
 * 动态/说说服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class MomentSvc
{
    /**
     * 发布动态
     */
    public static function create(int $userId, string $content, array $images = []): int
    {
        // 频率限制：30秒内只能发一条动态
        $floodKey = "flood:moment:{$userId}";
        if (Cache::get($floodKey) !== null) {
            throw new \RuntimeException('发布过于频繁，请稍后再试');
        }

        if (mb_strlen($content) < 1) {
            throw new \RuntimeException('内容不能为空');
        }
        if (mb_strlen($content) > 1000) {
            throw new \RuntimeException('内容不能超过1000字');
        }

        // 敏感词过滤
        $filter = SensitiveWordService::filter($content);
        if ($filter['blocked']) {
            throw new \RuntimeException('内容包含违禁词');
        }
        $content = $filter['text'];

        // 验证并过滤 images 数组：只允许站内上传路径
        $validImages = [];
        foreach (array_slice($images, 0, 9) as $img) {
            if (is_string($img) && preg_match('#^/uploads/images/\d{4}/\d{2}/img_[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$#i', $img)) {
                $validImages[] = $img;
            }
        }
        $imagesJson = !empty($validImages) ? json_encode($validImages) : null;

        Database::execute("
            INSERT INTO moments (user_id, content, images, created_at)
            VALUES (?, ?, ?, ?)
        ", [$userId, $content, $imagesJson, time()]);

        $momentId = Database::lastInsertId();

        // 积分奖励
        $creditSvc = new CreditSvc();
        $creditSvc->addCredits($userId, 2, 'moment', '发布动态', 'moment', $momentId);

        Cache::set($floodKey, time(), 30);
        return $momentId;
    }

    /**
     * 获取动态列表
     */
    public static function getList(int $page = 1, int $perPage = 20, ?int $userId = null): array
    {
        $page = max(1, min(500, $page));
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = 'm.deleted_at IS NULL';
        $params = [];

        if ($userId) {
            $where .= ' AND m.user_id = ?';
            $params[] = $userId;
        }

        $total = Database::fetchOne("SELECT COUNT(*) as c FROM moments m WHERE {$where}", $params)['c'] ?? 0;

        $params[] = $perPage;
        $params[] = $offset;

        $moments = Database::fetchAll("
            SELECT m.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color
            FROM moments m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE {$where}
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ", $params);

        foreach ($moments as &$m) {
            $m['images'] = $m['images'] ? (json_decode($m['images'], true) ?: []) : [];
        }

        // 批量查询评论，避免 N+1，PHP 层限制每条动态最多 3 条（兼容 MySQL 5.7）
        $momentIds = array_column($moments, 'id');
        if ($momentIds) {
            $placeholders = implode(',', array_fill(0, count($momentIds), '?'));
            $allComments = Database::fetchAll("
                SELECT mc.*, u.username, u.nickname, u.avatar, u.nickname_color,
                       ru.username as reply_username, ru.nickname as reply_nickname, ru.nickname_color as reply_nickname_color
                FROM moment_comments mc
                LEFT JOIN users u ON mc.user_id = u.id
                LEFT JOIN users ru ON mc.reply_user_id = ru.id
                WHERE mc.moment_id IN ({$placeholders}) AND mc.deleted_at IS NULL
                ORDER BY mc.created_at ASC
            ", $momentIds);

            $commentsByMoment = [];
            foreach ($allComments as $c) {
                $mid = $c['moment_id'];
                if (!isset($commentsByMoment[$mid]) || count($commentsByMoment[$mid]) < 3) {
                    $commentsByMoment[$mid][] = $c;
                }
            }
            foreach ($moments as &$m) {
                $m['comments'] = $commentsByMoment[$m['id']] ?? [];
            }
        }

        return [
            'moments' => $moments,
            'total' => (int)$total,
            'page' => $page,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * 获取单条动态
     */
    public static function getById(int $id): ?array
    {
        $m = Database::fetchOne("
            SELECT m.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color
            FROM moments m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.id = ? AND m.deleted_at IS NULL
        ", [$id]);

        if ($m) {
            $m['images'] = $m['images'] ? (json_decode($m['images'], true) ?: []) : [];
            $m['comments'] = self::getComments($m['id'], 50);
        }

        return $m;
    }

    /**
     * 点赞/取消点赞
     */
    public static function toggleLike(int $momentId, int $userId): array
    {
        // 验证动态是否存在
        $moment = Database::fetchOne("SELECT id FROM moments WHERE id = ? AND deleted_at IS NULL", [$momentId]);
        if (!$moment) {
            throw new \RuntimeException('动态不存在或已删除');
        }

        Database::beginTransaction();

        try {
            $existing = Database::fetchOne(
                "SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ? FOR UPDATE",
                [$momentId, $userId]
            );

            if ($existing) {
                Database::execute("DELETE FROM moment_likes WHERE id = ?", [$existing['id']]);
                Database::execute("UPDATE moments SET likes = GREATEST(likes - 1, 0) WHERE id = ?", [$momentId]);
                $liked = false;
            } else {
                Database::execute(
                    "INSERT INTO moment_likes (moment_id, user_id, created_at) VALUES (?, ?, ?)",
                    [$momentId, $userId, time()]
                );
                Database::execute("UPDATE moments SET likes = likes + 1 WHERE id = ?", [$momentId]);
                $liked = true;
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 通知放在事务外，失败不影响点赞
        if ($liked) {
            $moment = Database::fetchOne("SELECT user_id FROM moments WHERE id = ? AND deleted_at IS NULL", [$momentId]);
            if ($moment && (int)$moment['user_id'] !== $userId) {
                $userRow = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$userId]);
                $username = $userRow['username'] ?? '用户';
                NotificationSvc::notify(
                    (int)$moment['user_id'], $userId, 'like',
                    $username . ' 赞了你的动态', '', 'moment', $momentId
                );
            }
        }

        $row = Database::fetchOne("SELECT likes FROM moments WHERE id = ?", [$momentId]);
        return ['liked' => $liked, 'likes' => (int)($row['likes'] ?? 0)];
    }

    /**
     * 检查是否已点赞
     */
    public static function isLiked(int $momentId, int $userId): bool
    {
        return (bool)Database::fetchOne(
            "SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ?",
            [$momentId, $userId]
        );
    }

    /**
     * 发表评论
     */
    public static function comment(int $momentId, int $userId, string $content, int $replyUserId = 0): int
    {
        // 频率限制：10秒内只能发一条评论
        $floodKey = "flood:moment_comment:{$userId}";
        if (Cache::get($floodKey) !== null) {
            throw new \RuntimeException('评论过于频繁，请稍后再试');
        }

        if (mb_strlen($content) < 1 || mb_strlen($content) > 500) {
            throw new \RuntimeException('评论内容1-500字');
        }

        $filter = SensitiveWordService::filter($content);
        if ($filter['blocked']) {
            throw new \RuntimeException('评论包含违禁词');
        }
        $content = $filter['text'];

        $moment = Database::fetchOne("SELECT id, user_id FROM moments WHERE id = ? AND deleted_at IS NULL", [$momentId]);
        if (!$moment) {
            throw new \RuntimeException('动态不存在');
        }

        // 黑名单检查：动态作者拉黑了你则禁止评论
        if (BlacklistSvc::isBlocked((int)$moment['user_id'], $userId)) {
            throw new \RuntimeException('无法评论该动态');
        }

        // 验证回复目标用户存在
        if ($replyUserId > 0) {
            $replyUser = Database::fetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$replyUserId]);
            if (!$replyUser) {
                $replyUserId = 0;
            }
        }

        Database::beginTransaction();

        try {
            Database::execute("
                INSERT INTO moment_comments (moment_id, user_id, reply_user_id, content, created_at)
                VALUES (?, ?, ?, ?, ?)
            ", [$momentId, $userId, $replyUserId, $content, time()]);

            $commentId = Database::lastInsertId();

            Database::execute("UPDATE moments SET comment_count = comment_count + 1 WHERE id = ?", [$momentId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 通知放在事务外
        $notifyUserId = $replyUserId ?: (int)$moment['user_id'];
        if ($notifyUserId !== $userId) {
            $userRow = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$userId]);
            $username = $userRow['username'] ?? '用户';
            NotificationSvc::notify(
                $notifyUserId, $userId, 'moment_comment',
                $username . ' 评论了你的动态', mb_substr($content, 0, 50),
                'moment', $momentId
            );
        }

        Cache::set($floodKey, time(), 10);
        return $commentId;
    }

    /**
     * 获取评论列表
     */
    public static function getComments(int $momentId, int $limit = 20): array
    {
        return Database::fetchAll("
            SELECT mc.*, u.username, u.nickname, u.avatar, u.nickname_color,
                   ru.username as reply_username, ru.nickname as reply_nickname, ru.nickname_color as reply_nickname_color
            FROM moment_comments mc
            LEFT JOIN users u ON mc.user_id = u.id
            LEFT JOIN users ru ON mc.reply_user_id = ru.id
            WHERE mc.moment_id = ? AND mc.deleted_at IS NULL
            ORDER BY mc.created_at ASC
            LIMIT ?
        ", [$momentId, $limit]);
    }

    /**
     * 删除动态
     */
    public static function delete(int $momentId, int $userId): void
    {
        $moment = Database::fetchOne("SELECT user_id FROM moments WHERE id = ? AND deleted_at IS NULL", [$momentId]);
        if (!$moment) {
            throw new \RuntimeException('动态不存在');
        }
        if ((int)$moment['user_id'] !== $userId && !PermissionSvc::isAdminOrMod($userId)) {
            throw new \RuntimeException('没有权限');
        }
        Database::execute("UPDATE moments SET deleted_at = ? WHERE id = ?", [time(), $momentId]);
    }
}
