<?php
/**
 * 定时任务服务
 */

namespace App\Services;

use App\Models\Forum;
use App\Models\Moment;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Thread;
use App\Models\User;
use Core\Cache;

class CronSvc
{
    /**
     * 执行所有定时任务
     */
    public function runAll(): array
    {
        $results = [];

        // 使用带 owner token 的原子锁防止并发执行
        // TTL 要留够余量：10 个任务串行跑完可能超过 5 分钟，锁提前过期会导致重复执行
        $lockKey = 'cron:lock';
        $lockToken = bin2hex(random_bytes(8));
        if (!Cache::add($lockKey, $lockToken, 1800)) {
            return ['message' => '任务正在执行中'];
        }

        $tasks = [
            'clean_attachments' => fn() => $this->cleanOrphanAttachments(),
            'update_forum_stats' => fn() => $this->updateForumStats(),
            'clean_ip_blacklist' => fn() => $this->cleanExpiredIpBlacklist(),
            'reset_today_stats' => fn() => $this->resetTodayStats(),
            'flush_views' => fn() => $this->flushPendingViews(),
            'warm_cache' => fn() => $this->warmCache(),
            'gc_cache' => fn() => $this->gcCache(),
            'rotate_logs' => fn() => $this->rotateLogs(),
            'clean_ip_access' => fn() => IpAccessSvc::cleanOldLogs(7),
            'clean_old_logs' => fn() => LogService::cleanOldLogs(90),
            'process_queue' => fn() => $this->processQueues(),
            'release_stale_jobs' => fn() => QueueSvc::releaseStale(300),
        ];

        try {
            foreach ($tasks as $name => $task) {
                try {
                    $results[$name] = $task();
                } catch (\Throwable $e) {
                    $results[$name] = 'error: ' . $e->getMessage();
                    error_log("[CronSvc] {$name} 失败: " . $e->getMessage());
                }
            }
        } finally {
            // 仅删除自己持有的锁，避免误删其他进程的锁
            $currentToken = Cache::get($lockKey);
            if ($currentToken === $lockToken) {
                Cache::delete($lockKey);
            }
        }

        return $results;
    }

    /**
     * 清理未关联的临时附件
     */
    private function cleanOrphanAttachments(): int
    {
        return AttachmentSvc::cleanOrphanAttachments();
    }

    /**
     * 回收过期缓存文件
     *
     * 文件驱动的 TTL 是惰性的：只有「再次被读到」才会 unlink，再也无人读的 key
     * 会永久留在磁盘上；原来的 maybeGc 只是 1/200 抽奖，且只在请求里触发。
     * 这里由 cron 兜底全量回收（Cache::gc() 之前全项目零调用者）。
     */
    private function gcCache(): int
    {
        return Cache::gc();
    }

    /**
     * 轮转 / 清理文件日志
     *
     * 日志是「一天一个文件、FILE_APPEND」且**没有任何删除逻辑**，
     * storage/logs 会单调增长。这里按天保留 $keepDays 天，
     * 单个文件超过 $maxBytes 时归档一份（只留一代），当前文件重新开始。
     *
     * @return array{deleted:int,rotated:int}
     */
    private function rotateLogs(): array
    {
        $dir = APP_PATH . 'storage/logs/';
        $keepDays = 30;
        $maxBytes = 10 * 1024 * 1024; // 10 MB
        $now = time();
        $deleted = 0;
        $rotated = 0;

        foreach (glob($dir . '*.log') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            // 文件名形如 2026-09-25.log：按日期删过期文件
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                $ts = strtotime($m[1] . ' 00:00:00');
                if ($ts !== false && $ts < $now - $keepDays * 86400) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                    continue;
                }
            }

            // 单文件过大：归档一份（覆盖上一代归档），避免任何单个文件无限增长
            if (filesize($file) > $maxBytes) {
                if (@rename($file, $file . '.1')) {
                    $rotated++;
                }
            }
        }

        return ['deleted' => $deleted, 'rotated' => $rotated];
    }

    /**
     * 更新板块统计（重新计算 thread_count 和 post_count）
     */
    private function updateForumStats(): int
    {
        // 计数与批量写回都在模型里（Thread/Post 各一次 GROUP BY + Forum 一条 CASE WHEN）
        return Forum::rebuildStats();
    }

    /**
     * 清理过期 IP 黑名单
     */
    private function cleanExpiredIpBlacklist(): int
    {
        try {
            $count = \App\Models\IpBlacklist::pruneExpired();
            if ($count > 0) {
                IpBlacklistService::clearCache();
            }
            return $count;
        } catch (\Throwable $e) {
            error_log('[CronSvc] cleanExpiredIpBlacklist failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 重置今日统计缓存（每日0点由定时任务调用）
     */
    private function resetTodayStats(): bool
    {
        $lastReset = Cache::get('cron:today_reset_date');
        $today = date('Y-m-d');

        if ($lastReset === $today) {
            return false; // 今天已重置过
        }

        // 清零所有板块的今日统计
        Forum::resetTodayCounts();

        Cache::delete('runtime:today');
        Cache::delete('forums:list');
        Cache::set('cron:today_reset_date', $today, 86400);
        RuntimeSvc::refresh();
        return true;
    }

    /**
     * 将缓存中的待写入浏览量刷入数据库
     */
    private function flushPendingViews(): int
    {
        $count = 0;
        try {
            // 1) 最近有活动的帖子
            $threadIds = Thread::idsUpdatedSince(time() - 86400, 1000);

            // 2) 再加上「请求期间登记过的待写帖子」——老帖（超过 24h 没有回帖）
            //    不会出现在 idsUpdatedSince() 里，只靠上面的查询会整批漏掉。
            $dirty = Cache::getAndDelete(ThreadSvc::PENDING_VIEW_IDS_KEY);
            if (is_array($dirty) && $dirty) {
                $threadIds = array_values(array_unique(array_merge(
                    $threadIds,
                    array_map('intval', $dirty)
                )));
            }

            // 批量收集待写入的浏览量
            $batch = [];
            foreach ($threadIds as $tid) {
                $key = "views:pending:{$tid}";
                // 驱动无关的原子「读取并删除」，避免 get/delete 之间新增的浏览量丢失
                $pending = (int)Cache::getAndDelete($key);
                // 请求路径已经按 10 的整数倍落过库，这里只补零头，
                // 否则会把同一批浏览量写两遍
                $remainder = $pending % 10;
                if ($remainder > 0) {
                    $batch[$tid] = $remainder;
                }
            }

            // 批量 CASE WHEN 一次性写入数据库
            if (!empty($batch)) {
                Thread::addViewsBulk($batch);
                $count = count($batch);
            }
        } catch (\Throwable $e) {
            error_log('[CronSvc] flushViews failed: ' . $e->getMessage());
        }
        return $count;
    }

    /**
     * 缓存预热：主动刷新高频 key，避免用户请求触发冷启动
     */
    private function warmCache(): int
    {
        $warmed = 0;

        try {
            // 1. 首页全局置顶帖
            // 预热用的查询必须与读取方（ThreadSvc）完全一致，否则暖出来的形状对不上
            Cache::set('threads:global_tops_forum', Thread::getGlobalTopThreads(), 300);
            $warmed++;

            // 2. 版块列表（通过 ForumSvc 预热，保持带子板块的结构一致）
            $forumSvc = new ForumSvc();
            $forumSvc->getForumsWithChildren();
            $warmed++;

            // 3. 全站设置
            Cache::set('settings:all', Setting::all(), 3600);
            $warmed++;

            // 4. 侧边栏：活跃用户
            $activeUsers = User::mostActiveSince(time() - 604800, 12);
            Cache::set('sidebar:active_users', $activeUsers, 120);
            $warmed++;

            // 5. 侧边栏：最新动态
            Cache::set('sidebar:moments', Moment::latestWithUser(5), 60);
            $warmed++;

            // 6. 热门帖子（本周）
            $hotThreads = Thread::hotSince(strtotime('monday this week'), 10);
            Cache::set('threads:hot_weekly', $hotThreads, 300);
            $warmed++;

            // 7. 运行时统计
            RuntimeSvc::refresh();
            $warmed++;

        } catch (\Throwable $e) {
            error_log('[CronSvc] warmCache failed: ' . $e->getMessage());
        }

        return $warmed;
    }

    /**
     * 处理消息队列
     */
    private function processQueues(): int
    {
        $total = 0;

        // 处理邮件队列
        $total += QueueSvc::process('email', function (array $payload) {
            $mailer = new \Core\Mailer();
            $mailer->send($payload['to'], $payload['subject'], $payload['body']);
        }, 20);

        // 处理通知队列
        $total += QueueSvc::process('notification', function (array $payload) {
            if (!empty($payload['user_id']) && !empty($payload['content'])) {
                Notification::insertForUser(
                    (int)$payload['user_id'],
                    (int)($payload['from_user_id'] ?? 0),
                    (string)($payload['type'] ?? 'system'),
                    (string)($payload['title'] ?? ''),
                    (string)$payload['content'],
                    (string)($payload['target_type'] ?? ''),
                    (int)($payload['target_id'] ?? 0),
                    time()
                );
            }
        }, 50);

        return $total;
    }
}
