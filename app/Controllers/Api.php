<?php
/**
 * RESTful API 控制器
 */

namespace App\Controllers;

use App\Services\UserSvc;
use App\Services\ThreadSvc;
use App\Services\NotificationSvc;
use Core\Event;
use App\Events\Events;
use Core\Database;
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
        Database::execute("UPDATE users SET api_token = ?, login_ip = ?, login_at = ? WHERE id = ?", [
            $tokenHash, $_SERVER['REMOTE_ADDR'] ?? '', time(), $user['id']
        ]);

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
        Database::execute("UPDATE users SET api_token = ? WHERE id = ?", [$tokenHash, $userId]);

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
        Database::execute("UPDATE users SET api_token = NULL WHERE id = ?", [$this->authUser['id']]);
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
        $forums = Cache::get('api:forums:list', function () {
            return Database::fetchAll(
                "SELECT id, parent_id, name, description, icon, thread_count, post_count FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC"
            );
        }, 300);

        $this->apiSuccess($forums);
    }

    /**
     * GET /api/forums/{id} — 板块详情
     */
    public function forumShow(string $id): void
    {
        $forum = Database::fetchOne(
            "SELECT id, parent_id, name, description, icon, thread_count, post_count FROM forums WHERE id = ? AND deleted_at IS NULL",
            [(int)$id]
        );

        if (!$forum) {
            $this->apiError('板块不存在', 404);
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
            $result = $threadSvc->getThreadsByForum($forumId, $page, $perPage);
            $this->apiSuccess([
                'items' => $result['threads'],
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $perPage,
                'total_pages' => $result['totalPages'],
            ]);
        } else {
            // 全站帖子列表
            $page = min($page, 500);
            $offset = ($page - 1) * $perPage;
            $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL")['cnt'] ?? 0);
            $threads = Database::fetchAll("
                SELECT t.id, t.forum_id, t.user_id, t.username, t.title, t.views, t.reply_count,
                       t.is_top, t.is_highlight, t.created_at, t.last_post_time,
                       f.name as forum_name
                FROM threads t LEFT JOIN forums f ON t.forum_id = f.id
                WHERE t.deleted_at IS NULL
                ORDER BY t.is_top DESC, t.last_post_time DESC
                LIMIT ? OFFSET ?
            ", [$perPage, $offset]);
            $this->apiSuccess([
                'items' => $threads,
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

        $threadSvc->incrementViews((int)$id);
        $this->apiSuccess($thread);
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

        $threadSvc = new ThreadSvc();
        $result = $threadSvc->getPosts((int)$threadId, $page, $perPage);

        $this->apiSuccess([
            'items' => $result['posts'],
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

        // 检查帖子是否存在及是否锁定
        $thread = Database::fetchOne(
            "SELECT id, forum_id, is_locked FROM threads WHERE id = ? AND deleted_at IS NULL",
            [(int)$threadId]
        );
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
        $offset = ($page - 1) * $perPage;

        if ($q === '') {
            $this->apiError('请输入搜索关键词');
            return;
        }

        // 限制关键词长度
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        $items = [];
        $total = 0;

        // 构造 BOOLEAN MODE 搜索词：过滤特殊字符，按空格拆分加 + 前缀
        $words = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $q), -1, PREG_SPLIT_NO_EMPTY);
        $booleanQuery = $words ? implode(' ', array_map(fn($w) => '+' . $w . '*', array_slice($words, 0, 10))) : null;
        $escapedQ = addcslashes($q, '%_\\');

        if ($type === 'post') {
            if ($booleanQuery !== null) {
                try {
                    $total = Database::fetchOne("SELECT COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL AND MATCH(content) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery])['cnt'] ?? 0;
                    $items = Database::fetchAll("
                        SELECT p.id, p.thread_id, p.username, p.content, p.created_at, t.title as thread_title
                        FROM posts p LEFT JOIN threads t ON p.thread_id = t.id
                        WHERE p.deleted_at IS NULL AND MATCH(p.content) AGAINST(? IN BOOLEAN MODE)
                        ORDER BY p.created_at DESC LIMIT ? OFFSET ?
                    ", [$booleanQuery, $perPage, $offset]);
                } catch (\Throwable $e) {
                    $booleanQuery = null; // 回退到 LIKE
                }
            }
            if ($booleanQuery === null) {
                $total = Database::fetchOne("SELECT COUNT(*) as cnt FROM posts WHERE deleted_at IS NULL AND content LIKE ?", ["%{$escapedQ}%"])['cnt'] ?? 0;
                $items = Database::fetchAll("
                    SELECT p.id, p.thread_id, p.username, p.content, p.created_at, t.title as thread_title
                    FROM posts p LEFT JOIN threads t ON p.thread_id = t.id
                    WHERE p.deleted_at IS NULL AND p.content LIKE ?
                    ORDER BY p.created_at DESC LIMIT ? OFFSET ?
                ", ["%{$escapedQ}%", $perPage, $offset]);
            }
        } else {
            if ($booleanQuery !== null) {
                try {
                    $total = Database::fetchOne("SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL AND MATCH(title, content) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery])['cnt'] ?? 0;
                    $items = Database::fetchAll("
                        SELECT t.id, t.title, t.username, t.views, t.reply_count, t.created_at, f.name as forum_name
                        FROM threads t LEFT JOIN forums f ON t.forum_id = f.id
                        WHERE t.deleted_at IS NULL AND MATCH(t.title, t.content) AGAINST(? IN BOOLEAN MODE)
                        ORDER BY t.created_at DESC LIMIT ? OFFSET ?
                    ", [$booleanQuery, $perPage, $offset]);
                } catch (\Throwable $e) {
                    $booleanQuery = null; // 回退到 LIKE
                }
            }
            if ($booleanQuery === null) {
                $total = Database::fetchOne("SELECT COUNT(*) as cnt FROM threads WHERE deleted_at IS NULL AND (title LIKE ? OR content LIKE ?)", ["%{$escapedQ}%", "%{$escapedQ}%"])['cnt'] ?? 0;
                $items = Database::fetchAll("
                    SELECT t.id, t.title, t.username, t.views, t.reply_count, t.created_at, f.name as forum_name
                    FROM threads t LEFT JOIN forums f ON t.forum_id = f.id
                    WHERE t.deleted_at IS NULL AND (t.title LIKE ? OR t.content LIKE ?)
                    ORDER BY t.created_at DESC LIMIT ? OFFSET ?
                ", ["%{$escapedQ}%", "%{$escapedQ}%", $perPage, $offset]);
            }
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

        $total = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ?",
            [$this->authUser['id']]
        )['cnt'] ?? 0;

        $items = Database::fetchAll("
            SELECT n.*, u.username as from_username, u.avatar as from_avatar
            FROM notifications n
            LEFT JOIN users u ON n.from_user_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ", [$this->authUser['id'], $perPage, $offset]);

        $unread = NotificationSvc::getUnreadCount($this->authUser['id']);

        $this->apiSuccess([
            'items' => $items,
            'total' => (int)$total,
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
            NotificationSvc::markAllRead($this->authUser['id']);
        } else {
            // 确保 ids 都是整数，防止注入
            $ids = array_map('intval', array_slice($ids, 0, 100));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $userId = $this->authUser['id'];

            Database::beginTransaction();
            try {
                // 统计实际被标记已读的未读通知数
                $params = array_merge($ids, [$userId]);
                $unreadCount = (int)(Database::fetchOne(
                    "SELECT COUNT(*) as c FROM notifications WHERE id IN ({$placeholders}) AND user_id = ? AND is_read = 0",
                    $params
                )['c'] ?? 0);

                Database::execute("UPDATE notifications SET is_read = 1 WHERE id IN ({$placeholders}) AND user_id = ?", $params);

                if ($unreadCount > 0) {
                    Database::execute(
                        "UPDATE users SET unread_notifications = CASE WHEN unread_notifications >= ? THEN unread_notifications - ? ELSE 0 END WHERE id = ?",
                        [$unreadCount, $unreadCount, $userId]
                    );
                }
                Database::commit();
                \Core\Cache::delete("unread_notif:{$userId}");
            } catch (\Throwable $e) {
                Database::rollBack();
                error_log('[Api] notificationRead failed: ' . $e->getMessage());
                $this->apiError('操作失败，请重试');
                return;
            }
        }

        $this->apiSuccess(null, '已标记已读');
    }
}
