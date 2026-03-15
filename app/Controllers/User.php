<?php
/**
 * 用户控制器
 */

namespace App\Controllers;

use App\Events\Events;
use App\Services\SettingSvc;
use Core\Database;
use Core\Event;

class User extends Base
{
    /**
     * 注册页面
     */
    public function registerPage(): void
    {
        $this->render('user/register', [
            'verifyEnabled' => \App\Services\SettingSvc::getBool('user_register_verify', false),
            'captchaRequired' => \App\Services\CaptchaSvc::isRequired('register'),
        ]);
    }

    /**
     * 注册 - 发送邮箱验证码
     */
    public function registerSendCode(): void
    {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('请输入有效的邮箱地址');
            return;
        }

        // 检查邮箱是否已注册
        $exist = \Core\Database::fetchOne("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
        if ($exist) {
            $this->error('该邮箱已被注册');
            return;
        }

        // 频率限制：60秒内只能发一次（基于 IP，防止新 session 绕过）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cacheKey = "reg_code:" . md5($ip . ':' . $email);
        if (\Core\Cache::get($cacheKey) !== null) {
            $this->error('发送太频繁，请稍后再试');
            return;
        }

        $code = (string)random_int(100000, 999999);
        $_SESSION['reg_code'] = $code;
        $_SESSION['reg_email'] = $email;
        $_SESSION['reg_code_time'] = time();
        $_SESSION['reg_code_expires'] = time() + 900;

        try {
            $mailer = new \Core\Mailer();
            $mailer->sendVerifyCode($email, $code, '注册');
            \Core\Cache::set($cacheKey, time(), 60);
            $this->success('验证码已发送到你的邮箱');
        } catch (\Throwable $e) {
            unset($_SESSION['reg_code']);
            error_log('[User] registerSendCode mail error: ' . $e->getMessage());
            $this->error('邮件发送失败，请稍后重试');
            return;
        }
    }

    /**
     * 处理注册（改用 UserSvc）
     */
    public function register(): void
    {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $nickname = trim($_POST['nickname'] ?? '') ?: null;

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('register');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        // IP 频率限制
        try {
            \App\Services\IpAccessSvc::check($_SERVER['REMOTE_ADDR'] ?? '', 'register');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        // 邮箱验证码校验
        if (\App\Services\SettingSvc::getBool('user_register_verify', false)) {
            $code = trim($_POST['email_code'] ?? '');
            $sessionCode = $_SESSION['reg_code'] ?? '';
            $sessionEmail = $_SESSION['reg_email'] ?? '';
            $expires = $_SESSION['reg_code_expires'] ?? 0;

            if (empty($code) || empty($sessionCode)) {
                $this->error('请先获取邮箱验证码');
                return;
            }
            if (!hash_equals($sessionCode, $code)) {
                $this->error('验证码错误');
                return;
            }
            if ($sessionEmail !== $email) {
                $this->error('邮箱与获取验证码时不一致');
                return;
            }
            if (time() > $expires) {
                unset($_SESSION['reg_code'], $_SESSION['reg_email'], $_SESSION['reg_code_expires']);
                $this->error('验证码已过期，请重新获取');
                return;
            }
        }

        try {
            $service = new \App\Services\UserSvc();
            $userId = $service->register($username, $email, $password, $passwordConfirm, $nickname);

            // 清理验证码 session
            unset($_SESSION['reg_code'], $_SESSION['reg_email'], $_SESSION['reg_code_time'], $_SESSION['reg_code_expires']);

            // 更新运行时统计
            $service->onRegistered($userId);

            // 防止 session fixation 攻击 - 先重新生成 session ID，再设置登录信息
            session_regenerate_id(true);

            // 自动登录（使用设置中的默认用户组）
            $defaultGroup = SettingSvc::getInt('user_default_group', 1);
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['nickname'] = $nickname;
            $_SESSION['group_id'] = $defaultGroup;

            // 触发事件
            Event::dispatch(Events::USER_REGISTERED, [
                'user_id' => $userId,
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            \App\Services\IpAccessSvc::increment($_SERVER['REMOTE_ADDR'] ?? '', 'register');
            $this->success('注册成功', ['user_id' => $userId]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 登录页面
     */
    public function loginPage(): void
    {
        $this->render('user/login', [
            'captchaRequired' => \App\Services\CaptchaSvc::isRequired('login'),
        ]);
    }

    /**
     * 处理登录（改用 UserSvc）
     */
    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = !empty($_POST['remember_me']);

        // 验证码校验
        try {
            \App\Services\CaptchaSvc::check('login');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        try {
            $service = new \App\Services\UserSvc();
            $user = $service->login($username, $password, $_SERVER['REMOTE_ADDR'] ?? '');

            // 防止 session fixation 攻击
            session_regenerate_id(true);

            // 设置 Session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nickname'] = $user['nickname'] ?? null;
            $_SESSION['group_id'] = $user['group_id'];
            $_SESSION['avatar'] = $user['avatar'] ?? '';

            // 管理员登录时标记已验证（用于后台 AdminAuth 首次签发 token）
            if ((int)$user['group_id'] === \App\Services\PermissionSvc::ADMIN_GROUP_ID) {
                $_SESSION['admin_verified'] = time();
            }

            // Remember Me 持久登录
            if ($rememberMe) {
                \Core\RememberToken::generate($user['id'], $user['password']);
            }

            // 触发事件
            Event::dispatch(Events::USER_LOGGED_IN, [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            $redirect = null;
            if (!empty($_SESSION['login_redirect'])) {
                $redirect = $_SESSION['login_redirect'];
                unset($_SESSION['login_redirect']);
                // 防止 open redirect：只允许站内相对路径
                if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                    $redirect = '/';
                }
            }

            $this->success('登录成功', [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'redirect' => $redirect,
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 退出登录
     */
    public function logout(): void
    {
        // 仅允许 POST 请求，防止 CSRF 通过 GET 触发登出
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('请使用正确的方式退出登录', 405);
            return;
        }

        $userId = $this->getCurrentUserId();
        $username = $_SESSION['username'] ?? '';

        // 清除 Remember Me token
        \Core\RememberToken::clear($userId);

        session_destroy();

        Event::dispatch(Events::USER_LOGGED_OUT, [
            'user_id' => $userId,
            'username' => $username,
        ]);

        $this->json(['success' => true, 'message' => '已退出登录']);
    }

    /**
     * 忘记密码 - 发送验证码
     */
    public function forgotSendCode(): void
    {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('请输入有效的邮箱地址');
            return;
        }

        $user = \Core\Database::fetchOne("SELECT id, username FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
        if (!$user) {
            // 不暴露用户是否存在，统一提示
            $this->success('如果该邮箱已注册，验证码已发送');
            return;
        }

        // 频率限制：60秒内只能发一次（基于 IP，防止新 session 绕过）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cacheKey = "forgot_code:" . md5($ip . ':' . $email);
        if (\Core\Cache::get($cacheKey) !== null) {
            $this->error('发送太频繁，请稍后再试');
            return;
        }

        $code = (string)random_int(100000, 999999);
        $_SESSION['forgot_code'] = $code;
        $_SESSION['forgot_email'] = $email;
        $_SESSION['forgot_user_id'] = $user['id'];
        $_SESSION['forgot_code_time'] = time();
        $_SESSION['forgot_code_expires'] = time() + 900; // 15分钟

        try {
            $mailer = new \Core\Mailer();
            $mailer->sendVerifyCode($email, $code, '密码重置');
            \Core\Cache::set($cacheKey, time(), 60);
            $this->success('验证码已发送到你的邮箱');
        } catch (\Throwable $e) {
            unset($_SESSION['forgot_code']);
            error_log('[User] forgotSendCode mail error: ' . $e->getMessage());
            $this->error('邮件发送失败，请稍后重试');
            return;
        }
    }

    /**
     * 忘记密码 - 验证并重置
     */
    public function forgotReset(): void
    {
        $code = trim($_POST['code'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if (empty($code) || empty($password)) {
            $this->error('请填写验证码和新密码');
            return;
        }

        // 使用统一密码策略校验
        $minLen = SettingSvc::getInt('user_password_min_length', 6);
        if (strlen($password) < $minLen) {
            $this->error("密码长度至少 {$minLen} 个字符");
            return;
        }
        if (SettingSvc::getBool('user_password_require_mixed', false)) {
            if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $this->error('密码必须同时包含字母和数字');
                return;
            }
        }

        if ($password !== $passwordConfirm) {
            $this->error('两次密码不一致');
            return;
        }

        $sessionCode = $_SESSION['forgot_code'] ?? '';
        $expires = $_SESSION['forgot_code_expires'] ?? 0;
        $userId = (int)($_SESSION['forgot_user_id'] ?? 0);

        // 验证码尝试次数限制（最多 5 次）
        $attempts = (int)($_SESSION['forgot_code_attempts'] ?? 0);
        if ($attempts >= 5) {
            unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_expires'], $_SESSION['forgot_code_attempts']);
            $this->error('验证码尝试次数过多，请重新获取');
            return;
        }

        if (empty($sessionCode) || !hash_equals($sessionCode, $code)) {
            $_SESSION['forgot_code_attempts'] = $attempts + 1;
            $this->error('验证码错误');
            return;
        }

        if (time() > $expires) {
            unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_expires']);
            $this->error('验证码已过期，请重新获取');
            return;
        }

        if (!$userId) {
            $this->error('操作无效，请重新获取验证码');
            return;
        }

        // 更新密码
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        \Core\Database::useMaster();
        try {
            \Core\Database::execute("UPDATE users SET password = ?, updated_at = ? WHERE id = ?", [$hashed, time(), $userId]);
        } finally {
            \Core\Database::restoreReadWrite();
        }

        // 清除 Remember Me token，防止旧 cookie 继续登录
        \Core\RememberToken::clear($userId);

        // 销毁该用户的所有活跃 session，强制其他设备下线
        \Core\Database::execute("DELETE FROM sessions WHERE user_id = ?", [$userId]);

        // 清理 session
        unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_time'], $_SESSION['forgot_code_expires']);

        Event::dispatch(Events::USER_PASSWORD_RESET, [
            'user_id' => $userId,
            'action' => '密码重置',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $this->success('密码重置成功，请重新登录');
    }

    /**
     * 用户公开主页
     */
    public function publicProfile(string $userId): void
    {
        $userId = (int)$userId;

        $service = new \App\Services\UserSvc();
        $user = $service->getProfile($userId);

        if (!$user) {
            $this->error('用户不存在', 404);
            return;
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $threads = $service->getUserThreads($userId, 20, $page);
        $replies = $service->getUserReplies($userId, 10);

        // 关注数据
        $followingCount = 0; $followerCount = 0; $isFollowing = false; $isBlocked = false;
        try {
            $followingCount = \App\Services\FollowSvc::getFollowingCount($userId);
            $followerCount = \App\Services\FollowSvc::getFollowerCount($userId);
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $userId) {
                $isFollowing = \App\Services\FollowSvc::isFollowing($_SESSION['user_id'], $userId);
                $isBlocked = \App\Services\BlacklistSvc::isBlocked($_SESSION['user_id'], $userId);
            }
        } catch (\Throwable $e) {
            error_log('[User] follow data: ' . $e->getMessage());
        }

        // 用户动态
        $userMoments = [];
        $momentTotal = 0;
        try {
            $momentResult = \App\Services\MomentSvc::getList(1, 10, $userId);
            $userMoments = $momentResult['moments'] ?? [];
            $momentTotal = $momentResult['total'] ?? 0;
        } catch (\Throwable $e) {
            error_log('[User] moments: ' . $e->getMessage());
        }

        $this->render('user/public_profile', [
            'user' => \App\Services\UserSvc::safeInfo($user),
            'threads' => $threads['threads'] ?? [],
            'replies' => $replies,
            'page' => $page,
            'totalPages' => $threads['totalPages'] ?? 1,
            'totalThreads' => $threads['total'] ?? count($threads['threads'] ?? []),
            'totalReplies' => $user['post_count'] ?? count($replies),
            'followingCount' => $followingCount,
            'followerCount' => $followerCount,
            'isFollowing' => $isFollowing,
            'isBlocked' => $isBlocked,
            'userMoments' => $userMoments,
            'momentTotal' => $momentTotal,
        ]);
    }

    /**
     * 个人中心
     */
    public function profile(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        $service = new \App\Services\UserSvc();
        $user = $service->getProfile($userId);

        if (!$user) {
            $this->error('用户不存在', 404);
            return;
        }

        $threads = $service->getUserThreads($userId, 10);
        $replies = $service->getUserReplies($userId, 10);
        $favorites = \App\Services\FavoriteSvc::getUserFavorites($userId, 5);

        $this->render('user/profile', [
            'user' => $user,
            'threads' => $threads['threads'] ?? [],
            'replies' => $replies,
            'favorites' => $favorites,
        ]);
    }

    /**
     * 上传头像
     */
    public function uploadAvatar(): void
    {
        $this->requireLogin();
        $file = $_FILES['avatar'] ?? null;
        if (!$file) {
            $this->error('请选择头像文件');
            return;
        }

        try {
            $service = new \App\Services\UserSvc();
            $avatarUrl = $service->uploadAvatar($this->getCurrentUserId(), $file);
            $this->success('头像上传成功', ['avatar' => $avatarUrl]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 更新个人资料
     */
    public function updateProfile(): void
    {
        $this->requireLogin();
        $email = trim($_POST['email'] ?? '');
        $signature = trim($_POST['signature'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '') ?: null;

        try {
            $service = new \App\Services\UserSvc();
            $service->updateProfile($this->getCurrentUserId(), $email, $signature, $nickname);
            $_SESSION['nickname'] = $nickname;
            $this->success('资料更新成功');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(): void
    {
        $this->requireLogin();
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        try {
            $service = new \App\Services\UserSvc();
            $service->changePassword($this->getCurrentUserId(), $oldPassword, $newPassword, $confirmPassword);
            $this->success('密码修改成功');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 关注/取消关注
     */
    public function follow(): void
    {
        $this->requireLogin();
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId === $this->getCurrentUserId()) {
            $this->error('不能关注自己');
            return;
        }
        try {
            $result = \App\Services\FollowSvc::toggle($this->getCurrentUserId(), $targetUserId);
            $this->json(['success' => true, 'followed' => $result['followed']]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 我的收藏
     */
    public function favorites(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $favorites = \App\Services\FavoriteSvc::getUserFavorites($userId, $perPage, $offset);
        $total = \App\Services\FavoriteSvc::countUserFavorites($userId);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->render('user/favorites', [
            'favorites' => $favorites,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    /**
     * 粉丝列表
     */
    public function followers(string $userId): void
    {
        $userId = (int)$userId;
        $service = new \App\Services\UserSvc();
        $user = $service->getProfile($userId);
        if (!$user) { $this->error('用户不存在', 404); return; }

        $followers = \App\Services\FollowSvc::getFollowerList($userId, 50);
        $this->render('user/follow_list', [
            'user' => \App\Services\UserSvc::safeInfo($user),
            'list' => $followers,
            'type' => 'followers',
        ]);
    }

    /**
     * 关注列表
     */
    public function following(string $userId): void
    {
        $userId = (int)$userId;
        $service = new \App\Services\UserSvc();
        $user = $service->getProfile($userId);
        if (!$user) { $this->error('用户不存在', 404); return; }

        $following = \App\Services\FollowSvc::getFollowingList($userId, 50);
        $this->render('user/follow_list', [
            'user' => \App\Services\UserSvc::safeInfo($user),
            'list' => $following,
            'type' => 'following',
        ]);
    }

    /**
     * 积分记录
     */
    public function creditLogs(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $page = max(1, (int)($_GET['page'] ?? 1));

        $creditSvc = new \App\Services\CreditSvc();
        $result = $creditSvc->getUserCreditLogs($userId, $page, 20);

        // 预加载 post 类型记录的 thread_id，避免视图中 N+1 查询
        $logs = $result['logs'];
        $postIds = [];
        foreach ($logs as $log) {
            if (($log['related_type'] ?? '') === 'post' && !empty($log['related_id'])) {
                $postIds[] = (int)$log['related_id'];
            }
        }
        if (!empty($postIds)) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $postRows = Database::fetchAll("SELECT id, thread_id FROM posts WHERE id IN ({$placeholders})", $postIds);
            $postThreadMap = array_column($postRows, 'thread_id', 'id');
            foreach ($logs as &$log) {
                if (($log['related_type'] ?? '') === 'post') {
                    $log['_thread_id'] = $postThreadMap[$log['related_id']] ?? null;
                }
            }
            unset($log);
        }

        $this->render('user/credit_logs', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => $result['totalPages'],
            'total' => $result['total'],
        ]);
    }

    /**
     * 积分排行榜
     */
    public function creditRanking(): void
    {
        $creditSvc = new \App\Services\CreditSvc();
        $ranking = $creditSvc->getCreditRanking(50);

        $this->render('user/credit_ranking', [
            'ranking' => $ranking,
        ]);
    }

    /**
     * 等级体系页面
     */
    public function levels(): void
    {
        $this->render('user/levels');
    }

    /**
     * 清空浏览历史
     */
    public function clearHistory(): void
    {
        $this->requireLogin();
        \App\Services\BrowseHistorySvc::clear($this->getCurrentUserId());
        $this->success('已清空');
    }

    /**
     * 拉黑/取消拉黑
     */
    public function blacklist(): void
    {
        $this->requireLogin();
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        try {
            $result = \App\Services\BlacklistSvc::toggle($this->getCurrentUserId(), $targetUserId);
            $this->json(['success' => true, 'blocked' => $result['blocked']]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }
    }

    /**
     * 我的黑名单列表
     */
    public function blacklistPage(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $list = \App\Services\BlacklistSvc::getList($userId, $perPage, $offset);
        $total = \App\Services\BlacklistSvc::getCount($userId);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->render('user/blacklist', [
            'list' => $list,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
