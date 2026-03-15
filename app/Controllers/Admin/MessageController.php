<?php
/**
 * 后台 - 私信监控
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Event;
use App\Events\Events;

class MessageController extends AdminBase
{
    public function messages(): void
    {
        $this->requireAdmin();
        $this->render('admin/messages', ['pageTitle' => '私信监控']);
    }

    /**
     * 私信列表 API
     */
    public function messagesApi(): void
    {
        $this->requireAdmin();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $search = trim($_GET['search'] ?? '');

        $where = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $where .= " AND (fu.username LIKE ? OR tu.username LIKE ? OR m.content LIKE ?)";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }

        $total = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM messages m LEFT JOIN users fu ON m.from_user_id = fu.id LEFT JOIN users tu ON m.to_user_id = tu.id {$where}",
            $params
        )['cnt'] ?? 0);

        $offset = ($page - 1) * $limit;
        $messages = Database::fetchAll(
            "SELECT m.*, fu.username as from_username, fu.avatar as from_avatar, tu.username as to_username
             FROM messages m
             LEFT JOIN users fu ON m.from_user_id = fu.id
             LEFT JOIN users tu ON m.to_user_id = tu.id
             {$where} ORDER BY m.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($messages, $total);
    }

    public function messageDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }
        Database::execute("DELETE FROM messages WHERE id = ?", [$id]);
        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '删除私信',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除私信 ID:{$id}",
            'target_type' => 'message',
            'target_id' => $id,
        ]);
        $this->success('私信已删除');
    }
}
