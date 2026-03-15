<?php
/**
 * 后台 - 通知管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Event;
use App\Events\Events;

class NotifyController extends AdminBase
{
    private function buildNotificationsWhere(): array
    {
        $search = trim($_GET['search'] ?? '');
        $type = trim($_GET['type'] ?? '');

        $where = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $where .= " AND (n.title LIKE ? OR u.username LIKE ?)";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
        }
        if ($type !== '') {
            $where .= " AND n.type = ?";
            $params[] = $type;
        }

        return [$where, $params];
    }

    public function notifications(): void
    {
        $this->requireAdmin();
        $this->render('admin/notifications', ['pageTitle' => '通知管理']);
    }

    /**
     * 通知列表 API
     */
    public function notificationsApi(): void
    {
        $this->requireAdmin();

        [$where, $params] = $this->buildNotificationsWhere();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM notifications n LEFT JOIN users u ON n.user_id = u.id {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $notifications = Database::fetchAll(
            "SELECT n.*, u.username, fu.username as from_username
             FROM notifications n
             LEFT JOIN users u ON n.user_id = u.id
             LEFT JOIN users fu ON n.from_user_id = fu.id
             {$where} ORDER BY n.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($notifications, $total);
    }

    public function notificationSend(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $target = $input['target'] ?? 'all';

        if ($title === '') { $this->error('通知标题不能为空'); return; }

        $now = time();
        $adminId = (int)($_SESSION['user_id'] ?? 0);

        Database::useMaster();
        try {
            if ($target === 'all') {
                // 分批处理，避免一次性加载全部用户导致内存溢出
                $count = 0;
                $lastId = 0;
                $batchSize = 500;
                while (true) {
                    $users = Database::fetchAll(
                        "SELECT id FROM users WHERE deleted_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
                        [$lastId, $batchSize]
                    );
                    if (empty($users)) break;
                    Database::beginTransaction();
                    try {
                        foreach ($users as $u) {
                            Database::execute(
                                "INSERT INTO notifications (user_id, from_user_id, type, title, content, created_at) VALUES (?, ?, 'system', ?, ?, ?)",
                                [$u['id'], $adminId, $title, $content, $now]
                            );
                            Database::execute("UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?", [$u['id']]);
                            $lastId = (int)$u['id'];
                        }
                        Database::commit();
                        $count += count($users);
                    } catch (\Throwable $e) {
                        Database::rollBack();
                        throw $e;
                    }
                }
            } elseif ($target === 'user') {
                $username = trim($input['username'] ?? '');
                $user = Database::fetchOne("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL", [$username]);
                if (!$user) { Database::restoreReadWrite(); $this->error('用户不存在'); return; }
                Database::execute(
                    "INSERT INTO notifications (user_id, from_user_id, type, title, content, created_at) VALUES (?, ?, 'system', ?, ?, ?)",
                    [$user['id'], $adminId, $title, $content, $now]
                );
                Database::execute("UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?", [$user['id']]);
                $count = 1;
            } elseif ($target === 'group') {
                $groupId = (int)($input['group_id'] ?? 0);
                $count = 0;
                $lastId = 0;
                $batchSize = 500;
                while (true) {
                    $users = Database::fetchAll(
                        "SELECT id FROM users WHERE group_id = ? AND deleted_at IS NULL AND id > ? ORDER BY id ASC LIMIT ?",
                        [$groupId, $lastId, $batchSize]
                    );
                    if (empty($users)) break;
                    Database::beginTransaction();
                    try {
                        foreach ($users as $u) {
                            Database::execute(
                                "INSERT INTO notifications (user_id, from_user_id, type, title, content, created_at) VALUES (?, ?, 'system', ?, ?, ?)",
                                [$u['id'], $adminId, $title, $content, $now]
                            );
                            Database::execute("UPDATE users SET unread_notifications = unread_notifications + 1 WHERE id = ?", [$u['id']]);
                            $lastId = (int)$u['id'];
                        }
                        Database::commit();
                        $count += count($users);
                    } catch (\Throwable $e) {
                        Database::rollBack();
                        throw $e;
                    }
                }
            } else {
                Database::restoreReadWrite();
                $this->error('无效的发送目标');
                return;
            }
        } finally {
            Database::restoreReadWrite();
        }

        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '发送系统通知',
            'admin_id' => $adminId,
            'detail' => "发送系统通知「{$title}」给 {$count} 人",
            'target_type' => 'notification',
        ]);
        $this->success("已发送给 {$count} 位用户");
    }

    public function notificationDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }

        // 若通知未读，递减用户未读计数
        $notif = Database::fetchOne("SELECT user_id, is_read FROM notifications WHERE id = ?", [$id]);
        if ($notif && !(int)$notif['is_read']) {
            Database::execute(
                "UPDATE users SET unread_notifications = CASE WHEN unread_notifications > 0 THEN unread_notifications - 1 ELSE 0 END WHERE id = ?",
                [$notif['user_id']]
            );
        }
        Database::execute("DELETE FROM notifications WHERE id = ?", [$id]);
        $this->success('通知已删除');
    }
}
