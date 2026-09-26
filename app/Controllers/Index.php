<?php
/**
 * 首页控制器
 */

namespace App\Controllers;

use App\Models\Announcement;
use App\Models\Checkin;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\Thread;
use App\Services\RuntimeSvc;
use App\Services\SettingSvc;
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
            "threads:latest:p{$page}",
            'threads:total_count',
            "threads:hot:p{$page}",
            'runtime:stats',
            'runtime:today',
            'tags:popular',
            "threads:featured:p{$page}",
            'threads:featured:count',
            // 与 Announcement::activeCached(10) 同一个 key（key 带 limit，别再手写字符串）
            Announcement::activeCacheKey(10),
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

        // 获取板块列表（顶级 + 子板块，两级都带缓存，分组逻辑统一在 Forum 模型里）
        $forums = Forum::topWithChildrenCached();

        // 批量获取各板块的最新帖子（避免 N+1）
        $forumIds = array_column($forums, 'id');
        $latestMap = [];
        if (!empty($forumIds)) {
            // 这个 key 带板块集合指纹（原来的固定 key 会让不同板块集合互相返回错数据），
            // 只能等拿到 forumIds 之后再预热
            Cache::preload([Forum::latestThreadsCacheKey($forumIds)]);
            try {
                foreach (Forum::latestThreadsCached($forumIds) as $lt) {
                    $latestMap[$lt['forum_id']] = $lt;
                }
            } catch (\Throwable $e) {
                error_log('[Index] forums:latest_threads - ' . $e->getMessage());
            }
        }
        foreach ($forums as &$forum) {
            $forum['latest_thread'] = $latestMap[$forum['id']] ?? null;
        }
        unset($forum);

        // 获取最新帖子（分页，短期缓存）
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = max(5, min(100, SettingSvc::getInt('threads_per_page', 20)));
        $offset = ($page - 1) * $perPage;
        $latestThreads = [];
        $totalThreadPages = 1;
        try {
            $latestThreads = Thread::latestPageCached($page, $perPage, $offset);
            $totalThreadPages = max(1, (int)ceil(Thread::activeCountCached() / $perPage));
        } catch (\Throwable $e) {
            error_log('[Index] threads:latest - ' . $e->getMessage());
        }

        // 热门帖子（按回复数排序，受后台每页主题数设置控制）
        $hotThreads = [];
        $totalHotPages = 1;
        try {
            $hotThreads = Thread::hotPageCached($page, $perPage, $offset);
            // 复用 threads:total_count，避免重复 COUNT 查询
            $totalHotPages = $totalThreadPages;
        } catch (\Throwable $e) {
            error_log('[Index] threads:hot - ' . $e->getMessage());
        }

        // 获取统计信息（使用 RuntimeSvc 缓存，避免每次 COUNT）
        $stats = ['threads' => 0, 'posts' => 0, 'users' => 0];
        $todayStats = ['threads' => 0, 'posts' => 0, 'users' => 0];
        try {
            $stats = RuntimeSvc::getStats();
            $todayStats = RuntimeSvc::getTodayStats();
        } catch (\Throwable $e) {
            error_log('[Index] stats - ' . $e->getMessage());
        }

        // 热门标签
        $popularTags = [];
        try {
            $popularTags = Tag::getPopularTags(15);
        } catch (\Throwable $e) {
            error_log('[Index] tags - ' . $e->getMessage());
        }

        // 推荐帖子（精华帖，受后台每页主题数设置控制）
        $featuredThreads = [];
        $totalFeaturedPages = 1;
        try {
            $featuredThreads = Thread::featuredPageCached($page, $perPage, $offset);
            $totalFeaturedPages = max(1, (int)ceil(Thread::featuredCountCached() / $perPage));
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
            $checkinRow = Checkin::todayCached($uid, $today);
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
            $announcements = Announcement::activeCached(10);
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
        ]);

        PageCache::end();
    }

    /**
     * 板块分类页
     */
    public function forums(): void
    {
        $this->render('thread/forums', [
            'forums' => Forum::topWithChildrenCached(),
        ]);
    }
    public function allThreads(): void
    {
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $sort = in_array($_GET['sort'] ?? '', ['latest', 'hot', 'highlight'], true) ? $_GET['sort'] : 'latest';
        $perPage = max(5, min(100, SettingSvc::getInt('threads_per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $threads = Thread::allThreadsCached($sort, $page, $perPage, $offset);

        // 第一页合并全局置顶帖
        if ($page === 1 && $sort !== 'highlight') {
            $globalTops = Thread::globalTopsCached();
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

        $total = Thread::allThreadsCountCached($sort);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // 批量加载帖子标签（消除 N+1）
        $threadIds = array_column($threads, 'id');
        $tagsMap = Tag::batchLoadTags($threadIds);
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
