<?php
/**
 * 定时任务服务
 */

namespace App\Services;

use Core\Database;
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
        $lockKey = 'cron:lock';
        $lockToken = bin2hex(random_bytes(8));
        if (!Cache::add($lockKey, $lockToken, 300)) {
            return ['message' => '任务正在执行中'];
        }

        $tasks = [
            'clean_sessions' => fn() => $this->cleanExpiredSessions(),
            'clean_attachments' => fn() => $this->cleanOrphanAttachments(),
            'update_forum_stats' => fn() => $this->updateForumStats(),
            'clean_ip_blacklist' => fn() => $this->cleanExpiredIpBlacklist(),
            'reset_today_stats' => fn() => $this->resetTodayStats(),
            'flush_views' => fn() => $this->flushPendingViews(),
            'warm_cache' => fn() => $this->warmCache(),
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
     * 清理过期 session
     * 登录用户（user_id > 0）：超过7天清理
     * 游客（user_id = 0）：超过1小时清理
     */
    private function cleanExpiredSessions(): int
    {
        try {
            $userThreshold = time() - 604800;  // 7天
            $guestThreshold = time() - 3600;   // 1小时
            $count = Database::execute(
                "DELETE FROM sessions WHERE (user_id > 0 AND last_activity < ?) OR (user_id = 0 AND last_activity < ?)",
                [$userThreshold, $guestThreshold]
            );
            return $count;
        } catch (\Throwable $e) {
            error_log('[CronSvc] cleanExpiredSessions failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 清理未关联的临时附件
     */
    private function cleanOrphanAttachments(): int
    {
        return AttachmentSvc::cleanOrphanAttachments();
    }

    /**
     * 更新板块统计（重新计算 thread_count 和 post_count）
     */
    private function updateForumStats(): int
    {
        // 一次 GROUP BY 统计所有板块的帖子数和回复数，替代逐板块 N+1 查询
        $threadStats = Database::fetchAll(
            "SELECT forum_id, COUNT(*) as c FROM threads WHERE deleted_at IS NULL GROUP BY forum_id"
        );
        $threadMap = [];
        foreach ($threadStats as $row) {
            $threadMap[(int)$row['forum_id']] = (int)$row['c'];
        }

        $postStats = Database::fetchAll(
            "SELECT t.forum_id, COUNT(*) as c FROM posts p INNER JOIN threads t ON p.thread_id = t.id WHERE t.deleted_at IS NULL AND p.deleted_at IS NULL GROUP BY t.forum_id"
        );
        $postMap = [];
        foreach ($postStats as $row) {
            $postMap[(int)$row['forum_id']] = (int)$row['c'];
        }

        $forums = Database::fetchAll("SELECT id FROM forums WHERE deleted_at IS NULL");
        if (empty($forums)) {
            return 0;
        }

        // 批量 CASE WHEN 一次性更新，避免逐板块 N+1
        $ids = [];
        $threadCases = [];
        $postCases = [];
        $threadParams = [];
        $postParams = [];
        foreach ($forums as $forum) {
            $fid = (int)$forum['id'];
            $ids[] = $fid;
            $threadCases[] = "WHEN id = ? THEN ?";
            $threadParams[] = $fid;
            $threadParams[] = $threadMap[$fid] ?? 0;
            $postCases[] = "WHEN id = ? THEN ?";
            $postParams[] = $fid;
            $postParams[] = $postMap[$fid] ?? 0;
        }
        $threadCaseStr = implode(' ', $threadCases);
        $postCaseStr = implode(' ', $postCases);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // 参数顺序必须与 SQL 一致：先 thread CASE，再 post CASE，最后 WHERE IN
        $params = array_merge($threadParams, $postParams, $ids);
        Database::execute(
            "UPDATE forums SET thread_count = CASE {$threadCaseStr} ELSE thread_count END, post_count = CASE {$postCaseStr} ELSE post_count END WHERE id IN ({$placeholders})",
            $params
        );

        Cache::delete('forums:list');
        return count($forums);
    }

    /**
     * 清理过期 IP 黑名单
     */
    private function cleanExpiredIpBlacklist(): int
    {
        try {
            $now = time();
            $count = Database::execute(
                "DELETE FROM ip_blacklist WHERE expire_at > 0 AND expire_at < ?",
                [$now]
            );
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
        Database::execute("UPDATE forums SET today_threads = 0, today_posts = 0 WHERE deleted_at IS NULL");

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
            // 获取所有帖子 ID（只查最近有活动的）
            $threads = Database::fetchAll(
                "SELECT id FROM threads WHERE deleted_at IS NULL AND updated_at > ? ORDER BY id DESC LIMIT 1000",
                [time() - 86400]
            );

            // 批量收集待写入的浏览量
            $batch = [];
            foreach ($threads as $t) {
                $tid = (int)$t['id'];
                $key = "views:pending:{$tid}";
                // 原子读取并删除，避免 get/delete 之间新增的浏览量丢失
                $pending = (int)Cache::eval(
                    "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]) end; return v",
                    [$key]
                );
                if ($pending > 0) {
                    $batch[$tid] = $pending;
                }
            }

            // 批量 CASE WHEN 一次性写入数据库
            if (!empty($batch)) {
                $ids = array_keys($batch);
                $cases = [];
                $params = [];
                foreach ($batch as $tid => $views) {
                    $cases[] = "WHEN id = ? THEN views + ?";
                    $params[] = $tid;
                    $params[] = $views;
                }
                $caseStr = implode(' ', $cases);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge($params, $ids);
                Database::execute(
                    "UPDATE threads SET views = CASE {$caseStr} ELSE views END WHERE id IN ({$placeholders})",
                    $params
                );
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
            $topThreads = Database::fetchAll(
                "SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color FROM threads t LEFT JOIN users u ON t.user_id = u.id WHERE t.is_top = 2 AND t.deleted_at IS NULL ORDER BY t.updated_at DESC LIMIT 10"
            );
            Cache::set('threads:global_tops_forum', $topThreads, 300);
            $warmed++;

            // 2. 版块列表（通过 ForumSvc 预热，保持带子板块的结构一致）
            $forumSvc = new \App\Services\ForumSvc();
            $forumSvc->getForumsWithChildren();
            $warmed++;

            // 3. 全站设置
            $settings = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $map = [];
            foreach ($settings as $s) { $map[$s['key']] = $s['value']; }
            Cache::set('settings:all', $map, 3600);
            $warmed++;

            // 4. 侧边栏：活跃用户
            $weekAgo = time() - 604800;
            $activeUsers = Database::fetchAll(
                "SELECT u.id, u.username, u.nickname, u.avatar, u.nickname_color, COUNT(p.id) as post_count FROM posts p INNER JOIN users u ON p.user_id = u.id WHERE p.created_at > ? AND p.deleted_at IS NULL GROUP BY u.id ORDER BY post_count DESC LIMIT 12",
                [$weekAgo]
            );
            Cache::set('sidebar:active_users', $activeUsers, 120);
            $warmed++;

            // 5. 侧边栏：最新动态
            $moments = Database::fetchAll(
                "SELECT m.*, u.username, u.nickname, u.avatar, u.nickname_color FROM moments m LEFT JOIN users u ON m.user_id = u.id WHERE m.deleted_at IS NULL ORDER BY m.created_at DESC LIMIT 5"
            );
            Cache::set('sidebar:moments', $moments, 60);
            $warmed++;

            // 6. 热门帖子（本周）
            $weekStart = strtotime('monday this week');
            $hotThreads = Database::fetchAll(
                "SELECT id, title, username, views, reply_count FROM threads WHERE created_at >= ? AND deleted_at IS NULL ORDER BY reply_count DESC, views DESC LIMIT 10",
                [$weekStart]
            );
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
                Database::execute(
                    "INSERT INTO notifications (user_id, from_user_id, type, title, content, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        (int)$payload['user_id'],
                        (int)($payload['from_user_id'] ?? 0),
                        $payload['type'] ?? 'system',
                        $payload['title'] ?? '',
                        $payload['content'],
                        $payload['target_type'] ?? '',
                        (int)($payload['target_id'] ?? 0),
                        time(),
                    ]
                );
                Database::execute("UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?", [(int)$payload['user_id']]);
            }
        }, 50);

        return $total;
    }
}
