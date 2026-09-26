<?php
/**
 * 后台 - 用户组管理
 *
 * 迁移说明（现在是 layuimini / layui）：
 *   原实现是「空页面 + layui table 远程取数 + JSON 提交」，页面本身没有任何服务端数据。
 *   现在列表由 layui table 吃 /admin/api/user-groups，表单是独立的 iframe 子页面
 *   （layer.open type:2），服务端不再往视图里塞行数据，
 *   但该接口仍保留给外部调用方。
 */

namespace App\Controllers\Admin;

use App\Models\UserGroup;
use App\Services\PermissionSvc;

class UserGroupController extends AdminBase
{
    /**
     * 12 个核心权限字段（字段名 => 中文名）
     *
     * 顺序即页面展示顺序，控制器与表单/列表两个视图共用这一份定义，
     * 避免多处硬编码后加字段漏改。
     */
    public const PERMISSION_LABELS = [
        'allow_read'        => '浏览',
        'allow_thread'      => '发帖',
        'allow_post'        => '回复',
        'allow_attach'      => '附件',
        'allow_down'        => '下载',
        'allow_top'         => '置顶',
        'allow_update'      => '编辑',
        'allow_delete'      => '删除',
        'allow_move'        => '移动',
        'allow_ban_user'    => '封禁',
        'allow_delete_user' => '删用户',
        'allow_view_ip'     => '查IP',
    ];

    /** 内置用户组（1 普通 / 2 版主 / 3 管理员），不可删除 */
    private const BUILTIN_MAX_ID = 3;

    public function userGroups(): void
    {
        $this->requireAdmin();
        $this->renderGroupsPage();
    }

    /**
     * 用户组列表（含用户数）
     */
    private function fetchGroups(): array
    {
        return UserGroup::adminList();
    }

    /**
     * 渲染用户组页面片段（GET 与增删改后的刷新共用同一渲染路径）
     */
    private function renderGroupsPage(): void
    {
        $this->renderAdmin('admin/user_groups', [
            'pageTitle'  => '用户组管理',
            'groups'     => $this->fetchGroups(),
            'permLabels' => self::PERMISSION_LABELS,
        ], 'user_groups');
    }

    /**
     * 用户组表单片段（layer iframe 弹层用）
     *
     * ?id=0 或省略 → 新增；?id=N → 编辑
     */
    public function userGroupForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $group = null;

        if ($id > 0) {
            $group = UserGroup::findFresh($id);
            if (!$group) {
                http_response_code(404);
                $this->renderAdmin('admin/partials/error', ['pageTitle' => '用户组不存在', 'message' => '用户组不存在']);
                return;
            }
        }

        $this->renderAdmin('admin/partials/group_form', [
            'pageTitle'  => $group !== null ? '编辑用户组' : '新增用户组',
            'group'      => $group,
            'isEdit'     => $group !== null,
            'permLabels' => self::PERMISSION_LABELS,
        ], 'user_groups');
    }

    /**
     * 用户组列表 API（保留，供外部 AJAX 调用）
     */
    public function userGroupsApi(): void
    {
        $this->requireAdmin();

        $groups = $this->fetchGroups();
        $this->jsonTable($groups, count($groups));
    }

    public function userGroupSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id   = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));

        if ($name === '') {
            $this->respondMutation(false, '组名不能为空', fn() => $this->renderGroupsPage());
            return;
        }
        // name 是 varchar(32)，先拦住再报错，避免直接抛 1406 变成 500
        if (mb_strlen($name) > 32) {
            $this->respondMutation(false, '组名不能超过 32 个字符', fn() => $this->renderGroupsPage());
            return;
        }

        // 扩展权限：表单里是 JSON 文本，空值按空对象处理
        $permRaw = trim((string)($input['permissions'] ?? ''));
        if ($permRaw === '') {
            $permRaw = '{}';
        }
        $perm = json_decode($permRaw, true);
        if (!is_array($perm)) {
            $this->respondMutation(false, '扩展权限 JSON 格式错误', fn() => $this->renderGroupsPage());
            return;
        }
        // 空数组必须编码回 {}（json_encode([]) 会得到 "[]"，是个 JSON 数组而非对象，
        // 会把原本是对象的字段悄悄变成数组，读到的地方按 ['key'] 取值就会出错）
        $permJson = $perm === [] ? '{}' : json_encode($perm, JSON_UNESCAPED_UNICODE);

        $isAdmin = (int)($input['is_admin'] ?? 0);

        // 未勾选的 checkbox 不会提交，因此表单里每个权限位都配了一个 hidden=0，
        // 这里缺失即视为 0（区别于旧实现 ?? 1 的「缺失即放行」）。
        $flags = [];
        foreach (array_keys(self::PERMISSION_LABELS) as $field) {
            $flags[$field] = !empty($input[$field]) ? 1 : 0;
        }

        if ($id > 0) {
            UserGroup::adminSave($id, $name, $permJson, $isAdmin, $flags);
            // clearCache() 的两个参数都默认 0，不传参等于什么都没删（group_perms:{id} 会留 600 秒）
            UserGroup::forgetCaches($id);
            PermissionSvc::clearCache($id);
            $this->respondMutation(true, '用户组已更新', fn() => $this->renderGroupsPage());
            return;
        }

        UserGroup::adminSave(0, $name, $permJson, $isAdmin, $flags);
        // 新建组没有具体 id，整片失效
        UserGroup::forgetCaches();
        $this->respondMutation(true, '用户组已创建', fn() => $this->renderGroupsPage());
    }

    public function userGroupDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= self::BUILTIN_MAX_ID) {
            $this->respondMutation(false, '系统内置用户组不可删除', fn() => $this->renderGroupsPage());
            return;
        }

        $userCount = UserGroup::userCount($id);
        if ($userCount > 0) {
            $this->respondMutation(false, "该用户组下还有 {$userCount} 个用户，请先转移", fn() => $this->renderGroupsPage());
            return;
        }

        UserGroup::remove($id);
        UserGroup::forgetCaches($id);
        PermissionSvc::clearCache($id);
        $this->respondMutation(true, '用户组已删除', fn() => $this->renderGroupsPage());
    }
}
