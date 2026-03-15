<?php
/**
 * 后台 - 帖子管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Cache;
use Core\Event;
use App\Events\Events;

class ThreadController extends AdminBase
{
    /**
     * 构建帖子列表查询条件（threads/threadsApi 共用）
     */
    private function buildThreadsWhere(): array
    {
        $search = trim($_GET['search'] ?? '');
        $forumId = (int) ($_GET['forum_id'] ?? 0);
        $username = trim($_GET['username'] ?? '');
        $ip = trim($_GET['ip'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $where = "WHERE t.deleted_at IS NULL";
        $params = [];

        if ($search !== '') {
            $where .= " AND t.title LIKE ?";
            $params[] = "%" . addcslashes($search, '%_\\') . "%";
        }
        if ($forumId > 0) {
            $where .= " AND t.forum_id = ?";
            $params[] = $forumId;
        }
        if ($username !== '') {
            $where .= " AND t.username LIKE ?";
            $params[] = "%" . addcslashes($username, '%_\\') . "%";
        }
        if ($ip !== '') {
            $where .= " AND t.user_ip LIKE ?";
            $params[] = "%" . addcslashes($ip, '%_\\') . "%";
        }
        if ($dateFrom !== '') {
            $ts = strtotime($dateFrom);
            if ($ts) { $where .= " AND t.created_at >= ?"; $params[] = $ts; }
        }
        if ($dateTo !== '') {
            $ts = strtotime($dateTo . ' 23:59:59');
            if ($ts) { $where .= " AND t.created_at <= ?"; $params[] = $ts; }
        }
        if ($status === 'top') {
            $where .= " AND t.is_top > 0";
        } elseif ($status === 'highlight') {
            $where .= " AND t.is_highlight = 1";
        } elseif ($status === 'locked') {
            $where .= " AND t.is_locked = 1";
        }

        return [$where, $params, compact('search', 'forumId', 'username', 'ip', 'dateFrom', 'dateTo', 'status')];
    }

    private function getThreadsSortCol(): array
    {
        $sortBy = $_GET['sort'] ?? 'id';
        $sortDir = strtolower($_GET['dir'] ?? 'desc');
        $allowedSorts = [
            'id' => 't.id',
            'views' => 't.views',
            'reply_count' => 't.reply_count',
            'created_at' => 't.created_at',
        ];
        $sortCol = $allowedSorts[$sortBy] ?? 't.id';
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';
        return [$sortCol, $sortDir];
    }

    public function threads(): void
    {
        $this->requireAdmin();

        $forums = Database::fetchAll("SELECT id, name FROM forums WHERE deleted_at IS NULL ORDER BY `rank` DESC, id ASC");

        $this->render('admin/threads', [
            'pageTitle' => '帖子管理',
            'forums' => $forums,
        ]);
    }

    /**
     * 帖子列表 API（layui table 数据源）
     */
    public function threadsApi(): void
    {
        $this->requireAdmin();

        [$where, $params] = $this->buildThreadsWhere();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM threads t {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        [$sortCol, $sortDir] = $this->getThreadsSortCol();

        $threads = Database::fetchAll(
            "SELECT t.*, f.name as forum_name FROM threads t LEFT JOIN forums f ON t.forum_id = f.id {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($threads, $total);
    }

    public function threadBatch(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        $action = $input['action'] ?? '';

        if (empty($ids) || !is_array($ids)) {
            $this->error('请选择帖子');
            return;
        }

        if (count($ids) > 100) {
            $this->error('单次最多操作 100 篇帖子');
            return;
        }

        $ids = array_map('intval', $ids);
        $count = 0;

        switch ($action) {
            case 'delete':
                $threadSvc = new \App\Services\ThreadSvc();
                $adminId = $_SESSION['user_id'] ?? 0;
                $groupId = $_SESSION['group_id'] ?? 0;
                $count = $threadSvc->batchDelete($ids, $adminId, $groupId);
                Event::dispatch(Events::ADMIN_THREAD_DELETED, [
                    'action' => '批量删除帖子',
                    'admin_id' => $adminId,
                    'detail' => "批量删除 {$count} 篇帖子",
                    'target_type' => 'thread',
                ]);
                $this->success("已删除 {$count} 篇帖子");
                break;

            case 'lock':
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute("UPDATE threads SET is_locked = 1 WHERE id IN ({$placeholders})", $ids);
                $count = count($ids);
                foreach ($ids as $id) { Cache::delete("thread:{$id}"); }
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                $this->success("已锁定 {$count} 篇帖子");
                break;

            case 'unlock':
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute("UPDATE threads SET is_locked = 0 WHERE id IN ({$placeholders})", $ids);
                $count = count($ids);
                foreach ($ids as $id) { Cache::delete("thread:{$id}"); }
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                $this->success("已解锁 {$count} 篇帖子");
                break;

            case 'top':
                $level = max(0, min(2, (int)($input['level'] ?? 1)));
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$level], $ids);
                Database::execute("UPDATE threads SET is_top = ? WHERE id IN ({$placeholders})", $params);
                $count = count($ids);
                foreach ($ids as $id) { Cache::delete("thread:{$id}"); }
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                $labels = [0 => '取消置顶', 1 => '板块置顶', 2 => '全局置顶'];
                $this->success("已" . ($labels[$level] ?? '操作') . " {$count} 篇帖子");
                break;

            case 'highlight':
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute("UPDATE threads SET is_highlight = 1 WHERE id IN ({$placeholders})", $ids);
                $count = count($ids);
                foreach ($ids as $id) { Cache::delete("thread:{$id}"); }
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                $this->success("已加精 {$count} 篇帖子");
                break;

            case 'unhighlight':
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute("UPDATE threads SET is_highlight = 0 WHERE id IN ({$placeholders})", $ids);
                $count = count($ids);
                foreach ($ids as $id) { Cache::delete("thread:{$id}"); }
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                $this->success("已取消加精 {$count} 篇帖子");
                break;

            case 'move':
                $targetForumId = (int)($input['target_forum_id'] ?? 0);
                if ($targetForumId <= 0) {
                    $this->error('请选择目标板块');
                    return;
                }
                $targetForum = Database::fetchOne("SELECT id FROM forums WHERE id = ? AND deleted_at IS NULL", [$targetForumId]);
                if (!$targetForum) {
                    $this->error('目标板块不存在');
                    return;
                }
                Database::beginTransaction();
                try {
                    foreach ($ids as $id) {
                        $thread = Database::fetchOne("SELECT forum_id FROM threads WHERE id = ? AND deleted_at IS NULL", [$id]);
                        if ($thread && (int)$thread['forum_id'] !== $targetForumId) {
                            $sourceForum = (int)$thread['forum_id'];
                            Database::execute("UPDATE threads SET forum_id = ?, updated_at = ? WHERE id = ?", [$targetForumId, time(), $id]);
                            Database::execute("UPDATE forums SET thread_count = CASE WHEN thread_count > 0 THEN thread_count - 1 ELSE 0 END WHERE id = ?", [$sourceForum]);
                            Database::execute("UPDATE forums SET thread_count = thread_count + 1 WHERE id = ?", [$targetForumId]);

                            $postCount = (int)(Database::fetchOne(
                                "SELECT COUNT(*) as c FROM posts WHERE thread_id = ? AND deleted_at IS NULL",
                                [$id]
                            )['c'] ?? 0);
                            if ($postCount > 0) {
                                Database::execute("UPDATE forums SET post_count = CASE WHEN post_count >= ? THEN post_count - ? ELSE 0 END WHERE id = ?", [$postCount, $postCount, $sourceForum]);
                                Database::execute("UPDATE forums SET post_count = post_count + ? WHERE id = ?", [$postCount, $targetForumId]);
                            }

                            $count++;
                        }
                    }
                    Database::commit();
                } catch (\Throwable $e) {
                    Database::rollBack();
                    throw $e;
                }
                Cache::delete('forums:list');
                Cache::delete('forums:children:all');
                Cache::deletePattern('threads:*');
                Cache::deletePattern('allthreads:*');
                Cache::deletePattern('forums:children:*');
                foreach ($ids as $id) {
                    Cache::delete("thread:{$id}");
                }
                $this->success("已移动 {$count} 篇帖子");
                break;

            default:
                $this->error('未知操作');
                return;
        }
    }

    public function threadDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int) ($input['thread_id'] ?? 0);

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        $threadSvc = new \App\Services\ThreadSvc();
        $adminId = $_SESSION['user_id'] ?? 0;
        $groupId = $_SESSION['group_id'] ?? 0;
        $threadSvc->deleteThread($id, $adminId, $groupId);

        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '删除帖子',
            'admin_id' => $adminId,
            'detail' => "删除帖子 ID:{$id}",
            'target_type' => 'thread',
            'target_id' => $id,
        ]);
        $this->success('帖子已删除');
    }

    public function threadToggleTop(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int) ($input['thread_id'] ?? 0);
        $level = isset($input['level']) ? (int)$input['level'] : -1;

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        $thread = Database::fetchOne("SELECT is_top, title FROM threads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$thread) {
            $this->error('帖子不存在');
            return;
        }

        if ($level < 0) {
            $level = ((int)$thread['is_top'] > 0) ? 0 : 1;
        }

        Database::execute("UPDATE threads SET is_top = ? WHERE id = ?", [$level, $id]);
        Cache::delete("thread:{$id}");

        $labels = [0 => '取消置顶', 1 => '板块置顶', 2 => '全局置顶'];
        $label = $labels[$level] ?? '置顶';
        Event::dispatch(Events::ADMIN_THREAD_TOPPED, [
            'action' => $label,
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$label}帖子 ID:{$id}「{$thread['title']}」",
            'target_type' => 'thread',
            'target_id' => $id,
        ]);
        $this->success("已{$label}", ['level' => $level]);
    }

    public function threadToggleHighlight(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int) ($input['thread_id'] ?? 0);
        $level = isset($input['level']) ? (int)$input['level'] : -1;

        if ($id <= 0) {
            $this->error('参数错误');
            return;
        }

        $thread = Database::fetchOne("SELECT is_highlight, title FROM threads WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$thread) {
            $this->error('帖子不存在');
            return;
        }

        $newVal = $level >= 0 ? max(0, min(3, $level)) : ($thread['is_highlight'] ? 0 : 1);
        Database::execute("UPDATE threads SET is_highlight = ? WHERE id = ?", [$newVal, $id]);
        Cache::delete("thread:{$id}");

        $labels = [0 => '取消精华', 1 => '设为精华I', 2 => '设为精华II', 3 => '设为精华III'];
        $label = $labels[$newVal] ?? '操作成功';
        Event::dispatch(Events::ADMIN_THREAD_HIGHLIGHTED, [
            'action' => $label,
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$label} 帖子 ID:{$id}「{$thread['title']}」",
            'target_type' => 'thread',
            'target_id' => $id,
        ]);
        $this->success($label);
    }
}
