<?php
/**
 * 后台 - 帖子管理
 */

namespace App\Controllers\Admin;

use Core\Event;
use App\Events\Events;
use App\Models\Forum;
use App\Models\Thread as ThreadModel;

class ThreadController extends AdminBase
{
    public function threads(): void
    {
        $this->requireAdmin();
        $this->renderThreadsPage();
    }

    /**
     * 帖子数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchThreads(int $page, int $limit): array
    {
        // 筛选/排序/SQL 都在模型里（Thread::adminList）
        return ThreadModel::adminList($this->threadFilters(), $page, $limit);
    }

    /**
     * 从查询串里取出后台列表的筛选条件（纯取值，不含 SQL）
     */
    private function threadFilters(): array
    {
        return [
            'search'    => trim($_GET['search'] ?? ''),
            'forum_id'  => (int)($_GET['forum_id'] ?? 0),
            'username'  => trim($_GET['username'] ?? ''),
            'ip'        => trim($_GET['ip'] ?? ''),
            'date_from' => trim($_GET['date_from'] ?? ''),
            'date_to'   => trim($_GET['date_to'] ?? ''),
            'status'    => trim($_GET['status'] ?? ''),
            'sort'      => (string)($_GET['sort'] ?? 'id'),
            'dir'       => (string)($_GET['dir'] ?? 'desc'),
        ];
    }

    /**
     * 渲染帖子管理页面片段（GET 与各种操作后的刷新共用同一个渲染路径）
     */
    private function renderThreadsPage(): void
    {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;

        $result = $this->fetchThreads($page, $limit);
        $sortDir = $result['sortDir'];

        $this->renderAdmin('admin/threads', [
            'pageTitle' => '帖子管理',
            'forums'    => Forum::getOptions(),
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'filters'   => [
                'search'    => trim($_GET['search'] ?? ''),
                'username'  => trim($_GET['username'] ?? ''),
                'ip'        => trim($_GET['ip'] ?? ''),
                'forum_id'  => (int)($_GET['forum_id'] ?? 0),
                'status'    => trim($_GET['status'] ?? ''),
                'date_from' => trim($_GET['date_from'] ?? ''),
                'date_to'   => trim($_GET['date_to'] ?? ''),
            ],
            'sort'      => [
                'field' => $_GET['sort'] ?? 'id',
                'dir'   => $sortDir,
            ],
        ], 'threads');
    }

    /**
     * 帖子列表 API（保留，供外部 AJAX 调用）
     */
    public function threadsApi(): void
    {
        $this->requireAdmin();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchThreads($page, $limit);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function threadBatch(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $ids = $input['ids'] ?? [];
        $action = $input['action'] ?? '';

        if (empty($ids) || !is_array($ids)) {
            $this->respondMutation(false, '请先选择帖子', fn() => $this->renderThreadsPage());
            return;
        }

        if (count($ids) > 100) {
            $this->respondMutation(false, '单次最多操作 100 篇帖子', fn() => $this->renderThreadsPage());
            return;
        }

        $ids = array_map('intval', $ids);
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $groupId = (int)($_SESSION['group_id'] ?? 0);
        $threadSvc = new \App\Services\ThreadSvc();

        // 批量操作全部走服务层：权限校验、存在性校验、跨表计数、mod 日志都在那里，
        // 控制器只负责「取参数 → 调服务 → 报结果（含审计事件）」。
        try {
            switch ($action) {
                case 'delete':
                    $count = $threadSvc->batchDelete($ids, $adminId, $groupId);
                    Event::dispatch(Events::ADMIN_THREAD_DELETED, [
                        'action' => '批量删除帖子',
                        'admin_id' => $adminId,
                        'detail' => "批量删除 {$count} 篇帖子",
                        'target_type' => 'thread',
                    ]);
                    $this->respondMutation(true, "已删除 {$count} 篇帖子", fn() => $this->renderThreadsPage());
                    break;

                case 'lock':
                case 'unlock':
                    $count = $threadSvc->batchLock($ids, $action === 'lock', $adminId, $groupId);
                    $this->respondMutation(true, ($action === 'lock' ? '已锁定 ' : '已解锁 ') . "{$count} 篇帖子", fn() => $this->renderThreadsPage());
                    break;

                case 'top':
                    // level 省略时沿用「设为板块置顶」的旧默认值
                    $level = max(0, min(2, (int)($input['level'] ?? 1)));
                    $count = $threadSvc->batchTop($ids, $level, $adminId, $groupId);
                    $labels = [0 => '取消置顶', 1 => '板块置顶', 2 => '全局置顶'];
                    $this->respondMutation(true, '已' . ($labels[$level] ?? '操作') . " {$count} 篇帖子", fn() => $this->renderThreadsPage());
                    break;

                case 'highlight':
                case 'unhighlight':
                    $count = $threadSvc->batchDigest($ids, $action === 'highlight' ? 1 : 0, $adminId, $groupId);
                    $this->respondMutation(true, ($action === 'highlight' ? '已加精 ' : '已取消加精 ') . "{$count} 篇帖子", fn() => $this->renderThreadsPage());
                    break;

                case 'move':
                    $targetForumId = (int)($input['target_forum_id'] ?? 0);
                    if ($targetForumId <= 0) {
                        $this->respondMutation(false, '请选择目标板块', fn() => $this->renderThreadsPage());
                        return;
                    }
                    $count = $threadSvc->batchMove($ids, $targetForumId, $adminId, $groupId);
                    $this->respondMutation(true, "已移动 {$count} 篇帖子", fn() => $this->renderThreadsPage());
                    break;

                default:
                    $this->respondMutation(false, '未知操作', fn() => $this->renderThreadsPage());
                    return;
            }
        } catch (\RuntimeException $e) {
            // 权限不足、目标板块不存在、板块非法等，都由服务层给出可直接展示的原因
            $this->respondMutation(false, $e->getMessage(), fn() => $this->renderThreadsPage());
        }
    }

    public function threadDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int) ($input['thread_id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderThreadsPage());
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
        $this->respondMutation(true, '帖子已删除', fn() => $this->renderThreadsPage());
    }

    public function threadToggleTop(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int) ($input['thread_id'] ?? 0);
        $level = isset($input['level']) ? (int)$input['level'] : -1;

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderThreadsPage());
            return;
        }

        $thread = ThreadModel::findById($id);
        if (!$thread) {
            $this->respondMutation(false, '帖子不存在', fn() => $this->renderThreadsPage());
            return;
        }

        if ($level < 0) {
            $level = ((int)$thread['is_top'] > 0) ? 0 : 1;
        }

        ThreadModel::setTopLevel($id, $level);

        $labels = [0 => '取消置顶', 1 => '板块置顶', 2 => '全局置顶'];
        $label = $labels[$level] ?? '置顶';
        Event::dispatch(Events::ADMIN_THREAD_TOPPED, [
            'action' => $label,
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$label}帖子 ID:{$id}「{$thread['title']}」",
            'target_type' => 'thread',
            'target_id' => $id,
        ]);
        $this->respondMutation(true, "已{$label}", fn() => $this->renderThreadsPage());
    }

    public function threadToggleHighlight(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int) ($input['thread_id'] ?? 0);
        $level = isset($input['level']) ? (int)$input['level'] : -1;

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderThreadsPage());
            return;
        }

        $thread = ThreadModel::findById($id);
        if (!$thread) {
            $this->respondMutation(false, '帖子不存在', fn() => $this->renderThreadsPage());
            return;
        }

        $newVal = $level >= 0 ? max(0, min(3, $level)) : ($thread['is_highlight'] ? 0 : 1);
        ThreadModel::setDigest($id, $newVal);

        $labels = [0 => '取消精华', 1 => '设为精华I', 2 => '设为精华II', 3 => '设为精华III'];
        $label = $labels[$newVal] ?? '操作成功';
        Event::dispatch(Events::ADMIN_THREAD_HIGHLIGHTED, [
            'action' => $label,
            'admin_id' => $_SESSION['user_id'],
            'detail' => "{$label} 帖子 ID:{$id}「{$thread['title']}」",
            'target_type' => 'thread',
            'target_id' => $id,
        ]);
        $this->respondMutation(true, $label, fn() => $this->renderThreadsPage());
    }
}
