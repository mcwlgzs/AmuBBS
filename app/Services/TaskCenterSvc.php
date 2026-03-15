<?php
namespace App\Services;

use Core\Database;
use Core\Cache;

/**
 * 任务中心服务
 */
class TaskCenterSvc
{
    // 已领取缓存（类属性，可跨方法清除）
    private static array $claimCache = [];

    // 任务定义
    private static array $tasks = [
        'checkin' => ['name' => '每日签到', 'desc' => '完成每日签到', 'credits' => 5, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/></svg>', 'max' => 1],
        'thread' => ['name' => '发布帖子', 'desc' => '发布一个新帖子', 'credits' => 10, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>', 'max' => 1],
        'reply' => ['name' => '评论帖子', 'desc' => '评论3个帖子', 'credits' => 5, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>', 'max' => 3],
        'like' => ['name' => '点赞内容', 'desc' => '给5个内容点赞', 'credits' => 3, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>', 'max' => 5],
        'moment' => ['name' => '发布动态', 'desc' => '发布一条动态', 'credits' => 5, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>', 'max' => 1],
        'follow' => ['name' => '关注用户', 'desc' => '关注2个用户', 'credits' => 3, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', 'max' => 2],
        'share' => ['name' => '浏览帖子', 'desc' => '浏览10个帖子', 'credits' => 3, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>', 'max' => 10], // key 历史原因为 share，实际统计 browse_history
    ];

    /**
     * 获取任务列表及用户完成状态
     */
    public static function getTaskList(int $userId): array
    {
        $today = date('Y-m-d');
        $progress = self::getTodayProgress($userId, $today);

        $tasks = [];
        $completedCount = 0;
        $totalCredits = 0;
        $earnedCredits = 0;

        foreach (self::$tasks as $key => $task) {
            $current = $progress[$key] ?? 0;
            $completed = $current >= $task['max'];
            $claimed = self::isClaimed($userId, $key, $today);

            if ($completed) $completedCount++;
            $totalCredits += $task['credits'];
            if ($claimed) $earnedCredits += $task['credits'];

            $tasks[] = [
                'key' => $key,
                'name' => $task['name'],
                'desc' => $task['desc'],
                'credits' => $task['credits'],
                'icon' => $task['icon'],
                'max' => $task['max'],
                'current' => min($current, $task['max']),
                'completed' => $completed,
                'claimed' => $claimed,
            ];
        }

        return [
            'tasks' => $tasks,
            'completedCount' => $completedCount,
            'totalCount' => count(self::$tasks),
            'totalCredits' => $totalCredits,
            'earnedCredits' => $earnedCredits,
        ];
    }

    /**
     * 获取今日进度（7次COUNT → 1次子查询SQL）
     */
    private static function getTodayProgress(int $userId, string $today): array
    {
        $todayStart = strtotime($today);
        $todayEnd = $todayStart + 86400;

        $row = Database::fetchOne("
            SELECT
                (SELECT COUNT(*) FROM user_checkins WHERE user_id = ? AND checkin_date = ?) as checkin,
                (SELECT COUNT(*) FROM threads WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as thread,
                (SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as reply,
                (SELECT COUNT(*) FROM post_likes WHERE user_id = ? AND created_at >= ? AND created_at < ?) as `like`,
                (SELECT COUNT(*) FROM moments WHERE user_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL) as moment,
                (SELECT COUNT(*) FROM user_follows WHERE user_id = ? AND created_at >= ? AND created_at < ?) as follow,
                (SELECT COUNT(*) FROM browse_history WHERE user_id = ? AND created_at >= ? AND created_at < ?) as share
        ", [
            $userId, $today,
            $userId, $todayStart, $todayEnd,
            $userId, $todayStart, $todayEnd,
            $userId, $todayStart, $todayEnd,
            $userId, $todayStart, $todayEnd,
            $userId, $todayStart, $todayEnd,
            $userId, $todayStart, $todayEnd,
        ]);

        return [
            'checkin' => min(1, (int)($row['checkin'] ?? 0)),
            'thread'  => (int)($row['thread'] ?? 0),
            'reply'   => (int)($row['reply'] ?? 0),
            'like'    => (int)($row['like'] ?? 0),
            'moment'  => (int)($row['moment'] ?? 0),
            'follow'  => (int)($row['follow'] ?? 0),
            'share'   => (int)($row['share'] ?? 0),
        ];
    }

    /**
     * 检查是否已领取奖励（单条）
     */
    private static function isClaimed(int $userId, string $taskKey, string $date): bool
    {
        $claimed = self::batchIsClaimed($userId, $date);
        return isset($claimed[$taskKey]);
    }

    /**
     * 批量检查已领取的任务（7次查询 → 1次）
     */
    private static function batchIsClaimed(int $userId, string $date): array
    {
        $cacheKey = "{$userId}:{$date}";
        if (isset(self::$claimCache[$cacheKey])) {
            return self::$claimCache[$cacheKey];
        }

        $rows = Database::fetchAll(
            "SELECT task_key FROM user_task_claims WHERE user_id = ? AND claim_date = ?",
            [$userId, $date]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['task_key']] = true;
        }
        self::$claimCache[$cacheKey] = $map;
        return $map;
    }

    /**
     * 领取任务奖励
     */
    public static function claim(int $userId, string $taskKey): array
    {
        if (!isset(self::$tasks[$taskKey])) {
            throw new \RuntimeException('任务不存在');
        }

        $today = date('Y-m-d');
        $task = self::$tasks[$taskKey];

        // 检查是否已领取
        if (self::isClaimed($userId, $taskKey, $today)) {
            throw new \RuntimeException('今日已领取该任务奖励');
        }

        // 检查是否完成
        $progress = self::getTodayProgress($userId, $today);
        $current = $progress[$taskKey] ?? 0;
        if ($current < $task['max']) {
            throw new \RuntimeException('任务尚未完成');
        }

        // 记录领取 + 发放积分在同一事务内，避免领取记录已写入但积分未到账
        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO user_task_claims (user_id, task_key, credits, claim_date, created_at) VALUES (?, ?, ?, ?, ?)",
                [$userId, $taskKey, $task['credits'], $today, time()]
            );

            // 直接执行积分操作，避免 addCredits 内部吞掉异常导致领取记录已写入但积分未到账
            Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$task['credits'], $userId]);
            $balance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId])['credits'] ?? 0;
            Database::execute(
                "INSERT INTO credit_logs (user_id, amount, balance, type, description, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $task['credits'], $balance, 'task', '完成任务: ' . $task['name'], time()]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            // 唯一索引冲突 = 已领取，其他异常也统一处理
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw new \RuntimeException('今日已领取该任务奖励');
            }
            throw new \RuntimeException('领取失败，请重试');
        }

        // 清除领取缓存，避免同一请求内返回旧数据
        self::$claimCache = [];
        Cache::delete("user:profile:{$userId}");

        return ['credits' => $task['credits'], 'task_name' => $task['name']];
    }

    /**
     * 一键领取所有已完成任务
     */
    public static function claimAll(int $userId): array
    {
        $today = date('Y-m-d');
        $progress = self::getTodayProgress($userId, $today);
        $totalCredits = 0;
        $claimedTasks = [];

        Database::beginTransaction();

        $claimed = self::batchIsClaimed($userId, $today);
        try {
            foreach (self::$tasks as $key => $task) {
                $current = $progress[$key] ?? 0;
                if ($current >= $task['max'] && !isset($claimed[$key])) {
                    try {
                        Database::execute(
                            "INSERT INTO user_task_claims (user_id, task_key, credits, claim_date, created_at) VALUES (?, ?, ?, ?, ?)",
                            [$userId, $key, $task['credits'], $today, time()]
                        );
                        $totalCredits += $task['credits'];
                        $claimedTasks[] = $task['name'];
                    } catch (\Throwable $e) {
                        // 唯一索引冲突 = 已被并发请求领取，跳过
                        if (!str_contains($e->getMessage(), 'Duplicate')) {
                            throw $e;
                        }
                    }
                }
            }

            // 积分发放在同一事务内，避免领取记录已提交但积分未到账
            if ($totalCredits > 0) {
                Database::execute("UPDATE users SET credits = credits + ? WHERE id = ?", [$totalCredits, $userId]);
                $balance = Database::fetchOne("SELECT credits FROM users WHERE id = ?", [$userId])['credits'] ?? 0;
                Database::execute(
                    "INSERT INTO credit_logs (user_id, amount, balance, type, description, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                    [$userId, $totalCredits, $balance, 'task', '批量领取任务奖励', time()]
                );
            }

            Database::commit();

            if ($totalCredits > 0) {
                Cache::delete("user:profile:{$userId}");
            }
            // 清除领取缓存
            self::$claimCache = [];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new \RuntimeException('领取失败，请重试');
        }

        return ['credits' => $totalCredits, 'tasks' => $claimedTasks];
    }
}
