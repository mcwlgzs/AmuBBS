<?php
/**
 * 签到控制器
 */

namespace App\Controllers;

use Core\Database;
use Core\Cache;
use App\Services\CreditSvc;

class Checkin extends Base
{
    /**
     * 签到
     */
    public function checkin(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $today = date('Y-m-d');

        // 检查今日是否已签到
        $key = "checkin:{$userId}:{$today}";
        if (Cache::get($key)) {
            $this->error('今日已签到');
            return;
        }

        try {
            Database::beginTransaction();

            try {
                // 检查数据库（事务内加锁防止并发重复签到）
                $existing = Database::fetchOne(
                    "SELECT id, credits, consecutive_days FROM user_checkins WHERE user_id = ? AND checkin_date = ? FOR UPDATE",
                    [$userId, $today]
                );
                if ($existing) {
                    Database::commit();
                    Cache::set($key, $existing, 86400);
                    $this->error('今日已签到');
                    return;
                }

                // 计算连续签到天数
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $lastCheckin = Database::fetchOne(
                    "SELECT checkin_date, consecutive_days FROM user_checkins WHERE user_id = ? ORDER BY checkin_date DESC LIMIT 1",
                    [$userId]
                );

                $consecutiveDays = 1;
                if ($lastCheckin && $lastCheckin['checkin_date'] === $yesterday) {
                    $consecutiveDays = (int)$lastCheckin['consecutive_days'] + 1;
                }

                // 计算积分：基础 10 分，连续签到额外奖励
                $credits = 10;
                if ($consecutiveDays >= 7) {
                    $credits = 20; // 连续7天奖励20积分
                } elseif ($consecutiveDays >= 3) {
                    $credits = 15; // 连续3天奖励15积分
                }

                // VIP 积分倍率
                $multiplier = \App\Services\VipSvc::getCheckinMultiplier($userId);
                $credits = (int)round($credits * $multiplier);

                // 记录签到
                Database::execute(
                    "INSERT INTO user_checkins (user_id, checkin_date, consecutive_days, credits, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$userId, $today, $consecutiveDays, $credits, time()]
                );

                // 积分发放放在事务内，保证签到记录和积分一致性
                $creditSvc = new CreditSvc();
                $ok = $creditSvc->addCredits($userId, $credits, 'checkin', "每日签到（连续{$consecutiveDays}天）");
                if (!$ok) {
                    throw new \RuntimeException('积分发放失败');
                }

                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }

            Cache::delete("user:profile:{$userId}");
            Cache::set($key, ['id' => 1, 'credits' => $credits, 'consecutive_days' => $consecutiveDays], 86400);

            $this->success('签到成功', [
                'credits' => $credits,
                'consecutive_days' => $consecutiveDays,
            ]);
        } catch (\Throwable $e) {
            error_log('[Checkin] ' . $e->getMessage());
            $this->error('签到失败，请稍后重试');
            return;
        }
    }
}
