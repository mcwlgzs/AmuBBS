<?php
/**
 * 签到控制器
 */

namespace App\Controllers;

use App\Models\Checkin as CheckinModel;
use App\Models\User;
use App\Services\CreditSvc;
use App\Services\VipSvc;
use Core\Database;

class Checkin extends Base
{
    /**
     * 签到
     *
     * 事务边界留在控制器：它包住的不只是签到表，还有 CreditSvc 发积分（写 credit_logs + users.credits），
     * 属于跨表的业务编排，不适合塞进只负责 SQL 的模型。
     */
    public function checkin(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $today = date('Y-m-d');

        // 检查今日是否已签到（先看缓存）
        $cached = CheckinModel::cachedToday($userId, $today);
        if ($cached) {
            // htmx：把卡片刷成「今日已签到」就行（卡片本身就是反馈，不用再弹提示）
            if ($this->isHtmx()) {
                $this->renderCheckinCard(
                    true,
                    (int)($cached['credits'] ?? 0),
                    (int)($cached['consecutive_days'] ?? 0)
                );
                return;
            }
            $this->error('今日已签到');
            return;
        }

        $credits = 0;
        $consecutiveDays = 0;
        $existingRow = null;

        try {
            Database::transaction(function () use ($userId, $today, &$credits, &$consecutiveDays, &$existingRow): void {
                // 检查数据库（事务内加锁防止并发重复签到）
                $existing = CheckinModel::lockedToday($userId, $today);
                if ($existing) {
                    $existingRow = $existing;
                    $credits = (int)($existing['credits'] ?? 0);
                    $consecutiveDays = (int)($existing['consecutive_days'] ?? 0);
                    return;
                }

                // 计算连续签到天数
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $lastCheckin = CheckinModel::lastCheckin($userId);

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
                $credits = (int)round($credits * VipSvc::getCheckinMultiplier($userId));

                // 记录签到
                CheckinModel::create($userId, $today, $consecutiveDays, $credits);

                // 积分发放放在事务内，保证签到记录和积分一致性
                $creditSvc = new CreditSvc();
                if (!$creditSvc->addCredits($userId, $credits, 'checkin', "每日签到（连续{$consecutiveDays}天）")) {
                    throw new \RuntimeException('积分发放失败');
                }
            });

            if ($existingRow !== null) {
                // 缓存写在事务提交之后：万一提交失败，缓存不该提前留下「已签到」
                CheckinModel::rememberRow($userId, $today, $existingRow);
                if ($this->isHtmx()) {
                    $this->renderCheckinCard(true, $credits, $consecutiveDays);
                    return;
                }
                $this->error('今日已签到');
                return;
            }

            User::forgetUserCaches($userId);
            CheckinModel::rememberToday($userId, $today, $credits, $consecutiveDays);

            if ($this->isHtmx()) {
                // 回刷新后的签到卡片（首页 hx-target="#checkinCard" 替换它）
                $this->renderCheckinCard(true, $credits, $consecutiveDays);
                return;
            }

            $this->success('签到成功', [
                'credits' => $credits,
                'consecutive_days' => $consecutiveDays,
            ]);
        } catch (\Throwable $e) {
            error_log('[Checkin] ' . $e->getMessage());
            $this->respondFragment(false, '签到失败，请稍后重试', static function (): void {});
            return;
        }
    }

    /**
     * 渲染签到卡片片段
     *
     * 首页整页渲染走的是 index/_checkin.php 同一份标记，这里只是给 htmx 一个出口。
     */
    private function renderCheckinCard(bool $checkedIn, int $credits, int $days): void
    {
        $this->render('index/_checkin', [
            'checkedIn'      => $checkedIn,
            'checkinCredits' => $credits,
            'checkinDays'    => $days,
        ]);
    }
}
