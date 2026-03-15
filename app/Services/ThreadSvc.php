<?php
/**
 * 帖子业务逻辑层
 */

namespace App\Services;

use App\Repositories\ThreadRepo;
use App\Repositories\PostRepo;
use App\Events\Events;
use Core\Cache;
use Core\Database;
use Core\Event;

class ThreadSvc
{
    private ThreadRepo $threadRepo;
    private PostRepo $postRepo;
    private ForumSvc $forumService;
    private UserSvc $userService;

    /** 请求级实体缓存（带LRU淘汰） */
    private static array $entityCache = [];
    private const ENTITY_CACHE_MAX = 100; // 最多缓存100个帖子详情

    public function __construct()
    {
        $this->threadRepo = new ThreadRepo();
        $this->postRepo = new PostRepo();
        $this->forumService = new ForumSvc();
        $this->userService = new UserSvc();
    }

    /**
     * 缓存的最大页数
     */
    private const CACHE_LIST_PAGES = 5;

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
        // 使用 Redis SCAN 清除所有相关缓存
        $redis = Cache::getRedis();
        if ($redis !== null) {
            try {
                // 清除所有页面的缓存
                $patterns = [
                    "posts:thread:{$threadId}:asc:*",
                    "posts:thread:{$threadId}:desc:*",
                    "posts:count:{$threadId}",
                ];
                
                foreach ($patterns as $pattern) {
                    $iterator = null;
                    while ($keys = $redis->scan($iterator, $pattern, 100)) {
                        if (!empty($keys)) {
                            $redis->del($keys);
                        }
                        if ($iterator === 0) break;
                    }
                }
            } catch (\Exception $e) {
                error_log('[ThreadSvc] 清除缓存失败: ' . $e->getMessage());
                // 降级：清除前 10 页缓存
                foreach (['asc', 'desc'] as $order) {
                    for ($p = 1; $p <= 10; $p++) {
                        Cache::delete("posts:thread:{$threadId}:{$order}:p{$p}");
                    }
                }
            }
        } else {
            // 无 Redis 时清除前 10 页缓存
            foreach (['asc', 'desc'] as $order) {
                for ($p = 1; $p <= 10; $p++) {
                    Cache::delete("posts:thread:{$threadId}:{$order}:p{$p}");
                }
            }
        }
        Cache::delete("posts:count:{$threadId}");
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
            $total = $this->threadRepo->countByForum($forumId, $filter);
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
        $threads = $this->threadRepo->getByForum($forumId, $perPage, $offset, $orderBy, $reversed, $filter);

        // 反向查询后翻转结果，恢复正常展示顺序
        if ($reversed) {
            $threads = array_reverse($threads);
        }

        // 第一页合并全局置顶帖（排除当前板块已有的，带缓存）
        if ($page === 1) {
            $globalTops = Cache::get('threads:global_tops_forum', function() {
                return $this->threadRepo->getGlobalTopThreads();
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
            return $this->threadRepo->getDetail($threadId);
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

        $posts = $this->postRepo->getByThread($threadId, $perPage, $offset, $order, $authorOnly);
        $total = $this->postRepo->countByThread($threadId, $authorOnly);
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * 增加浏览量（使用Redis原子操作优化）
     * 先累积到Redis计数器，每10次或缓存过期时批量写入数据库
     */
    public function incrementViews(int $threadId): void
    {
        $cacheKey = "views:pending:{$threadId}";

        $redis = Cache::getRedis();
        if ($redis) {
            try {
                // 使用Redis INCR原子递增，避免竞态条件
                $count = $redis->incr($cacheKey);
                if ($count === 1) {
                    $redis->expire($cacheKey, 300);
                }
                // 累积到10次时批量写入数据库
                if ($count >= 10) {
                    $this->threadRepo->incrementViews($threadId, $count);
                    $redis->del($cacheKey);
                }
                return;
            } catch (\Throwable $e) {
                error_log('[ThreadSvc] Redis INCR 失败: ' . $e->getMessage());
                // 降级到直接写 DB
            }
        }

        // 降级：Redis 不可用时直接写数据库（使用数据库原子递增）
        $this->threadRepo->incrementViews($threadId, 1);
    }

    /**
     * 获取最新帖子
     */
    public function getLatestThreads(int $limit = 10): array
    {
        return Cache::get("threads:latest:{$limit}", function () use ($limit) {
            return $this->threadRepo->getLatest($limit);
        }, 300);
    }

    /**
     * 确定性清除最新帖子缓存（替代 deletePattern SCAN）
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

        // 验证板块存在
        $forum = $this->forumService->getForum($forumId);
        if (!$forum) {
            throw new \RuntimeException('板块不存在');
        }

        Database::beginTransaction();

        try {
            // 创建帖子
            $threadId = $this->threadRepo->create($forumId, $userId, $username, $title, $content);

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
        $thread = $this->threadRepo->findById($threadId);
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
            $postId = $this->postRepo->create($threadId, $userId, $username, $content, $quotePostId);

            // 更新帖子、板块、用户统计
            $this->threadRepo->updateLastPost($threadId, $userId);
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
            NotificationSvc::notify(
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
            $placeholders = implode(',', array_fill(0, count($mentioned), '?'));
            $users = Database::fetchAll(
                "SELECT id, username FROM users WHERE username IN ({$placeholders}) AND deleted_at IS NULL",
                array_values($mentioned)
            );

            foreach ($users as $user) {
                if ((int)$user['id'] !== $fromUserId) {
                    NotificationSvc::notify(
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
        $redis = Cache::getRedis();
        
        if ($redis !== null) {
            try {
                // 使用 SET NX EX 原子操作：只有当 key 不存在时才设置，并返回成功
                $result = $redis->set($key, time(), ['NX', 'EX' => 30]);
                
                if ($result === false) {
                    // key 已存在，说明在防灌水时间窗口内
                    throw new \RuntimeException('操作过于频繁，请稍后再试');
                }
                // 成功设置，允许操作
                return;
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                error_log('[ThreadSvc] Redis 防灌水检查失败: ' . $e->getMessage());
                // Redis 失败时降级到普通检查
            }
        }
        
        // 降级方案：使用普通缓存（存在竞态条件，但总比没有好）
        $last = Cache::get($key);
        if ($last !== null) {
            throw new \RuntimeException('操作过于频繁，请稍后再试');
        }
        // 立即设置标记
        Cache::set($key, time(), 30);
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
        $thread = $this->threadRepo->findById($threadId);
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
        PostEditLogSvc::log($threadId, 0, $userId, $thread['content'], $content);

        $this->threadRepo->update($threadId, $title, $content);
        Cache::delete("thread:{$threadId}");
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
        $thread = $this->threadRepo->findById($threadId);
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
            $lockedThread = Database::fetchOne(
                "SELECT id FROM threads WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$threadId]
            );
            if (!$lockedThread) {
                throw new \RuntimeException('帖子不存在或已被删除');
            }

            $this->threadRepo->softDelete($threadId);

            // 级联软删除子回复，并扣减每个回复作者的 post_count
            // 使用 FOR UPDATE 锁定回复记录，防止并发修改
            $replies = Database::fetchAll(
                "SELECT user_id FROM posts WHERE thread_id = ? AND deleted_at IS NULL FOR UPDATE",
                [$threadId]
            );
            if (!empty($replies)) {
                Database::execute(
                    "UPDATE posts SET deleted_at = ? WHERE thread_id = ? AND deleted_at IS NULL",
                    [time(), $threadId]
                );
                $authorCounts = [];
                foreach ($replies as $r) {
                    $aid = (int)$r['user_id'];
                    $authorCounts[$aid] = ($authorCounts[$aid] ?? 0) + 1;
                }
                // 批量锁定用户记录，按ID排序避免死锁
                $userIds = array_keys($authorCounts);
                sort($userIds);
                foreach ($userIds as $uid) {
                    Database::execute(
                        "UPDATE users SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
                        [$authorCounts[$uid], $authorCounts[$uid], $uid]
                    );
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

        $thread = $this->threadRepo->findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        $oldLevel = (int)($thread['is_top'] ?? 0);
        $this->threadRepo->setTopLevel($threadId, $level);
        Cache::delete("thread:{$threadId}");

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
        $thread = $this->threadRepo->findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        Database::beginTransaction();

        try {
            $existing = Database::fetchOne(
                "SELECT id FROM post_likes WHERE user_id = ? AND thread_id = ? FOR UPDATE",
                [$userId, $threadId]
            );

            if ($existing) {
                Database::execute("DELETE FROM post_likes WHERE id = ?", [$existing['id']]);
                Database::execute("UPDATE threads SET likes = CASE WHEN likes > 0 THEN likes - 1 ELSE 0 END WHERE id = ?", [$threadId]);
                $liked = false;
            } else {
                Database::execute(
                    "INSERT INTO post_likes (user_id, thread_id, created_at) VALUES (?, ?, ?)",
                    [$userId, $threadId, time()]
                );
                Database::execute("UPDATE threads SET likes = likes + 1 WHERE id = ?", [$threadId]);
                $liked = true;
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Cache::delete("user:liked:{$userId}:{$threadId}");

        // 积分变动放在事务外，通过 CreditSvc 记录日志
        if ((int)$thread['user_id'] !== $userId) {
            $creditSvc = new CreditSvc();
            if ($liked) {
                $creditSvc->rewardForLike((int)$thread['user_id'], 'thread', $threadId);
            } else {
                $creditSvc->deductCredits((int)$thread['user_id'], 1, 'unlike', '点赞被取消', 'thread', $threadId);
            }
        }

        $updated = Database::fetchOne("SELECT likes FROM threads WHERE id = ?", [$threadId]);

        return [
            'liked' => $liked,
            'likes' => (int)($updated['likes'] ?? 0),
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

        $thread = $this->threadRepo->findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        $level = max(0, min(3, $level));
        $this->threadRepo->setDigest($threadId, $level);
        Cache::delete("thread:{$threadId}");
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
        $thread = $this->threadRepo->findById($threadId);
        if (!$thread) {
            throw new \RuntimeException('帖子不存在');
        }

        // 权限检查：管理员或版主
        if (!PermissionSvc::canModerate($userId, 'update', (int)$thread['forum_id'])) {
            throw new \RuntimeException('没有锁定权限');
        }

        $newState = !($thread['is_locked'] ?? false);
        Database::execute("UPDATE threads SET is_locked = ?, updated_at = ? WHERE id = ?", [(int)$newState, time(), $threadId]);
        Cache::delete("thread:{$threadId}");

        // 记录版主操作日志
        LogService::log(
            $newState ? 'mod_lock' : 'mod_unlock',
            'thread',
            $threadId,
            ['title' => $thread['title'], 'before' => !$newState, 'after' => $newState],
            $userId
        );

        return $newState;
    }

    /**
     * 移动帖子到其他板块
     */
    public function moveThread(int $threadId, int $targetForumId, int $userId): void
    {
        $thread = $this->threadRepo->findById($threadId);
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
            Database::execute("UPDATE threads SET forum_id = ?, updated_at = ? WHERE id = ?", [$targetForumId, time(), $threadId]);
            Database::execute("UPDATE forums SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END WHERE id = ?", [$sourceForum]);
            Database::execute("UPDATE forums SET thread_count = thread_count + 1 WHERE id = ?", [$targetForumId]);

            // 同步 post_count：该帖下的回复数也要从源板块移到目标板块
            $postCount = (int)(Database::fetchOne(
                "SELECT COUNT(*) as c FROM posts WHERE thread_id = ? AND deleted_at IS NULL",
                [$threadId]
            )['c'] ?? 0);
            if ($postCount > 0) {
                Database::execute("UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?", [$postCount, $postCount, $sourceForum]);
                Database::execute("UPDATE forums SET post_count = post_count + ? WHERE id = ?", [$postCount, $targetForumId]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        Cache::delete("thread:{$threadId}");
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
    }

    /**
     * 编辑回复
     */
    public function updatePost(int $postId, int $userId, string $content): void
    {
        $post = $this->postRepo->findById($postId);
        if (!$post) {
            throw new \RuntimeException('评论不存在');
        }

        // 权限：作者本人 或 管理员/版主
        $thread = $this->threadRepo->findById((int)$post['thread_id']);
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
        PostEditLogSvc::log((int)$post['thread_id'], $postId, $userId, $post['content'] ?? '', $content);

        $this->postRepo->update($postId, $content);
        Cache::delete("thread:{$post['thread_id']}");
        self::clearPostCache((int)$post['thread_id']);
    }

    /**
     * 删除回复
     */
    public function deletePost(int $postId, int $userId): void
    {
        $post = $this->postRepo->findById($postId);
        if (!$post) {
            throw new \RuntimeException('评论不存在');
        }

        $threadId = (int)$post['thread_id'];
        $thread = $this->threadRepo->findById($threadId);
        $forumId = $thread ? (int)$thread['forum_id'] : 0;

        // 权限：作者本人 或 管理员/版主
        if ((int)$post['user_id'] !== $userId && !PermissionSvc::canModerate($userId, 'delete', $forumId)) {
            throw new \RuntimeException('没有删除权限');
        }

        Database::beginTransaction();

        try {
            $this->postRepo->softDelete($postId);

            if ($forumId > 0) {
                $this->forumService->onPostDeleted($forumId);
            }
            $this->userService->decrementPostCount((int)$post['user_id']);

            // 更新帖子回复数
            Database::execute("UPDATE threads SET reply_count = CASE WHEN reply_count > 0 THEN reply_count - 1 ELSE 0 END, updated_at = ? WHERE id = ?", [time(), $threadId]);

            // 更新 last_post 信息
            $lastPost = Database::fetchOne(
                "SELECT user_id, created_at FROM posts WHERE thread_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1",
                [$threadId]
            );
            if ($lastPost) {
                Database::execute("UPDATE threads SET last_post_user_id = ?, last_post_time = ? WHERE id = ?",
                    [(int)$lastPost['user_id'], (int)$lastPost['created_at'], $threadId]);
            } else {
                // 无回复时回退到帖子自身的创建时间和作者
                $threadInfo = Database::fetchOne("SELECT user_id, created_at FROM threads WHERE id = ?", [$threadId]);
                $fallbackUserId = $threadInfo ? (int)$threadInfo['user_id'] : 0;
                $fallbackTime = $threadInfo ? (int)$threadInfo['created_at'] : 0;
                Database::execute("UPDATE threads SET last_post_user_id = ?, last_post_time = ? WHERE id = ?", [$fallbackUserId, $fallbackTime, $threadId]);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        RuntimeSvc::decrement('posts');

        Cache::delete("thread:{$threadId}");
        self::clearPostCache($threadId);
        self::clearLatestCache();
        if ($forumId > 0) {
            self::clearForumCache($forumId);
        }

        LogService::log('mod_delete_post', 'post', $postId, [
            'thread_id' => $threadId,
            'author' => $post['username'] ?? '',
        ], $userId);
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

        // 批量验证存在性
        $placeholders = implode(',', array_fill(0, count($intTids), '?'));
        $existing = Database::fetchAll(
            "SELECT id FROM threads WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            $intTids
        );
        $existingIds = array_column($existing, 'id');
        if (empty($existingIds)) return 0;

        // 批量更新置顶级别
        $ph2 = implode(',', array_fill(0, count($existingIds), '?'));
        Database::execute(
            "UPDATE threads SET is_top = ? WHERE id IN ({$ph2})",
            array_merge([$level], $existingIds)
        );
        $count = count($existingIds);

        foreach ($existingIds as $eid) {
            Cache::delete("thread:{$eid}");
        }
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
        $intTids = array_map('intval', $tids);
        $ph = implode(',', array_fill(0, count($intTids), '?'));
        $threads = Database::fetchAll(
            "SELECT id, forum_id, user_id, reply_count FROM threads WHERE id IN ({$ph}) AND deleted_at IS NULL",
            $intTids
        );
        if (empty($threads)) {
            return 0;
        }

        $threadIds = array_column($threads, 'id');
        $count = count($threadIds);
        $affectedForums = [];
        $threadPh = implode(',', array_fill(0, count($threadIds), '?'));

        Database::beginTransaction();

        try {
            // 1. 批量软删除帖子
            Database::execute(
                "UPDATE threads SET deleted_at = ? WHERE id IN ({$threadPh})",
                array_merge([time()], $threadIds)
            );

            // 2. 批量查出所有回复作者，然后批量软删除回复
            $replies = Database::fetchAll(
                "SELECT user_id FROM posts WHERE thread_id IN ({$threadPh}) AND deleted_at IS NULL",
                $threadIds
            );
            if (!empty($replies)) {
                Database::execute(
                    "UPDATE posts SET deleted_at = ? WHERE thread_id IN ({$threadPh}) AND deleted_at IS NULL",
                    array_merge([time()], $threadIds)
                );
                // 按作者聚合回复数，批量扣减 users.post_count
                $authorCounts = [];
                foreach ($replies as $r) {
                    $aid = (int)$r['user_id'];
                    $authorCounts[$aid] = ($authorCounts[$aid] ?? 0) + 1;
                }
                $cases = [];
                $params = [];
                foreach ($authorCounts as $aid => $cnt) {
                    $cases[] = "WHEN id = ? THEN CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END";
                    $params[] = $aid;
                    $params[] = $cnt;
                    $params[] = $cnt;
                }
                $caseStr = implode(' ', $cases);
                $aidPh = implode(',', array_fill(0, count($authorCounts), '?'));
                $params = array_merge($params, array_keys($authorCounts));
                Database::execute(
                    "UPDATE users SET post_count = CASE {$caseStr} ELSE post_count END WHERE id IN ({$aidPh})",
                    $params
                );
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
            $allFids = array_unique(array_merge(array_keys($forumThreadDec), array_keys($forumPostDec)));
            $tCases = [];
            $pCases = [];
            $params = [];
            foreach ($allFids as $fid) {
                $dec = $forumThreadDec[$fid] ?? 0;
                $tCases[] = "WHEN id = ? THEN CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END";
                $params[] = $fid;
                $params[] = $dec;
                $params[] = $dec;
            }
            foreach ($allFids as $fid) {
                $dec = $forumPostDec[$fid] ?? 0;
                $pCases[] = "WHEN id = ? THEN CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END";
                $params[] = $fid;
                $params[] = $dec;
                $params[] = $dec;
            }
            $tCaseStr = implode(' ', $tCases);
            $pCaseStr = implode(' ', $pCases);
            $fidPh = implode(',', array_fill(0, count($allFids), '?'));
            $params = array_merge($params, $allFids);
            Database::execute(
                "UPDATE forums SET thread_count = CASE {$tCaseStr} ELSE thread_count END, post_count = CASE {$pCaseStr} ELSE post_count END WHERE id IN ({$fidPh})",
                $params
            );

            // 4. 批量扣减帖子作者的 users.thread_count
            $cases = [];
            $params = [];
            foreach ($userThreadDec as $uid => $dec) {
                $cases[] = "WHEN id = ? THEN CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END";
                $params[] = $uid;
                $params[] = $dec;
                $params[] = $dec;
            }
            $caseStr = implode(' ', $cases);
            $uidPh = implode(',', array_fill(0, count($userThreadDec), '?'));
            $params = array_merge($params, array_keys($userThreadDec));
            Database::execute(
                "UPDATE users SET thread_count = CASE {$caseStr} ELSE thread_count END WHERE id IN ({$uidPh})",
                $params
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 缓存清理放在事务外
        self::clearLatestCache();
        foreach ($threadIds as $tid) {
            Cache::delete("thread:{$tid}");
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

        $intTids = array_map('intval', $tids);
        $intTids = array_filter($intTids, fn($t) => $t > 0);
        if (empty($intTids)) return 0;

        // 批量获取所有帖子信息（1 条 SQL 替代 N 条）
        $placeholders = implode(',', array_fill(0, count($intTids), '?'));
        $threads = Database::fetchAll(
            "SELECT id, forum_id FROM threads WHERE id IN ({$placeholders}) AND deleted_at IS NULL AND forum_id != ?",
            array_merge($intTids, [$forumId])
        );
        if (empty($threads)) return 0;

        $moveIds = array_column($threads, 'id');
        $sourceFids = [];
        foreach ($threads as $t) {
            $sourceFids[(int)$t['forum_id']][] = (int)$t['id'];
        }

        Database::beginTransaction();

        try {
            // 批量更新帖子板块
            $ph2 = implode(',', array_fill(0, count($moveIds), '?'));
            Database::execute(
                "UPDATE threads SET forum_id = ?, updated_at = ? WHERE id IN ({$ph2})",
                array_merge([$forumId, time()], $moveIds)
            );
            $count = count($moveIds);

            // 按源板块分组更新统计
            foreach ($sourceFids as $srcFid => $srcTids) {
                $srcCount = count($srcTids);
                Database::execute(
                    "UPDATE forums SET thread_count = CASE WHEN thread_count >= ? THEN thread_count - ? ELSE 0 END WHERE id = ?",
                    [$srcCount, $srcCount, $srcFid]
                );

                // 批量统计这些帖子的回复数
                $phSrc = implode(',', array_fill(0, count($srcTids), '?'));
                $postSum = (int)(Database::fetchOne(
                    "SELECT COUNT(*) as c FROM posts WHERE thread_id IN ({$phSrc}) AND deleted_at IS NULL",
                    $srcTids
                )['c'] ?? 0);
                if ($postSum > 0) {
                    Database::execute(
                        "UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?",
                        [$postSum, $postSum, $srcFid]
                    );
                    Database::execute("UPDATE forums SET post_count = post_count + ? WHERE id = ?", [$postSum, $forumId]);
                }

                $affectedForums[$srcFid] = true;
            }

            // 目标板块增加帖子数
            Database::execute("UPDATE forums SET thread_count = thread_count + ? WHERE id = ?", [$count, $forumId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        // 缓存清理放在事务外
        Cache::delete('forums:list');
        foreach ($tids as $tid) {
            Cache::delete("thread:{$tid}");
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
        $count = 0;
        $intTids = array_map('intval', $tids);
        $intTids = array_filter($intTids, fn($t) => $t > 0);
        if (empty($intTids)) return 0;

        // 批量验证存在性
        $placeholders = implode(',', array_fill(0, count($intTids), '?'));
        $existing = Database::fetchAll(
            "SELECT id FROM threads WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            $intTids
        );
        $existingIds = array_column($existing, 'id');
        if (empty($existingIds)) return 0;

        // 批量更新锁定状态
        $ph2 = implode(',', array_fill(0, count($existingIds), '?'));
        Database::execute(
            "UPDATE threads SET is_locked = ?, updated_at = ? WHERE id IN ({$ph2})",
            array_merge([(int)$lock, time()], $existingIds)
        );
        $count = count($existingIds);

        foreach ($existingIds as $eid) {
            Cache::delete("thread:{$eid}");
        }
        LogService::log($lock ? 'mod_batch_lock' : 'mod_batch_unlock', 'thread', 0, [
            'tids' => $tids, 'count' => $count,
        ], $userId);
        return $count;
    }
}
