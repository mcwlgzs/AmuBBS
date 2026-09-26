<?php
/**
 * 后台 - 回帖管理
 */

namespace App\Controllers\Admin;

use Core\Event;
use App\Events\Events;
use App\Models\Post;

class PostController extends AdminBase
{
    /**
     * 从查询串里取出后台列表的筛选条件（纯取值，SQL 在 Post::adminQuery 里）
     */
    private function postFilters(): array
    {
        return [
            'search'    => trim($_GET['search'] ?? ''),
            'username'  => trim($_GET['username'] ?? ''),
            'thread_id' => (int)($_GET['thread_id'] ?? 0),
            'ip'        => trim($_GET['ip'] ?? ''),
            'sort'      => (string)($_GET['sort'] ?? 'id'),
            'dir'       => (string)($_GET['dir'] ?? 'desc'),
        ];
    }

    public function posts(): void
    {
        $this->requireAdmin();
        $this->renderPostsPage();
    }

    /**
     * 回帖数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int, page: int, pages: int, sortDir: string}
     */
    private function fetchPosts(int $page, int $limit): array
    {
        return Post::adminList($this->postFilters(), $page, $limit);
    }

    /**
     * 渲染回帖管理页面片段（GET 与增删后的刷新共用同一个渲染路径）
     */
    private function renderPostsPage(): void
    {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;

        $result = $this->fetchPosts($page, $limit);
        $sortDir = $result['sortDir'];

        $this->renderAdmin('admin/posts', [
            'pageTitle' => '回帖管理',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'filters'   => [
                'search'    => trim($_GET['search'] ?? ''),
                'username'  => trim($_GET['username'] ?? ''),
                'thread_id' => trim($_GET['thread_id'] ?? ''),
                'ip'        => trim($_GET['ip'] ?? ''),
            ],
            'sort'      => [
                'field' => $_GET['sort'] ?? 'id',
                'dir'   => $sortDir,
            ],
        ], 'posts');
    }

    /**
     * 回帖列表 API（保留，供外部 AJAX 调用）
     */
    public function postsApi(): void
    {
        $this->requireAdmin();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchPosts($page, $limit);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function postDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        // 兼容两种字段名：旧页面发的是 post_id，接口文档里写的是 id
        $id = (int)($input['id'] ?? ($input['post_id'] ?? 0));

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderPostsPage());
            return;
        }

        // 上下文用于审计日志；删除本身走服务层（计数、last_post、缓存都在那里统一处理）
        $contexts = Post::getDeleteContexts([$id]);
        if (empty($contexts)) {
            $this->respondMutation(false, '回帖不存在', fn() => $this->renderPostsPage());
            return;
        }
        $threadId = $contexts[0]['thread_id'];

        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $groupId = (int)($_SESSION['group_id'] ?? 0);

        try {
            (new \App\Services\ThreadSvc())->deletePost($id, $adminId);
        } catch (\RuntimeException $e) {
            $this->respondMutation(false, $e->getMessage(), fn() => $this->renderPostsPage());
            return;
        }

        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '删除回帖',
            'admin_id' => $adminId,
            'detail' => "删除回帖 ID:{$id}（帖子 ID:{$threadId}）",
            'target_type' => 'post',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, '回帖已删除', fn() => $this->renderPostsPage());
    }

    public function postBatch(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $ids = $input['ids'] ?? [];
        $action = $input['action'] ?? '';

        if (empty($ids) || !is_array($ids)) {
            $this->respondMutation(false, '请先选择要删除的回帖', fn() => $this->renderPostsPage());
            return;
        }
        $ids = array_map('intval', $ids);

        if ($action !== 'delete') {
            $this->respondMutation(false, '未知操作', fn() => $this->renderPostsPage());
            return;
        }

        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $groupId = (int)($_SESSION['group_id'] ?? 0);

        try {
            // 存在性、软删除、三处计数、last_post 重算、缓存清理与 mod 日志都在服务层
            $count = (new \App\Services\ThreadSvc())->batchDeletePosts($ids, $adminId, $groupId);
        } catch (\RuntimeException $e) {
            $this->respondMutation(false, $e->getMessage(), fn() => $this->renderPostsPage());
            return;
        }

        if ($count === 0) {
            $this->respondMutation(false, '没有可删除的回帖', fn() => $this->renderPostsPage());
            return;
        }

        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '批量删除回帖',
            'admin_id' => $adminId,
            'detail' => "批量删除 {$count} 条回帖",
            'target_type' => 'post',
        ]);

        $this->respondMutation(true, "已删除 {$count} 条回帖", fn() => $this->renderPostsPage());
    }
}
