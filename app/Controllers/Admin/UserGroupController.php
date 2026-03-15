<?php
/**
 * 后台 - 用户组管理
 */

namespace App\Controllers\Admin;

use Core\Database;

class UserGroupController extends AdminBase
{
    public function userGroups(): void
    {
        $this->requireAdmin();
        $this->render('admin/user_groups', ['pageTitle' => '用户组管理']);
    }

    /**
     * 用户组列表 API
     */
    public function userGroupsApi(): void
    {
        $this->requireAdmin();
        $groups = Database::fetchAll("SELECT g.*, (SELECT COUNT(*) FROM users WHERE group_id = g.id AND deleted_at IS NULL) as user_count FROM user_groups g ORDER BY g.id");
        $this->layuiJson($groups, count($groups));
    }

    public function userGroupSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $permissions = $input['permissions'] ?? [];
        $isAdmin = (int)($input['is_admin'] ?? 0);

        $allowRead = (int)($input['allow_read'] ?? 1);
        $allowThread = (int)($input['allow_thread'] ?? 1);
        $allowPost = (int)($input['allow_post'] ?? 1);
        $allowAttach = (int)($input['allow_attach'] ?? 1);
        $allowDown = (int)($input['allow_down'] ?? 1);
        $allowTop = (int)($input['allow_top'] ?? 0);
        $allowUpdate = (int)($input['allow_update'] ?? 0);
        $allowDelete = (int)($input['allow_delete'] ?? 0);
        $allowMove = (int)($input['allow_move'] ?? 0);
        $allowBanUser = (int)($input['allow_ban_user'] ?? 0);
        $allowDeleteUser = (int)($input['allow_delete_user'] ?? 0);
        $allowViewIp = (int)($input['allow_view_ip'] ?? 0);

        if ($name === '') {
            $this->error('组名不能为空');
            return;
        }

        $permJson = json_encode($permissions);

        if ($id > 0) {
            Database::execute(
                "UPDATE user_groups SET name = ?, permissions = ?, is_admin = ?,
                 allow_read = ?, allow_thread = ?, allow_post = ?, allow_attach = ?, allow_down = ?,
                 allow_top = ?, allow_update = ?, allow_delete = ?, allow_move = ?, allow_ban_user = ?,
                 allow_delete_user = ?, allow_view_ip = ?
                 WHERE id = ?",
                [$name, $permJson, $isAdmin,
                 $allowRead, $allowThread, $allowPost, $allowAttach, $allowDown,
                 $allowTop, $allowUpdate, $allowDelete, $allowMove, $allowBanUser,
                 $allowDeleteUser, $allowViewIp, $id]
            );
            \App\Services\PermissionSvc::clearCache();
            $this->success('用户组已更新');
        } else {
            Database::execute(
                "INSERT INTO user_groups (name, permissions, is_admin,
                 allow_read, allow_thread, allow_post, allow_attach, allow_down,
                 allow_top, allow_update, allow_delete, allow_move, allow_ban_user,
                 allow_delete_user, allow_view_ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $permJson, $isAdmin,
                 $allowRead, $allowThread, $allowPost, $allowAttach, $allowDown,
                 $allowTop, $allowUpdate, $allowDelete, $allowMove, $allowBanUser,
                 $allowDeleteUser, $allowViewIp, time()]
            );
            \App\Services\PermissionSvc::clearCache();
            $this->success('用户组已创建', ['id' => Database::lastInsertId()]);
        }
    }

    public function userGroupDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);

        if ($id <= 3) {
            $this->error('系统内置用户组不可删除');
            return;
        }

        $userCount = Database::fetchOne("SELECT COUNT(*) as c FROM users WHERE group_id = ? AND deleted_at IS NULL", [$id])['c'] ?? 0;
        if ($userCount > 0) {
            $this->error("该用户组下还有 {$userCount} 个用户，请先转移");
            return;
        }

        Database::execute("DELETE FROM user_groups WHERE id = ?", [$id]);
        \App\Services\PermissionSvc::clearCache();
        $this->success('用户组已删除');
    }
}
