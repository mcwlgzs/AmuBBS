<?php
/**
 * 帖子业务逻辑层
 */

namespace App\Services;

use App\Models\Thread;
use App\Models\Post;
use App\Models\PostEditLog;
use App\Models\PostLike;
use App\Models\Forum;
use App\Models\User;
use App\Models\Notification;
use App\Events\Events;
use Core\Cache;
use Core\Database;
use Core\Event;

class ThreadSvc
{
    private ForumSvc $forumService;
    private UserSvc $userService;

    /** 请求级实体缓存（带LRU淘汰） */
    private static array $entityCache = [];
    private const ENTITY_CACHE_MAX = 100; // 最多缓存100个帖子详情

    public function __construct()
    {
        $this->forumService = new ForumSvc();
        $this->userService = new UserSvc();
    }

    /**
     * 缓存的最大页数
     */
    private const CACHE_LIST_PAGES = 5;

    /**
     * 首页/全部帖子页列表缓存的最大页数（与 clearLatestCache() 的 50 页保持一致）
     */
    private const CACHE_THREAD_LIST_PAGES = 50;

    /**
     * 清除板块帖子列表缓存
     */
    public static function clearForumCache(int $forumId): void
    {
        foreach (['lastpost', 'tid', 'replies', 'views'] as $order) {
            foreach (['', 'highlight', 'top'] as $filter) {
                for ($p = 1; $p <= self::CACHE_LIST_PAGES; $p++) {
                    Cache::delete("forum:threads:{$forumId}:{$order}:{$filter}:p{$p}");
                }
            }
        }
    }

    /**
     * 清除帖子回复列表缓存
     * 修复：使用通配符清除所有页面的缓存，避免深分页缓存不一致
     */
    public static function clearPostCache(int $threadId): void
    {
        // 具体清哪些键由模型负责（回复增删改都该走同一套），这里只保留旧名字给历史调用方
        Post::forgetCaches($threadId);
    }

    /**
     * 获取板块帖子列表（分页，前 N 页带缓存）
     * 深分页优化：当页码超过总页数一半时，反向查询减少 OFFSET 开销
     */
    public function getThreadsByForum(int $forumId, int $page = 1, int $perPage = 20, string $orderBy = 'lastpost', string $filter = ''): array
    {
        $cacheKey = "forum:threads:{$forumId}:{$orderBy}:{$filter}:p{$page}";
        $useCache = $page <= self::CACHE_LIST_PAGES;

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 无筛选时优先使用 forums.thread_count 避免 COUNT 查询
        if ($filter === '') {
            $forum = $this->forumService->getForum($forumId);
            $total = $forum ? (int)($forum['thread_count'] ?? 0) : 0;
        } else {
            $total = Thread::countByForum($forumId, $filter);
        }
        $totalPages = max(1, (int) ceil($total / $perPage));

        // 深分页优化：页码超过100且超过总页数一半时，反向查询
        $reversed = false;
        $actualPage = $page;
        if ($page > 100) {
            $halfPage = (int) ceil($totalPages / 2);
            if ($page > $halfPage) {
                $actualPage = max(1, $totalPages - $page + 1);
                $reversed = true;
            }
        }

        $offset = ($actualPage - 1) * $perPage;
        $threads = Thread::getByForum($forumId, $perPage, $offset, $orderBy, $reversed, $filter);

        // 反向查询后翻转结果，恢复正常展示顺序
        if ($reversed) {
            $threads = array_reverse($threads);
        }

        // 第一页合并全局置顶帖（排除当前板块已有的，带缓存）
        if ($page === 1) {
            $globalTops = Cache::get('threads:global_tops_forum', function() {
                return Thread::getGlobalTopThreads();
            }, 120);
            $existingIds = array_column($threads, 'id');
            $merged = [];
            foreach ($globalTops as $gt) {
                if (!in_array($gt['id'], $existingIds, true) && (int)$gt['forum_id'] !== $forumId) {
                    $merged[] = $gt;
                }
            }
            $threads = array_merge($merged, $threads);
        }

        $result = [
            'threads' => $threads,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ];

        if ($useCache) {
            Cache::set($cacheKey, $result, 120); // 缓存2分钟
        }

        return $result;
    }

    /**
     * 获取帖子详情（Redis + 请求级双层缓存，带LRU淘汰）
     */
    public function getThreadDetail(int $threadId): ?array
    {
        if (isset(self::$entityCache[$threadId])) {
            return self::$entityCache[$threadId];
        }

        $detail = Cache::get("thread:{$threadId}", function() use ($threadId) {
            return Thread::getDetail($threadId);
        }, 300);

        if ($detail) {
            // LRU淘汰：超过上限时删除最早的一半
            if (count(self::$entityCache) >= self::ENTITY_CACHE_MAX) {
                $half = (int)(self::ENTITY_CACHE_MAX / 2);
                self::$entityCache = array_slice(self::$entityCache, -$half, $half, true);
            }
            self::$entityCache[$threadId] = $detail;
        }
        return $detail;
    }

    /**
     * 清除帖子请求级缓存
     */
    public static function clearEntityCache(int $threadId = 0): void
    {
        if ($threadId > 0) {
            unset(self::$entityCache[$threadId]);
        } else {
            self::$entityCache = [];
        }
    }

    /**
     * 获取帖子回复列表（分页）
     */
    public function getPosts(int $threadId, int $page = 1, int $perPage = 20, string $order = 'asc', int $authorOnly = 0): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $posts = Post::getByThread($threadId, $perPage, $offset, $order, $authorOnly);
        $total = Post::countByThread($threadId, $authorOnly);
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * 增加浏览量（缓存驱动无关的原子操作）
     *
     * 计数策略：请求内只做原子累加，跨过 10 的整数倍时落库 10 条，
     * 剩余零头交给 cron 的 flushPendingViews() 用 `pending % 10` 补齐。
     */
    public function incrementViews(int $threadId): void
    {
        $cacheKey = "views:pending:{$threadId}";

        // 驱动无关的原子递增：Redis 走 INCR，文件驱动走 flock
        // TTL 由 300s 提到 3600s：原来低流量帖子在 300s 内不足 10 次浏览时，
        // 计数会随 key 过期整批消失，现在给 cron 留出足够长的窗口。
        try {
            $count = Cache::increment($cacheKey, 1, 3600);

            // 只在「刚凑齐一组 10」时落库。
            // 原来的写法是 `if ($count >= 10) { 写 $count; delete; }`：
            // 并发下第 10、11 个请求都会看到 >=10 并各自落库，11 次浏览可能写成 +21。
            // 现在每个请求只写自己刚凑齐的那 10 条，且不删除计数器（零头由 cron 补）。
            if ($count > 0 && $count % 10 === 0) {
                Thread::incrementViews($threadId, 10);
            }

            // 登记「有待写浏览量」的帖子 ID，让 cron 也能覆盖那些最近没有回帖、
            // 不在 idsUpdatedSince() 结果里的老帖（只在计数器刚创建时登记一次）
            if ($count === 1) {
                $this->markPendingViews($threadId);
            }

            return;
        } catch (\Throwable $e) {
            error_log('[ThreadSvc] 浏览量累加失败，降级直写数据库: ' . $e->getMessage());
        }

        // 降级：直接写数据库
        Thread::incrementViews($threadId, 1);
    }

    /** 待写浏览量的帖子 ID 清单（cron 用它覆盖老帖，避免只依赖 idsUpdatedSince） */
    public const PENDING_VIEW_IDS_KEY = 'views:pending_ids';

    private function markPendingViews(int $threadId): void
    {
        $ids = Cache::get(self::PENDING_VIEW_IDS_KEY);
        $ids = is_array($ids) ? $ids : [];

        if (in_array($threadId, $ids, true)) {
            return;
        }

        // 有界队列：只保留最近 1000 个待写帖子
        if (count($ids) >= 1000) {
            array_shift($ids);
        }

        $ids[] = $threadId;
        Cache::set(self::PENDING_VIEW_IDS_KEY, $ids, 86400);
    }

    /**
     * 获取最新帖子
     */
    public function getLatestThreads(int $limit = 10): array
    {
        return Cache::get("threads:latest:{$limit}", function () use ($limit) {
            return Thread::getLatest($limit);
        }, 300);
    }

    /**
     * 清除最新帖子缓存（替代 deletePattern SCAN）
     */
    public static function clearLatestCache(): void
    {
        $keys = ['threads:latest:10', 'threads:total_count'];
        for ($i = 1; $i <= 50; $i++) {
            $keys[] = "threads:latest:p{$i}";
        }
        Cache::deleteMulti($keys);
    }

    /**
     * 清理首页 / 全部帖子页的三大列表缓存
     *
     * 这些 key 全是确定性的（threads:latest|hot|featured:pN、allthreads:{sort}:pN 与总数），
     * 以前这里用 deletePattern('threads:*') + deletePattern('allthreads:*')：
     * 文件驱动下 deletePattern 要递归扫整个缓存目录并逐个文件读 key 再正则匹配，
     * 代价随缓存文件数线性增长（1 万文件 ≈ 2 万次 syscall），而置顶是常用管理动作。
     * 现在点名删除；超过 CACHE_THREAD_LIST_PAGES 的深分页交给 SWR 的 TTL 自然过期。
     */
    public static function clearThreadListCache(): void
    {
        $keys = [
            'threads:latest:10',
            'threads:total_count',
            'threads:featured:count',
            'threads:global_tops',
            'threads:global_tops_forum',
            'threads:hot_weekly',
        ];

        for ($i = 1; $i <= self::CACHE_THREAD_LIST_PAGES; $i++) {
            $keys[] = "threads:latest:p{$i}";
            $keys[] = "threads:hot:p{$i}";
            $keys[] = "threads:featured:p{$i}";
        }

        foreach (['latest', 'hot', 'highlight'] as $sort) {
            $keys[] = "allthreads:count:{$sort}";
            for ($i = 1; $i <= self::CACHE_THREAD_LIST_PAGES; $i++) {
                $keys[] = "allthreads:{$sort}:p{$i}";
            }
        }

        Cache::deleteMulti($keys);
    }

    /**
     * 发帖
     *
     * @throws \RuntimeException 防灌水或业务异常
     */
    public function createThread(int $forumId, int $userId, string $username, string $title, string $content): int
    {
        // 防灌水检查（30秒内不能重复发帖）
        $this->checkFloodControl($userId, 'thread');

        // IP 频率限制
        IpAccessSvc::checkAndIncrement($_SERVER['REMOTE_ADDR'] ?? '', 'thread');

        // 敏感词过滤
        $titleFilter = SensitiveWordService::filter($title);
        if ($titleFilter['blocked']) {
            throw new \RuntimeException('标题包含违禁词，禁止发布');
        }
        $title = $titleFilter['text'];

        $contentFilter = SensitiveWordService::filter($content);
        if ($contentFilter['blocked']) {
            throw new \RuntimeException('内容包含违禁词，禁止发布');
        }
        $content = $contentFilter['text'];

        // 插件过滤器：允许插件改写标题 / 内容（加前缀、二次审核、免责声明等）
        $title   = apply_filters('thread.title', $title, $forumId);
        $content = apply_filters('post.content', $content, $forumId);

        // 验证板块存在
        $forum = $this->forumService->getForum($forumId);
        if (!$forum) {
            throw new \RuntimeException('板块不存在');
        }

        Database::beginTransaction();

        try {
            // 创建帖子
            $threadId = Thread::create($forumId, $userId, $username, $title, $content);

            // 更新板块和用户统计
            $this->forumService->onThreadCreated($forumId, $threadId);
            $this->userService->incrementThreadCount($userId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 操作成功后设置防灌水标记
        $this->markFloodControl($userId, 'thread');

        // 以下操作在事务外执行，避免嵌套事务和 rollBack 已提交事务

        // 发帖积分奖励
        (new CreditSvc())->rewardForThread($userId, $threadId);

        // 清除缓存
        self::clearLatestCache();
        self::clearForumCache($forumId);

        // 更新运行时统计
        RuntimeSvc::increment('threads');

        // 自动用户组升级检查
        LevelSvc::autoUpgradeGroup($userId);

        // 解析 @提醒
        $this->parseAtMentions($content, $userId, $username, $threadId);

        // 触发事件
        Event::dispatch(Events::THREAD_CREATED, [
            'thread_id' => $threadId,
            'forum_id' => $forumId,
            'user_id' => $userId,
            'username' => $username,
        ]);

        return $threadId;
    }

    /**
     * 回复帖子
     *
     * @throws \RuntimeException 防灌水或业务异常
     */
    public function createReply(int $threadId, int $userId, string $username, string $content, int $quotePostId = 0): int
    {
        // 防灌水检查
        $this->checkFloodControl($userId, 'post');

        // IP 频率限制
        IpAccessSvc::checkAndIncrement($_SERVER['REMOTE_ADDR'] ?? '', 'post');

        // 敏感词过滤
        $contentFilter = SensitiveWordService::filter($content);
        if ($contentFilter['blocked']) {
            throw new \RuntimeException('评论内容包含违禁词，禁止发布');
        }
        $content = $contentFilter['text'];

        // 验证帖子存在
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // 锁定检查
        if (!empty($thread['is_locked'])) {
            throw new \RuntimeException('帖子已锁定，无法评论');
        }

        Database::beginTransaction();

        try {
            // 创建回复
            $postId = Post::create($threadId, $userId, $username, $content, $quotePostId);

            // 更新帖子、板块、用户统计
            Thread::updateLastPost($threadId, $userId);
            $this->forumService->onPostCreated($thread['forum_id']);
            $this->userService->incrementPostCount($userId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 操作成功后设置防灌水标记
        $this->markFloodControl($userId, 'post');

        // 以下操作在事务外执行，避免嵌套事务

        // 通知帖子作者（不通知自己）
        if ((int)$thread['user_id'] !== $userId) {
            Notification::notify(
                (int)$thread['user_id'],
                $userId,
                'reply',
                $username . ' 评论了你的帖子',
                mb_substr($content, 0, 100),
                'thread',
                $threadId
            );
        }

        // 解析 @提醒
        $this->parseAtMentions($content, $userId, $username, $threadId);

        // 回复积分奖励
        (new CreditSvc())->rewardForPost($userId, $postId);

        // 清除缓存
        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);
        self::clearPostCache($threadId);
        self::clearLatestCache();
        self::clearForumCache((int)$thread['forum_id']);

        // 清除回复可见隐藏内容缓存，使用户回复后立即可见
        $replyHideSql = "SELECT id FROM posts WHERE thread_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1";
        Cache::delete('dbq1:' . md5($replyHideSql . serialize([$threadId, $userId])));

        // 更新运行时统计
        RuntimeSvc::increment('posts');

        // 自动用户组升级检查
        LevelSvc::autoUpgradeGroup($userId);

        // 触发事件
        Event::dispatch(Events::POST_CREATED, [
            'post_id' => $postId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'username' => $username,
        ]);

        return $postId;
    }

    /**
     * 解析内容中的 @username 并发送通知
     */
    private function parseAtMentions(string $content, int $fromUserId, string $fromUsername, int $threadId): void
    {
        if (preg_match_all('/@([\w\x{4e00}-\x{9fff}]+)/u', $content, $matches)) {
            $mentioned = array_unique($matches[1]);
            if (empty($mentioned)) return;

            // 批量查询所有被 @ 的用户，避免 N+1
            $users = User::idsByUsernames(array_values($mentioned));

            foreach ($users as $user) {
                if ((int)$user['id'] !== $fromUserId) {
                    Notification::notify(
                        (int)$user['id'],
                        $fromUserId,
                        'mention',
                        $fromUsername . ' 在评论中提到了你',
                        mb_substr($content, 0, 100),
                        'thread',
                        $threadId
                    );
                }
            }
        }
    }

    /**
     * 防灌水检查（30秒间隔），使用原子操作避免竞态条件
     */
    private function checkFloodControl(int $userId, string $type): void
    {
        $key = "flood:{$type}:{$userId}";
        // 驱动无关的原子 SET NX：Redis 走 SET NX EX，文件驱动走 'x' 创建模式
        // 抢到标记 = 放行；抢不到说明仍在 30 秒防灌水窗口内
        if (!Cache::add($key, time(), 30)) {
            throw new \RuntimeException('操作过于频繁，请稍后再试');
        }
    }

    /**
     * 设置防灌水标记（操作成功后调用）
     * 注意：使用新的 checkFloodControl 后，此方法已不再需要单独调用
     * 保留此方法以保持向后兼容
     */
    private function markFloodControl(int $userId, string $type): void
    {
        // 新的 checkFloodControl 已经设置了标记，这里不需要再设置
        // 但为了向后兼容，保留此方法
    }

    /**
     * 编辑帖子
     *
     * @throws \RuntimeException 权限或业务异常
     */
    public function updateThread(int $threadId, int $userId, int $groupId, string $title, string $content): void
    {
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // 只有作者或版主/管理员可以编辑（服务端实时校验权限）
        if ((int)$thread['user_id'] !== $userId && !PermissionSvc::canModerate($userId, 'update', (int)$thread['forum_id'])) {
            throw new \RuntimeException('没有编辑权限');
        }

        // 敏感词过滤
        $titleFilter = SensitiveWordService::filter($title);
        if ($titleFilter['blocked']) {
            throw new \RuntimeException('标题包含违禁词，禁止发布');
        }
        $title = $titleFilter['text'];

        $contentFilter = SensitiveWordService::filter($content);
        if ($contentFilter['blocked']) {
            throw new \RuntimeException('内容包含违禁词，禁止发布');
        }
        $content = $contentFilter['text'];

        // 记录编辑历史
        PostEditLog::log($threadId, 0, $userId, $thread['content'], $content);

        Thread::update($threadId, $title, $content);
        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);
        self::clearLatestCache();
        self::clearForumCache((int)$thread['forum_id']);
        self::clearEntityCache($threadId);
    }

    /**
     * 删除帖子（软删除）
     *
     * @throws \RuntimeException 权限或业务异常
     */
    public function deleteThread(int $threadId, int $userId, int $groupId): void
    {
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // 只有作者或版主/管理员可以删除（服务端实时校验权限）
        if ((int)$thread['user_id'] !== $userId && !PermissionSvc::canModerate($userId, 'delete', (int)$thread['forum_id'])) {
            throw new \RuntimeException('没有删除权限');
        }

        $forumId = (int)$thread['forum_id'];
        $authorId = (int)$thread['user_id'];
        $replyCount = (int)($thread['reply_count'] ?? 0);

        Database::beginTransaction();

        try {
            // 使用 FOR UPDATE 锁定帖子记录，防止并发删除导致的死锁
            if (!Thread::lockForDelete($threadId)) {
                throw new \RuntimeException('帖子不存在或已被删除');
            }

            Thread::softDelete($threadId);

            // 级联软删除子回复，并扣减每个回复作者的 post_count
            // 使用 FOR UPDATE 锁定回复记录，防止并发修改
            $replyAuthors = Post::lockAuthorIdsInThread($threadId);
            if (!empty($replyAuthors)) {
                Post::softDeleteByThread($threadId, time());
                $authorCounts = [];
                foreach ($replyAuthors as $aid) {
                    $authorCounts[$aid] = ($authorCounts[$aid] ?? 0) + 1;
                }
                // 批量锁定用户记录，按ID排序避免死锁
                $userIds = array_keys($authorCounts);
                sort($userIds);
                foreach ($userIds as $uid) {
                    User::adjustPostCount($uid, -$authorCounts[$uid]);
                }
            }

            // 级联更新统计（按固定顺序锁定，避免死锁）
            $this->forumService->onThreadDeleted($forumId);
            $this->userService->decrementThreadCount($authorId);

            if ($replyCount > 0) {
                $this->forumService->onPostsDeleted($forumId, $replyCount);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        RuntimeSvc::decrement('threads');
        if ($replyCount > 0) {
            RuntimeSvc::decrement('posts', $replyCount);
        }

        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);
        self::clearLatestCache();
        self::clearForumCache($forumId);
        self::clearEntityCache($threadId);

        if ($authorId !== $userId) {
            LogService::log('mod_delete_thread', 'thread', $threadId, [
                'title' => $thread['title'],
                'author' => $thread['username'] ?? '',
                'forum_id' => $forumId,
            ], $userId);
        }
    }

    /**
     * 设置置顶级别（0=普通 1=板块置顶 2=全局置顶）
     *
     * @throws \RuntimeException 权限异常
     */
    public function setTopLevel(int $threadId, int $level, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'top')) {
            throw new \RuntimeException('没有置顶权限');
        }

        // 全局置顶仅管理员可设
        $realGroupId = PermissionSvc::getUserGroupId($userId) ?? 1;
        if ($level >= 2 && $realGroupId < 3) {
            throw new \RuntimeException('全局置顶仅管理员可操作');
        }

        $level = max(0, min(2, $level));

        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        $oldLevel = (int)($thread['is_top'] ?? 0);
        Thread::setTopLevel($threadId, $level);
        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);

        $labels = [0 => '普通', 1 => '板块置顶', 2 => '全局置顶'];
        LogService::log('mod_top', 'thread', $threadId, [
            'title' => $thread['title'],
            'from' => $labels[$oldLevel] ?? $oldLevel,
            'to' => $labels[$level] ?? $level,
        ], $userId);

        return $level;
    }

    /**
     * 点赞/取消点赞
     */
    public function toggleLike(int $threadId, int $userId): array
    {
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // post_likes 的增删与 threads.likes 计数由模型一起负责（含缓存失效）
        $liked = PostLike::toggle($userId, $threadId);

        // 积分变动放在事务外，通过 CreditSvc 记录日志
        if ((int)$thread['user_id'] !== $userId) {
            $creditSvc = new CreditSvc();
            if ($liked) {
                $creditSvc->rewardForLike((int)$thread['user_id'], 'thread', $threadId);
            } else {
                $creditSvc->deductCredits((int)$thread['user_id'], 1, 'unlike', '点赞被取消', 'thread', $threadId);
            }
        }

        $updatedLikes = Thread::getLikes($threadId);

        return [
            'liked' => $liked,
            'likes' => $updatedLikes,
        ];
    }

    /**
     * 设置精华级别（0=取消, 1/2/3=精华级别）
     *
     * @throws \RuntimeException 权限异常
     */
    public function setDigest(int $threadId, int $level, int $groupId, int $userId = 0): int
    {
        if (!PermissionSvc::canModerate($userId, 'digest')) {
            throw new \RuntimeException('没有加精权限');
        }

        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        $level = max(0, min(3, $level));
        Thread::setDigest($threadId, $level);
        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);
        self::clearForumCache((int)$thread['forum_id']);

        if ($userId > 0) {
            $action = $level > 0 ? "mod_digest_{$level}" : 'mod_undigest';
            LogService::log($action, 'thread', $threadId, [
                'title' => $thread['title'],
                'old_level' => $thread['is_highlight'] ?? 0,
                'new_level' => $level,
            ], $userId);
        }

        return $level;
    }

    /**
     * 锁定/解锁帖子
     */
    public function toggleLock(int $threadId, int $userId): bool
    {
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // 权限检查：管理员或版主
        if (!PermissionSvc::canModerate($userId, 'update', (int)$thread['forum_id'])) {
            throw new \RuntimeException('没有锁定权限');
        }

        $newState = !($thread['is_locked'] ?? false);
        // 写库与缓存失效都交给模型，避免这里漏掉行缓存（会导致短时间内解锁无效）
        Thread::setLocked($threadId, $newState);
        Cache::delete("thread:{$threadId}");

        // 记录版主操作日志
        LogService::log(
            $newState ? 'mod_lock' : 'mod_unlock',
            'thread',
            $threadId,
            ['title' => $thread['title'], 'before' => !$newState, 'after' => $newState],
            $userId
        );

        // 插件钩子：版主锁定/解锁（只有管理员或版主能走到这里）
        Event::dispatch(Events::MOD_THREAD_LOCKED, [
            'thread_id' => $threadId,
            'forum_id'  => (int)$thread['forum_id'],
            'is_locked' => $newState,
            'user_id'   => $userId,
        ]);

        return $newState;
    }

    /**
     * 移动帖子到其他板块
     */
    public function moveThread(int $threadId, int $targetForumId, int $userId): void
    {
        $thread = Thread::findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        $sourceForum = (int)$thread['forum_id'];
        if ($sourceForum === $targetForumId) {
            throw new \RuntimeException('目标板块与当前板块相同');
        }

        // 权限检查
        if (!PermissionSvc::canModerate($userId, 'move', $sourceForum)) {
            throw new \RuntimeException('没有移动权限');
        }

        // 验证目标板块
        $target = $this->forumService->getForum($targetForumId);
        if (!$target) {
            throw new \RuntimeException('目标板块不存在');
        }

        Database::beginTransaction();
        try {
            Thread::setForumBulk([$threadId], $targetForumId);
            Forum::adjustThreadCount($sourceForum, -1);
            Forum::adjustThreadCount($targetForumId, 1);

            // 同步 post_count：该帖下的回复数也要从源板块移到目标板块
            $postCount = Post::countByThread($threadId);
            if ($postCount > 0) {
                Forum::adjustPostCount($sourceForum, -$postCount);
                Forum::adjustPostCount($targetForumId, $postCount);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Cache::delete("thread:{$threadId}");
        \App\Models\Thread::forgetRowCachesFor([$threadId]);
        Cache::delete("forum:{$sourceForum}");
        Cache::delete("forum:{$targetForumId}");
        Cache::delete('forums:list');
        self::clearForumCache($sourceForum);
        self::clearForumCache($targetForumId);

        LogService::log('mod_move', 'thread', $threadId, [
            'title' => $thread['title'],
            'from_forum' => $sourceForum,
            'to_forum' => $targetForumId,
        ], $userId);

        // 插件钩子：帖子被移动到别的板块
        Event::dispatch(Events::MOD_THREAD_MOVED, [
            'thread_id'     => $threadId,
            'from_forum_id' => $sourceForum,
            'to_forum_id'   => $targetForumId,
            'user_id'       => $userId,
        ]);
    }

    /**
     * 编辑回复
     */
    public function updatePost(int $postId, int $userId, string $content): void
    {
        $post = Post::findById($postId);
        if (!$post) {
            throw new \RuntimeException('评论不存在');
        }

        // 权限：作者本人 或 管理员/版主
        $thread = Thread::findById((int)$post['thread_id']);
        $forumId = $thread ? (int)$thread['forum_id'] : 0;

        if ((int)$post['user_id'] !== $userId && !PermissionSvc::canModerate($userId, 'update', $forumId)) {
            throw new \RuntimeException('没有编辑权限');
        }

        // 敏感词过滤
        $contentFilter = SensitiveWordService::filter($content);
        if ($contentFilter['blocked']) {
            throw new \RuntimeException('内容包含违禁词，禁止发布');
        }
        $content = $contentFilter['text'];

        // 记录编辑历史
        PostEditLog::log((int)$post['thread_id'], $postId, $userId, $post['content'] ?? '', $content);

        Post::update($postId, $content);
        Cache::delete("thread:{$post['thread_id']}");
        \App\Models\Thread::forgetRowCachesFor([$post['thread_id']]);
        self::clearPostCache((int)$post['thread_id']);

        // 插件钩子：作者改自己的回复 -> post.updated；版主/管理员改别人的 -> mod.post.edited
        // 两个钩子互斥（不是同时触发两条），插件扣分、发通知不会重复执行。
        $isAuthor = (int)$post['user_id'] === $userId;
        Event::dispatch($isAuthor ? Events::POST_UPDATED : Events::MOD_POST_EDITED, [
            'post_id'   => $postId,
            'thread_id' => (int)$post['thread_id'],
            'user_id'   => $userId,          // 谁执行的编辑
            'author_id' => (int)$post['user_id'],   // 回复作者
        ]);
    }

    /**
     * 删除回复
     */
    public function deletePost(int $postId, int $userId): void
    {
        $post = Post::findById($postId);
        if (!$post) {
            throw new \RuntimeException('评论不存在');
        }

        $threadId = (int)$post['thread_id'];
        $thread = Thread::findById($threadId);
        $forumId = $thread ? (int)$thread['forum_id'] : 0;

        // 权限：作者本人 或 管理员/版主
        if ((int)$post['user_id'] !== $userId && !PermissionSvc::canModerate($userId, 'delete', $forumId)) {
            throw new \RuntimeException('没有删除权限');
        }

        Database::beginTransaction();

        try {
            Post::softDelete($postId);

            if ($forumId > 0) {
                $this->forumService->onPostDeleted($forumId);
            }
            $this->userService->decrementPostCount((int)$post['user_id']);

            // 更新帖子回复数，并重算「最后回复」（删掉的可能正是最新那条）
            Thread::adjustReplyCount($threadId, -1);
            Thread::refreshLastPost($threadId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        RuntimeSvc::decrement('posts');

        self::clearPostCache($threadId);
        self::clearLatestCache();
        if ($forumId > 0) {
            self::clearForumCache($forumId);
        }

        LogService::log('mod_delete_post', 'post', $postId, [
            'thread_id' => $threadId,
            'author' => $post['username'] ?? '',
        ], $userId);

        // 插件钩子：作者删自己的回复 -> post.deleted；版主/管理员删别人的 -> mod.post.deleted
        $isAuthor = (int)$post['user_id'] === $userId;
        Event::dispatch($isAuthor ? Events::POST_DELETED : Events::MOD_POST_DELETED, [
            'post_id'   => $postId,
            'thread_id' => $threadId,
            'user_id'   => $userId,
            'author_id' => (int)$post['user_id'],
        ]);
    }

    /**
     * 批量删除回帖（后台回帖管理用）
     *
     * 与单条删除同一套规则：软删除 → 按作者/主题/板块聚合扣减计数 →
     * 重算受影响主题的「最后回复」→ 清缓存 → 记 mod 日志。
     * 以前后台控制器自己拼了一套，缺了 last_post 重算与回复列表缓存清理，
     * 表现是「删完回帖，列表和最后回复还是旧的」。
     *
     * @param int[] $postIds
     * @return int 实际删除条数
     */
    public function batchDeletePosts(array $postIds, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'delete')) {
            throw new \RuntimeException('没有删除权限');
        }

        $contexts = Post::getDeleteContexts($postIds);
        if (empty($contexts)) {
            return 0;
        }

        $ids = array_column($contexts, 'id');
        $threadDeltas = [];
        $userDeltas = [];
        $forumDeltas = [];
        foreach ($contexts as $c) {
            $threadDeltas[$c['thread_id']] = ($threadDeltas[$c['thread_id']] ?? 0) + 1;
            $userDeltas[$c['user_id']] = ($userDeltas[$c['user_id']] ?? 0) + 1;
            if ($c['forum_id'] > 0) {
                $forumDeltas[$c['forum_id']] = ($forumDeltas[$c['forum_id']] ?? 0) + 1;
            }
        }

        Database::beginTransaction();

        try {
            $count = Post::softDeleteBulk($ids);

            foreach ($threadDeltas as $tid => $delta) {
                Thread::adjustReplyCount($tid, -$delta);
            }
            foreach ($userDeltas as $uid => $delta) {
                User::adjustPostCount($uid, -$delta);
            }
            foreach ($forumDeltas as $fid => $delta) {
                Forum::adjustPostCount($fid, -$delta);
            }

            // 「最后回复」要在事务里重算，避免删除后主题列表短暂显示已删回复
            foreach (array_keys($threadDeltas) as $tid) {
                Thread::refreshLastPost($tid);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        foreach (array_keys($threadDeltas) as $tid) {
            self::clearPostCache($tid);
        }
        self::clearLatestCache();
        foreach (array_keys($forumDeltas) as $fid) {
            self::clearForumCache($fid);
        }

        LogService::log('mod_batch_delete_post', 'post', 0, [
            'post_ids' => $ids, 'count' => $count,
        ], $userId);

        return (int)$count;
    }

    /**
     * 批量置顶
     */
    public function batchTop(array $tids, int $level, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'top')) {
            throw new \RuntimeException('没有置顶权限');
        }
        $realGroupId = PermissionSvc::getUserGroupId($userId) ?? 1;
        if ($level >= 2 && $realGroupId < 3) {
            throw new \RuntimeException('全局置顶仅管理员可操作');
        }
        $count = 0;
        $intTids = array_map('intval', $tids);
        $intTids = array_filter($intTids, fn($t) => $t > 0);
        if (empty($intTids)) return 0;

        // 置顶级别由模型负责写库与行缓存失效，这里只管权限与审计
        $count = Thread::setTopBulk($intTids, $level);
        if ($count === 0) {
            return 0;
        }

        self::clearThreadListCache();
        LogService::log('mod_batch_top', 'thread', 0, [
            'tids' => $tids, 'level' => $level, 'count' => $count,
        ], $userId);
        return $count;
    }

    /**
     * 批量删除（软删除）
     */
    public function batchDelete(array $tids, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'delete')) {
            throw new \RuntimeException('没有删除权限');
        }
        if (empty($tids)) {
            return 0;
        }

        // 批量查出所有待删帖子
        $threads = Thread::rowsForBulkDelete(array_map('intval', $tids));
        if (empty($threads)) {
            return 0;
        }

        $threadIds = array_column($threads, 'id');
        $count = count($threadIds);
        $affectedForums = [];

        Database::beginTransaction();

        try {
            // 1. 批量软删除帖子
            Thread::softDeleteByIds($threadIds, time());

            // 2. 批量查出所有回复作者，然后批量软删除回复
            $replyAuthors = Post::authorIdsInThreads($threadIds);
            if (!empty($replyAuthors)) {
                Post::softDeleteByThreadIds($threadIds, time());
                // 按作者聚合回复数，批量扣减 users.post_count
                $authorCounts = [];
                foreach ($replyAuthors as $aid) {
                    $authorCounts[$aid] = ($authorCounts[$aid] ?? 0) + 1;
                }
                User::adjustPostCountBulk($authorCounts);
            }

            // 3. 按板块聚合 thread_count 和 post_count 扣减量
            $forumThreadDec = [];
            $forumPostDec = [];
            $userThreadDec = [];
            foreach ($threads as $t) {
                $fid = (int)$t['forum_id'];
                $affectedForums[$fid] = true;
                $forumThreadDec[$fid] = ($forumThreadDec[$fid] ?? 0) + 1;
                $rc = (int)($t['reply_count'] ?? 0);
                if ($rc > 0) {
                    $forumPostDec[$fid] = ($forumPostDec[$fid] ?? 0) + $rc;
                }
                $uid = (int)$t['user_id'];
                $userThreadDec[$uid] = ($userThreadDec[$uid] ?? 0) + 1;
            }

            // 批量更新 forums.thread_count 和 forums.post_count
            Forum::decrementCountsBulk($forumThreadDec, $forumPostDec);

            // 4. 批量扣减帖子作者的 users.thread_count
            User::adjustThreadCountBulk($userThreadDec);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 缓存清理放在事务外
        self::clearLatestCache();
        foreach ($threadIds as $tid) {
            Cache::delete("thread:{$tid}");
        \App\Models\Thread::forgetRowCachesFor([$tid]);
        }
        RuntimeSvc::decrement('threads', $count);
        // 扣减全站回复统计
        $totalReplies = 0;
        foreach ($threads as $t) {
            $totalReplies += (int)($t['reply_count'] ?? 0);
        }
        if ($totalReplies > 0) {
            RuntimeSvc::decrement('posts', $totalReplies);
        }
        foreach (array_keys($affectedForums) as $fid) {
            self::clearForumCache($fid);
        }
        // 清理受影响用户的缓存
        foreach (array_keys($userThreadDec) as $uid) {
            Cache::delete("user:profile:{$uid}");
        }
        LogService::log('mod_batch_delete', 'thread', 0, [
            'tids' => $tids, 'count' => $count,
        ], $userId);
        return $count;
    }

    /**
     * 批量移动到指定板块
     */
    public function batchMove(array $tids, int $forumId, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'move')) {
            throw new \RuntimeException('没有移动权限');
        }
        $target = $this->forumService->getForum($forumId);
        if (!$target) {
            throw new \RuntimeException('目标板块不存在');
        }
        $count = 0;
        $affectedForums = [];

        // 批量获取所有帖子信息（1 条 SQL 替代 N 条）
        $threads = Thread::rowsForBulkMove(array_map('intval', $tids), $forumId);
        if (empty($threads)) return 0;

        $moveIds = array_column($threads, 'id');
        $sourceFids = [];
        foreach ($threads as $t) {
            $sourceFids[(int)$t['forum_id']][] = (int)$t['id'];
        }

        Database::beginTransaction();

        try {
            // 批量更新帖子板块（行缓存在模型里失效）
            Thread::setForumBulk($moveIds, $forumId);
            $count = count($moveIds);

            // 按源板块分组更新统计
            foreach ($sourceFids as $srcFid => $srcTids) {
                $srcCount = count($srcTids);
                Forum::adjustThreadCount($srcFid, -$srcCount);

                // 批量统计这些帖子的回复数
                $postSum = Post::countActiveByThreadIds($srcTids);
                if ($postSum > 0) {
                    Forum::adjustPostCount($srcFid, -$postSum);
                    Forum::adjustPostCount($forumId, $postSum);
                }

                $affectedForums[$srcFid] = true;
            }

            // 目标板块增加帖子数
            Forum::adjustThreadCount($forumId, $count);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 缓存清理放在事务外
        Cache::delete('forums:list');
        foreach ($tids as $tid) {
            Cache::delete("thread:{$tid}");
        \App\Models\Thread::forgetRowCachesFor([$tid]);
        }
        $affectedForums[$forumId] = true;
        foreach (array_keys($affectedForums) as $fid) {
            self::clearForumCache($fid);
        }
        LogService::log('mod_batch_move', 'thread', 0, [
            'tids' => $tids, 'to_forum' => $forumId, 'count' => $count,
        ], $userId);
        return $count;
    }

    /**
     * 批量锁定/解锁
     */
    public function batchLock(array $tids, bool $lock, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'lock')) {
            throw new \RuntimeException('没有锁定权限');
        }

        // 只更新确实存在的行，受影响行数即真实成功数（模型内部按 id 失效行缓存）
        $count = Thread::setLockedBulk($tids, $lock);
        if ($count === 0) {
            return 0;
        }

        self::clearLatestCache();
        LogService::log($lock ? 'mod_batch_lock' : 'mod_batch_unlock', 'thread', 0, [
            'tids' => $tids, 'count' => $count,
        ], $userId);

        return $count;
    }

    /**
     * 批量加精 / 取消加精
     */
    public function batchDigest(array $tids, int $level, int $userId, int $groupId): int
    {
        if (!PermissionSvc::canModerate($userId, 'digest')) {
            throw new \RuntimeException('没有加精权限');
        }

        $level = max(0, min(3, $level));
        $count = Thread::setHighlightBulk($tids, $level);
        if ($count === 0) {
            return 0;
        }

        self::clearLatestCache();
        LogService::log($level > 0 ? 'mod_batch_highlight' : 'mod_batch_unhighlight', 'thread', 0, [
            'tids' => $tids, 'level' => $level, 'count' => $count,
        ], $userId);

        return $count;
    }
}
