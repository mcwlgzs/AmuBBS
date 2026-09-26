<?php
/**
 * 后台 - 板块管理
 */

namespace App\Controllers\Admin;

use App\Models\Forum;
use App\Models\ForumAccess;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\ForumSvc;
use Core\Event;
use App\Events\Events;

class ForumController extends AdminBase
{
    public function forums(): void
    {
        $this->requireAdmin();
        $this->renderForumsPage();
    }

    /**
     * 板块列表数据（页面与 JSON API 共用同一份查询逻辑）
     */
    private function fetchForums(): array
    {
        $forums = Forum::adminList();

        // 将版主 ID 转为用户名
        $allModIds = [];
        foreach ($forums as $f) {
            if (!empty($f['moderators'])) {
                foreach (explode(',', $f['moderators']) as $mid) {
                    $mid = (int)$mid;
                    if ($mid > 0) $allModIds[$mid] = true;
                }
            }
        }
        $modNameMap = User::getUsernameMap(array_keys($allModIds));

        foreach ($forums as &$f) {
            if (!empty($f['moderators'])) {
                $ids = array_filter(array_map('intval', explode(',', $f['moderators'])));
                $names = array_map(fn($id) => $modNameMap[$id] ?? (string)$id, $ids);
                $f['moderators_display'] = implode(',', $names);
            } else {
                $f['moderators_display'] = '';
            }
        }
        unset($f);

        return $forums;
    }

    /**
     * 渲染板块管理页面片段（GET 与增删改后的刷新共用同一个渲染路径）
     */
    private function renderForumsPage(): void
    {
        $this->renderAdmin('admin/forums', [
            'pageTitle' => '板块管理',
            'rows'      => $this->fetchForums(),
        ], 'forums');
    }

    /**
     * 板块列表 API（保留，供外部 AJAX 调用）
     */
    public function forumsApi(): void
    {
        $this->requireAdmin();

        $forums = $this->fetchForums();
        $this->jsonTable($forums, count($forums));
    }

    /**
     * 板块表单片段（layer iframe 弹层用）
     *
     * ?id=0 或省略 → 新增；?id=N → 编辑
     */
    public function forumForm(): void
    {
        $this->requireAdmin();

        $id = max(0, (int)($_GET['id'] ?? 0));
        $forum = null;

        if ($id > 0) {
            $forum = Forum::findById($id);
            if (!$forum) {
                http_response_code(404);
                $this->renderAdmin('admin/partials/error', ['pageTitle' => '板块不存在', 'message' => '板块不存在']);
                return;
            }

            // 版主 ID → 用户名，供输入框回显
            $modIds = array_filter(array_map('intval', explode(',', (string)($forum['moderators'] ?? ''))));
            $forum['moderators_display'] = '';
            if (!empty($modIds)) {
                $nameMap = User::getUsernameMap($modIds);
                $forum['moderators_display'] = implode(',', array_map(
                    static fn(int $mid): string => $nameMap[$mid] ?? (string)$mid,
                    $modIds
                ));
            }
        }

        $this->renderAdmin('admin/partials/forum_form', [
            'pageTitle' => $forum !== null ? '编辑板块' : '新增板块',
            'forum'     => $forum,
            'isEdit'    => $forum !== null,
            // 上级板块下拉：不能把自己设成自己的上级，视图里按 id 过滤
            'parents'   => Forum::getOptions(),
        ], 'forums');
    }

    public function forumCreate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $parentId = (int) ($input['parent_id'] ?? 0);
        $rank = (int) ($input['rank'] ?? 0);
        $moderatorsInput = trim($input['moderators'] ?? '');
        $announcement = trim($input['announcement'] ?? '');
        $seoTitle = trim($input['seo_title'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? '');

        if ($name === '') {
            $this->respondMutation(false, '板块名称不能为空', fn() => $this->renderForumsPage());
            return;
        }

        $moderators = $this->resolveModeratorIds($moderatorsInput);

        $forumId = Forum::adminCreate($parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords);

        Event::dispatch(Events::ADMIN_FORUM_CREATED, [
            'action' => '创建板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "创建板块「{$name}」",
            'target_type' => 'forum',
            'target_id' => $forumId,
        ]);

        $this->respondMutation(true, "已创建板块「{$name}」", fn() => $this->renderForumsPage());
    }

    public function forumUpdate(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $parentId = (int) ($input['parent_id'] ?? 0);
        $rank = (int) ($input['rank'] ?? 0);
        $moderatorsInput = trim($input['moderators'] ?? '');
        $announcement = trim($input['announcement'] ?? '');
        $seoTitle = trim($input['seo_title'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? '');

        if ($id <= 0 || $name === '') {
            $this->respondMutation(false, '参数错误', fn() => $this->renderForumsPage());
            return;
        }

        $moderators = $this->resolveModeratorIds($moderatorsInput);

        Forum::adminUpdate($id, $parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords);

        Event::dispatch(Events::ADMIN_FORUM_UPDATED, [
            'action' => '编辑板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "编辑板块 ID:{$id}「{$name}」",
            'target_type' => 'forum',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, "已更新板块「{$name}」", fn() => $this->renderForumsPage());
    }

    public function forumDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int) ($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderForumsPage());
            return;
        }

        $forumSvc = new ForumSvc();
        $forumSvc->deleteForum($id);

        Event::dispatch(Events::ADMIN_FORUM_DELETED, [
            'action' => '删除板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除板块 ID:{$id}（含级联清理）",
            'target_type' => 'forum',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, '板块已删除', fn() => $this->renderForumsPage());
    }

    /**
     * 板块权限片段（layer iframe 弹层用）
     *
     * 原来是一个返回 JSON 的接口，由前端 JS 拼表格；
     * 现在直接返回服务端渲染好的表格片段。
     */
    public function forumAccess(): void
    {
        $this->requireAdmin();

        $forumId = (int)($_GET['forum_id'] ?? 0);
        if ($forumId <= 0) {
            http_response_code(400);
            $this->renderAdmin('admin/partials/error', ['pageTitle' => '参数错误', 'message' => '参数错误']);
            return;
        }

        $forum = Forum::findById($forumId);
        if (!$forum) {
            http_response_code(404);
            $this->renderAdmin('admin/partials/error', ['pageTitle' => '板块不存在', 'message' => '板块不存在']);
            return;
        }

        $groups = UserGroup::all();
        $accessRows = ForumAccess::rowsForForum($forumId);

        $accessMap = [];
        foreach ($accessRows as $row) {
            $accessMap[(int)$row['group_id']] = $row;
        }

        $this->renderAdmin('admin/partials/forum_access', [
            'pageTitle' => '板块权限',
            'forum'     => $forum,
            'groups'    => $groups,
            'accessMap' => $accessMap,
        ], 'forums');
    }

    public function forumAccessSave(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $forumId = (int)($input['forum_id'] ?? 0);
        $permissions = $input['permissions'] ?? [];

        if ($forumId <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderForumsPage());
            return;
        }

        // 整批替换（先清空再按需插入）与缓存失效都在模型里，一个事务
        ForumAccess::replaceForForum($forumId, $permissions);

        $this->respondMutation(true, '权限已保存', fn() => $this->renderForumsPage());
    }

    private function resolveModeratorIds(string $input): string
    {
        if ($input === '') {
            return '';
        }

        $names = array_map('trim', explode(',', $input));
        $ids = [];
        foreach ($names as $name) {
            if ($name === '') continue;
            if (ctype_digit($name)) {
                $ids[] = (int)$name;
                continue;
            }
            $user = User::findByUsername($name);
            if ($user) {
                $ids[] = (int)$user['id'];
            }
        }

        return implode(',', array_unique($ids));
    }
}
