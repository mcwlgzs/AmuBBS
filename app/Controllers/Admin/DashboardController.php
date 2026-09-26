<?php
/**
 * 后台 - 概览（仪表盘、监控统计）
 */

namespace App\Controllers\Admin;

use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use App\Services\LogService;
use App\Services\RuntimeSvc;
use Core\Database;

class DashboardController extends AdminBase
{
    /**
     * 后台入口（外壳）
     *
     * layuimini 的 iframe 多 tab 形态：这条路由只输出**外壳** ——
     * 顶栏、左侧菜单容器、tab 栏。真正的页面在外壳的 iframe 里各自加载
     * （首页 tab 由 homeInfo.href 指向 /admin/dashboard）。
     *
     * 之所以外壳和页面分开：切 tab 不用重建菜单和顶栏，
     * 刷新某个子页面也不会把整套外壳重画一遍。
     */
    public function shell(): void
    {
        $this->requireAdmin();

        $this->render('admin/layout', ['pageTitle' => '管理后台']);
    }

    /**
     * 后台菜单接口（layuimini 的 iniUrl）
     *
     * 由外壳里的 miniAdmin.render({iniUrl: '/admin/menu.json'}) 拉取，
     * 返回 layuimini 约定的 {homeInfo, logoInfo, menuInfo} 三段结构。
     * 菜单数据只有一份来源：config/admin_nav.php。
     */
    public function menu(): void
    {
        $this->requireAdmin();

        $nav = require APP_PATH . 'config/admin_nav.php';

        $menuInfo = [];
        foreach ($nav['groups'] as $label => $group) {
            $children = [];
            foreach ($group['pages'] as $page) {
                $children[] = [
                    'title'  => $page['title'],
                    'href'   => $page['href'],
                    'icon'   => $page['icon'],
                    'target' => '_self',
                ];
            }

            $menuInfo[] = [
                'title'  => $label,
                'icon'   => $group['icon'],
                'href'   => '',
                'target' => '_self',
                'child'  => $children,
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'homeInfo' => $nav['home'],
            'logoInfo' => $nav['logo'],
            'menuInfo' => $menuInfo,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 仪表盘
     */
    public function dashboard(): void
    {
        $this->requireAdmin();

        $this->renderAdmin('admin/dashboard', $this->dashboardData(), 'dashboard');
    }

    /**
     * 汇总仪表盘所需数据
     */
    private function dashboardData(): array
    {
        $stats = RuntimeSvc::getStats();
        $todayStats = RuntimeSvc::getTodayStats();

        // 最近 10 条操作日志
        $recentLogs = [];
        try {
            $recentLogs = LogService::getModLogs(1, 10, []);
        } catch (\Throwable $e) {
            error_log('[Admin:Dashboard] recent logs: ' . $e->getMessage());
        }

        $recentUsers = User::recentlyRegistered(5);
        $recentThreads = Thread::recentlyCreated(5);

        // 系统信息
        $mysqlVersion = Database::serverVersion() ?: '-';

        return [
            'pageTitle' => '仪表盘',
            'stats' => $stats,
            'todayStats' => $todayStats,
            'recentLogs' => $recentLogs,
            'recentUsers' => $recentUsers,
            'recentThreads' => $recentThreads,
            'mysqlVersion' => $mysqlVersion,
        ];
    }

    /**
     * 监控统计页面
     */
    public function monitor(): void
    {
        $this->requireAdmin();

        $todayStats = RuntimeSvc::getTodayStats();
        $todayStats['new_users'] = $todayStats['users'];
        $todayStats['new_threads'] = $todayStats['threads'];
        $todayStats['new_posts'] = $todayStats['posts'];

        $trend = [];
        $weekAgo = strtotime("-6 days midnight");
        $tomorrow = strtotime("+1 day midnight");

        // 3 条 GROUP BY 查询替代 21 条逐日 COUNT 查询
        // （dailyCounts 在 Model 基类里，用 late static binding 取各自的表）
        $threadTrend = Thread::dailyCounts($weekAgo, $tomorrow);
        $postTrend = Post::dailyCounts($weekAgo, $tomorrow);
        $userTrend = User::dailyCounts($weekAgo, $tomorrow);

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
        $hotThreads = Thread::hotSince($weekStart, 10);
        $activeUsers = User::mostActiveSince($weekStart, 10);

        $this->renderAdmin('admin/monitor', [
            'pageTitle' => '监控统计',
            'todayStats' => $todayStats,
            'trend' => $trend,
            'hotThreads' => $hotThreads,
            'activeUsers' => $activeUsers,
        ], 'monitor');
    }
}
