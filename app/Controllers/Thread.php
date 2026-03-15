<?php
/**
 * 帖子控制器
 */

namespace App\Controllers;

use Core\Database;
use Core\Cache;
use App\Events\Events;
use Core\Event;
use App\Services\PermissionSvc;

class Thread extends Base
{
    /**
     * 板块详情页（改用 ThreadSvc）
     */
    public function forum(string $forumId): void
    {
        $forumId = (int) $forumId;

        $forumSvc = new \App\Services\ForumSvc();
        $forum = $forumSvc->getForum($forumId);

        if (!$forum) {
            $this->error('板块不存在', 404);
            return;
        }

        // 板块级访问控制：检查浏览权限
        $groupId = (int)($_SESSION['group_id'] ?? 1);
        if (!$forumSvc->checkAccess($forumId, $groupId, 'read')) {
            $this->error('您所在的用户组无权浏览此板块', 403);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $orderBy = $_GET['orderby'] ?? 'lastpost';
        if (!in_array($orderBy, ['lastpost', 'tid', 'replies', 'views'], true)) $orderBy = 'lastpost';
        $filter = $_GET['filter'] ?? '';
        if (!in_array($filter, ['', 'highlight', 'top'], true)) $filter = '';

        $threadSvc = new \App\Services\ThreadSvc();
        $result = $threadSvc->getThreadsByForum($forumId, $page, 20, $orderBy, $filter);

        // 批量加载帖子标签（消除 N+1）
        $threads = $result['threads'];
        $threadIds = array_column($threads, 'id');
        $tagsMap = \App\Services\TagSvc::batchLoadTags($threadIds);
        foreach ($threads as &$t) {
            $t['tags'] = $tagsMap[$t['id']] ?? [];
        }
        unset($t);

        $this->render('thread/forum', [
            'forum' => $forum,
            'threads' => $threads,
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'orderBy' => $orderBy,
            'filter' => $filter,
        ]);
    }

    /**
     * 帖子详情页（改用 ThreadSvc）
     */
    public function detail(string $threadId): void
    {
        $threadId = (int) $threadId;

        // 批量预加载 Redis 缓存，将 10+ 次串行 GET 合并为 1 次 MGET
        $preloadKeys = [
            "thread:{$threadId}",
            "thread:tags:{$threadId}",
        ];
        if ($this->isLoggedIn()) {
            $uid = $this->getCurrentUserId();
            $preloadKeys[] = "user:liked:{$uid}:{$threadId}";
            $preloadKeys[] = "user:fav:{$uid}:{$threadId}";
        }
        Cache::preload($preloadKeys);

        $threadSvc = new \App\Services\ThreadSvc();
        $thread = $threadSvc->getThreadDetail($threadId);

        if (!$thread) {
            $this->error('帖子不存在', 404);
            return;
        }

        // 增加浏览量
        $threadSvc->incrementViews($threadId);

        // 添加等级信息
        $credits = (int)($thread['credits'] ?? 0);
        $levelInfo = \App\Services\LevelSvc::getLevelProgress($credits);
        $thread['level_name'] = $levelInfo['current_level']['name'] ?? '学前班';
        $thread['level_color'] = $levelInfo['current_level']['color'] ?? '#999999';

        // 记录浏览历史
        if ($this->isLoggedIn()) {
            \App\Services\BrowseHistorySvc::record($this->getCurrentUserId(), $threadId);
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $postOrder = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $authorOnly = (int) ($_GET['author_only'] ?? 0);
        $postResult = $threadSvc->getPosts($threadId, $page, 20, $postOrder, $authorOnly);

        // 获取帖子标签（带缓存）
        $tags = [];
        try {
            $tags = Cache::get("thread:tags:{$threadId}", function() use ($threadId) {
                return Database::fetchAll("
                    SELECT t.* FROM tags t
                    INNER JOIN thread_tags tt ON t.id = tt.tag_id
                    WHERE tt.thread_id = ?
                ", [$threadId]);
            }, 600);
        } catch (\Throwable $e) {
            error_log('[Thread] tags query failed: ' . $e->getMessage());
        }

        // 检查当前用户是否已点赞
        $liked = false;
        $favorited = false;
        if ($this->isLoggedIn()) {
            $uid = $this->getCurrentUserId();
            $liked = (bool)Cache::get("user:liked:{$uid}:{$threadId}", function() use ($uid, $threadId) {
                return Database::fetchOne(
                    "SELECT id FROM post_likes WHERE user_id = ? AND thread_id = ?",
                    [$uid, $threadId]
                ) ? 1 : 0;
            }, 120);
            $favorited = (bool)Cache::get("user:fav:{$uid}:{$threadId}", function() use ($uid, $threadId) {
                return \App\Services\FavoriteSvc::isFavorited($uid, $threadId) ? 1 : 0;
            }, 120);
        }

        // 获取打赏信息
        $rewardStats = \App\Services\RewardSvc::getTotalRewards('thread', $threadId);
        $rewardList = \App\Services\RewardSvc::getRewards('thread', $threadId, 5);

        $this->render('thread/detail', [
            'thread' => $thread,
            'posts' => $postResult['posts'],
            'total' => $postResult['total'],
            'page' => $postResult['page'],
            'totalPages' => $postResult['totalPages'],
            'tags' => $tags,
            'liked' => $liked,
            'favorited' => $favorited,
            'rewardStats' => $rewardStats,
            'rewardList' => $rewardList,
            'postOrder' => $postOrder,
            'authorOnly' => $authorOnly,
        ]);
    }

    /**
     * 发帖页面
     */
    public function createPage(): void
    {
        $this->requireLogin();
        $forumId = (int) ($_GET['forum_id'] ?? 0);

        if ($forumId <= 0) {
            // 使用缓存获取论坛列表
            $forums = Cache::get('forums:all', function() {
                return Database::fetchAll("
                    SELECT * FROM forums
                    WHERE deleted_at IS NULL
                    ORDER BY parent_id ASC, `rank` DESC
                ");
            }, 3600);
            $this->render('thread/select_forum', ['forums' => $forums]);
            return;
        }

        $forumSvc = new \App\Services\ForumSvc();
        $forum = $forumSvc->getForum($forumId);

        if (!$forum) {
            $this->error('板块不存在', 404);
            return;
        }

        // 获取所有标签供选择（使用缓存）
        $allTags = [];
        $tagGroups = [];
        try {
            $allTags = Cache::get('tags:popular:50', function() {
                return Database::fetchAll("SELECT * FROM tags ORDER BY thread_count DESC LIMIT 50");
            }, 1800);
            $tagGroups = \App\Services\TagSvc::getTagsForForum($forumId);
        } catch (\Throwable $e) {
            error_log('[Thread] create tags query failed: ' . $e->getMessage());
        }

        $this->render('thread/create', [
            'forum' => $forum,
            'allTags' => $allTags,
            'tagGroups' => $tagGroups,
        ]);
    }

    /**
     * 发帖处理（改用 ThreadSvc）
     */
    public function create(): void
    {
        $this->requireLogin();
        $forumId = (int) ($_POST['forum_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $tagNames = is_string($_POST['tags'] ?? '') ? ($_POST['tags'] ?? '') : '';

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('thread');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        if (empty($title) || mb_strlen($title) < 2) {
            $this->error('标题至少2个字符');
            return;
        }
        if (empty($content) || mb_strlen($content) < 5) {
            $this->error('内容至少5个字符');
            return;
        }

        // 权限检查：用户组发帖权限 + 板块级发帖权限
        if (!\App\Services\PermissionSvc::can($this->getCurrentUserId(), 'thread', $forumId)) {
            $this->error('您没有在此板块发帖的权限');
            return;
        }

        try {
            $threadSvc = new \App\Services\ThreadSvc();
            $currentUser = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$this->getCurrentUserId()]);
            $threadId = $threadSvc->createThread(
                $forumId,
                $this->getCurrentUserId(),
                $currentUser ? ($currentUser['username'] ?? '') : '',
                $title,
                $content
            );

            // 处理标签
            if (!empty($tagNames)) {
                \App\Services\TagSvc::attachTags($threadId, $tagNames);
            }

            $this->json([
                'success' => true,
                'thread_id' => $threadId,
                'message' => '发帖成功',
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 帖子编辑页面
     */
    public function editPage(string $threadId): void
    {
        $this->requireLogin();
        $threadId = (int) $threadId;

        $threadSvc = new \App\Services\ThreadSvc();
        $thread = $threadSvc->getThreadDetail($threadId);

        if (!$thread) {
            $this->error('帖子不存在', 404);
            return;
        }

        // 权限检查：只有作者或版主/管理员可以编辑
        $userId = $this->getCurrentUserId();
        if ((int)$thread['user_id'] !== $userId && !\App\Services\PermissionSvc::isAdminOrMod($userId)) {
            $this->error('没有编辑权限', 403);
            return;
        }

        // 获取帖子标签
        $tags = [];
        try {
            $tags = Database::fetchAll("
                SELECT t.* FROM tags t
                INNER JOIN thread_tags tt ON t.id = tt.tag_id
                WHERE tt.thread_id = ?
            ", [$threadId]);
        } catch (\Throwable $e) {
            error_log('[Thread] edit tags query failed: ' . $e->getMessage());
        }

        $allTags = [];
        try {
            $allTags = Database::fetchAll("SELECT * FROM tags ORDER BY thread_count DESC LIMIT 50");
        } catch (\Throwable $e) {
            error_log('[Thread] edit allTags query failed: ' . $e->getMessage());
        }

        $forumSvc = new \App\Services\ForumSvc();
        $forum = $forumSvc->getForum((int)$thread['forum_id']);

        $this->render('thread/edit', [
            'thread' => $thread,
            'forum' => $forum,
            'tags' => $tags,
            'allTags' => $allTags,
        ]);
    }

    /**
     * 回复帖子（改用 ThreadSvc）
     */
    public function reply(): void
    {
        $this->requireLogin();
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $quotePostId = (int) ($_POST['quote_post_id'] ?? 0);

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('reply');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        if (empty($content) || mb_strlen($content) < 2) {
            $this->error('评论内容至少2个字符');
            return;
        }

        // 检查帖子是否锁定
        $thread = Database::fetchOne("SELECT forum_id, is_locked, user_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
        if (!$thread) {
            $this->error('帖子不存在');
            return;
        }
        if ($thread['is_locked']) {
            $this->error('帖子已锁定，无法评论');
            return;
        }

        // 黑名单检查：帖主拉黑了你则禁止回复
        if (\App\Services\BlacklistSvc::isBlocked((int)$thread['user_id'], $this->getCurrentUserId())) {
            $this->error('无法回复该帖子');
            return;
        }

        // 权限检查：用户组回复权限 + 板块级回复权限
        if (!\App\Services\PermissionSvc::can($this->getCurrentUserId(), 'post', (int)$thread['forum_id'])) {
            $this->error('您没有在此板块评论的权限');
            return;
        }

        // 验证引用的回复存在
        if ($quotePostId > 0) {
            $quotePost = Database::fetchOne("SELECT id FROM posts WHERE id = ? AND thread_id = ? AND deleted_at IS NULL", [$quotePostId, $threadId]);
            if (!$quotePost) {
                $quotePostId = 0;
            }
        }

        try {
            $threadSvc = new \App\Services\ThreadSvc();
            $replyUser = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$this->getCurrentUserId()]);
            $postId = $threadSvc->createReply(
                $threadId,
                $this->getCurrentUserId(),
                $replyUser ? ($replyUser['username'] ?? '') : '',
                $content,
                $quotePostId
            );

            $this->json([
                'success' => true,
                'post_id' => $postId,
                'message' => '评论成功',
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 编辑帖子
     */
    public function edit(): void
    {
        $this->requireLogin();
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $tagNames = is_string($_POST['tags'] ?? '') ? ($_POST['tags'] ?? '') : '';

        if (empty($title) || mb_strlen($title) < 2) {
            $this->error('标题至少2个字符');
            return;
        }
        if (empty($content) || mb_strlen($content) < 5) {
            $this->error('内容至少5个字符');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $service->updateThread(
                $threadId,
                $this->getCurrentUserId(),
                (int)($_SESSION['group_id'] ?? 1),
                $title,
                $content
            );

            // 更新标签
            \App\Services\TagSvc::syncTags($threadId, $tagNames);

            Event::dispatch(Events::THREAD_UPDATED, [
                'thread_id' => $threadId,
                'user_id' => $this->getCurrentUserId(),
            ]);

            $this->json(['success' => true, 'message' => '编辑成功']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 删除帖子
     */
    public function delete(): void
    {
        $this->requireLogin();
        $threadId = (int) ($_POST['thread_id'] ?? 0);

        try {
            $service = new \App\Services\ThreadSvc();
            $service->deleteThread(
                $threadId,
                $this->getCurrentUserId(),
                (int)($_SESSION['group_id'] ?? 1)
            );

            Event::dispatch(Events::THREAD_DELETED, [
                'thread_id' => $threadId,
                'user_id' => $this->getCurrentUserId(),
            ]);

            $this->json(['success' => true, 'message' => '删除成功']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 置顶/取消置顶（支持多级：0普通 1板块置顶 2全局置顶）
     */
    public function toggleTop(): void
    {
        $this->requireLogin();
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $level = isset($_POST['level']) ? (int)$_POST['level'] : -1;

        try {
            $service = new \App\Services\ThreadSvc();
            $groupId = (int)($_SESSION['group_id'] ?? 1);
            $userId = $this->getCurrentUserId();

            // 未指定 level 则在 0/1 之间切换（兼容旧调用）
            if ($level < 0) {
                $thread = $service->getThreadDetail($threadId);
                $level = ($thread && (int)$thread['is_top'] > 0) ? 0 : 1;
            }

            $newLevel = $service->setTopLevel($threadId, $level, $userId, $groupId);
            $labels = [0 => '已取消置顶', 1 => '已板块置顶', 2 => '已全局置顶'];
            $this->json([
                'success' => true,
                'is_top' => $newLevel,
                'message' => $labels[$newLevel] ?? '操作成功',
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 点赞/取消点赞
     */
    public function like(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);

        try {
            $service = new \App\Services\ThreadSvc();
            $result = $service->toggleLike($threadId, $this->getCurrentUserId());

            $this->json([
                'success' => true,
                'liked' => $result['liked'],
                'likes' => $result['likes'],
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 加精/取消加精（支持多级：0取消 1精华I 2精华II 3精华III）
     */
    public function toggleHighlight(): void
    {
        $this->requireLogin();
        $threadId = (int) ($_POST['thread_id'] ?? 0);
        $level = (int) ($_POST['level'] ?? -1);

        try {
            $service = new \App\Services\ThreadSvc();

            // 如果没有指定 level，则在 0 和 1 之间切换（兼容旧调用）
            if ($level < 0) {
                $thread = Database::fetchOne("SELECT is_highlight FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
                $level = ($thread && ($thread['is_highlight'] ?? 0) > 0) ? 0 : 1;
            }

            $newLevel = $service->setDigest($threadId, $level, (int)($_SESSION['group_id'] ?? 1), $this->getCurrentUserId());

            $labels = [0 => '已取消精华', 1 => '已设为精华I', 2 => '已设为精华II', 3 => '已设为精华III'];
            $this->json([
                'success' => true,
                'is_highlight' => $newLevel,
                'message' => $labels[$newLevel] ?? '操作成功',
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 图片上传
     */
    public function uploadImage(): void
    {
        $this->requireLogin();
        $file = $_FILES['image'] ?? null;
        if (!$file) {
            $this->error('请选择图片');
            return;
        }

        try {
            \App\Services\IpAccessSvc::checkAndIncrement($_SERVER['REMOTE_ADDR'] ?? '', 'upload');
            $url = \App\Services\AttachmentSvc::uploadImage($this->getCurrentUserId(), $file);
            $this->json(['success' => true, 'data' => ['url' => $url]]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 收藏/取消收藏
     */
    public function favorite(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);
        try {
            $result = \App\Services\FavoriteSvc::toggle($this->getCurrentUserId(), $threadId);
            $this->json(['success' => true, 'favorited' => $result['favorited']]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 按标签查看帖子
     */
    public function tagThreads(string $name): void
    {
        $name = urldecode($name);
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $tag = Database::fetchOne("SELECT * FROM tags WHERE name = ?", [$name]);
        if (!$tag) {
            $this->error('标签不存在', 404);
            return;
        }

        $row = Database::fetchOne("
            SELECT COUNT(*) as c FROM thread_tags tt
            INNER JOIN threads t ON tt.thread_id = t.id
            WHERE tt.tag_id = ? AND t.deleted_at IS NULL
        ", [$tag['id']]);
        $total = (int)($row['c'] ?? 0);

        $threads = Database::fetchAll("
            SELECT t.*, u.username, u.nickname, u.avatar, u.nickname_color, f.name as forum_name
            FROM thread_tags tt
            INNER JOIN threads t ON tt.thread_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN forums f ON t.forum_id = f.id
            WHERE tt.tag_id = ? AND t.deleted_at IS NULL
            ORDER BY t.created_at DESC
            LIMIT ? OFFSET ?
        ", [$tag['id'], $perPage, $offset]);

        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->render('thread/forum', [
            'forum' => ['id' => 0, 'name' => '标签: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), 'description' => ''],
            'threads' => $threads,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * 打赏
     */
    public function reward(): void
    {
        $this->requireLogin();
        $targetType = trim($_POST['target_type'] ?? '');
        $targetId = (int)($_POST['target_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        // 根据目标类型从数据库查询实际作者，不信任客户端提交的 to_user_id
        $toUserId = 0;
        if ($targetType === 'thread') {
            $thread = \Core\Database::fetchOne("SELECT user_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$targetId]);
            $toUserId = (int)($thread['user_id'] ?? 0);
        } elseif ($targetType === 'post') {
            $post = \Core\Database::fetchOne("SELECT user_id FROM posts WHERE id = ? AND deleted_at IS NULL", [$targetId]);
            $toUserId = (int)($post['user_id'] ?? 0);
        }
        if ($toUserId <= 0) {
            $this->error('打赏目标不存在');
            return;
        }

        try {
            $rewardId = \App\Services\RewardSvc::reward(
                $this->getCurrentUserId(),
                $toUserId,
                $amount,
                $targetType,
                $targetId,
                $message
            );

            $this->success('打赏成功', ['reward_id' => $rewardId]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 购买隐藏内容
     */
    public function buyContent(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);

        // 不信任客户端提交的 price，从数据库获取实际价格
        try {
            \App\Services\ContentHideSvc::purchase($this->getCurrentUserId(), $threadId);
            $this->success('解锁成功');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 锁定/解锁帖子
     */
    public function toggleLock(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);

        try {
            $service = new \App\Services\ThreadSvc();
            $isLocked = $service->toggleLock($threadId, $this->getCurrentUserId());

            $this->json([
                'success' => true,
                'is_locked' => $isLocked,
                'message' => $isLocked ? '已锁定' : '已解锁',
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 移动帖子到其他板块
     */
    public function move(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $targetForumId = (int)($_POST['target_forum_id'] ?? 0);

        if ($targetForumId <= 0) {
            $this->error('请选择目标板块');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $service->moveThread($threadId, $targetForumId, $this->getCurrentUserId());
            $this->json(['success' => true, 'message' => '帖子已移动']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 编辑回复
     */
    public function editPost(): void
    {
        $this->requireLogin();
        $postId = (int)($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if (empty($content) || mb_strlen($content) < 2) {
            $this->error('评论内容至少2个字符');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $service->updatePost($postId, $this->getCurrentUserId(), $content);
            $this->json(['success' => true, 'message' => '编辑成功']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 删除回复
     */
    public function deletePost(): void
    {
        $this->requireLogin();
        $postId = (int)($_POST['post_id'] ?? 0);

        try {
            $service = new \App\Services\ThreadSvc();
            $service->deletePost($postId, $this->getCurrentUserId());
            $this->json(['success' => true, 'message' => '删除成功']);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 编辑历史页面（JSON）
     */
    public function editLog(string $threadId): void
    {
        $threadId = (int)$threadId;
        $postId = (int)($_GET['post_id'] ?? 0);

        // 检查帖子是否存在（防止枚举已删除帖子的编辑记录）
        $thread = Database::fetchOne("SELECT id FROM threads WHERE id = ? AND deleted_at IS NULL", [$threadId]);
        if (!$thread) {
            $this->json(['success' => false, 'logs' => []]);
            return;
        }

        $logs = \App\Services\PostEditLogSvc::getByThread($threadId, $postId);

        $this->json([
            'success' => true,
            'logs' => array_map(function ($log) {
                return [
                    'id' => $log['id'],
                    'username' => $log['username'] ?? '',
                    'reason' => $log['reason'] ?? '',
                    'created_at' => date('Y-m-d H:i', $log['created_at']),
                ];
            }, $logs),
        ]);
    }

    /**
     * 批量版主操作
     */
    public function batchMod(): void
    {
        $this->requireLogin();
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = trim($input['action'] ?? '');
        $tids = $input['tids'] ?? [];

        if (!is_array($tids) || empty($tids)) {
            $this->error('请选择要操作的帖子');
            return;
        }

        $tids = array_map('intval', $tids);
        $userId = $this->getCurrentUserId();

        // 权限前置检查：从数据库实时查询用户组，仅版主及以上可执行批量操作
        if (!PermissionSvc::canModerate($userId, 'mod')) {
            $this->error('没有批量操作权限');
            return;
        }
        $groupId = PermissionSvc::getUserGroupId($userId) ?? 1;

        if (!in_array($action, ['delete', 'top', 'untop', 'move', 'lock', 'unlock'], true)) {
            $this->error('未知操作');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $count = 0;
            $msg = '操作完成';

            switch ($action) {
                case 'delete':
                    $count = $service->batchDelete($tids, $userId, $groupId);
                    $msg = "已删除 {$count} 个帖子";
                    break;
                case 'top':
                    $level = (int)($input['level'] ?? 1);
                    $count = $service->batchTop($tids, $level, $userId, $groupId);
                    $msg = "已置顶 {$count} 个帖子";
                    break;
                case 'untop':
                    $count = $service->batchTop($tids, 0, $userId, $groupId);
                    $msg = "已取消置顶 {$count} 个帖子";
                    break;
                case 'move':
                    $forumId = (int)($input['target_forum_id'] ?? 0);
                    if ($forumId <= 0) {
                        $this->error('请选择目标板块');
                        return;
                    }
                    $count = $service->batchMove($tids, $forumId, $userId, $groupId);
                    $msg = "已移动 {$count} 个帖子";
                    break;
                case 'lock':
                    $count = $service->batchLock($tids, true, $userId, $groupId);
                    $msg = "已锁定 {$count} 个帖子";
                    break;
                case 'unlock':
                    $count = $service->batchLock($tids, false, $userId, $groupId);
                    $msg = "已解锁 {$count} 个帖子";
                    break;
            }

            $this->json(['success' => true, 'message' => $msg, 'count' => $count]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 上传附件（通用文件）
     */
    public function uploadAttachment(): void
    {
        $this->requireLogin();
        $file = $_FILES['file'] ?? null;
        if (!$file) {
            $this->error('请选择文件');
            return;
        }

        try {
            \App\Services\IpAccessSvc::checkAndIncrement($_SERVER['REMOTE_ADDR'] ?? '', 'upload');
            $result = \App\Services\AttachmentSvc::uploadFile($this->getCurrentUserId(), $file);
            $this->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 下载附件（含权限检查）
     */
    public function downloadAttachment(string $id): void
    {
        $attachId = (int)$id;
        $userId = $this->isLoggedIn() ? $this->getCurrentUserId() : null;

        try {
            \App\Services\AttachmentSvc::download($attachId, $userId);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 前台更新板块版主（仅管理员）
     */
    public function updateModerators(): void
    {
        $this->requireLogin();

        $groupId = (int)($_SESSION['group_id'] ?? 1);
        if ($groupId < 3) {
            $this->error('仅管理员可操作');
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $forumId = (int)($input['forum_id'] ?? 0);
        $action = $input['action'] ?? '';

        if ($forumId <= 0) {
            $this->error('参数错误');
            return;
        }

        $forum = Database::fetchOne("SELECT id, moderators FROM forums WHERE id = ? AND deleted_at IS NULL", [$forumId]);
        if (!$forum) {
            $this->error('板块不存在');
            return;
        }

        $currentIds = array_filter(array_map('intval', explode(',', $forum['moderators'] ?? '')));

        if ($action === 'add') {
            $username = trim($input['username'] ?? '');
            if ($username === '') {
                $this->error('请输入用户名或ID');
                return;
            }
            if (ctype_digit($username)) {
                $user = Database::fetchOne("SELECT id, username, avatar FROM users WHERE id = ? AND deleted_at IS NULL", [(int)$username]);
            } else {
                $user = Database::fetchOne("SELECT id, username, avatar FROM users WHERE (username = ? OR nickname = ?) AND deleted_at IS NULL", [$username, $username]);
            }
            if (!$user) {
                $this->error('用户不存在');
                return;
            }
            if (in_array((int)$user['id'], $currentIds, true)) {
                $this->error('该用户已是版主');
                return;
            }
            $currentIds[] = (int)$user['id'];
            $newMods = implode(',', $currentIds);
            Database::execute("UPDATE forums SET moderators = ?, updated_at = ? WHERE id = ?", [$newMods, time(), $forumId]);
            Cache::delete('forums:list');
            Cache::delete("forum:{$forumId}");
            $this->success('已添加版主', ['user' => ['id' => (int)$user['id'], 'name' => $user['username'], 'avatar' => $user['avatar'] ?: '/assets/images/default-avatar.png']]);
        } elseif ($action === 'remove') {
            $removeId = (int)($input['user_id'] ?? 0);
            if ($removeId <= 0) {
                $this->error('参数错误');
                return;
            }
            $currentIds = array_filter($currentIds, fn($id) => $id !== $removeId);
            $newMods = implode(',', $currentIds);
            Database::execute("UPDATE forums SET moderators = ?, updated_at = ? WHERE id = ?", [$newMods, time(), $forumId]);
            Cache::delete('forums:list');
            Cache::delete("forum:{$forumId}");
            $this->success('已移除版主');
        } else {
            $this->error('未知操作');
            return;
        }
    }
}
