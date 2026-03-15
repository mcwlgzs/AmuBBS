<?php
/**
 * 打赏服务
 */

namespace App\Services;

use Core\Database;
use Core\Cache;

class RewardSvc
{
    /**
     * 打赏
     */
    public static function reward(int $fromUserId, int $toUserId, int $amount, string $targetType, int $targetId, string $message = ''): int
    {
        if ($fromUserId === $toUserId) {
            throw new \RuntimeException('不能打赏自己');
        }

        if ($amount < 1) {
            throw new \RuntimeException('打赏金额至少为1积分');
        }

        if ($amount > 10000) {
            throw new \RuntimeException('单次打赏不能超过10000积分');
        }

        if (!in_array($targetType, ['thread', 'post'], true)) {
            throw new \RuntimeException('打赏对象类型无效');
        }

        // 验证目标是否存在且属于接收者
        if ($targetType === 'thread') {
            $target = Database::fetchOne("SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$targetId]);
            if (!$target) {
                throw new \RuntimeException('帖子不存在');
            }
            if ((int)$target['user_id'] !== $toUserId) {
                throw new \RuntimeException('帖子作者不匹配');
            }
        } else {
            $target = Database::fetchOne("SELECT user_id FROM posts WHERE id = ? AND deleted_at IS NULL", [$targetId]);
            if (!$target) {
                throw new \RuntimeException('评论不存在');
            }
            if ((int)$target['user_id'] !== $toUserId) {
                throw new \RuntimeException('评论作者不匹配');
            }
        }

        // 在一个事务中完成：积分转账 + 打赏记录 + 通知
        Database::beginTransaction();
        try {
            // 原子扣减发送方积分
            $affected = Database::execute(
                "UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?",
                [$amount, $fromUserId, $amount]
            );
            if ($affected === 0) {
                throw new \RuntimeException('积分不足');
            }
            // 紧跟 UPDATE 查询余额，确保记录准确
            $fromBalance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$fromUserId])['credits'] ?? 0;

            // 增加接收方积分
            Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$amount, $toUserId]);
            $toBalance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$toUserId])['credits'] ?? 0;
            $now = time();

            // 一次查询获取两个用户的 username/nickname
            $bothUsers = Database::fetchAll("SELECT id, username, nickname FROM users WHERE id IN (?, ?)", [$fromUserId, $toUserId]);
            $userMap = [];
            foreach ($bothUsers as $u) { $userMap[(int)$u['id']] = $u; }
            $fromUser = $userMap[$fromUserId] ?? null;
            $toUser = $userMap[$toUserId] ?? null;
            $fromName = (UserSvc::displayName($fromUser) ?: '用户') . "#{$fromUserId}";
            $toName = (UserSvc::displayName($toUser) ?: '用户') . "#{$toUserId}";

            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$fromUserId, -$amount, $fromBalance, 'transfer', "{$fromName} 打赏 {$toName}（{$targetType}#{$targetId} 转出）", 'user', $toUserId, $now]
            );
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$toUserId, $amount, $toBalance, 'reward', "{$fromName} 打赏 {$toName}（{$targetType}#{$targetId} 转入）", 'user', $fromUserId, $now]
            );

            // 打赏记录
            Database::execute(
                "INSERT INTO rewards (from_user_id, to_user_id, amount, target_type, target_id, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$fromUserId, $toUserId, $amount, $targetType, $targetId, $message, $now]
            );
            $rewardId = Database::lastInsertId();

            // 通知
            $notificationTitle = "{$fromName} 打赏了你 {$amount} 积分";
            Database::execute(
                "INSERT INTO notifications (user_id, from_user_id, type, title, content, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$toUserId, $fromUserId, 'reward', $notificationTitle, $message, $targetType, $targetId, $now]
            );
            Database::execute("UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?", [$toUserId]);

            Database::commit();
        } catch (\RuntimeException $e) {
            Database::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new \RuntimeException('打赏失败，请重试');
        }

        // 缓存清理放在事务外，失败不影响业务
        Cache::delete("rewards:total:{$targetType}:{$targetId}");
        Cache::deletePattern("rewards:list:{$targetType}:{$targetId}:*");
        Cache::delete("user:profile:{$fromUserId}");
        Cache::delete("user:profile:{$toUserId}");
        Cache::delete("unread_notif:{$toUserId}");

        return $rewardId;
    }

    /**
     * 获取打赏记录（带缓存）
     */
    public static function getRewards(string $targetType, int $targetId, int $limit = 10): array
    {
        return Cache::get("rewards:list:{$targetType}:{$targetId}:{$limit}", function() use ($targetType, $targetId, $limit) {
            return Database::fetchAll("
                SELECT r.*, u.username, u.nickname, u.avatar, u.nickname_color
                FROM rewards r
                LEFT JOIN users u ON r.from_user_id = u.id
                WHERE r.target_type = ? AND r.target_id = ?
                ORDER BY r.created_at DESC
                LIMIT ?
            ", [$targetType, $targetId, $limit]);
        }, 300);
    }

    /**
     * 获取打赏总额（带缓存）
     */
    public static function getTotalRewards(string $targetType, int $targetId): array
    {
        return Cache::get("rewards:total:{$targetType}:{$targetId}", function() use ($targetType, $targetId) {
            $result = Database::fetchOne("
                SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
                FROM rewards
                WHERE target_type = ? AND target_id = ?
            ", [$targetType, $targetId]);

            return [
                'count' => (int)($result['count'] ?? 0),
                'total' => (int)($result['total'] ?? 0),
            ];
        }, 300);
    }

    /**
     * 检查用户是否已打赏
     */
    public static function hasRewarded(int $userId, string $targetType, int $targetId): bool
    {
        $result = Database::fetchOneCached("
            SELECT id FROM rewards
            WHERE from_user_id = ? AND target_type = ? AND target_id = ?
            LIMIT 1
        ", [$userId, $targetType, $targetId], 120);

        return !empty($result);
    }
}
