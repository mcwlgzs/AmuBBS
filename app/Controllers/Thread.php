<?php
/**
 * 帖子控制器
 */

namespace App\Controllers;

use Core\Cache;
use App\Events\Events;
use Core\Event;
use App\Models\Forum;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Tag;
use App\Models\Thread as ThreadModel;
use App\Models\User;
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

        $page = max(1, min(500, (int) ($_GET['page'] ?? 1)));
        $orderBy = $_GET['orderby'] ?? 'lastpost';
        if (!in_array($orderBy, ['lastpost', 'tid', 'replies', 'views'], true)) $orderBy = 'lastpost';
        $filter = $_GET['filter'] ?? '';
        if (!in_array($filter, ['', 'highlight', 'top'], true)) $filter = '';

        $threadSvc = new \App\Services\ThreadSvc();
        $result = $threadSvc->getThreadsByForum($forumId, $page, 20, $orderBy, $filter);

        // 批量加载帖子标签（消除 N+1）
        $threads = $result['threads'];
        $threadIds = array_column($threads, 'id');
        $tagsMap = \App\Models\Tag::batchLoadTags($threadIds);
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

        // 板块浏览权限：以前只有板块列表页做了检查，帖子详情页可以绕过直接读受限板块
        $viewerId = $this->isLoggedIn() ? $this->getCurrentUserId() : null;
        if (!\App\Services\ForumSvc::canRead((int)($thread['forum_id'] ?? 0), $viewerId)) {
            $this->error('您所在的用户组无权浏览此板块', 403);
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

        // 页码封顶：深分页一律 LIMIT 20 OFFSET huge，不封顶时 ?page=99999999 会拖垮库
        $page = max(1, min(500, (int) ($_GET['page'] ?? 1)));
        $postOrder = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $authorOnly = (int) ($_GET['author_only'] ?? 0);
        $postResult = $threadSvc->getPosts($threadId, $page, 20, $postOrder, $authorOnly);

        // 获取帖子标签（缓存由模型内部维护）
        $tags = [];
        try {
            $tags = Tag::getByThread($threadId);
        } catch (\Throwable $e) {
            error_log('[Thread] tags query failed: ' . $e->getMessage());
        }

        // 检查当前用户是否已点赞
        $liked = false;
        $favorited = false;
        if ($this->isLoggedIn()) {
            $uid = $this->getCurrentUserId();
            $liked = (bool)Cache::get(PostLike::cacheKey($uid, $threadId), function() use ($uid, $threadId) {
                return PostLike::isLiked($uid, $threadId) ? 1 : 0;
            }, 120);
            $favorited = (bool)Cache::get(\App\Models\Favorite::cacheKey($uid, $threadId), function() use ($uid, $threadId) {
                return \App\Models\Favorite::isFavorited($uid, $threadId) ? 1 : 0;
            }, 120);
        }

        // 获取打赏信息
        $rewardStats = \App\Models\Reward::getTotalRewards('thread', $threadId);
        $rewardList = \App\Models\Reward::getRewards('thread', $threadId, 5);

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
            // 发帖时选板块（缓存由模型内部维护）
            $forums = Forum::getAllOrdered();
            $this->render('thread/select_forum', ['forums' => $forums]);
            return;
        }

        $forumSvc = new \App\Services\ForumSvc();
        $forum = $forumSvc->getForum($forumId);

        if (!$forum) {
            $this->error('板块不存在', 404);
            return;
        }

        // 获取所有标签供选择（缓存由模型内部维护）
        $allTags = [];
        $tagGroups = [];
        try {
            $allTags = Tag::getPickerList(50);
            $tagGroups = \App\Models\Tag::getTagsForForum($forumId);
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
        $input = $this->input();
        $forumId = (int) ($input['forum_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $content = trim((string)($input['content'] ?? ''));
        // 注意不能写成 is_string($input['tags'] ?? '')：回退值 '' 本身就是字符串，
        // 恒为 true，然后真分支去取不存在的 key 会报 Undefined array key。
        $tagNames = isset($input['tags']) && is_string($input['tags']) ? $input['tags'] : '';

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('thread');
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }

        if (empty($title) || mb_strlen($title) < 2) {
            $this->respondSubmit(false, '标题至少2个字符');
            return;
        }
        if (empty($content) || mb_strlen($content) < 5) {
            $this->respondSubmit(false, '内容至少5个字符');
            return;
        }

        // 权限检查：用户组发帖权限 + 板块级发帖权限
        if (!\App\Services\PermissionSvc::can($this->getCurrentUserId(), 'thread', $forumId)) {
            $this->respondSubmit(false, '您没有在此板块发帖的权限');
            return;
        }

        try {
            $threadSvc = new \App\Services\ThreadSvc();
            $threadId = $threadSvc->createThread(
                $forumId,
                $this->getCurrentUserId(),
                User::getUsername($this->getCurrentUserId()),
                $title,
                $content
            );

            // 处理标签
            if (!empty($tagNames)) {
                \App\Models\Tag::attachTags($threadId, $tagNames);
            }

            // htmx：回 HX-Redirect 跳转到新帖；非 htmx：仍是原来的 JSON（带 thread_id）
            $this->respondSubmit(true, '发帖成功', '/thread/' . $threadId, ['thread_id' => $threadId]);
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
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
            $tags = Tag::getByThread($threadId);
        } catch (\Throwable $e) {
            error_log('[Thread] edit tags query failed: ' . $e->getMessage());
        }

        $allTags = [];
        try {
            $allTags = Tag::getPickerList(50);
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
        $input = $this->input();
        $threadId = (int) ($input['thread_id'] ?? 0);
        $content = trim((string) ($input['content'] ?? ''));
        $quotePostId = (int) ($input['quote_post_id'] ?? 0);

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('reply');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
            return;
        }

        if (empty($content) || mb_strlen($content) < 2) {
            $this->respondRefresh(false, '评论内容至少2个字符');
            return;
        }

        // 检查帖子是否锁定
        $thread = ThreadModel::getLockState($threadId);
        if (!$thread) {
            $this->respondRefresh(false, '帖子不存在');
            return;
        }
        if ($thread['is_locked']) {
            $this->respondRefresh(false, '帖子已锁定，无法评论');
            return;
        }

        // 黑名单检查：帖主拉黑了你则禁止回复
        if (\App\Models\Blacklist::isBlocked((int)$thread['user_id'], $this->getCurrentUserId())) {
            $this->respondRefresh(false, '无法回复该帖子');
            return;
        }

        // 权限检查：用户组回复权限 + 板块级回复权限
        if (!\App\Services\PermissionSvc::can($this->getCurrentUserId(), 'post', (int)$thread['forum_id'])) {
            $this->respondRefresh(false, '您没有在此板块评论的权限');
            return;
        }

        // 验证引用的回复存在
        if ($quotePostId > 0 && !Post::existsInThread($quotePostId, $threadId)) {
            $quotePostId = 0;
        }

        try {
            $threadSvc = new \App\Services\ThreadSvc();
            $postId = $threadSvc->createReply(
                $threadId,
                $this->getCurrentUserId(),
                User::getUsername($this->getCurrentUserId()),
                $content,
                $quotePostId
            );

            if ($this->isHtmx()) {
                $this->respondRefresh(true, '评论成功');
                return;
            }

            $this->json([
                'success' => true,
                'post_id' => $postId,
                'message' => '评论成功',
            ]);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 编辑帖子
     */
    public function edit(): void
    {
        $this->requireLogin();
        $input = $this->input();
        $threadId = (int) ($input['thread_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $content = trim((string)($input['content'] ?? ''));
        // 同 create()：不能用 ?? '' 配 is_string，恒真后会取不存在的 key
        $tagNames = isset($input['tags']) && is_string($input['tags']) ? $input['tags'] : '';

        if (empty($title) || mb_strlen($title) < 2) {
            $this->respondSubmit(false, '标题至少2个字符');
            return;
        }
        if (empty($content) || mb_strlen($content) < 5) {
            $this->respondSubmit(false, '内容至少5个字符');
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
            \App\Models\Tag::syncTags($threadId, $tagNames);

            Event::dispatch(Events::THREAD_UPDATED, [
                'thread_id' => $threadId,
                'user_id' => $this->getCurrentUserId(),
            ]);

            $this->respondSubmit(true, '编辑成功', '/thread/' . $threadId);
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }
    }

    /**
     * 删除帖子
     */
    public function delete(): void
    {
        $this->requireLogin();
        $threadId = (int) ($this->input()['thread_id'] ?? 0);

        // htmx 删除后要跳回板块，先把 forum_id 取出来
        $forumId = (int)(\App\Models\Thread::findById($threadId)['forum_id'] ?? 0);

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

            $this->respondSubmit(true, '删除成功', $forumId > 0 ? '/forum/' . $forumId : '/');
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
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
            $msg = $labels[$newLevel] ?? '操作成功';

            if (!$this->isHtmx()) {
                $this->json(['success' => true, 'is_top' => $newLevel, 'message' => $msg]);
                return;
            }
            $this->respondRefresh(true, $msg);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
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

            if (!$this->isHtmx()) {
                $this->json([
                    'success' => true,
                    'liked' => $result['liked'],
                    'likes' => $result['likes'],
                ]);
                return;
            }

            // 按钮状态本身就是反馈，不弹提示
            $this->respondFragment(true, '', function () use ($threadId, $result): void {
                $this->renderLikeButton($threadId, (int)$result['likes'], (bool)$result['liked']);
            }, false);
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
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
                $level = ThreadModel::getHighlightLevel($threadId) > 0 ? 0 : 1;
            }

            $newLevel = $service->setDigest($threadId, $level, (int)($_SESSION['group_id'] ?? 1), $this->getCurrentUserId());

            $labels = [0 => '已取消精华', 1 => '已设为精华I', 2 => '已设为精华II', 3 => '已设为精华III'];
            $msg = $labels[$newLevel] ?? '操作成功';

            if (!$this->isHtmx()) {
                $this->json(['success' => true, 'is_highlight' => $newLevel, 'message' => $msg]);
                return;
            }
            $this->respondRefresh(true, $msg);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 图片上传
     */
    /**
     * 渲染主题点赞按钮片段
     */
    private function renderLikeButton(int $threadId, int $likes, bool $isLiked): void
    {
        $this->render('thread/_like_btn', ['threadId' => $threadId, 'likes' => $likes, 'isLiked' => $isLiked]);
    }

    /**
     * 渲染主题收藏按钮片段
     */
    private function renderFavoriteButton(int $threadId, bool $isFavorited): void
    {
        $this->render('thread/_favorite_btn', ['threadId' => $threadId, 'isFavorited' => $isFavorited]);
    }

    /**
     * 上传图片（表单编辑器 / 动态发布共用）
     *
     * 支持一次选多张（name="image[]"）：
     *   - htmx 请求：每张成功上传的图片回一个缩略图片段，前端 append 到目标容器
     *   - 普通请求：保持原 JSON（data.url 是第一张，另附 data.urls 全部）
     */
    public function uploadImage(): void
    {
        $this->requireLogin();

        $files = $this->normalizeUploadedFiles($_FILES['image'] ?? null);
        if (empty($files)) {
            $this->respondFragment(false, '请选择图片', static function (): void {});
            return;
        }

        $urls = [];
        $error = '';
        try {
            \App\Services\IpAccessSvc::checkAndIncrement($_SERVER['REMOTE_ADDR'] ?? '', 'upload');
            foreach ($files as $file) {
                $urls[] = \App\Services\AttachmentSvc::uploadImage($this->getCurrentUserId(), $file);
            }
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        if (!$this->isHtmx()) {
            if (empty($urls)) {
                $this->error($error !== '' ? $error : '上传失败');
                return;
            }
            $this->json(['success' => true, 'data' => ['url' => $urls[0], 'urls' => $urls]]);
            return;
        }

        // 多选时中途失败：已经传成功的那几张照样给出去，别让用户白传
        if ($error !== '') {
            $this->frontFlash($error, empty($urls) ? 'danger' : 'warning');
        }
        foreach ($urls as $url) {
            $this->render('components/uploaded-image', ['url' => $url]);
        }
    }

    /**
     * 把 $_FILES['x'] 规范化成「一组单文件数组」
     *
     * PHP 对 name="x[]" 的多文件上传会把 name/type/tmp_name/... 各自拆成数组，
     * 这里还原成 [ ['name'=>…,'tmp_name'=>…,'error'=>…], … ] 这种逐文件结构，
     * 好让 AttachmentSvc 继续按单文件处理。
     *
     * @param mixed $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeUploadedFiles($files): array
    {
        if (!is_array($files) || empty($files['name'])) {
            return [];
        }

        // 单文件上传：本来就是逐字段的标量
        if (!is_array($files['name'])) {
            return (($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ? [$files] : [];
        }

        $out = [];
        foreach (array_keys($files['name']) as $i) {
            $one = [];
            foreach ($files as $key => $values) {
                $one[$key] = is_array($values) ? ($values[$i] ?? null) : $values;
            }
            if (($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $out[] = $one;
            }
        }
        return $out;
    }

    /**
     * 收藏/取消收藏
     */
    public function favorite(): void
    {
        $this->requireLogin();
        $threadId = (int)($_POST['thread_id'] ?? 0);
        try {
            $result = \App\Models\Favorite::toggle($this->getCurrentUserId(), $threadId);

            if (!$this->isHtmx()) {
                $this->json(['success' => true, 'favorited' => $result['favorited']]);
                return;
            }

            $this->respondFragment(true, '', function () use ($threadId, $result): void {
                $this->renderFavoriteButton($threadId, (bool)$result['favorited']);
            }, false);
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
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

        $tag = Tag::findByName($name);
        if (!$tag) {
            $this->error('标签不存在', 404);
            return;
        }

        $total = ThreadModel::countByTag((int)$tag['id']);

        $threads = ThreadModel::getByTag((int)$tag['id'], $perPage, $offset);

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
            $toUserId = ThreadModel::getAuthorId($targetId);
        } elseif ($targetType === 'post') {
            $toUserId = Post::getAuthorId($targetId);
        }
        if ($toUserId <= 0) {
            $this->respondRefresh(false, '打赏目标不存在');
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

            if (!$this->isHtmx()) {
                $this->success('打赏成功', ['reward_id' => $rewardId]);
                return;
            }
            $this->respondRefresh(true, '打赏成功');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
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

            $msg = $isLocked ? '已锁定' : '已解锁';

            if (!$this->isHtmx()) {
                $this->json(['success' => true, 'is_locked' => $isLocked, 'message' => $msg]);
                return;
            }
            $this->respondRefresh(true, $msg);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
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
            $this->respondRefresh(false, '请选择目标板块');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $service->moveThread($threadId, $targetForumId, $this->getCurrentUserId());

            if (!$this->isHtmx()) {
                $this->json(['success' => true, 'message' => '帖子已移动']);
                return;
            }
            $this->respondRefresh(true, '帖子已移动');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 编辑回复
     */
    public function editPost(): void
    {
        $this->requireLogin();
        $input = $this->input();
        $postId = (int)($input['post_id'] ?? 0);
        $content = trim((string)($input['content'] ?? ''));

        if (empty($content) || mb_strlen($content) < 2) {
            $this->respondRefresh(false, '评论内容至少2个字符');
            return;
        }

        try {
            $service = new \App\Services\ThreadSvc();
            $service->updatePost($postId, $this->getCurrentUserId(), $content);
            $this->respondRefresh(true, '编辑成功');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 删除回复
     */
    public function deletePost(): void
    {
        $this->requireLogin();
        $input = $this->input();
        $postId = (int)($input['post_id'] ?? 0);

        try {
            $service = new \App\Services\ThreadSvc();
            $service->deletePost($postId, $this->getCurrentUserId());
            $this->respondRefresh(true, '删除成功');
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
        }
    }

    /**
     * 编辑历史页面（JSON）
     */
    public function editLog(string $threadId): void
    {
        $threadId = (int)$threadId;
        $postId = (int)($_GET['post_id'] ?? 0);

        // 编辑历史会暴露「谁在什么时候改了什么、理由是什么」，属于内部信息：
        // 必须登录 + 板块可读，且仅限「主题作者」或「有该板块管理权的人」查看（原来完全公开）。
        if (!$this->isLoggedIn()) {
            $this->error('请先登录', 401);
            return;
        }

        $viewerId = $this->getCurrentUserId();

        // 检查帖子是否存在（防止枚举已删除帖子的编辑记录）
        $thread = ThreadModel::getLockState($threadId);
        if (!$thread) {
            $this->json(['success' => false, 'logs' => []]);
            return;
        }

        $forumId = (int)($thread['forum_id'] ?? 0);
        if (!\App\Services\ForumSvc::canRead($forumId, $viewerId)) {
            $this->error('您所在的用户组无权浏览此板块', 403);
            return;
        }

        $isAuthor = (int)($thread['user_id'] ?? 0) === $viewerId;
        if (!$isAuthor && $postId > 0) {
            // 楼层作者也能看自己楼层的编辑历史（视图里的 $canEdit 就是这么判的）
            $isAuthor = \App\Models\Post::findAuthorId($postId) === $viewerId;
        }
        $isStaff = \App\Services\PermissionSvc::can($viewerId, 'update', $forumId)
            || \App\Services\PermissionSvc::can($viewerId, 'delete', $forumId);
        if (!$isAuthor && !$isStaff) {
            $this->error('只有作者或版主可以查看编辑历史', 403);
            return;
        }

        $logs = \App\Models\PostEditLog::getByThread($threadId, $postId);

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
        $input = $this->input();
        $action = trim((string)($input['action'] ?? ''));
        $tids = $input['tids'] ?? [];

        if (!is_array($tids) || empty($tids)) {
            $this->respondRefresh(false, '请选择要操作的帖子');
            return;
        }

        $tids = array_map('intval', $tids);
        $userId = $this->getCurrentUserId();

        // 权限前置检查：从数据库实时查询用户组，仅版主及以上可执行批量操作
        if (!PermissionSvc::canModerate($userId, 'mod')) {
            $this->respondRefresh(false, '没有批量操作权限');
            return;
        }
        $groupId = PermissionSvc::getUserGroupId($userId) ?? 1;

        if (!in_array($action, ['delete', 'top', 'untop', 'move', 'lock', 'unlock'], true)) {
            $this->respondRefresh(false, '未知操作');
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
                        $this->respondRefresh(false, '请选择目标板块');
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

            if ($this->isHtmx()) {
                // 列表里的置顶/锁定状态、分页都要跟着变，让前端整页刷新一次
                $this->respondRefresh(true, $msg);
                return;
            }

            $this->json(['success' => true, 'message' => $msg, 'count' => $count]);
        } catch (\RuntimeException $e) {
            $this->respondRefresh(false, $e->getMessage());
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
        // 必须与 PermissionSvc::ADMIN_GROUP_ID 精确比较：用「< 3」这种数值比较时，
        // 任何 id ≥ 4 的自定义用户组（例如内置的「待验证用户」）都能自封版主
        if ($groupId !== \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
            $this->respondFragment(false, '仅管理员可操作', static function (): void {});
            return;
        }

        $input = $this->input();
        $forumId = (int)($input['forum_id'] ?? 0);
        $action = (string)($input['action'] ?? '');

        if ($forumId <= 0) {
            $this->respondFragment(false, '参数错误', static function (): void {});
            return;
        }

        // 版主列表要「新鲜」读：setModerators 会失效行缓存，但如果这里吃缓存，
        // 连续添加两个版主时第二次会基于旧列表计算，把第一个人覆盖掉
        if (!Forum::exists($forumId)) {
            $this->respondFragment(false, '板块不存在', static function (): void {});
            return;
        }

        $currentIds = Forum::getModerators($forumId);

        if ($action === 'add') {
            $username = trim((string)($input['username'] ?? ''));
            if ($username === '') {
                $this->respondFragment(false, '请输入用户名或ID', static function (): void {});
                return;
            }
            // 传数字按 id 找，否则用户名/昵称任一匹配
            $user = ctype_digit($username)
                ? User::findById((int)$username)
                : User::findByUsernameOrNickname($username);
            if (!$user) {
                $this->respondFragment(false, '用户不存在', static function (): void {});
                return;
            }
            if (in_array((int)$user['id'], $currentIds, true)) {
                $this->respondFragment(false, '该用户已是版主', static function (): void {});
                return;
            }
            $currentIds[] = (int)$user['id'];
            Forum::setModerators($forumId, $currentIds);
            if (!$this->isHtmx()) {
                $this->success('已添加版主', ['user' => ['id' => (int)$user['id'], 'name' => $user['username'], 'avatar' => $user['avatar'] ?: '/assets/images/default-avatar.png']]);
                return;
            }
            $this->respondFragment(true, '已添加版主', function () use ($forumId): void {
                $this->renderModList($forumId);
            });
        } elseif ($action === 'remove') {
            $removeId = (int)($input['user_id'] ?? 0);
            if ($removeId <= 0) {
                $this->respondFragment(false, '参数错误', static function (): void {});
                return;
            }
            Forum::setModerators($forumId, array_filter($currentIds, fn($id) => $id !== $removeId));
            if (!$this->isHtmx()) {
                $this->success('已移除版主');
                return;
            }
            $this->respondFragment(true, '已移除版主', function () use ($forumId): void {
                $this->renderModList($forumId);
            });
        } else {
            $this->respondFragment(false, '未知操作', static function (): void {});
            return;
        }
    }

    /**
     * 渲染板块的版主列表片段
     *
     * 添加/移除之后都要重新算一遍列表，所以按 forum_id 现查一次 forums.moderators。
     */
    private function renderModList(int $forumId): void
    {
        $ids = Forum::getModerators($forumId);

        $modList = [];
        foreach (User::getBasicsByIds($ids) as $m) {
            $modList[] = [
                'id'     => $m['id'],
                'name'   => $m['nickname'] !== '' ? $m['nickname'] : $m['username'],
                'avatar' => $m['avatar'] ?: '/assets/images/default-avatar.png',
            ];
        }

        $this->render('thread/_mod_list', ['modList' => $modList, 'forumId' => $forumId]);
    }
}
