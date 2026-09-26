<?php
/**
 * RESTful API 控制器
 */

namespace App\Controllers;

use App\Services\SearchSvc;
use App\Services\UserSvc;
use App\Services\ThreadSvc;
use App\Models\Forum;
use App\Models\Notification;
use App\Models\Thread;
use App\Models\User;
use Core\Event;
use App\Events\Events;
use Core\Cache;

class Api extends ApiBase
{
    // ==================== 认证 ====================

    /**
     * POST /api/auth/login — 登录获取 Token
     */
    public function authLogin(): void
    {
        // IP 频率限制：防止暴力破解（原子操作）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipKey = "api:login:ip:" . md5($ip);
        if (Cache::incrementWithLimit($ipKey, 10, 300) === -1) {
            $this->apiError('登录尝试过于频繁，请稍后再试', 429);
            return;
        }

        $input = $this->getJsonInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        try {
            $userSvc = new UserSvc();
            $user = $userSvc->login($username, $password, $_SERVER['REMOTE_ADDR'] ?? '');
        } catch (\RuntimeException $e) {
            $this->apiError($e->getMessage(), 401);
            return;
        }

        // 生成 Token
        $token = $this->generateToken();
        $tokenHash = hash('sha256', $token);
        User::setApiToken((int)$user['id'], $tokenHash, $_SERVER['REMOTE_ADDR'] ?? '', time());

        $this->apiSuccess([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nickname' => $user['nickname'] ?? null,
                'email' => $user['email'],
                'group_id' => $user['group_id'],
            ],
        ], '登录成功');
    }

    /**
     * POST /api/auth/register — 注册
     */
    public function authRegister(): void
    {
        // IP 频率限制（提前设置，防止异常绕过）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipKey = "api:register:ip:" . md5($ip);
        if (Cache::incrementWithLimit($ipKey, 1, 60) === -1) {
            $this->apiError('注册过于频繁，请稍后再试', 429);
            return;
        }

        $input = $this->getJsonInput();
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $passwordConfirm = $input['password_confirm'] ?? $password;
        $nickname = trim($input['nickname'] ?? '') ?: null;

        try {
            $userSvc = new UserSvc();
            $userId = $userSvc->register($username, $email, $password, $passwordConfirm, $nickname);
        } catch (\RuntimeException $e) {
            $this->apiError($e->getMessage());
            return;
        }

        // 生成 Token
        $token = $this->generateToken();
        $tokenHash = hash('sha256', $token);
        User::setApiToken($userId, $tokenHash);

        // 触发用户注册事件（供 AutoAvatar 等插件使用）
        Event::dispatch(Events::USER_REGISTERED, [
            'user_id' => $userId,
            'username' => $username,
            'ip' => $ip,
        ]);

        $this->apiSuccess([
            'token' => $token,
            'user' => ['id' => $userId, 'username' => $username, 'nickname' => $nickname, 'email' => $email, 'group_id' => 1],
        ], '注册成功');
    }

    /**
     * POST /api/auth/logout — 登出（清除 Token）
     */
    public function authLogout(): void
    {
        $this->authenticate();
        User::setApiToken((int)$this->authUser['id'], null);
        $this->apiSuccess(null, '已登出');
    }

    // ==================== 用户 ====================

    /**
     * GET /api/users/{id} — 获取用户信息
     */
    public function userShow(string $id): void
    {
        $userSvc = new UserSvc();
        $user = $userSvc->getProfile((int)$id);

        if (!$user) {
            $this->apiError('用户不存在', 404);
            return;
        }

        $this->apiSuccess(UserSvc::safeInfo($user));
    }

    /**
     * GET /api/users/me — 获取当前用户信息
     */
    public function userMe(): void
    {
        $this->authenticate();
        $userSvc = new UserSvc();
        $user = $userSvc->getProfile($this->authUser['id']);
        if (!$user) {
            $this->apiError('用户不存在', 404);
            return;
        }
        $this->apiSuccess(UserSvc::safeInfo($user));
    }

    // ==================== 板块 ====================

    /**
     * GET /api/forums — 板块列表
     */
    public function forumList(): void
    {
        $forums = Cache::get('api:forums:list', static function () {
            return Forum::apiList();
        }, 300);

        $this->apiSuccess($forums);
    }

    /**
     * GET /api/forums/{id} — 板块详情
     */
    public function forumShow(string $id): void
    {
        $forum = Forum::apiDetail((int)$id);

        if (!$forum) {
            $this->apiError('板块不存在', 404);
            return;
        }

        if (!\App\Services\ForumSvc::canRead((int)$id, $this->viewerId())) {
            $this->apiError('您所在的用户组无权浏览此板块', 403);
            return;
        }

        $this->apiSuccess($forum);
    }

    // ==================== 帖子 ====================

    /**
     * GET /api/threads — 帖子列表
     */
    public function threadList(): void
    {
        $forumId = (int)($_GET['forum_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));

        $threadSvc = new ThreadSvc();

        if ($forumId > 0) {
            $viewerId = $this->viewerId();
            if (!\App\Services\ForumSvc::canRead($forumId, $viewerId)) {
                $this->apiError('您所在的用户组无权浏览此板块', 403);
                return;
            }
            $result = $threadSvc->getThreadsByForum($forumId, $page, $perPage);
            $this->apiSuccess([
                'items' => $this->onlyVisible($result['threads']),
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $perPage,
                'total_pages' => $result['totalPages'],
            ]);
        } else {
            // 全站帖子列表
            $page = min($page, 500);
            $offset = ($page - 1) * $perPage;
            $total = Thread::countActive();
            $threads = Thread::apiListAll($perPage, $offset);
            $this->apiSuccess([
                'items' => $this->onlyVisible($threads),
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => max(1, (int)ceil($total / $perPage)),
            ]);
        }
    }

    /**
     * GET /api/threads/{id} — 帖子详情
     */
    public function threadShow(string $id): void
    {
        $threadSvc = new ThreadSvc();
        $thread = $threadSvc->getThreadDetail((int)$id);

        if (!$thread) {
            $this->apiError('帖子不存在', 404);
            return;
        }

        $viewerId = $this->viewerId();

        // 板块浏览权限（与帖子详情页同一条判据）
        if (!\App\Services\ForumSvc::canRead((int)($thread['forum_id'] ?? 0), $viewerId)) {
            $this->apiError('您所在的用户组无权浏览此板块', 403);
            return;
        }

        $threadSvc->incrementViews((int)$id);
        $this->apiSuccess(self::prepareThreadRow($thread, (int)$id, $viewerId));
    }

    /**
     * 当前访客 ID（可选认证：Bearer Token 优先，其次会话 Cookie）
     */
    private ?int $viewerIdCache = null;
    private bool $viewerIdResolved = false;

    private function viewerId(): ?int
    {
        if (!$this->viewerIdResolved) {
            $this->optionalAuth();
            $id = (int)($this->authUser['id'] ?? $_SESSION['user_id'] ?? 0);
            $this->viewerIdCache = $id > 0 ? $id : null;
            $this->viewerIdResolved = true;
        }

        return $this->viewerIdCache;
    }

    /**
     * 剔除当前访客无权浏览的行（板块 read 权限）
     *
     * @param array<int,array> $items
     * @return array<int,array>
     */
    private function onlyVisible(array $items): array
    {
        $viewerId = $this->viewerId();

        return array_values(array_filter($items, static function ($row) use ($viewerId): bool {
            return is_array($row)
                && \App\Services\ForumSvc::canRead((int)($row['forum_id'] ?? 0), $viewerId);
        }));
    }

    /**
     * 帖子行的公开化处理：脱敏 + 付费/隐藏内容解析
     *
     * 原来 REST API 直接把 ThreadSvc::getThreadDetail() 的原始结果返回给任何人，
     * 于是匿名请求就能读到 [hide] / 付费可见的正文，以及作者 user_ip 等字段；
     * 网页版唯一的付费墙调用点在 resources/views/thread/detail.php。
     */
    private static function prepareThreadRow(array $thread, int $threadId, ?int $viewerId): array
    {
        foreach (['user_ip', 'reg_ip', 'email', 'password', 'remember_token', 'api_token'] as $field) {
            unset($thread[$field]);
        }

        $rendered = \Core\Markdown::render($thread['content_fmt'] ?? null, $thread['content'] ?? '');
        if ($rendered !== '') {
            $thread['content_fmt'] = \App\Services\ContentHideSvc::parse($rendered, $threadId, $viewerId);
        }

        return $thread;
    }

    /**
     * POST /api/threads — 发帖
     */
    public function threadCreate(): void
    {
        $this->authenticate();
        $input = $this->getJsonInput();

        $forumId = (int)($input['forum_id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');

        if ($forumId <= 0 || mb_strlen($title) < 2 || mb_strlen($content) < 5) {
            $this->apiError('参数不完整（forum_id, title>=2字, content>=5字）');
            return;
        }

        // 权限检查
        if (!\App\Services\PermissionSvc::can($this->authUser['id'], 'thread', $forumId)) {
            $this->apiError('您没有在此板块发帖的权限', 403);
            return;
        }

        try {
            $threadSvc = new ThreadSvc();
            $threadId = $threadSvc->createThread($forumId, $this->authUser['id'], $this->authUser['username'], $title, $content);
            $this->apiSuccess(['thread_id' => $threadId], '发帖成功');
        } catch (\RuntimeException $e) {
            $this->apiError($e->getMessage());
        }
    }

    // ==================== 回复 ====================

    /**
     * GET /api/threads/{id}/posts — 帖子回复列表
     */
    public function postList(string $threadId): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));

        // 先确认帖子存在且当前访客有权浏览它的板块（以前这里完全无鉴权）
        $thread = \App\Models\Thread::getLockState((int)$threadId);
        if (!$thread) {
            $this->apiError('帖子不存在', 404);
            return;
        }
        $viewerId = $this->viewerId();
        if (!\App\Services\ForumSvc::canRead((int)($thread['forum_id'] ?? 0), $viewerId)) {
            $this->apiError('您所在的用户组无权浏览此板块', 403);
            return;
        }

        $threadSvc = new ThreadSvc();
        $result = $threadSvc->getPosts((int)$threadId, $page, $perPage);

        $posts = [];
        foreach ($result['posts'] as $post) {
            if (is_array($post) && array_key_exists('content_fmt', $post)) {
                $rendered = \Core\Markdown::render($post['content_fmt'] ?? null, $post['content'] ?? '');
                $post['content_fmt'] = \App\Services\ContentHideSvc::parse($rendered, (int)$threadId, $viewerId);
            }
            $posts[] = $post;
        }

        $this->apiSuccess([
            'items' => $posts,
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $perPage,
            'total_pages' => $result['totalPages'],
        ]);
    }

    /**
     * POST /api/threads/{id}/posts — 回复帖子
     */
    public function postCreate(string $threadId): void
    {
        $this->authenticate();
        $input = $this->getJsonInput();
        $content = trim($input['content'] ?? '');

        if (mb_strlen($content) < 2) {
            $this->apiError('评论内容至少 2 个字符');
            return;
        }

        // 检查帖子是否存在及是否锁定（走新鲜读取，不吃行缓存）
        $thread = Thread::getLockState((int)$threadId);
        if (!$thread) {
            $this->apiError('帖子不存在', 404);
            return;
        }
        if (!empty($thread['is_locked'])) {
            $this->apiError('帖子已锁定，无法回复', 403);
            return;
        }

        // 权限检查
        if (!\App\Services\PermissionSvc::can($this->authUser['id'], 'post', (int)$thread['forum_id'])) {
            $this->apiError('您没有回复的权限', 403);
            return;
        }

        try {
            $threadSvc = new ThreadSvc();
            $postId = $threadSvc->createReply((int)$threadId, $this->authUser['id'], $this->authUser['username'], $content);
            $this->apiSuccess(['post_id' => $postId], '评论成功');
        } catch (\RuntimeException $e) {
            $this->apiError($e->getMessage());
        }
    }

    // ==================== 搜索 ====================

    /**
     * GET /api/search — 搜索
     */
    public function search(): void
    {
        $q = trim($_GET['q'] ?? '');
        $type = $_GET['type'] ?? 'thread';
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = 20;

        if ($q === '') {
            $this->apiError('请输入搜索关键词');
            return;
        }

        // 限制关键词长度
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        // FULLTEXT / LIKE 的选择与回退、关键词清洗都在 SearchSvc（与站内搜索同一份实现）
        $found = SearchSvc::byType($type, $q, $page, $perPage, true);
        $items = is_array($found['items']) ? $found['items'] : [];
        $total = $found['total'];

        // 搜索同样不能越过板块浏览权限
        if ($type === 'thread') {
            $items = $this->onlyVisible($items);
        }

        $this->apiSuccess([
            'items' => $items,
            'total' => (int)$total,
            'page' => $page,
            'total_pages' => (int)max(1, ceil($total / $perPage)),
        ]);
    }

    // ==================== 通知 ====================

    /**
     * GET /api/notifications — 通知列表
     */
    public function notificationList(): void
    {
        $this->authenticate();
        $page = max(1, min(500, (int)($_GET['page'] ?? 1)));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $list = Notification::apiList((int)$this->authUser['id'], $perPage, $offset);

        $unread = Notification::getUnreadCount((int)$this->authUser['id']);

        $this->apiSuccess([
            'items' => $list['items'],
            'total' => $list['total'],
            'unread' => $unread,
            'page' => $page,
        ]);
    }

    /**
     * POST /api/notifications/read — 标记已读
     */
    public function notificationRead(): void
    {
        $this->authenticate();
        $input = $this->getJsonInput();
        $ids = $input['ids'] ?? [];

        if (empty($ids)) {
            Notification::markAllRead($this->authUser['id']);
        } else {
            // 确保 ids 都是整数，防止注入
            $ids = array_map('intval', array_slice($ids, 0, 100));
            try {
                // 标记已读 + 递减未读计数由模型在一个事务里完成
                Notification::markReadIds((int)$this->authUser['id'], $ids);
            } catch (\Throwable $e) {
                $this->apiError('操作失败，请重试');
                return;
            }
        }

        $this->apiSuccess(null, '已标记已读');
    }
}
