<?php
/**
 * 用户业务逻辑层
 */

namespace App\Services;

use App\Models\Attachment;
use App\Models\Checkin;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\Forum;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Thread;
use App\Models\User;
use Core\Cache;

class UserSvc
{
    /** 请求级实体缓存，避免同一请求内重复查库 */
    private static array $entityCache = [];

    /**
     * 显示名：优先昵称，无昵称回退用户名
     */
    public static function displayName(?array $user): string
    {
        if ($user === null) {
            return '';
        }
        return (!empty($user['nickname'])) ? $user['nickname'] : ($user['username'] ?? '');
    }

    /**
     * 返回昵称颜色的 style 属性，无颜色返回空字符串
     */
    public static function nicknameStyle(?array $user): string
    {
        if ($user === null || empty($user['nickname_color'])) {
            return '';
        }
        $color = $user['nickname_color'];
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return '';
        }
        return ' style="color:' . htmlspecialchars($color) . ';"';
    }

    /**
     * 用户注册
     *
     * @throws \RuntimeException 验证失败
     */
    public function register(string $username, string $email, string $password, string $passwordConfirm, ?string $nickname = null): int
    {
        // 检查是否开放注册
        if (!SettingSvc::getBool('user_register_enabled', true)) {
            throw new \RuntimeException('站点暂未开放注册');
        }

        if (empty($username) || empty($email) || empty($password)) {
            throw new \RuntimeException('请填写完整信息');
        }

        // 用户名长度（从设置读取）
        $minLen = SettingSvc::getInt('user_username_min_length', 3);
        $maxLen = SettingSvc::getInt('user_username_max_length', 20);
        $usernameLen = mb_strlen($username);
        if ($usernameLen < $minLen || $usernameLen > $maxLen) {
            throw new \RuntimeException("用户名长度为 {$minLen}-{$maxLen} 个字符");
        }

        // 禁止注册的用户名
        $banned = SettingSvc::get('user_banned_usernames', '');
        if ($banned !== '') {
            $bannedList = array_map('trim', explode(',', strtolower($banned)));
            if (in_array(strtolower($username), $bannedList, true)) {
                throw new \RuntimeException('该用户名不允许注册');
            }
        }

        // 昵称验证
        $nickname = $this->validateNickname($nickname);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('邮箱格式不正确');
        }

        // 密码策略
        $this->validatePassword($password);

        if ($password !== $passwordConfirm) {
            throw new \RuntimeException('两次密码不一致');
        }

        // 唯一性检查
        if (User::findByUsername($username)) {
            throw new \RuntimeException('用户名已存在');
        }

        if (User::findByEmail($email)) {
            throw new \RuntimeException('邮箱已被注册');
        }

        // 创建用户（使用设置中的默认用户组、积分和头像）
        $defaultGroup = SettingSvc::getInt('user_default_group', 1);
        $defaultCredits = SettingSvc::getInt('user_default_credits', 0);
        $defaultAvatar = SettingSvc::get('user_default_avatar', '') ?: '/assets/images/default-avatar.png';
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        return User::create($username, $email, $hashedPassword, $defaultGroup, $defaultCredits, $nickname, $defaultAvatar);
    }

    /**
     * 注册后回调：更新运行时统计
     */
    public function onRegistered(int $userId): void
    {
        RuntimeSvc::increment('users');
    }

    /**
     * 用户登录
     *
     * @throws \RuntimeException 验证失败
     */
    public function login(string $identity, string $password, string $ip): array
    {
        if (empty($identity) || empty($password)) {
            throw new \RuntimeException('请填写用户名和密码');
        }

        // 登录失败次数限制：IP + 账号 双维度
        // 只按 IP 计数时，攻击者换 IP 就能继续爆破同一个账号；
        // 只按账号计数时，又会被人拿来锁死别人的账号（所以两个维度都要有，且都要原子递增）。
        $maxAttempts = max(1, SettingSvc::getInt('user_login_max_attempts', 5));
        $lockMinutes = SettingSvc::getInt('user_login_lock_minutes', 30);
        $window = $lockMinutes * 60;
        $ipKey = 'login:attempts:ip:' . md5($ip);
        // 用哈希而不是明文用户名，避免缓存键名里出现用户输入
        $accountKey = 'login:attempts:user:' . md5(mb_strtolower(trim($identity)));

        if ($this->tooManyLoginAttempts($ipKey, $maxAttempts)
            || $this->tooManyLoginAttempts($accountKey, $maxAttempts)) {
            throw new \RuntimeException("登录失败次数过多，请 {$lockMinutes} 分钟后再试");
        }

        $user = User::findByUsernameOrEmail($identity);

        if (!$user || !password_verify($password, $user['password'])) {
            // 原子递增（原来的 read-modify-write 在并发下会丢计数）
            Cache::incrementWithLimit($ipKey, $maxAttempts, $window);
            Cache::incrementWithLimit($accountKey, $maxAttempts, $window);
            throw new \RuntimeException('用户名或密码错误');
        }

        // 登录成功，清除失败计数
        Cache::delete($ipKey);
        Cache::delete($accountKey);

        // 更新登录信息
        User::updateLoginInfo($user['id'], $ip);

        return $user;
    }

    /**
     * 登录失败次数是否已达上限
     */
    private function tooManyLoginAttempts(string $key, int $maxAttempts): bool
    {
        return (int)(Cache::get($key) ?? 0) >= $maxAttempts;
    }

    /**
     * 获取用户资料（请求级缓存 + Redis 缓存）
     */
    public function getProfile(int $userId): ?array
    {
        // 请求级缓存：同一请求内直接返回
        if (isset(self::$entityCache[$userId])) {
            return self::$entityCache[$userId];
        }

        $profile = Cache::get("user:profile:{$userId}", function () use ($userId) {
            $profile = User::getProfile($userId);
            if ($profile) {
                // 添加等级信息
                $credits = (int)($profile['credits'] ?? 0);
                $levelInfo = LevelSvc::getLevelProgress($credits);
                $profile['level_info'] = $levelInfo;
                $profile['level_name'] = $levelInfo['current_level']['name'] ?? '学前班';
                $profile['level_num'] = $levelInfo['current_level']['level'] ?? 0;
                $profile['level_color'] = $levelInfo['current_level']['color'] ?? '#999999';
            }
            return $profile;
        }, 600);

        if ($profile) {
            self::$entityCache[$userId] = $profile;
        }

        return $profile;
    }

    /**
     * 过滤敏感字段，返回可安全输出到前端的用户信息
     * 用于 API 响应、模板渲染等场景
     */
    public static function safeInfo(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        unset(
            $user['password'],
            $user['remember_token'],
            $user['api_token']
        );

        // 根据站点设置决定是否隐藏邮箱和登录 IP
        if (!SettingSvc::getBool('user_show_email', false)) {
            unset($user['email']);
        }
        if (!SettingSvc::getBool('user_show_login_ip', false)) {
            unset($user['login_ip']);
        }

        return $user;
    }

    /**
     * 清除用户请求级缓存
     */
    public static function clearEntityCache(int $userId = 0): void
    {
        if ($userId > 0) {
            unset(self::$entityCache[$userId]);
        } else {
            self::$entityCache = [];
        }
    }

    /**
     * 获取用户的帖子列表（支持分页）
     */
    public function getUserThreads(int $userId, int $perPage = 10, int $page = 1): array
    {
        
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $threads = Thread::getByUser($userId, $perPage, $offset);
        $total = Thread::countByUser($userId);
        return [
            'threads' => $threads,
            'total' => (int)$total,
            'page' => $page,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * 获取用户的回复列表
     */
    public function getUserReplies(int $userId, int $limit = 10): array
    {
        return Post::listByUserWithThread($userId, $limit);
    }

    /**
     * 增加发帖数
     */
    public function incrementThreadCount(int $userId): void
    {
        User::incrementThreadCount($userId);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);
    }

    /**
     * 增加回复数
     */
    public function incrementPostCount(int $userId): void
    {
        User::incrementPostCount($userId);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);
    }

    /**
     * 减少发帖数
     */
    public function decrementThreadCount(int $userId): void
    {
        User::decrementThreadCount($userId);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);
    }

    /**
     * 减少回复数
     */
    public function decrementPostCount(int $userId): void
    {
        User::decrementPostCount($userId);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);
    }

    /**
     * 上传头像
     *
     * @throws \RuntimeException 验证失败
     */
    public function uploadAvatar(int $userId, array $file): string
    {
        // 验证文件
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('文件上传失败');
        }

        // 从设置读取允许的格式
        $formatsStr = SettingSvc::get('user_avatar_formats', 'jpg,jpeg,png,gif,webp');
        $allowedExts = array_map('trim', explode(',', strtolower($formatsStr)));

        // MIME 类型映射
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!isset($mimeMap[$mimeType])) {
            throw new \RuntimeException('不支持的图片格式');
        }

        $ext = $mimeMap[$mimeType];
        // jpg 和 jpeg 视为同一格式
        $extCheck = ($ext === 'jpg') ? ['jpg', 'jpeg'] : [$ext];
        if (empty(array_intersect($extCheck, $allowedExts))) {
            throw new \RuntimeException('仅支持 ' . strtoupper($formatsStr) . ' 格式');
        }

        // 从设置读取大小限制（KB）
        $maxSizeKB = SettingSvc::getInt('user_avatar_max_size', 2048);
        if ($file['size'] > $maxSizeKB * 1024) {
            throw new \RuntimeException("头像文件不能超过 {$maxSizeKB}KB");
        }

        // 生成文件名
        $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = APP_PATH . 'public/uploads/avatars/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $destPath = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('文件保存失败');
        }

        // 更新数据库
        $avatarUrl = '/uploads/avatars/' . $filename;
        User::updateAvatar($userId, $avatarUrl);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);

        return $avatarUrl;
    }

    /**
     * 更新个人资料
     *
     * @throws \RuntimeException 验证失败
     */
    public function updateProfile(int $userId, string $email, string $signature, ?string $nickname = null): void
    {
        // 检查是否允许修改邮箱
        if (!SettingSvc::getBool('user_allow_change_email', true)) {
            $currentUser = User::findById($userId);
            if ($currentUser && $currentUser['email'] !== $email) {
                throw new \RuntimeException('不允许修改邮箱地址');
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('邮箱格式不正确');
        }

        $sigMaxLen = SettingSvc::getInt('user_signature_max_length', 100);
        if ($sigMaxLen === 0 && mb_strlen($signature) > 0) {
            throw new \RuntimeException('暂不支持设置签名');
        }
        if (mb_strlen($signature) > $sigMaxLen) {
            throw new \RuntimeException("签名不能超过 {$sigMaxLen} 个字符");
        }

        // 昵称验证
        $nickname = $this->validateNickname($nickname, $userId);

        // 检查邮箱是否被其他用户占用
        $existUser = User::findByEmail($email);
        if ($existUser && (int)$existUser['id'] !== $userId) {
            throw new \RuntimeException('该邮箱已被其他用户使用');
        }

        User::updateProfile($userId, $email, $signature, $nickname);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);
    }

    /**
     * 修改密码
     *
     * @throws \RuntimeException 验证失败
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword, string $confirmPassword): void
    {
        $this->validatePassword($newPassword);

        if ($newPassword !== $confirmPassword) {
            throw new \RuntimeException('两次密码不一致');
        }

        $user = User::findById($userId);
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }

        if (!password_verify($oldPassword, $user['password'])) {
            throw new \RuntimeException('原密码错误');
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        User::updatePassword($userId, $hashedPassword);

        // 密码修改后清除 Remember Me token
        \Core\RememberToken::clear($userId);

        // 说明：原本这里 DELETE FROM sessions 想踢掉其它设备，但真实 session 在文件 / Redis 里，
        // 该表只是旧在线统计的镜像（已随该功能移除），因此那条语句实际不产生任何效果。
        session_regenerate_id(true);
    }

    /**
     * 删除用户（软删除 + 级联清理）
     * 清理用户的帖子、回复、收藏、关注、通知、头像、Remember Token 等
     */
    public function deleteUser(int $userId): void
    {
        $user = User::findById($userId);
        if (!$user) {
            throw new \RuntimeException('用户不存在');
        }

        \Core\Database::beginTransaction();

        try {
            // 1. 软删除用户
            User::softDelete($userId);

            // 2. 软删除用户的帖子，并批量更新板块统计
            $threads = Thread::rowsForUserDeletion($userId);
            $deletedThreads = count($threads);
            $deletedPosts = 0;

            if ($deletedThreads > 0) {
                // 批量软删除帖子
                Thread::softDeleteByIds(array_column($threads, 'id'), time());

                // 按板块聚合 thread_count 和 reply_count（post_count）
                $forumThreadDec = [];
                $forumPostDec = [];
                foreach ($threads as $t) {
                    $fid = (int)$t['forum_id'];
                    $forumThreadDec[$fid] = ($forumThreadDec[$fid] ?? 0) + 1;
                    $rc = (int)($t['reply_count'] ?? 0);
                    if ($rc > 0) {
                        $forumPostDec[$fid] = ($forumPostDec[$fid] ?? 0) + $rc;
                        $deletedPosts += $rc;
                    }
                }

                // 一次 CASE WHEN 更新板块的 thread_count 与 post_count
                Forum::decrementCountsBulk($forumThreadDec, $forumPostDec);
            }

            // 3. 软删除用户的回复，并批量更新帖子回复数和板块统计
            $posts = Post::rowsForUserDeletion($userId);

            if (!empty($posts)) {
                // 批量软删除回复
                Post::softDeleteByIds(array_column($posts, 'id'), time());
                $deletedPosts += count($posts);

                // 按 thread_id 聚合回复数扣减
                $threadDec = [];
                $forumPostDec2 = [];
                foreach ($posts as $p) {
                    $tid = (int)$p['thread_id'];
                    $threadDec[$tid] = ($threadDec[$tid] ?? 0) + 1;
                    if (!empty($p['forum_id'])) {
                        $fid = (int)$p['forum_id'];
                        $forumPostDec2[$fid] = ($forumPostDec2[$fid] ?? 0) + 1;
                    }
                }

                // 批量更新 threads.reply_count
                Thread::decrementReplyCountBulk($threadDec);

                // 批量更新 forums.post_count
                if (!empty($forumPostDec2)) {
                    Forum::decrementCountsBulk([], $forumPostDec2);
                }
            }

            // 4. 清理关联数据
            Favorite::deleteByUser($userId);
            Follow::deleteByUser($userId);
            Notification::deleteByUser($userId);
            PostLike::deleteByUser($userId);
            Checkin::deleteByUser($userId);

            // 5. 清理附件文件记录（物理文件由定时任务清理）
            Attachment::softDeleteByUser($userId, time());

            \Core\Database::commit();
        } catch (\Throwable $e) {
            \Core\Database::rollBack();
            throw new \RuntimeException('删除用户失败: ' . $e->getMessage());
        }

        // 事务外操作：RuntimeSvc 在事务提交后调用，避免回滚时计数不一致
        if ($deletedThreads > 0) RuntimeSvc::decrement('threads', $deletedThreads);
        if ($deletedPosts > 0) RuntimeSvc::decrement('posts', $deletedPosts);
        RuntimeSvc::decrement('users');
        \Core\RememberToken::clear($userId);
        Cache::delete("user:profile:{$userId}");
        self::clearEntityCache($userId);

        // 删除头像文件
        if (!empty($user['avatar']) && $user['avatar'] !== '/assets/images/default-avatar.png') {
            $avatarPath = APP_PATH . 'public' . $user['avatar'];
            if (file_exists($avatarPath)) {
                @unlink($avatarPath);
            }
        }
    }

    /**
     * 昵称校验（注册、修改资料共用）
     * 返回处理后的昵称（空字符串转 null）
     */
    private function validateNickname(?string $nickname, int $excludeUserId = 0): ?string
    {
        if ($nickname === null || trim($nickname) === '') {
            return null;
        }

        $nickname = trim($nickname);
        $len = mb_strlen($nickname);
        if ($len < 2 || $len > 20) {
            throw new \RuntimeException('昵称长度为 2-20 个字符');
        }

        // 唯一性检查
        $exist = User::findByNickname($nickname);
        if ($exist && (int)$exist['id'] !== $excludeUserId) {
            throw new \RuntimeException('该昵称已被使用');
        }

        return $nickname;
    }

    /**
     * 密码策略校验（注册、修改密码、重置密码共用）
     *
     * @throws \RuntimeException
     */
    private function validatePassword(string $password): void
    {
        $minLen = SettingSvc::getInt('user_password_min_length', 6);
        if (strlen($password) < $minLen) {
            throw new \RuntimeException("密码长度至少 {$minLen} 个字符");
        }

        if (SettingSvc::getBool('user_password_require_mixed', false)) {
            if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                throw new \RuntimeException('密码必须同时包含字母和数字');
            }
        }
    }
}
