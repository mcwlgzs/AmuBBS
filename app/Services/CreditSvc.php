<?php
namespace App\Services;

use App\Models\CreditLog;
use App\Models\User;
use Core\Cache;
use Core\Database;

/**
 * 积分服务
 *
 * 分层说明（为什么这个类还留着）：
 *   addCredits / deductCredits / transferCredits 是**领域记账用例** ——
 *   它们必须在同一个事务里同时改 users.credits 和写 credit_logs，
 *   而且扣减要带余额条件、转账要校验接收方存在。
 *   这既不是「单纯取数」（不属于 Model），也不是「纯算法」（不属于 Support），
 *   所以按原样保留在服务层：SQL 交给 User / CreditLog 模型，事务边界留在这里。
 *   缓存清理统一放在 commit 之后，避免事务未提交就把旧值放回缓存。
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
            User::addCredits($userId, $amount);
            $balance = User::getCredits($userId);

            CreditLog::write($userId, $amount, $balance, $type, $description, $relatedType, $relatedId, time());

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
            if (User::deductCreditsIfEnough($userId, $amount) === 0) {
                Database::rollBack();
                return false;
            }

            $balance = User::getCredits($userId);

            CreditLog::write($userId, -$amount, $balance, $type, $description, $relatedType, $relatedId, time());

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
            if (!User::exists($toUserId)) {
                throw new \RuntimeException('接收方用户不存在');
            }
            if (User::deductCreditsIfEnough($fromUserId, $amount) === 0) {
                Database::rollBack();
                return false;
            }
            $now = time();
            $fromBalance = User::getCredits($fromUserId);
            CreditLog::write($fromUserId, -$amount, $fromBalance, 'transfer', $description . '（转出）', 'user', $toUserId, $now);

            User::addCredits($toUserId, $amount);
            $toBalance = User::getCredits($toUserId);
            CreditLog::write($toUserId, $amount, $toBalance, 'reward', $description . '（转入）', 'user', $fromUserId, $now);

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
