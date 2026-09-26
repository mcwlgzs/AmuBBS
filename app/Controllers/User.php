<?php
/**
 * 用户控制器
 */

namespace App\Controllers;

use App\Events\Events;
use App\Models\Post;
use App\Models\User as UserModel;
use App\Services\SettingSvc;
use Core\Event;

class User extends Base
{
    /**
     * 注册页面
     */
    public function registerPage(): void
    {
        $this->render('user/register', [
            // ?modal=1：只渲染表单本身，供登录弹窗用 htmx 取回（不带 layout）
            'isModal' => !empty($_GET['modal']),
            'redirectTo' => $this->formRedirectTarget(),
            'verifyEnabled' => \App\Services\SettingSvc::getBool('user_register_verify', false),
            'captchaRequired' => \App\Services\CaptchaSvc::isRequired('register'),
        ]);
    }

    /**
     * 忘记密码页面
     *
     * 以前这个流程只存在于登录弹窗里（两个 POST 接口，没有 GET 页面）。
     * 现在给它一个真正的 URL；htmx 请求时只回卡片片段（「重新获取验证码」用它换回第 1 步）。
     */
    public function forgotPage(): void
    {
        if ($this->isHtmx()) {
            $this->render('user/_forgot_card', ['step' => 'email']);
            return;
        }

        $this->render('user/forgot', ['step' => 'email']);
    }

    /**
     * 注册 - 发送邮箱验证码
     */
    public function registerSendCode(): void
    {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondSubmit(false, '请输入有效的邮箱地址');
            return;
        }

        // 检查邮箱是否已注册（收敛到 Model，避免各处手写 SQL）
        if (\App\Models\User::emailExists($email)) {
            $this->respondSubmit(false, '该邮箱已被注册');
            return;
        }

        // 频率限制：60秒内只能发一次（基于 IP，防止新 session 绕过）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cacheKey = "reg_code:" . md5($ip . ':' . $email);
        if (\Core\Cache::get($cacheKey) !== null) {
            $this->respondSubmit(false, '发送太频繁，请稍后再试');
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
            // 不跳转（hx-swap="none"）：只回一条提示，倒计时由前端 JS 负责
            $this->respondSubmit(true, '验证码已发送到你的邮箱');
        } catch (\Throwable $e) {
            unset($_SESSION['reg_code']);
            error_log('[User] registerSendCode mail error: ' . $e->getMessage());
            $this->respondSubmit(false, '邮件发送失败，请稍后重试');
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
            $this->respondSubmit(false, $e->getMessage());
            return;
        }

        // IP 频率限制
        try {
            \App\Services\IpAccessSvc::check($_SERVER['REMOTE_ADDR'] ?? '', 'register');
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }

        // 邮箱验证码校验
        if (\App\Services\SettingSvc::getBool('user_register_verify', false)) {
            $code = trim($_POST['email_code'] ?? '');
            $sessionCode = $_SESSION['reg_code'] ?? '';
            $sessionEmail = $_SESSION['reg_email'] ?? '';
            $expires = $_SESSION['reg_code_expires'] ?? 0;

            if (empty($code) || empty($sessionCode)) {
                $this->respondSubmit(false, '请先获取邮箱验证码');
                return;
            }
            if (!hash_equals($sessionCode, $code)) {
                $this->respondSubmit(false, '验证码错误');
                return;
            }
            if ($sessionEmail !== $email) {
                $this->respondSubmit(false, '邮箱与获取验证码时不一致');
                return;
            }
            if (time() > $expires) {
                unset($_SESSION['reg_code'], $_SESSION['reg_email'], $_SESSION['reg_code_expires']);
                $this->respondSubmit(false, '验证码已过期，请重新获取');
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
            $this->respondSubmit(true, '注册成功', $this->resolveLoginRedirect(), ['user_id' => $userId]);
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }
    }

    /**
     * 登录页面
     */
    public function loginPage(): void
    {
        $this->render('user/login', [
            'isModal' => !empty($_GET['modal']),
            'redirectTo' => $this->formRedirectTarget(),
            'captchaRequired' => \App\Services\CaptchaSvc::isRequired('login'),
        ]);
    }

    /**
     * 登录成功后跳回哪里
     *
     * 优先级：Session 里记的受保护地址（登录中间件写入）> 表单隐藏字段 > 首页。
     * 只接受站内相对路径，防止 open redirect。
     */
    private function resolveLoginRedirect(): string
    {
        $target = '';
        if (!empty($_SESSION['login_redirect'])) {
            $target = (string)$_SESSION['login_redirect'];
            unset($_SESSION['login_redirect']);
        } else {
            $posted = $this->input()['redirect'] ?? '';
            $target = is_string($posted) ? $posted : '';
        }

        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }
        return $target;
    }

    /**
     * 表单里 hidden redirect 的取值
     *
     * 让「登录后回到当前页」在两种形态下都成立：
     *   - 独立页面 /login：没有 ?redirect=，默认回首页
     *   - 弹窗：它是用 htmx 拉 /login?modal=1 的，此时 REQUEST_URI 是 /login 而不是宿主页面，
     *     所以打开弹窗时前端会把宿主地址放进 ?redirect= 带过来
     */
    private function formRedirectTarget(): string
    {
        $target = (string)($_GET['redirect'] ?? '');
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }
        return $target;
    }

    /**
     * 处理登录（改用 UserSvc）
     */
    public function login(): void
    {
        // 请求里塞数组（username[]=x）会让 string 类型参数直接抛 TypeError →
        // 之前 DEBUG 模式下会把整条调用栈和绝对路径回显给访客。这里非字符串一律按空处理。
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
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
            $user = $service->login($username, $password, \Core\Helper::clientIp());

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

            $redirect = $this->resolveLoginRedirect();

            // htmx：HX-Redirect 整页跳转；非 htmx：仍是原 JSON（保留 redirect 字段）
            $this->respondSubmit(true, '登录成功', $redirect, [
                'user_id'  => $user['id'],
                'username' => $user['username'],
                'redirect' => $redirect,
            ]);
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
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

        // 彻底退出：清空会话数据、删除服务端会话文件、失效客户端 Cookie。
        // 原来只调了 session_destroy()：会话 ID 未重置、$_SESSION 与 PHPSESSID Cookie
        // 都还在，且销毁后本请求内的写入会再建一个文件，等于没退干净。
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?: '/',
                'domain'   => (string)($params['domain'] ?? ''),
                'secure'   => (bool)($params['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Event::dispatch(Events::USER_LOGGED_OUT, [
            'user_id' => $userId,
            'username' => $username,
        ]);

        // htmx：回 HX-Redirect 到首页（否则响应体里的 JSON 没人看）；非 htmx：保持原 JSON
        $this->respondSubmit(true, '已退出登录', '/');
    }

    /**
     * 忘记密码 - 发送验证码
     */
    public function forgotSendCode(): void
    {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respondFragment(false, '请输入有效的邮箱地址', static function (): void {});
            return;
        }

        // 发送成功后切到「填验证码 + 新密码」那一步；片段替换 #forgotCard
        $toResetStep = function (): void {
            $this->render('user/_forgot_card', ['step' => 'reset']);
        };

        $user = UserModel::findByEmail($email);
        if (!$user) {
            // 不暴露用户是否存在，统一提示（照样前进到下一步，避免用行为差异探测账号）
            $this->respondFragment(true, '如果该邮箱已注册，验证码已发送', $toResetStep);
            return;
        }

        // 频率限制：60秒内只能发一次（基于 IP，防止新 session 绕过）
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cacheKey = "forgot_code:" . md5($ip . ':' . $email);
        if (\Core\Cache::get($cacheKey) !== null) {
            $this->respondFragment(false, '发送太频繁，请稍后再试', static function (): void {});
            return;
        }

        $code = (string)random_int(100000, 999999);
        $_SESSION['forgot_code'] = $code;
        $_SESSION['forgot_email'] = $email;
        $_SESSION['forgot_user_id'] = $user['id'];
        $_SESSION['forgot_code_time'] = time();
        $_SESSION['forgot_code_expires'] = time() + 900; // 15分钟
        unset($_SESSION['forgot_code_attempts']);

        try {
            $mailer = new \Core\Mailer();
            $mailer->sendVerifyCode($email, $code, '密码重置');
            \Core\Cache::set($cacheKey, time(), 60);
            $this->respondFragment(true, '验证码已发送到你的邮箱', $toResetStep);
        } catch (\Throwable $e) {
            unset($_SESSION['forgot_code']);
            error_log('[User] forgotSendCode mail error: ' . $e->getMessage());
            $this->respondFragment(false, '邮件发送失败，请稍后重试', static function (): void {});
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
            $this->respondSubmit(false, '请填写验证码和新密码');
            return;
        }

        // 使用统一密码策略校验
        $minLen = SettingSvc::getInt('user_password_min_length', 6);
        if (strlen($password) < $minLen) {
            $this->respondSubmit(false, "密码长度至少 {$minLen} 个字符");
            return;
        }
        if (SettingSvc::getBool('user_password_require_mixed', false)) {
            if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $this->respondSubmit(false, '密码必须同时包含字母和数字');
                return;
            }
        }

        if ($password !== $passwordConfirm) {
            $this->respondSubmit(false, '两次密码不一致');
            return;
        }

        $sessionCode = $_SESSION['forgot_code'] ?? '';
        $expires = $_SESSION['forgot_code_expires'] ?? 0;
        $userId = (int)($_SESSION['forgot_user_id'] ?? 0);

        // 验证码尝试次数限制（最多 5 次）
        $attempts = (int)($_SESSION['forgot_code_attempts'] ?? 0);
        if ($attempts >= 5) {
            unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_expires'], $_SESSION['forgot_code_attempts']);
            $this->respondSubmit(false, '验证码尝试次数过多，请重新获取');
            return;
        }

        if (empty($sessionCode) || !hash_equals($sessionCode, $code)) {
            $_SESSION['forgot_code_attempts'] = $attempts + 1;
            $this->respondSubmit(false, '验证码错误');
            return;
        }

        if (time() > $expires) {
            unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_expires']);
            $this->respondSubmit(false, '验证码已过期，请重新获取');
            return;
        }

        if (!$userId) {
            $this->respondSubmit(false, '操作无效，请重新获取验证码');
            return;
        }

        // 更新密码
        UserModel::updatePassword($userId, password_hash($password, PASSWORD_BCRYPT));

        // 清除 Remember Me token，防止旧 cookie 继续登录
        \Core\RememberToken::clear($userId);

        // 说明：这里原本 DELETE FROM sessions 想强制其他设备下线，
        // 但真实 session 存在文件 / Redis 中，那张表只是旧在线统计的镜像（已随该功能移除），
        // 所以那条语句并没有真正的下线效果。要真正做到需基于会话版本号校验。

        // 清理 session
        unset($_SESSION['forgot_code'], $_SESSION['forgot_email'], $_SESSION['forgot_user_id'], $_SESSION['forgot_code_time'], $_SESSION['forgot_code_expires']);

        Event::dispatch(Events::USER_PASSWORD_RESET, [
            'user_id' => $userId,
            'action' => '密码重置',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $this->respondSubmit(true, '密码重置成功，请重新登录', '/login');
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
            $followingCount = \App\Models\Follow::getFollowingCount($userId);
            $followerCount = \App\Models\Follow::getFollowerCount($userId);
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $userId) {
                $isFollowing = \App\Models\Follow::isFollowing($_SESSION['user_id'], $userId);
                $isBlocked = \App\Models\Blacklist::isBlocked($_SESSION['user_id'], $userId);
            }
        } catch (\Throwable $e) {
            error_log('[User] follow data: ' . $e->getMessage());
        }

        // 用户动态
        $userMoments = [];
        $momentTotal = 0;
        try {
            $momentResult = \App\Models\Moment::getList(1, 10, $userId);
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
        $favorites = \App\Models\Favorite::getUserFavorites($userId, 5);

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
            $this->respondSubmit(false, '请选择头像文件');
            return;
        }

        try {
            $service = new \App\Services\UserSvc();
            $avatarUrl = $service->uploadAvatar($this->getCurrentUserId(), $file);
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }

        if (!$this->isHtmx()) {
            $this->success('头像上传成功', ['avatar' => $avatarUrl]);
            return;
        }

        // htmx：回一个新的 <img id="avatarPreview">，前端换掉旧预览图即可，不用写 JS
        $this->frontFlash('头像上传成功');
        $this->render('user/_avatar_preview', ['avatarUrl' => $avatarUrl]);
    }

    /**
     * 更新个人资料
     */
    public function updateProfile(): void
    {
        $this->requireLogin();
        $input = $this->input();
        $email = trim((string)($input['email'] ?? ''));
        $signature = trim((string)($input['signature'] ?? ''));
        $nickname = trim((string)($input['nickname'] ?? '')) ?: null;

        try {
            $service = new \App\Services\UserSvc();
            $service->updateProfile($this->getCurrentUserId(), $email, $signature, $nickname);
            $_SESSION['nickname'] = $nickname;
            $this->respondSubmit(true, '资料更新成功');
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(): void
    {
        $this->requireLogin();
        $input = $this->input();
        $oldPassword = (string)($input['old_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $confirmPassword = (string)($input['confirm_password'] ?? '');

        try {
            $service = new \App\Services\UserSvc();
            $service->changePassword($this->getCurrentUserId(), $oldPassword, $newPassword, $confirmPassword);
            $this->respondSubmit(true, '密码修改成功');
        } catch (\RuntimeException $e) {
            $this->respondSubmit(false, $e->getMessage());
            return;
        }
    }

    /**
     * 关注/取消关注
     *
     * 校验与通知这类跨聚合的编排留在控制器：
     * Follow 模型只负责 user_follows 自身的关系读写。
     */
    public function follow(): void
    {
        $this->requireLogin();

        $currentUserId = $this->getCurrentUserId();
        $targetUserId = (int)($this->input()['user_id'] ?? 0);

        if ($targetUserId === $currentUserId) {
            $this->respondFragment(false, '不能关注自己', static function (): void {});
            return;
        }
        if (\App\Models\Blacklist::isEitherBlocked($currentUserId, $targetUserId)) {
            $this->respondFragment(false, '无法关注该用户', static function (): void {});
            return;
        }
        if (!\App\Models\User::exists($targetUserId)) {
            $this->respondFragment(false, '用户不存在', static function (): void {});
            return;
        }

        $followed = \App\Models\Follow::toggleRelation($currentUserId, $targetUserId);

        // 通知放在关系表落库之后，避免事务里做额外查询
        if ($followed) {
            $me = \App\Models\User::findById($currentUserId);
            \App\Models\Notification::notify(
                $targetUserId,
                $currentUserId,
                'follow',
                ($me['username'] ?? '用户') . ' 关注了你',
                '',
                'user',
                $currentUserId
            );
        }

        if (!$this->isHtmx()) {
            $this->json(['success' => true, 'followed' => $followed]);
            return;
        }

        $this->respondFragment(true, $followed ? '已关注' : '已取消关注', function () use ($targetUserId): void {
            $this->renderProfileActions($targetUserId);
        });
    }

    /**
     * 渲染用户主页的关注/拉黑按钮片段
     *
     * 拉黑会连带隐藏关注与私信入口，所以两个按钮放在同一个片段里一起重渲染，
     * 前端不需要自己判断该隐藏谁。
     */
    private function renderProfileActions(int $targetUserId): void
    {
        $viewerId = $this->getCurrentUserId();
        $notSelf = $viewerId > 0 && $viewerId !== $targetUserId;

        $this->render('user/_profile_actions', [
            'targetId'    => $targetUserId,
            'isFollowing' => $notSelf && \App\Models\Follow::isFollowing($viewerId, $targetUserId),
            'isBlocked'   => $notSelf && \App\Models\Blacklist::isBlocked($viewerId, $targetUserId),
        ]);
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

        $favorites = \App\Models\Favorite::getUserFavorites($userId, $perPage, $offset);
        $total = \App\Models\Favorite::countUserFavorites($userId);
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

        $followers = \App\Models\Follow::getFollowerList($userId, 50);
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

        $following = \App\Models\Follow::getFollowingList($userId, 50);
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

        $result = \App\Models\CreditLog::getUserCreditLogs($userId, $page, 20);

        // 预加载 post 类型记录的 thread_id，避免视图中 N+1 查询
        $logs = $result['logs'];
        $postIds = [];
        foreach ($logs as $log) {
            if (($log['related_type'] ?? '') === 'post' && !empty($log['related_id'])) {
                $postIds[] = (int)$log['related_id'];
            }
        }
        if (!empty($postIds)) {
            $postThreadMap = Post::threadIdMap($postIds);
            foreach ($logs as &$log) {
                if (($log['related_type'] ?? '') === 'post') {
                    $log['_thread_id'] = $postThreadMap[(int)$log['related_id']] ?? null;
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
        $ranking = \App\Models\User::getCreditRanking(50);

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
        $targetUserId = (int)($this->input()['user_id'] ?? 0);
        try {
            $result = \App\Models\Blacklist::toggle($this->getCurrentUserId(), $targetUserId);
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
            return;
        }

        if (!$this->isHtmx()) {
            $this->json(['success' => true, 'blocked' => $result['blocked']]);
            return;
        }

        $this->respondFragment(true, $result['blocked'] ? '已拉黑' : '已解除拉黑', function () use ($targetUserId): void {
            $this->renderProfileActions($targetUserId);
        });
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

        $list = \App\Models\Blacklist::getList($userId, $perPage, $offset);
        $total = \App\Models\Blacklist::getCount($userId);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $this->render('user/blacklist', [
            'list' => $list,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
