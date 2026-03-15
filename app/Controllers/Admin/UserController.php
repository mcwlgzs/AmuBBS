<?php
/**
 * 后台 - 用户管理（用户列表、用户操作、用户设置、在线用户、积分记录）
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;
use Core\Event;
use App\Events\Events;

class UserController extends AdminBase
{
    /**
     * 构建用户列表查询条件
     */
    private function buildUsersWhere(): array
    {
        $search = trim($_GET['search'] ?? '');
        $searchUid = trim($_GET['uid'] ?? '');
        $searchGroupId = $_GET['group_id'] ?? '';
        $searchIp = trim($_GET['ip'] ?? '');

        $where = "WHERE u.deleted_at IS NULL";
        $params = [];

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.nickname LIKE ?)";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }
        if ($searchUid !== '') {
            $where .= " AND u.id = ?";
            $params[] = (int)$searchUid;
        }
        if ($searchGroupId !== '') {
            $where .= " AND u.group_id = ?";
            $params[] = (int)$searchGroupId;
        }
        if ($searchIp !== '') {
            $escapedIp = addcslashes($searchIp, '%_\\');
            $where .= " AND (login_ip LIKE ? OR register_ip LIKE ?)";
            $params[] = "%{$escapedIp}%";
            $params[] = "%{$escapedIp}%";
        }

        return [$where, $params];
    }

    private function getUsersSortCol(): array
    {
        $sortBy = $_GET['sort'] ?? 'id';
        $sortDir = strtolower($_GET['dir'] ?? 'desc');
        $allowedSorts = ['id' => 'u.id', 'username' => 'u.username', 'credits' => 'u.credits', 'threads' => 'u.thread_count', 'posts' => 'u.post_count', 'created_at' => 'u.created_at'];
        $sortCol = $allowedSorts[$sortBy] ?? 'u.id';
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';
        return [$sortCol, $sortDir];
    }

    public function users(): void
    {
        $this->requireAdmin();
        $groups = Database::fetchAll("SELECT * FROM user_groups ORDER BY id");
        $this->render('admin/users', [
            'pageTitle' => '用户管理',
            'groups' => $groups,
        ]);
    }

    /**
     * 用户列表 API
     */
    public function usersApi(): void
    {
        $this->requireAdmin();

        [$where, $params] = $this->buildUsersWhere();
        [$sortCol, $sortDir] = $this->getUsersSortCol();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM users u {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $users = Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.email, u.avatar, u.group_id, u.credits, u.thread_count, u.post_count, u.login_ip, u.login_at, u.created_at, g.name as group_name FROM users u LEFT JOIN user_groups g ON u.group_id = g.id {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($users, $total);
    }

    public function userUpdate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        if ($action === 'add_user') {
            $username = trim($input['username'] ?? '');
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $groupId = (int)($input['group_id'] ?? 1);
            $nickname = trim($input['nickname'] ?? '') ?: null;

            if ($username === '' || $email === '' || $password === '') {
                $this->error('用户名、邮箱和密码不能为空');
                return;
            }
            $uLen = mb_strlen($username);
            if ($uLen < 3 || $uLen > 20) {
                $this->error('用户名长度为 3-20 个字符');
                return;
            }
            if (preg_match('/[\x00-\x1f\x7f<>"\'&\\\\\/]/', $username)) {
                $this->error('用户名包含非法字符');
                return;
            }
            if (strlen($password) < 6) {
                $this->error('密码长度至少 6 个字符');
                return;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('邮箱格式不正确');
                return;
            }
            $exist = Database::fetchOne("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
            if ($exist) {
                $this->error('用户名已存在');
                return;
            }
            $exist = Database::fetchOne("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$email]);
            if ($exist) {
                $this->error('邮箱已被注册');
                return;
            }

            if ($nickname !== null) {
                $nLen = mb_strlen($nickname);
                if ($nLen < 2 || $nLen > 20) {
                    $this->error('昵称长度为 2-20 个字符');
                    return;
                }
                $existNick = Database::fetchOne("SELECT id FROM users WHERE nickname = ? AND deleted_at IS NULL", [$nickname]);
                if ($existNick) {
                    $this->error('昵称已被使用');
                    return;
                }
            }

            $hashed = password_hash($password, PASSWORD_BCRYPT);
            Database::execute(
                "INSERT INTO users (username, nickname, email, password, group_id, credits, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)",
                [$username, $nickname, $email, $hashed, $groupId, time()]
            );
            $newId = Database::lastInsertId();
            Event::dispatch(Events::ADMIN_USER_UPDATED, [
                'action' => '添加用户',
                'admin_id' => $_SESSION['user_id'],
                'detail' => "管理员添加用户 {$username} (ID:{$newId})",
                'target_type' => 'user',
                'target_id' => $newId,
            ]);
            $this->success('用户已创建', ['id' => $newId]);
            return;
        }

        $userId = (int) ($input['user_id'] ?? 0);

        if ($userId <= 0) {
            $this->error('无效的用户ID');
            return;
        }

        if ($userId === (int) $_SESSION['user_id'] && in_array($action, ['ban', 'delete'], true)) {
            $this->error('不能对自己执行此操作');
            return;
        }

        switch ($action) {
            case 'change_group':
                $groupId = (int) ($input['group_id'] ?? 1);
                // 从数据库验证用户组是否存在
                $group = Database::fetchOne("SELECT id FROM user_groups WHERE id = ?", [$groupId]);
                if (!$group) {
                    $this->error('无效的用户组');
                    return;
                }
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id = ?", [$groupId, time(), $userId]);
                Cache::delete("user:profile:{$userId}");
                Cache::delete("user:group:{$userId}");
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '修改用户组',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "将用户 ID:{$userId} 的用户组改为 {$groupId}",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                $this->success('用户组已更新');
                break;

            case 'edit_profile':
                $username = trim($input['username'] ?? '');
                $email = trim($input['email'] ?? '');
                $signature = trim($input['signature'] ?? '');
                $nickname = trim($input['nickname'] ?? '') ?: null;
                $nicknameColor = trim($input['nickname_color'] ?? '') ?: null;

                // 校验颜色格式
                if ($nicknameColor !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $nicknameColor)) {
                    $nicknameColor = null;
                }

                if ($username === '' || $email === '') {
                    $this->error('用户名和邮箱不能为空');
                    return;
                }
                // 用户名格式校验（与注册一致）
                if (preg_match('/[\x00-\x1f\x7f<>"\'&\\\\\/]/', $username)) {
                    $this->error('用户名包含非法字符');
                    return;
                }
                $uLen = mb_strlen($username);
                if ($uLen < 2 || $uLen > 20) {
                    $this->error('用户名长度为 2-20 个字符');
                    return;
                }
                if (mb_strlen($signature) > 200) {
                    $this->error('个性签名最多 200 个字符');
                    return;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->error('邮箱格式不正确');
                    return;
                }

                if ($nickname !== null) {
                    $nLen = mb_strlen($nickname);
                    if ($nLen < 2 || $nLen > 20) {
                        $this->error('昵称长度为 2-20 个字符');
                        return;
                    }
                    $existNick = Database::fetchOne("SELECT id FROM users WHERE nickname = ? AND id != ? AND deleted_at IS NULL", [$nickname, $userId]);
                    if ($existNick) {
                        $this->error('昵称已被占用');
                        return;
                    }
                }

                $exist = Database::fetchOne("SELECT id FROM users WHERE username = ? AND id != ? AND deleted_at IS NULL", [$username, $userId]);
                if ($exist) {
                    $this->error('用户名已被占用');
                    return;
                }
                $exist = Database::fetchOne("SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL", [$email, $userId]);
                if ($exist) {
                    $this->error('邮箱已被占用');
                    return;
                }

                Database::execute("UPDATE users SET username = ?, nickname = ?, email = ?, signature = ?, nickname_color = ?, updated_at = ? WHERE id = ?",
                    [$username, $nickname, $email, $signature, $nicknameColor, time(), $userId]);
                Cache::delete("user:profile:{$userId}");
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '编辑用户资料',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "编辑用户 ID:{$userId} 的资料",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                $this->success('用户资料已更新');
                break;

            case 'reset_password':
                $newPassword = $input['new_password'] ?? '';
                if (strlen($newPassword) < 6) {
                    $this->error('密码长度至少 6 个字符');
                    return;
                }
                $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                Database::execute("UPDATE users SET password = ?, updated_at = ? WHERE id = ?", [$hashed, time(), $userId]);
                \Core\RememberToken::clear($userId);
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '重置密码',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "重置用户 ID:{$userId} 的密码",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                $this->success('密码已重置');
                break;

            case 'adjust_credits':
                $amount = (int) ($input['amount'] ?? 0);
                $reason = trim($input['reason'] ?? '管理员调整');
                if ($amount === 0) {
                    $this->error('积分变动不能为 0');
                    return;
                }
                $creditSvc = new \App\Services\CreditSvc();
                if ($amount > 0) {
                    $creditSvc->addCredits($userId, $amount, 'admin', $reason, 'admin', (int)$_SESSION['user_id']);
                } else {
                    $ok = $creditSvc->deductCredits($userId, abs($amount), 'admin', $reason, 'admin', (int)$_SESSION['user_id']);
                    if (!$ok) {
                        $this->error('扣除失败，积分不足');
                        return;
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
                $this->success('积分已调整');
                break;

            case 'ban':
                $banGroup = Database::fetchOne("SELECT id FROM user_groups WHERE name = '禁止用户组' LIMIT 1");
                if (!$banGroup) {
                    Database::execute("INSERT INTO user_groups (name, permissions, is_admin, created_at) VALUES (?, ?, 0, ?)",
                        ['禁止用户组', json_encode([]), time()]);
                    $banGroupId = Database::lastInsertId();
                } else {
                    $banGroupId = $banGroup['id'];
                }
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id = ?", [$banGroupId, time(), $userId]);
                Cache::delete("user:profile:{$userId}");
                Cache::delete("user:group:{$userId}");
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '封禁用户',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "封禁用户 ID:{$userId}",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                $this->success('用户已封禁');
                break;

            case 'unban':
                $defaultGroup = \App\Services\SettingSvc::getInt('user_default_group', 1);
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id = ?", [$defaultGroup, time(), $userId]);
                Cache::delete("user:profile:{$userId}");
                Cache::delete("user:group:{$userId}");
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '解封用户',
                    'admin_id' => $_SESSION['user_id'],
                    'detail' => "解封用户 ID:{$userId}，恢复为默认用户组",
                    'target_type' => 'user',
                    'target_id' => $userId,
                ]);
                $this->success('用户已解封');
                break;

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
                $this->success('用户已删除');
                break;

            case 'get_detail':
                $user = Database::fetchOne("SELECT id, username, nickname, email, signature, credits, group_id, nickname_color, login_ip, login_at, created_at, thread_count, post_count FROM users WHERE id = ?", [$userId]);
                if (!$user) {
                    $this->error('用户不存在');
                    return;
                }
                $this->success('ok', ['user' => $user]);
                break;

            default:
                $this->error('未知操作');
                return;
        }
    }

    /**
     * 批量操作用户
     */
    public function userBatchAction(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $ids = $input['ids'] ?? [];

        if (!is_array($ids) || empty($ids)) {
            $this->error('请选择用户');
            return;
        }

        // 过滤为正整数
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
        if (empty($ids)) {
            $this->error('无效的用户ID');
            return;
        }
        if (count($ids) > 100) {
            $this->error('单次最多操作100个用户');
            return;
        }

        // 排除自己
        $selfId = (int)$_SESSION['user_id'];
        if (in_array($action, ['ban', 'delete'], true)) {
            $ids = array_values(array_filter($ids, fn($id) => $id !== $selfId));
            if (empty($ids)) {
                $this->error('不能对自己执行此操作');
                return;
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = time();
        $count = count($ids);

        switch ($action) {
            case 'ban':
                $banGroup = Database::fetchOne("SELECT id FROM user_groups WHERE name = '禁止用户组' LIMIT 1");
                if (!$banGroup) {
                    Database::execute("INSERT INTO user_groups (name, permissions, is_admin, created_at) VALUES (?, ?, 0, ?)",
                        ['禁止用户组', json_encode([]), $now]);
                    $banGroupId = Database::lastInsertId();
                } else {
                    $banGroupId = $banGroup['id'];
                }
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id IN ({$placeholders})", array_merge([$banGroupId, $now], $ids));
                foreach ($ids as $uid) { Cache::delete("user:profile:{$uid}"); Cache::delete("user:group:{$uid}"); }
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量封禁',
                    'admin_id' => $selfId,
                    'detail' => "批量封禁 {$count} 个用户: " . implode(',', $ids),
                    'target_type' => 'user',
                ]);
                $this->success("已封禁 {$count} 个用户");
                break;

            case 'unban':
                $defaultGroup = \App\Services\SettingSvc::getInt('user_default_group', 1);
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id IN ({$placeholders})", array_merge([$defaultGroup, $now], $ids));
                foreach ($ids as $uid) { Cache::delete("user:profile:{$uid}"); Cache::delete("user:group:{$uid}"); }
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量解封',
                    'admin_id' => $selfId,
                    'detail' => "批量解封 {$count} 个用户: " . implode(',', $ids),
                    'target_type' => 'user',
                ]);
                $this->success("已解封 {$count} 个用户");
                break;

            case 'change_group':
                $groupId = (int)($input['group_id'] ?? 0);
                if ($groupId <= 0) {
                    $this->error('请选择用户组');
                    return;
                }
                // 验证用户组是否存在
                $groupExists = Database::fetchOne("SELECT id FROM user_groups WHERE id = ?", [$groupId]);
                if (!$groupExists) {
                    $this->error('用户组不存在');
                    return;
                }
                Database::execute("UPDATE users SET group_id = ?, updated_at = ? WHERE id IN ({$placeholders})", array_merge([$groupId, $now], $ids));
                foreach ($ids as $uid) { Cache::delete("user:profile:{$uid}"); Cache::delete("user:group:{$uid}"); }
                Event::dispatch(Events::ADMIN_USER_UPDATED, [
                    'action' => '批量修改用户组',
                    'admin_id' => $selfId,
                    'detail' => "批量修改 {$count} 个用户的用户组为 {$groupId}",
                    'target_type' => 'user',
                ]);
                $this->success("已修改 {$count} 个用户的用户组");
                break;

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
                $this->success("已删除 {$deleted} 个用户");
                break;

            default:
                $this->error('未知操作');
                return;
        }
    }

    // ==================== 用户设置 ====================

    public function userSettings(): void
    {
        $this->requireAdmin();

        $rows = Database::fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'user_%'");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $groups = Database::fetchAll("SELECT id, name FROM user_groups ORDER BY id");

        $this->render('admin/user_settings', [
            'pageTitle' => '用户设置',
            'settings' => $settings,
            'groups' => $groups,
        ]);
    }

    public function userSettingsSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $allowed = [
            'user_register_enabled', 'user_register_verify', 'user_default_group',
            'user_default_credits', 'user_banned_usernames',
            'user_username_min_length', 'user_username_max_length', 'user_allow_rename',
            'user_login_max_attempts', 'user_login_lock_minutes',
            'user_password_min_length', 'user_password_require_mixed',
            'user_avatar_max_size', 'user_avatar_formats', 'user_default_avatar',
            'user_signature_max_length', 'user_allow_change_email',
            'user_show_email', 'user_show_login_ip', 'user_bio_max_length',
        ];
        $now = time();

        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $value = is_bool($input[$key]) ? ($input[$key] ? '1' : '0') : trim((string)$input[$key]);
                Database::execute(
                    "INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = ?, `updated_at` = ?",
                    [$key, $value, $now, $value, $now]
                );
            }
        }

        \App\Services\SettingSvc::clearCache();
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改用户设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了用户相关设置',
            'target_type' => 'settings',
        ]);
        $this->success('用户设置已保存');
    }

    // ==================== 会员设置 ====================

    public function vipSettings(): void
    {
        $this->requireAdmin();

        $vipEnabled = \App\Services\SettingSvc::getBool('vip_enabled', true);
        $vipLevelsJson = \App\Services\SettingSvc::get('vip_levels', '');
        $vipLevels = json_decode($vipLevelsJson, true);
        if (!is_array($vipLevels) || empty($vipLevels)) {
            // getConfig() 返回按 level 索引的 map（含 level 0 普通用户），这里只取 level >= 1 的等级
            $all = \App\Services\VipSvc::getConfig();
            $vipLevels = [];
            foreach ($all as $lvl => $info) {
                if ($lvl < 1) continue;
                $info['level'] = $lvl;
                $vipLevels[] = $info;
            }
        }

        $this->render('admin/vip_settings', [
            'pageTitle' => '会员设置',
            'vipEnabled' => $vipEnabled,
            'vipLevels' => $vipLevels,
        ]);
    }

    public function vipSettingsSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $now = time();

        foreach (['vip_enabled', 'vip_levels'] as $key) {
            if (array_key_exists($key, $input)) {
                $raw = $input[$key];
                // vip_levels 可能是数组（前端传 JSON 对象），需要 json_encode
                if ($key === 'vip_levels' && is_array($raw)) {
                    $value = json_encode($raw, JSON_UNESCAPED_UNICODE);
                } else {
                    $value = trim((string)$raw);
                }
                Database::execute(
                    "INSERT INTO settings (`key`, `value`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = ?, `updated_at` = ?",
                    [$key, $value, $now, $value, $now]
                );
            }
        }

        \App\Services\SettingSvc::clearCache();
        Event::dispatch(Events::ADMIN_SETTINGS_SAVED, [
            'action' => '修改会员设置',
            'admin_id' => $_SESSION['user_id'],
            'detail' => '修改了 VIP 会员相关设置',
            'target_type' => 'settings',
        ]);
        $this->success('会员设置已保存');
    }

    // ==================== 在线用户 ====================

    public function onlineUsers(): void
    {
        $this->requireAdmin();
        $this->render('admin/online_users', ['pageTitle' => '在线用户']);
    }

    /**
     * 在线用户列表 API
     */
    public function onlineUsersApi(): void
    {
        $this->requireAdmin();

        $threshold = time() - 900;
        $onlineUsers = [];
        try {
            $onlineUsers = Database::fetchAll("
                SELECT s.user_id, s.ip, s.last_activity, u.username, u.avatar
                FROM sessions s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.last_activity >= ?
                ORDER BY s.last_activity DESC
            ", [$threshold]);
        } catch (\Throwable $e) {
            error_log('[Admin:User] onlineUsersApi: ' . $e->getMessage());
        }

        $this->layuiJson($onlineUsers, count($onlineUsers));
    }

    /**
     * 积分记录列表 API
     */
    public function creditLogsApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE u.username LIKE ?';
            $params[] = "%" . addcslashes($search, '%_\\') . "%";
        }

        $total = (int)(Database::fetchOne("
            SELECT COUNT(*) as cnt FROM credit_logs cl
            LEFT JOIN users u ON cl.user_id = u.id
            {$where}
        ", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $logs = Database::fetchAll("
            SELECT cl.*, u.username, u.avatar FROM credit_logs cl
            LEFT JOIN users u ON cl.user_id = u.id
            {$where}
            ORDER BY cl.created_at DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$limit, $offset]));

        $this->layuiJson($logs, $total);
    }

    // ==================== 积分记录 ====================

    public function creditLogs(): void
    {
        $this->requireAdmin();
        $this->render('admin/credit_logs', ['pageTitle' => '积分记录']);
    }
}
