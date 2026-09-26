<?php
/**
 * 后台 - 用户管理（用户列表、用户操作、用户设置、在线用户、积分记录）
 */

namespace App\Controllers\Admin;

use Core\Cache;
use Core\Event;
use App\Events\Events;
use App\Models\User;

class UserController extends AdminBase
{
    /**
     * 从查询串里取出列表筛选条件（纯取值；筛选/排序/SQL 都在 User::adminQuery）
     */
    private function userFilters(): array
    {
        return [
            'search'   => trim($_GET['search'] ?? ''),
            'uid'      => trim($_GET['uid'] ?? ''),
            'group_id' => (string)($_GET['group_id'] ?? ''),
            'ip'       => trim($_GET['ip'] ?? ''),
            'sort'     => (string)($_GET['sort'] ?? 'id'),
            'dir'      => (string)($_GET['dir'] ?? 'desc'),
        ];
    }

    public function users(): void
    {
        $this->requireAdmin();
        $this->renderUsersPage();
    }

    /**
     * 用户列表数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchUsers(int $page, int $limit): array
    {
        return User::adminList($this->userFilters(), $page, $limit);
    }

    /**
     * 渲染用户管理页面片段（GET 与增删改后的刷新共用同一渲染路径）
     *
     * 注意：变更操作的 URL 上会带上当前筛选/分页的查询串，
     * 这样操作完成后重绘页面不会把用户的筛选条件和页码丢掉。
     */
    private function renderUsersPage(): void
    {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchUsers($page, $limit);

        $this->renderAdmin('admin/users', [
            'pageTitle'     => '用户管理',
            'groups'        => \App\Models\UserGroup::all(),
            'users'         => $result['rows'],
            'total'         => $result['total'],
            'page'          => $page,
            'pages'         => max(1, (int)ceil($result['total'] / $limit)),
            'limit'         => $limit,
            'filters'       => [
                'search'   => trim($_GET['search'] ?? ''),
                'uid'      => trim($_GET['uid'] ?? ''),
                'group_id' => trim((string)($_GET['group_id'] ?? '')),
                'ip'       => trim($_GET['ip'] ?? ''),
            ],
            'sort'          => (string)($_GET['sort'] ?? 'id'),
            'dir'           => strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'adminGroupId'  => \App\Services\PermissionSvc::ADMIN_GROUP_ID,
            'currentUserId' => (int)($_SESSION['user_id'] ?? 0),
        ], 'users');
    }

    /**
     * 用户表单片段（layer iframe 弹层用）
     *
     * ?action=add                → 添加用户
     * ?action=manage&id=N        → 管理面板（资料 / 安全 / 用户组 / 危险操作）
     */
    public function userForm(): void
    {
        $this->requireAdmin();

        $action = (string)($_GET['action'] ?? 'add');
        $id = max(0, (int)($_GET['id'] ?? 0));

        $groups = \App\Models\UserGroup::all();

        if ($action === 'add') {
            $this->renderAdmin('admin/partials/user_add', [
                'pageTitle' => '添加用户',
                'groups'    => $groups,
            ], 'users');
            return;
        }

        if ($id <= 0) {
            http_response_code(400);
            $this->renderAdmin('admin/partials/error', ['pageTitle' => '参数错误', 'message' => '参数错误']);
            return;
        }

        $user = User::adminDetail($id);
        if (!$user) {
            http_response_code(404);
            $this->renderAdmin('admin/partials/error', ['pageTitle' => '用户不存在', 'message' => '用户不存在']);
            return;
        }

        $this->renderAdmin('admin/partials/user_manage', [
            'pageTitle'     => '管理用户',
            'user'          => $user,
            'groups'        => $groups,
            'isSelf'        => $id === (int)($_SESSION['user_id'] ?? 0),
            'adminGroupId'  => \App\Services\PermissionSvc::ADMIN_GROUP_ID,
        ], 'users');
    }

    /**
     * 用户列表 API（保留，供外部 AJAX 调用）
     */
    public function usersApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchUsers($page, $limit);
        $this->jsonTable($result['rows'], $result['total']);
    }

    /**
     * 单个用户的操作入口
     *
     * 业务逻辑抽到 applyUserAction()，这里只负责把结果翻译成响应：
     * 后台走 respondMutation() 的 JSON 分支（AdminUi 弹 layer.msg 再 reload 表格），
     * 旧的 JSON 调用方格式不变。
     */
    public function userUpdate(): void
    {
        $this->requireAdmin();

        $input = $this->input();

        // get_detail 是只读查询，保持纯 JSON（供外部调用方使用）
        if (($input['action'] ?? '') === 'get_detail') {
            $user = User::adminDetail((int)($input['user_id'] ?? 0), true);
            if (!$user) {
                $this->error('用户不存在');
                return;
            }
            $this->success('ok', ['user' => $user]);
            return;
        }

        $result = $this->applyUserAction($input);
        $this->respondMutation($result['ok'], $result['message'], fn() => $this->renderUsersPage());
    }

    /**
     * 执行单个用户操作
     *
     * @return array{ok: bool, message: string}
     */
    private function applyUserAction(array $input): array
    {
        $action = (string)($input['action'] ?? '');

        if ($action === 'add_user') {
            return $this->addUser($input);
        }

        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['ok' => false, 'message' => '无效的用户ID'];
        }

        if ($userId === (int)$_SESSION['user_id'] && in_array($action, ['ban', 'delete'], true)) {
            return ['ok' => false, 'message' => '不能对自己执行此操作'];
        }

        switch ($action) {
            case 'change_group':
                $groupId = (int)($input['group_id'] ?? 1);
                if (!\App\Models\UserGroup::exists($groupId)) {
                    return ['ok' => false, 'message' => '无效的用户组'];
                }
                User::setGroup($userId, $groupId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '修改用户组',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "将用户 ID:{$userId} 的用户组改为 {$groupId}",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '用户组已更新'];

            case 'edit_profile':
                return $this->editUserProfile($input, $userId);

            case 'reset_password':
                $newPassword = (string)($input['new_password'] ?? '');
                if (strlen($newPassword) < 6) {
                    return ['ok' => false, 'message' => '密码长度至少 6 个字符'];
                }
                $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                User::updatePassword($userId, $hashed);
                \Core\RememberToken::clear($userId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '重置密码',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "重置用户 ID:{$userId} 的密码",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '密码已重置'];

            case 'adjust_credits':
                $amount = (int)($input['amount'] ?? 0);
                $reason = trim((string)($input['reason'] ?? '')) ?: '管理员调整';
                if ($amount === 0) {
                    return ['ok' => false, 'message' => '积分变动不能为 0'];
                }
                $creditSvc = new \App\Services\CreditSvc();
                if ($amount > 0) {
                    $creditSvc->addCredits($userId, $amount, 'admin', $reason, 'admin', (int)$_SESSION['user_id']);
                } else {
                    $ok = $creditSvc->deductCredits($userId, abs($amount), 'admin', $reason, 'admin', (int)$_SESSION['user_id']);
                    if (!$ok) {
                        return ['ok' => false, 'message' => '扣除失败，积分不足'];
                    }
                }
                Cache::delete("user:profile:{$userId}");
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '调整积分',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "调整用户 ID:{$userId} 积分 {$amount}，原因：{$reason}",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '积分已调整'];

            case 'ban':
                $banGroupId = $this->getOrCreateBanGroup();
                User::setGroup($userId, $banGroupId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '封禁用户',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "封禁用户 ID:{$userId}",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '用户已封禁'];

            case 'unban':
                $defaultGroup = \App\Services\SettingSvc::getInt('user_default_group', 1);
                User::setGroup($userId, $defaultGroup);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '解封用户',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "解封用户 ID:{$userId}，恢复为默认用户组",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '用户已解封'];

            case 'delete':
                $userSvc = new \App\Services\UserSvc();
                $userSvc->deleteUser($userId);
                Event::dispatch(Events::ADMIN_USER_DELETED, [
                    'action' => '删除用户',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "删除用户 ID:{$userId}（含级联清理）",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                return ['ok' => true, 'message' => '用户已删除'];

            default:
                return ['ok' => false, 'message' => '未知操作'];
        }
    }

    /**
     * 添加用户
     *
     * @return array{ok: bool, message: string}
     */
    private function addUser(array $input): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $groupId = (int)($input['group_id'] ?? 1);
        $nickname = trim((string)($input['nickname'] ?? '')) ?: null;

        if ($username === '' || $email === '' || $password === '') {
            return ['ok' => false, 'message' => '用户名、邮箱和密码不能为空'];
        }
        $uLen = mb_strlen($username);
        if ($uLen < 3 || $uLen > 20) {
            return ['ok' => false, 'message' => '用户名长度为 3-20 个字符'];
        }
        if (preg_match('/[\x00-\x1f\x7f<>"\'&\\\\\/]/', $username)) {
            return ['ok' => false, 'message' => '用户名包含非法字符'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'message' => '密码长度至少 6 个字符'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }
        if (User::usernameExists($username)) {
            return ['ok' => false, 'message' => '用户名已存在'];
        }
        if (User::emailExists($email)) {
            return ['ok' => false, 'message' => '邮箱已被注册'];
        }

        if ($nickname !== null) {
            $nLen = mb_strlen($nickname);
            if ($nLen < 2 || $nLen > 20) {
                return ['ok' => false, 'message' => '昵称长度为 2-20 个字符'];
            }
            if (User::nicknameExists($nickname)) {
                return ['ok' => false, 'message' => '昵称已被使用'];
            }
        }

        // 用户组必须是真实存在的，否则会建出一个没有权限归属的账号
        if (!\App\Models\UserGroup::exists($groupId)) {
            return ['ok' => false, 'message' => '无效的用户组'];
        }

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $newId = User::create($username, $email, $hashed, $groupId, 0, $nickname);
        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '添加用户',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "管理员添加用户 {$username} (ID:{$newId})",
            'target_type' => 'user',
            'target_id' => $newId,
        ]);

        return ['ok' => true, 'message' => '用户已创建'];
    }

    /**
     * 编辑用户资料
     *
     * @return array{ok: bool, message: string}
     */
    private function editUserProfile(array $input, int $userId): array
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $signature = trim((string)($input['signature'] ?? ''));
        $nickname = trim((string)($input['nickname'] ?? '')) ?: null;
        $nicknameColor = trim((string)($input['nickname_color'] ?? '')) ?: null;

        // 校验颜色格式
        if ($nicknameColor !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $nicknameColor)) {
            $nicknameColor = null;
        }

        if ($username === '' || $email === '') {
            return ['ok' => false, 'message' => '用户名和邮箱不能为空'];
        }
        if (preg_match('/[\x00-\x1f\x7f<>"\'&\\\\\/]/', $username)) {
            return ['ok' => false, 'message' => '用户名包含非法字符'];
        }
        $uLen = mb_strlen($username);
        if ($uLen < 2 || $uLen > 20) {
            return ['ok' => false, 'message' => '用户名长度为 2-20 个字符'];
        }
        if (mb_strlen($signature) > 200) {
            return ['ok' => false, 'message' => '个性签名最多 200 个字符'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }

        if ($nickname !== null) {
            $nLen = mb_strlen($nickname);
            if ($nLen < 2 || $nLen > 20) {
                return ['ok' => false, 'message' => '昵称长度为 2-20 个字符'];
            }
            if (User::nicknameTakenByOther($nickname, $userId)) {
                return ['ok' => false, 'message' => '昵称已被占用'];
            }
        }

        if (User::usernameTakenByOther($username, $userId)) {
            return ['ok' => false, 'message' => '用户名已被占用'];
        }
        if (User::emailTakenByOther($email, $userId)) {
            return ['ok' => false, 'message' => '邮箱已被占用'];
        }

        User::adminUpdate($userId, $username, $nickname, $email, $signature, $nicknameColor);
        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '编辑用户资料',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "编辑用户 ID:{$userId} 的资料",
            'target_type' => 'user',
            'target_id' => $userId,
        ]);

        return ['ok' => true, 'message' => '用户资料已更新'];
    }

    /**
     * 取「禁止用户组」的 ID，不存在则创建
     */
    private function getOrCreateBanGroup(): int
    {
        return \App\Models\UserGroup::ensureByName(\App\Models\UserGroup::BANNED_NAME);
    }

    /**
     * 批量操作用户
     */
    public function userBatchAction(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $action = (string)($input['action'] ?? '');
        $ids = $input['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            $this->respondMutation(false, '请选择用户', fn() => $this->renderUsersPage());
            return;
        }

        // 过滤为正整数
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
        if (empty($ids)) {
            $this->respondMutation(false, '无效的用户ID', fn() => $this->renderUsersPage());
            return;
        }
        if (count($ids) > 100) {
            $this->respondMutation(false, '单次最多操作100个用户', fn() => $this->renderUsersPage());
            return;
        }

        // 排除自己
        $selfId = (int)$_SESSION['user_id'];
        if (in_array($action, ['ban', 'delete'], true)) {
            $ids = array_values(array_filter($ids, fn($id) => $id !== $selfId));
            if (empty($ids)) {
                $this->respondMutation(false, '不能对自己执行此操作', fn() => $this->renderUsersPage());
                return;
            }
        }

        $result = $this->applyBatchAction($action, $ids, $input);
        $this->respondMutation($result['ok'], $result['message'], fn() => $this->renderUsersPage());
    }

    /**
     * 执行批量操作
     *
     * @return array{ok: bool, message: string}
     */
    private function applyBatchAction(string $action, array $ids, array $input): array
    {
        $count = count($ids);
        $selfId = (int)$_SESSION['user_id'];

        switch ($action) {
            case 'ban':
                $banGroupId = $this->getOrCreateBanGroup();
                User::setGroupBulk($ids, $banGroupId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量封禁',
                    'admin_id' => $selfId,
                    'detail' => "批量封禁 {$count} 个用户: " . implode(',', $ids),
                    'target_type' => 'user',
                ]);
                return ['ok' => true, 'message' => "已封禁 {$count} 个用户"];

            case 'unban':
                $defaultGroup = \App\Services\SettingSvc::getInt('user_default_group', 1);
                User::setGroupBulk($ids, $defaultGroup);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量解封',
                    'admin_id' => $selfId,
                    'detail' => "批量解封 {$count} 个用户: " . implode(',', $ids),
                    'target_type' => 'user',
                ]);
                return ['ok' => true, 'message' => "已解封 {$count} 个用户"];

            case 'change_group':
                $groupId = (int)($input['group_id'] ?? 0);
                if ($groupId <= 0) {
                    return ['ok' => false, 'message' => '请选择用户组'];
                }
                if (!\App\Models\UserGroup::exists($groupId)) {
                    return ['ok' => false, 'message' => '用户组不存在'];
                }
                User::setGroupBulk($ids, $groupId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量修改用户组',
                    'admin_id' => $selfId,
                    'detail' => "批量修改 {$count} 个用户的用户组为 {$groupId}",
                    'target_type' => 'user',
                ]);
                return ['ok' => true, 'message' => "已修改 {$count} 个用户的用户组"];

            case 'delete':
                $userSvc = new \App\Services\UserSvc();
                $deleted = 0;
                foreach ($ids as $uid) {
                    try {
                        $userSvc->deleteUser($uid);
                        $deleted++;
                    } catch (\Throwable $e) {
                        error_log("[Admin:User] batch delete uid={$uid} failed: " . $e->getMessage());
                    }
                }
                Event::dispatch(Events::ADMIN_USER_DELETED, [
                    'action' => '批量删除',
                    'admin_id' => $selfId,
                    'detail' => "批量删除 {$deleted} 个用户: " . implode(',', $ids),
                    'target_type' => 'user',
                ]);
                return ['ok' => true, 'message' => "已删除 {$deleted} 个用户"];

            default:
                return ['ok' => false, 'message' => '未知操作'];
        }
    }

    // ==================== 用户设置 ====================

    /**
     * 用户设置：布尔开关型字段
     *
     * 页面里每个都配了 hidden=0 + checkbox=1，所以未勾选时会提交 "0"，
     * 不会出现「关不掉」的情况（旧实现依赖前端 JS 补 false，服务端拿不到就跳过）。
     */
    private const USER_SETTING_BOOL_KEYS = [
        'user_register_enabled', 'user_register_verify', 'user_allow_rename',
        'user_password_require_mixed', 'user_allow_change_email',
        'user_show_email', 'user_show_login_ip',
    ];

    /** 用户设置：文本/数字型字段 */
    private const USER_SETTING_TEXT_KEYS = [
        'user_default_group', 'user_default_credits', 'user_banned_usernames',
        'user_username_min_length', 'user_username_max_length',
        'user_login_max_attempts', 'user_login_lock_minutes',
        'user_password_min_length',
        'user_avatar_max_size', 'user_avatar_formats', 'user_default_avatar',
        'user_signature_max_length', 'user_bio_max_length',
    ];

    public function userSettings(): void
    {
        $this->requireAdmin();
        $this->renderUserSettingsPage();
    }

    /**
     * 读取全部 user_* 设置（键 => 值）
     */
    private function fetchUserSettings(): array
    {
        $settings = \App\Services\SettingSvc::allWithPrefix('user_');

        return $settings;
    }

    /**
     * 渲染用户设置页面片段（GET 与保存后的刷新共用同一渲染路径）
     */
    private function renderUserSettingsPage(): void
    {
        $this->renderAdmin('admin/user_settings', [
            'pageTitle' => '用户设置',
            'settings'  => $this->fetchUserSettings(),
            'groups'    => \App\Models\UserGroup::all(),
        ], 'user_settings');
    }

    public function userSettingsSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();

        // 用户名长度上下限必须自洽，否则注册会被卡死在永远无法满足的规则上
        $minLen = (int)($input['user_username_min_length'] ?? 3);
        $maxLen = (int)($input['user_username_max_length'] ?? 20);
        if ($minLen > $maxLen) {
            $this->respondMutation(false, '用户名最小长度不能大于最大长度', fn() => $this->renderUserSettingsPage());
            return;
        }
        if ($minLen < 1) {
            $this->respondMutation(false, '用户名最小长度至少为 1', fn() => $this->renderUserSettingsPage());
            return;
        }

        $values = [];

        foreach (self::USER_SETTING_BOOL_KEYS as $key) {
            $values[$key] = !empty($input[$key]) ? '1' : '0';
        }

        foreach (self::USER_SETTING_TEXT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = trim((string)$input[$key]);
            }
        }

        \App\Models\Setting::setMany($values);

        \App\Services\SettingSvc::clearCache();
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改用户设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了用户相关设置',
            'target_type' => 'settings',
        ]);
        $this->respondMutation(true, '用户设置已保存', fn() => $this->renderUserSettingsPage());
    }

    // ==================== 会员设置 ====================

    public function vipSettings(): void
    {
        $this->requireAdmin();
        $this->renderVipSettingsPage();
    }

    /**
     * 会员等级配置（列表形式，含 level 字段）
     *
     * 注意：等级数量完全由数据决定，不再像旧前端那样写死 4 个，
     * 否则后台一旦配了第 5 个等级，保存时会被静默丢掉。
     */
    private function fetchVipLevels(): array
    {
        $vipLevelsJson = \App\Services\SettingSvc::get('vip_levels', '');
        $vipLevels = json_decode($vipLevelsJson, true);

        if (is_array($vipLevels) && !empty($vipLevels)) {
            // 统一补上 level 字段并排序，避免存进来的 JSON 缺字段/乱序
            $normalized = [];
            foreach ($vipLevels as $lv) {
                if (!is_array($lv)) {
                    continue;
                }
                $lvl = (int)($lv['level'] ?? 0);
                if ($lvl < 1) {
                    continue;
                }
                $normalized[$lvl] = [
                    'level'    => $lvl,
                    'name'     => (string)($lv['name'] ?? ''),
                    'color'    => (string)($lv['color'] ?? '#999999'),
                    'icon'     => (string)($lv['icon'] ?? ''),
                    'price'    => (int)($lv['price'] ?? 0),
                    'benefits' => is_array($lv['benefits'] ?? null) ? $lv['benefits'] : [],
                ];
            }
            if (!empty($normalized)) {
                ksort($normalized);
                return array_values($normalized);
            }
        }

        // 回退到 VipSvc 的内置默认等级（getConfig() 含 level 0 普通用户，这里只取 level >= 1）
        $all = \App\Services\VipSvc::getConfig();
        $levels = [];
        foreach ($all as $lvl => $info) {
            if ($lvl < 1) {
                continue;
            }
            $info['level'] = $lvl;
            $info['benefits'] = is_array($info['benefits'] ?? null) ? $info['benefits'] : [];
            $levels[] = $info;
        }

        return $levels;
    }

    /**
     * 渲染会员设置页面片段（GET 与保存后的刷新共用同一渲染路径）
     */
    private function renderVipSettingsPage(): void
    {
        $this->renderAdmin('admin/vip_settings', [
            'pageTitle'  => '会员设置',
            'vipEnabled' => \App\Services\SettingSvc::getBool('vip_enabled', true),
            'vipLevels'  => $this->fetchVipLevels(),
        ], 'vip_settings');
    }

    public function vipSettingsSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();

        // vip_enabled：表单里配了 hidden=0 + checkbox=1
        $vipEnabled = !empty($input['vip_enabled']) ? '1' : '0';

        // 等级数据来自 levels[i][...]，等级号以显式隐藏域为准（不再假设 i+1）
        $levels = [];
        $rawLevels = $input['levels'] ?? [];
        if (is_array($rawLevels)) {
            // 按显式 level 去重排序，避免表单顺序被改动后等级错位
            $byLevel = [];
            foreach ($rawLevels as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lvl = (int)($row['level'] ?? 0);
                if ($lvl < 1) {
                    continue;
                }
                $benefits = [];
                foreach (preg_split('/\r\n|\r|\n/', (string)($row['benefits'] ?? '')) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $benefits[] = $line;
                    }
                }

                $color = trim((string)($row['color'] ?? ''));
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $color = '#999999';
                }

                $byLevel[$lvl] = [
                    'level'    => $lvl,
                    'name'     => trim((string)($row['name'] ?? '')),
                    'color'    => $color,
                    'icon'     => trim((string)($row['icon'] ?? '')),
                    'price'    => max(0, (int)($row['price'] ?? 0)),
                    'benefits' => $benefits,
                ];
            }
            ksort($byLevel);
            $levels = array_values($byLevel);
        }

        $vipLevelsJson = json_encode($levels, JSON_UNESCAPED_UNICODE);

        \App\Models\Setting::setMany(['vip_enabled' => $vipEnabled, 'vip_levels' => $vipLevelsJson]);

        \App\Services\SettingSvc::clearCache();
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改会员设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了 VIP 会员相关设置',
            'target_type' => 'settings',
        ]);
        $this->respondMutation(true, '会员设置已保存', fn() => $this->renderVipSettingsPage());
    }

    /**
     * 积分记录列表 API
     */
    public function creditLogsApi(): void
    {
        $this->requireAdmin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchCreditLogs($page, $limit, $search);
        $this->jsonTable($result['rows'], $result['total']);
    }

    /**
     * 积分记录数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchCreditLogs(int $page, int $limit, string $search): array
    {
        return \App\Models\CreditLog::adminList($search, $page, $limit);
    }

    // ==================== 积分记录 ====================

    public function creditLogs(): void
    {
        $this->requireAdmin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchCreditLogs($page, $limit, $search);

        $this->renderAdmin('admin/credit_logs', [
            'pageTitle' => '积分记录',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'search'    => $search,
        ], 'credit-logs');
    }
}
