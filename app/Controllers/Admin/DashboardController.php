<?php
/**
 * 后台 - 概览（仪表盘、监控统计）
 */

namespace App\Controllers\Admin;

use Core\Database;

class DashboardController extends AdminBase
{
    /**
     * 后台 shell 框架页（iframe 外壳）
     */
    public function shell(): void
    {
        $this->requireAdmin();
        $this->render('admin/layout_iframe', [
            'pageTitle' => '管理后台',
        ]);
    }

    /**
     * 仪表盘
     */
    public function dashboard(): void
    {
        $this->requireAdmin();

        $stats = \App\Services\RuntimeSvc::getStats();
        $todayStats = \App\Services\RuntimeSvc::getTodayStats();

        // 最近 10 条操作日志
        $recentLogs = [];
        try {
            $recentLogs = \App\Services\LogService::getModLogs(1, 10, []);
        } catch (\Throwable $e) {
            error_log('[Admin:Dashboard] recent logs: ' . $e->getMessage());
        }

        $recentUsers = Database::fetchAll(
            "SELECT id, username, email, group_id, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5"
        );

        $recentThreads = Database::fetchAll(
            "SELECT id, title, username, created_at FROM threads WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5"
        );

        // 系统信息
        $mysqlVersion = '-';
        try { $mysqlVersion = Database::fetchOne("SELECT VERSION() as v")['v'] ?? '-'; } catch (\Throwable $e) { /* 非关键信息，静默忽略 */ }

        $this->render('admin/dashboard', [
            'pageTitle' => '仪表盘',
            'stats' => $stats,
            'todayStats' => $todayStats,
            'recentLogs' => $recentLogs,
            'recentUsers' => $recentUsers,
            'recentThreads' => $recentThreads,
            'mysqlVersion' => $mysqlVersion,
        ]);
    }

    /**
     * 监控统计页面
     */
    public function monitor(): void
    {
        $this->requireAdmin();

        $todayStats = \App\Services\RuntimeSvc::getTodayStats();
        $todayStats['new_users'] = $todayStats['users'];
        $todayStats['new_threads'] = $todayStats['threads'];
        $todayStats['new_posts'] = $todayStats['posts'];

        $trend = [];
        $weekAgo = strtotime("-6 days midnight");
        $tomorrow = strtotime("+1 day midnight");

        // 3 条 GROUP BY 查询替代 21 条逐日 COUNT 查询
        $threadTrend = Database::fetchAll(
            "SELECT FROM_UNIXTIME(created_at, '%m-%d') as d, COUNT(*) as c FROM threads WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL GROUP BY d",
            [$weekAgo, $tomorrow]
        );
        $postTrend = Database::fetchAll(
            "SELECT FROM_UNIXTIME(created_at, '%m-%d') as d, COUNT(*) as c FROM posts WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL GROUP BY d",
            [$weekAgo, $tomorrow]
        );
        $userTrend = Database::fetchAll(
            "SELECT FROM_UNIXTIME(created_at, '%m-%d') as d, COUNT(*) as c FROM users WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL GROUP BY d",
            [$weekAgo, $tomorrow]
        );

        $threadMap = array_column($threadTrend, 'c', 'd');
        $postMap = array_column($postTrend, 'c', 'd');
        $userMap = array_column($userTrend, 'c', 'd');

        for ($i = 6; $i >= 0; $i--) {
            $day = date('m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date' => $day,
                'threads' => (int)($threadMap[$day] ?? 0),
                'posts' => (int)($postMap[$day] ?? 0),
                'users' => (int)($userMap[$day] ?? 0),
            ];
        }

        $weekStart = strtotime('monday this week');
        $hotThreads = Database::fetchAll("
            SELECT id, title, username, views, reply_count
            FROM threads
            WHERE created_at >= ? AND deleted_at IS NULL
            ORDER BY reply_count DESC, views DESC
            LIMIT 10
        ", [$weekStart]);

        $activeUsers = Database::fetchAll("
            SELECT u.id, u.username, u.avatar, COUNT(p.id) as post_count
            FROM posts p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.created_at >= ? AND p.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY post_count DESC
            LIMIT 10
        ", [$weekStart]);

        $this->render('admin/monitor', [
            'pageTitle' => '监控统计',
            'todayStats' => $todayStats,
            'trend' => $trend,
            'hotThreads' => $hotThreads,
            'activeUsers' => $activeUsers,
        ]);
    }
}
