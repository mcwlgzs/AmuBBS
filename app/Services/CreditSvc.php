<?php
namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * 积分服务
 */
class CreditSvc
{
    /**
     * 增加积分
     */
    public function addCredits(int $userId, int $amount, string $type, string $description = '', string $relatedType = '', int $relatedId = 0): bool
    {
        if ($amount <= 0) return false;

        try {
            Database::beginTransaction();
            Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$amount, $userId]);
            $user = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId]);
            $balance = $user['credits'] ?? 0;

            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, $amount, $balance, $type, $description, $relatedType, $relatedId, time()]
            );

            Database::commit();
            Cache::delete("user:profile:{$userId}");
            return true;
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[CreditSvc] addCredits failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 扣除积分
     */
    public function deductCredits(int $userId, int $amount, string $type, string $description = '', string $relatedType = '', int $relatedId = 0): bool
    {
        if ($amount <= 0) return false;

        try {
            Database::beginTransaction();
            $affected = Database::execute("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?", [$amount, $userId, $amount]);
            if ($affected === 0) {
                Database::rollBack();
                return false;
            }

            $user = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId]);
            $balance = $user['credits'] ?? 0;

            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$userId, -$amount, $balance, $type, $description, $relatedType, $relatedId, time()]
            );

            Database::commit();
            Cache::delete("user:profile:{$userId}");
            return true;
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[CreditSvc] deductCredits failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 转账积分
     */
    public function transferCredits(int $fromUserId, int $toUserId, int $amount, string $description = '积分转账'): bool
    {
        if ($amount <= 0 || $fromUserId === $toUserId) return false;

        try {
            Database::beginTransaction();
            // 验证接收方用户存在，防止积分凭空消失
            $toUser = Database::fetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$toUserId]);
            if (!$toUser) {
                throw new \RuntimeException('接收方用户不存在');
            }
            $affected = Database::execute(
                "UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?",
                [$amount, $fromUserId, $amount]
            );
            if ($affected === 0) {
                Database::rollBack();
                return false;
            }
            $fromRow = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$fromUserId]);
            $fromBalance = (int)($fromRow['credits'] ?? 0);
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$fromUserId, -$amount, $fromBalance, 'transfer', $description . '（转出）', 'user', $toUserId, time()]
            );

            Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$amount, $toUserId]);
            $toRow = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$toUserId]);
            $toBalance = (int)($toRow['credits'] ?? 0);
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, related_type, related_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$toUserId, $amount, $toBalance, 'reward', $description . '（转入）', 'user', $fromUserId, time()]
            );

            Database::commit();
            Cache::delete("user:profile:{$fromUserId}");
            Cache::delete("user:profile:{$toUserId}");
            return true;
        } catch (\RuntimeException $e) {
            Database::rollBack();
            throw $e; // 业务异常（如用户不存在）向上传播
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[CreditSvc] transferCredits failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取用户积分记录
     */
    public function getUserCreditLogs(int $userId, int $page = 1, int $pageSize = 20)
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $logs = Database::fetchAll(
            "SELECT * FROM credit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $pageSize, $offset]
        );
        $total = Database::fetchOne(
            "SELECT COUNT(*) as count FROM credit_logs WHERE user_id = ?",
            [$userId]
        )['count'] ?? 0;

        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => max(1, (int)ceil($total / $pageSize))
        ];
    }

    /**
     * 获取积分排行榜（带缓存）
     */
    public function getCreditRanking($limit = 10)
    {
        return Cache::get("credits:ranking:{$limit}", function() use ($limit) {
            return Database::fetchAll(
                "SELECT id, username, nickname, avatar, credits, nickname_color, thread_count, post_count
                 FROM users WHERE deleted_at IS NULL ORDER BY credits DESC LIMIT ?",
                [$limit]
            );
        }, 300);
    }

    public function rewardForThread($userId, $threadId)
    {
        return $this->addCredits($userId, 5, 'thread', '发布帖子', 'thread', $threadId);
    }

    public function rewardForPost($userId, $postId)
    {
        return $this->addCredits($userId, 2, 'post', '发表评论', 'post', $postId);
    }

    public function rewardForLike($userId, $type, $id)
    {
        return $this->addCredits($userId, 1, 'like', '内容被点赞', $type, $id);
    }

    public function getCreditConfig()
    {
        return [
            'checkin' => 10,
            'thread' => 5,
            'post' => 2,
            'like' => 1,
            'first_thread' => 20,
            'first_post' => 10,
        ];
    }
}
