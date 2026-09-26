<?php
/**
 * 后台 - 私信监控
 */

namespace App\Controllers\Admin;

use App\Models\Message;
use Core\Event;
use App\Events\Events;

class MessageController extends AdminBase
{
    public function messages(): void
    {
        $this->requireAdmin();
        $this->renderMessagesPage();
    }

    /**
     * 私信数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchMessages(int $page, int $limit, string $search): array
    {
        return Message::adminList($search, $page, $limit);
    }

    /**
     * 渲染私信监控页面片段（GET 与删除后的刷新共用同一个渲染路径）
     */
    private function renderMessagesPage(): void
    {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchMessages($page, $limit, $search);

        $this->renderAdmin('admin/messages', [
            'pageTitle' => '私信监控',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'search'    => $search,
        ], 'messages');
    }

    /**
     * 私信列表 API（保留，供外部 AJAX 调用）
     */
    public function messagesApi(): void
    {
        $this->requireAdmin();

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $result = $this->fetchMessages($page, $limit, $search);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function messageDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderMessagesPage());
            return;
        }

        Message::remove($id);
        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '删除私信',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除私信 ID:{$id}",
            'target_type' => 'message',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, '私信已删除', fn() => $this->renderMessagesPage());
    }
}
