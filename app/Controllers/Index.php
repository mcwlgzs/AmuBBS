<?php
/**
 * 首页控制器
 */

namespace App\Controllers;

use Core\Database;
use Core\Cache;
use Core\PageCache;

class Index extends Base
{
    /**
     * 首页
     */
    public function index(): void
    {
        $startTime = microtime(true);

        // 批量预热：一次 Redis MGET 拉取所有首页缓存 key 到进程内存
        // 后续所有 Cache::get() 直接命中 L1，零网络开销
        $page = max(1, (int)($_GET['page'] ?? 1));
        $preloadKeys = [
            'settings:all',
            'forums:list',
            'forums:children:all',
            'forums:latest_threads',
            "threads:latest:p{$page}",
            'threads:total_count',
            "threads:hot:p{$page}",
            'threads:hot:count',
            'runtime:stats',
            'runtime:today',
            'online:summary',
            'online:users:10',
            'tags:popular',
            "threads:featured:p{$page}",
            'threads:featured:count',
            'announcements:active',
            'sidebar:checkin_rank',
            'sidebar:credit_rank',
            'sidebar:new_users',
            'sidebar:moments',
            'sidebar:active_users',
            'friend_links:active',
            'ip_blacklist:map',
        ];
        if ($this->isLoggedIn()) {
            $uid = $this->getCurrentUserId();
            $today = date('Y-m-d');
            $preloadKeys[] = "checkin:{$uid}:{$today}";
            $preloadKeys[] = "user:header:{$uid}";
        }
        Cache::preload($preloadKeys);

        // 获取板块列表（带缓存）
        $forums = [];
        try {
            $forums = Cache::getStale('forums:list', function() {
                return Database::fetchAll("
                    SELECT * FROM forums
                    WHERE parent_id = 0 AND deleted_at IS NULL
                    ORDER BY `rank` DESC
                ");
            }, 3600) ?? [];
        } catch (\Throwable $e) {
            error_log('[Index] forums:list - ' . $e->getMessage());
        }

        // 批量获取所有子板块 + 最新帖子（避免 N+1）
        $forumIds = array_column($forums, 'id');
        $allChildren = [];
        $allLatest = [];
        if (!empty($forumIds)) {
            try {
                $allChildren = Cache::getStale('forums:children:all', function() {
                    return Database::fetchAll("
                        SELECT * FROM forums
                        WHERE parent_id > 0 AND deleted_at IS NULL
                        ORDER BY `rank` DESC
                    ");
                }, 3600);
            } catch (\Throwable $e) {
                error_log('[Index] forums:children - ' . $e->getMessage());
            }

            $placeholders = implode(',', array_fill(0, count($forumIds), '?'));
            try {
                $allLatest = Cache::getStale('forums:latest_threads', function() use ($placeholders, $forumIds) {
                    return Database::fetchAll("
                        SELECT t.id, t.title, t.username, t.created_at, t.forum_id
                        FROM threads t
                        INNER JOIN (
                            SELECT forum_id, MAX(id) as max_id
                            FROM threads
                            WHERE forum_id IN ({$placeholders}) AND deleted_at IS NULL
                            GROUP BY forum_id
                        ) latest ON t.id = latest.max_id
                    ", $forumIds);
                }, 300);
            } catch (\Throwable $e) {
                error_log('[Index] forums:latest_threads - ' . $e->getMessage());
            }
        }

        // 按 parent_id / forum_id 分组挂载
        $childrenMap = [];
        foreach ($allChildren as $child) {
            $childrenMap[$child['parent_id']][] = $child;
        }
        $latestMap = [];
        foreach ($allLatest as $lt) {
            $latestMap[$lt['forum_id']] = $lt;
        }
        foreach ($forums as &$forum) {
            $forum['children'] = $childrenMap[$forum['id']] ?? [];
            $forum['latest_thread'] = $latestMap[$forum['id']] ?? null;
        }

        // 获取最新帖子（分页，短期缓存）
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = max(5, min(100, \App\Services\SettingSvc::getInt('threads_per_page', 20)));
        $offset = ($page - 1) * $perPage;
        $latestThreads = [];
        $totalThreadPages = 1;
        try {
            $latestThreads = Cache::getStale("threads:latest:p{$page}", function() use ($perPage, $offset) {
                return Database::fetchAll("
                    SELECT t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name
                    FROM threads t
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN forums f ON t.forum_id = f.id
                    WHERE t.deleted_at IS NULL
                    ORDER BY t.is_top DESC, t.created_at DESC
                    LIMIT ? OFFSET ?
                ", [$perPage, $offset]);
            }, 60);
            $totalCount = Cache::getStale('threads:total_count', function() {
                return Database::fetchOne("SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL")['c'] ?? 0;
            }, 60);
            $totalThreadPages = max(1, (int)ceil($totalCount / $perPage));
        } catch (\Throwable $e) {
            error_log('[Index] threads:latest - ' . $e->getMessage());
        }

        // 热门帖子（按回复数排序，受后台每页主题数设置控制）
        $hotThreads = [];
        $totalHotPages = 1;
        try {
            $hotThreads = Cache::getStale("threads:hot:p{$page}", function() use ($perPage, $offset) {
                return Database::fetchAll("
                    SELECT t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name
                    FROM threads t
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN forums f ON t.forum_id = f.id
                    WHERE t.deleted_at IS NULL
                    ORDER BY t.is_top DESC, t.reply_count DESC, t.views DESC
                    LIMIT ? OFFSET ?
                ", [$perPage, $offset]);
            }, 300);
            // 复用 threads:total_count，避免重复 COUNT 查询
            $totalHotPages = $totalThreadPages;
        } catch (\Throwable $e) {
            error_log('[Index] threads:hot - ' . $e->getMessage());
        }

        // 获取统计信息（使用 RuntimeSvc 缓存，避免每次 COUNT）
        $stats = ['threads' => 0, 'posts' => 0, 'users' => 0];
        $todayStats = ['threads' => 0, 'posts' => 0, 'users' => 0];
        try {
            $stats = \App\Services\RuntimeSvc::getStats();
            $todayStats = \App\Services\RuntimeSvc::getTodayStats();
        } catch (\Throwable $e) {
            error_log('[Index] stats - ' . $e->getMessage());
        }

        // 热门标签
        $popularTags = [];
        try {
            $popularTags = \App\Services\TagSvc::getPopularTags(15);
        } catch (\Throwable $e) {
            error_log('[Index] tags - ' . $e->getMessage());
        }

        // 推荐帖子（精华帖，受后台每页主题数设置控制）
        $featuredThreads = [];
        $totalFeaturedPages = 1;
        try {
            $featuredThreads = Cache::getStale("threads:featured:p{$page}", function() use ($perPage, $offset) {
                return Database::fetchAll("
                    SELECT t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name
                    FROM threads t
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN forums f ON t.forum_id = f.id
                    WHERE t.deleted_at IS NULL AND t.is_highlight >= 1
                    ORDER BY t.is_highlight DESC, t.created_at DESC
                    LIMIT ? OFFSET ?
                ", [$perPage, $offset]);
            }, 600);
            $totalFeaturedCount = Cache::getStale('threads:featured:count', function() {
                return Database::fetchOne("SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL AND is_highlight >= 1")['c'] ?? 0;
            }, 600);
            $totalFeaturedPages = max(1, (int)ceil($totalFeaturedCount / $perPage));
        } catch (\Throwable $e) {
            error_log('[Index] featured - ' . $e->getMessage());
        }

        // 签到状态（合并为一次查询）
        $checkedIn = false;
        $checkinCredits = 0;
        $checkinDays = 0;
        if ($this->isLoggedIn()) {
            $today = date('Y-m-d');
            $uid = $this->getCurrentUserId();
            $checkinRow = Cache::get("checkin:{$uid}:{$today}", function() use ($uid, $today) {
                return Database::fetchOne(
                    "SELECT id, credits, consecutive_days FROM user_checkins WHERE user_id = ? AND checkin_date = ? LIMIT 1",
                    [$uid, $today]
                );
            }, 300);
            if ($checkinRow && is_array($checkinRow)) {
                $checkedIn = true;
                $checkinCredits = (int)($checkinRow['credits'] ?? 0);
                $checkinDays = (int)($checkinRow['consecutive_days'] ?? 0);
            } elseif ($checkinRow) {
                $checkedIn = true;
            }
        }

        // 公告
        $announcements = [];
        try {
            $now = time();
            $announcements = Cache::getStale('announcements:active', function() use ($now) {
                return Database::fetchAll("
                    SELECT id, title, content, url, type
                    FROM announcements
                    WHERE is_enabled = 1
                      AND (start_at IS NULL OR start_at <= ?)
                      AND (end_at IS NULL OR end_at >= ?)
                    ORDER BY `rank` DESC, id DESC
                    LIMIT 10
                ", [$now, $now]);
            }, 300);
        } catch (\Throwable $e) {
            error_log('[Index] announcements - ' . $e->getMessage());
        }

        $responseTime = round((microtime(true) - $startTime) * 1000, 2);

        // 页面缓存：匿名用户缓存 180 秒
        PageCache::start(180);

        $this->render('index', [
            'forums' => $forums,
            'latestThreads' => $latestThreads,
            'hotThreads' => $hotThreads,
            'stats' => $stats,
            'todayStats' => $todayStats,
            'popularTags' => $popularTags,
            'featuredThreads' => $featuredThreads,
            'checkedIn' => $checkedIn,
            'checkinCredits' => $checkinCredits,
            'checkinDays' => $checkinDays,
            'announcements' => $announcements,
            'responseTime' => $responseTime,
            'page' => $page,
            'totalThreadPages' => $totalThreadPages,
            'totalHotPages' => $totalHotPages,
            'totalFeaturedPages' => $totalFeaturedPages,
            'onlineUsers' => \App\Services\OnlineSvc::getOnlineUsers(10),
            'onlineSummary' => \App\Services\OnlineSvc::getSummary(),
        ]);

        PageCache::end();
    }

    /**
     * 板块分类页
     */
    public function forums(): void
    {
        $forums = [];
        try {
            $forums = Cache::getStale('forums:list', function() {
                return Database::fetchAll("
                    SELECT * FROM forums
                    WHERE parent_id = 0 AND deleted_at IS NULL
                    ORDER BY `rank` DESC
                ");
            }, 3600) ?? [];
        } catch (\Throwable $e) {
            error_log('[Index] forums:list - ' . $e->getMessage());
        }

        // 批量获取所有子板块，避免 N+1
        $allChildren = [];
        try {
            $allChildren = Cache::getStale('forums:children:all', function() {
                return Database::fetchAll("
                    SELECT * FROM forums
                    WHERE parent_id > 0 AND deleted_at IS NULL
                    ORDER BY `rank` DESC
                ");
            }, 3600) ?? [];
        } catch (\Throwable $e) {
            error_log('[Index] forums:children - ' . $e->getMessage());
        }
        $childrenMap = [];
        foreach ($allChildren as $child) {
            $childrenMap[$child['parent_id']][] = $child;
        }

        foreach ($forums as &$forum) {
            $forum['children'] = $childrenMap[$forum['id']] ?? [];
        }
        unset($forum);

        $this->render('thread/forums', [
            'forums' => $forums,
        ]);
    }
    public function allThreads(): void
    {
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $sort = in_array($_GET['sort'] ?? '', ['latest', 'hot', 'highlight'], true) ? $_GET['sort'] : 'latest';
        $perPage = max(5, min(100, \App\Services\SettingSvc::getInt('threads_per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $orderBy = match ($sort) {
            'hot' => 't.reply_count DESC, t.views DESC',
            'highlight' => 't.is_highlight DESC, t.created_at DESC',
            default => 't.created_at DESC',
        };

        $whereExtra = '';
        if ($sort === 'highlight') {
            $whereExtra = ' AND t.is_highlight = 1';
        }

        $cacheKey = "allthreads:{$sort}:p{$page}";
        $threads = Cache::getStale($cacheKey, function() use ($perPage, $offset, $orderBy, $whereExtra) {
            return Database::fetchAll("
                SELECT t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name
                FROM threads t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN forums f ON t.forum_id = f.id
                WHERE t.deleted_at IS NULL{$whereExtra}
                ORDER BY t.is_top DESC, {$orderBy}
                LIMIT ? OFFSET ?
            ", [$perPage, $offset]);
        }, 60);

        // 第一页合并全局置顶帖
        if ($page === 1 && $sort !== 'highlight') {
            $globalTops = Cache::getStale('threads:global_tops', function() {
                return Database::fetchAll("
                    SELECT t.*, u.username, u.nickname, u.avatar, u.credits, u.nickname_color, f.name as forum_name
                    FROM threads t
                    LEFT JOIN users u ON t.user_id = u.id
                    LEFT JOIN forums f ON t.forum_id = f.id
                    WHERE t.is_top = 2 AND t.deleted_at IS NULL
                    ORDER BY t.updated_at DESC
                ");
            }, 120);
            if (!empty($globalTops)) {
                $existingIds = array_column($threads, 'id');
                $merged = [];
                foreach ($globalTops as $gt) {
                    if (!in_array($gt['id'], $existingIds)) {
                        $merged[] = $gt;
                    }
                }
                $threads = array_merge($merged, $threads);
            }
        }

        $countWhere = $sort === 'highlight' ? ' AND is_highlight = 1' : '';
        $total = Cache::getStale("allthreads:count:{$sort}", function() use ($countWhere) {
            return Database::fetchOne("SELECT COUNT(*) as c FROM threads WHERE deleted_at IS NULL{$countWhere}")['c'] ?? 0;
        }, 60);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // 批量加载帖子标签（消除 N+1）
        $threadIds = array_column($threads, 'id');
        $tagsMap = \App\Services\TagSvc::batchLoadTags($threadIds);
        foreach ($threads as &$t) {
            $t['tags'] = $tagsMap[$t['id']] ?? [];
        }
        unset($t);

        $this->render('thread/forum', [
            'forum' => ['id' => 0, 'name' => '全部帖子', 'description' => '浏览全站所有帖子'],
            'threads' => $threads,
            'page' => $page,
            'totalPages' => $totalPages,
            'sort' => $sort,
        ]);
    }
}
