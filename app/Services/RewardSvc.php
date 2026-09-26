<?php
/**
 * 打赏服务
 */

namespace App\Services;

use App\Models\CreditLog;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Reward;
use App\Models\Thread;
use App\Models\User;
use Core\Cache;
use Core\Database;

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
        $authorId = $targetType === 'thread'
            ? Thread::findAuthorId($targetId)
            : Post::findAuthorId($targetId);

        if ($authorId === null) {
            throw new \RuntimeException($targetType === 'thread' ? '帖子不存在' : '评论不存在');
        }
        if ($authorId !== $toUserId) {
            throw new \RuntimeException($targetType === 'thread' ? '帖子作者不匹配' : '评论作者不匹配');
        }

        // 在一个事务中完成：积分转账 + 打赏记录 + 通知
        Database::beginTransaction();
        try {
            // 原子扣减发送方积分
            if (User::deductCreditsIfEnough($fromUserId, $amount) === 0) {
                throw new \RuntimeException('积分不足');
            }
            // 紧跟 UPDATE 查询余额，确保记录准确
            $fromBalance = User::getCredits($fromUserId);

            // 增加接收方积分
            User::addCredits($toUserId, $amount);
            $toBalance = User::getCredits($toUserId);
            $now = time();

            // 一次查询获取两个用户的 username/nickname
            $userMap = User::nameMapByIds([$fromUserId, $toUserId]);
            $fromName = (UserSvc::displayName($userMap[$fromUserId] ?? null) ?: '用户') . "#{$fromUserId}";
            $toName = (UserSvc::displayName($userMap[$toUserId] ?? null) ?: '用户') . "#{$toUserId}";

            CreditLog::write($fromUserId, -$amount, $fromBalance, 'transfer', "{$fromName} 打赏 {$toName}（{$targetType}#{$targetId} 转出）", 'user', $toUserId, $now);
            CreditLog::write($toUserId, $amount, $toBalance, 'reward', "{$fromName} 打赏 {$toName}（{$targetType}#{$targetId} 转入）", 'user', $fromUserId, $now);

            // 打赏记录
            $rewardId = Reward::create($fromUserId, $toUserId, $amount, $targetType, $targetId, $message, $now);

            // 通知（insertForUser 内部同时 +1 未读数并清未读缓存）
            Notification::insertForUser(
                $toUserId,
                $fromUserId,
                'reward',
                "{$fromName} 打赏了你 {$amount} 积分",
                $message,
                $targetType,
                $targetId,
                $now
            );

            Database::commit();
        } catch (\RuntimeException $e) {
            Database::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new \RuntimeException('打赏失败，请重试');
        }

        // 缓存清理放在事务外，失败不影响业务
        // 打赏相关缓存键集中在 App\Models\Reward，避免这里手写字面量漂移
        Reward::forgetTargetCaches($targetType, $targetId);
        Cache::delete("user:profile:{$fromUserId}");
        Cache::delete("user:profile:{$toUserId}");

        return $rewardId;
    }

}
