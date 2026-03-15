<?php
/**
 * 后台 - 板块管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;
use Core\Event;
use App\Events\Events;

class ForumController extends AdminBase
{
    public function forums(): void
    {
        $this->requireAdmin();
        $this->render('admin/forums', [
            'pageTitle' => '板块管理',
        ]);
    }

    /**
     * 板块列表 API（layui table 数据源）
     */
    public function forumsApi(): void
    {
        $this->requireAdmin();

        $forums = Database::fetchAll(
            "SELECT id, parent_id, name, description, `rank`, moderators, announcement, seo_title, seo_keywords, thread_count, post_count, created_at FROM forums WHERE deleted_at IS NULL ORDER BY parent_id ASC, `rank` DESC, id ASC"
        );

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
        $modNameMap = [];
        if (!empty($allModIds)) {
            $ids = array_keys($allModIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll("SELECT id, username FROM users WHERE id IN ({$placeholders})", $ids);
            foreach ($rows as $r) {
                $modNameMap[(int)$r['id']] = $r['username'];
            }
        }
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

        $this->layuiJson($forums, count($forums));
    }

    public function forumCreate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $parentId = (int) ($input['parent_id'] ?? 0);
        $rank = (int) ($input['rank'] ?? 0);
        $moderatorsInput = trim($input['moderators'] ?? '');
        $announcement = trim($input['announcement'] ?? '');
        $seoTitle = trim($input['seo_title'] ?? '');
        $seoKeywords = trim($input['seo_keywords'] ?? '');

        if ($name === '') {
            $this->error('板块名称不能为空');
            return;
        }

        $moderators = $this->resolveModeratorIds($moderatorsInput);

        Database::execute(
            "INSERT INTO forums (parent_id, name, description, `rank`, moderators, announcement, seo_title, seo_keywords, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords, time()]
        );

        $forumId = Database::lastInsertId();
        Cache::delete('forums:list');
        Cache::delete('forums:children:all');
        Event::dispatch(Events::ADMIN_FORUM_CREATED, [
            'action' => '创建板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "创建板块「{$name}」",
            'target_type' => 'forum',
            'target_id' => $forumId,
        ]);
        $this->success('板块已创建', ['id' => $forumId]);
    }

    public function forumUpdate(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
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
            $this->error('参数错误');
            return;
        }

        $moderators = $this->resolveModeratorIds($moderatorsInput);

        Database::execute(
            "UPDATE forums SET parent_id = ?, name = ?, description = ?, `rank` = ?, moderators = ?, announcement = ?, seo_title = ?, seo_keywords = ?, updated_at = ? WHERE id = ?",
            [$parentId, $name, $description, $rank, $moderators, $announcement, $seoTitle, $seoKeywords, time(), $id]
        );

        Cache::delete('forums:list');
        Cache::delete('forums:children:all');
        Cache::delete("forum:{$id}");
        Event::dispatch(Events::ADMIN_FORUM_UPDATED, [
            'action' => '编辑板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "编辑板块 ID:{$id}「{$name}」",
            'target_type' => 'forum',
            'target_id' => $id,
        ]);
        $this->success('板块已更新');
    }

    public function forumDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int) ($input['id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        $forumSvc = new \App\Services\ForumSvc();
        $forumSvc->deleteForum($id);

        Event::dispatch(Events::ADMIN_FORUM_DELETED, [
            'action' => '删除板块',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除板块 ID:{$id}（含级联清理）",
            'target_type' => 'forum',
            'target_id' => $id,
        ]);
        $this->success('板块已删除');
    }

    public function forumAccess(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $forumId = (int)($input['forum_id'] ?? 0);

        if ($forumId <= 0) {
            $this->error('参数错误');
            return;
        }

        $groups = Database::fetchAll("SELECT * FROM user_groups ORDER BY id");
        $accessRows = Database::fetchAll("SELECT * FROM forum_access WHERE forum_id = ?", [$forumId]);

        $accessMap = [];
        foreach ($accessRows as $row) {
            $accessMap[$row['group_id']] = $row;
        }

        $this->success('ok', ['groups' => $groups, 'access' => $accessMap]);
    }

    public function forumAccessSave(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $forumId = (int)($input['forum_id'] ?? 0);
        $permissions = $input['permissions'] ?? [];

        if ($forumId <= 0) {
            $this->error('参数错误');
            return;
        }

        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM forum_access WHERE forum_id = ?", [$forumId]);

            foreach ($permissions as $groupId => $perms) {
                $gid = (int)$groupId;
                $allowRead = (int)($perms['allow_read'] ?? 1);
                $allowThread = (int)($perms['allow_thread'] ?? 1);
                $allowPost = (int)($perms['allow_post'] ?? 1);
                $allowAttach = (int)($perms['allow_attach'] ?? 1);
                $allowDown = (int)($perms['allow_down'] ?? 1);

                if ($allowRead && $allowThread && $allowPost && $allowAttach && $allowDown) {
                    continue;
                }

                Database::execute(
                    "INSERT INTO forum_access (forum_id, group_id, allow_read, allow_thread, allow_post, allow_attach, allow_down) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$forumId, $gid, $allowRead, $allowThread, $allowPost, $allowAttach, $allowDown]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $groups = Database::fetchAll("SELECT id FROM user_groups");
        foreach ($groups as $g) {
            Cache::delete("forum_access:{$forumId}:{$g['id']}");
        }

        $this->success('权限已保存');
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
            $user = Database::fetchOne("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL", [$name]);
            if ($user) {
                $ids[] = (int)$user['id'];
            }
        }

        return implode(',', array_unique($ids));
    }
}
