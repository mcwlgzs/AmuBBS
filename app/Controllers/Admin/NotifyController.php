<?php
/**
 * 后台 - 通知管理
 *
 * 「接收用户」不再逐条列出收件人：
 * 一次群发在 notifications 里必然生成 N 条收件记录（未读状态必须逐用户保存），
 * 但后台要看的是「这一次发送」——所以管理端发送的通知会在 target_type 上打范围标记
 * （all / user / group），列表按批次合并成一行，接收用户列显示范围标签。
 * 改造前发出的历史记录没有标记，仍按逐条显示，删除即可。
 */

namespace App\Controllers\Admin;

use App\Models\Notification;
use App\Models\UserGroup;
use Core\Event;
use App\Events\Events;
use App\Models\User;

class NotifyController extends AdminBase
{
    // 发送范围（all / user / group）的取值定义在 Notification 模型里：
    // 它同时决定「哪些通知算一次群发」，两侧共用一份常量，避免改一处漏一处。

    /**
     * 列表筛选条件（SQL 与 UNION 组装都在 Notification::adminQuery / adminList 里）
     */
    private function notificationFilters(): array
    {
        return [
            'search' => trim($_GET['search'] ?? ''),
            'type'   => trim($_GET['type'] ?? ''),
        ];
    }

    /**
     * 通知数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * 一次群发 = 一行：广播按批次指纹合并，逐条通知原样一条一行，两边 UNION 后统一分页。
     * 具体 SQL 在 Notification::adminList()。
     *
     * @return array{rows: array, total: int}
     */
    private function fetchNotifications(int $page, int $limit): array
    {
        return Notification::adminList($this->notificationFilters(), $page, $limit);
    }

    /**
     * 通知管理页（GET /admin/notifications）
     */
    public function notifications(): void
    {
        $this->requireAdmin();
        $this->renderNotificationsPage();
    }

    /**
     * 渲染通知管理页面片段（GET 与发送/删除后的刷新共用同一个渲染路径）
     */
    private function renderNotificationsPage(): void
    {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;

        $result = $this->fetchNotifications($page, $limit);

        // 用户组列表：发送弹窗的下拉，以及接收用户列显示「用户组 · 版主」都用它
        $groups = UserGroup::all();
        $groupNames = [];
        foreach ($groups as $g) {
            $groupNames[(int)$g['id']] = (string)$g['name'];
        }

        $this->renderAdmin('admin/notifications', [
            'pageTitle'  => '通知管理',
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'pages'      => max(1, (int)ceil($result['total'] / $limit)),
            'groups'     => $groups,
            'groupNames' => $groupNames,
            'filters'    => [
                'search' => trim($_GET['search'] ?? ''),
                'type'   => trim($_GET['type'] ?? ''),
            ],
        ], 'notifications');
    }

    /**
     * 通知列表 API（保留，供外部 AJAX 调用）
     */
    public function notificationsApi(): void
    {
        $this->requireAdmin();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchNotifications($page, $limit);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function notificationSend(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $target = $input['target'] ?? 'all';

        if ($title === '') {
            $this->respondMutation(false, '通知标题不能为空', fn() => $this->renderNotificationsPage());
            return;
        }

        $now = time();
        $adminId = (int)($_SESSION['user_id'] ?? 0);

        // 发送范围写进 notifications.target_type / target_id：
        // 列表据此把 N 条收件记录合并成一行，接收用户列显示「全部用户 / 用户名 / 用户组名」。
        $scopeType = '';
        $scopeId = 0;
        $scopeText = '';

        if ($target === 'all') {
            $scopeType = Notification::SCOPE_ALL;
            $scopeText = '给全部用户';
        } elseif ($target === 'user') {
            $user = User::findByUsername(trim($input['username'] ?? ''));
            if (!$user) {
                $this->respondMutation(false, '用户不存在', fn() => $this->renderNotificationsPage());
                return;
            }
            $scopeType = Notification::SCOPE_USER;
            $scopeId = (int)$user['id'];
            $scopeText = '给用户 ' . (string)$user['username'];
        } elseif ($target === 'group') {
            $groupId = (int)($input['group_id'] ?? 0);
            $group = UserGroup::findById($groupId);
            if (!$group) {
                $this->respondMutation(false, '用户组不存在', fn() => $this->renderNotificationsPage());
                return;
            }
            $scopeType = Notification::SCOPE_GROUP;
            $scopeId = (int)$group['id'];
            $scopeText = '给用户组「' . (string)$group['name'] . '」';
        } else {
            $this->respondMutation(false, '无效的发送目标', fn() => $this->renderNotificationsPage());
            return;
        }

        if ($target === 'user') {
            Notification::insertForUser($scopeId, $adminId, 'system', $title, $content, $scopeType, $scopeId, $now);
            $count = 1;
        } else {
            // 分批入库 + 每批一个事务都在模型里（见 broadcastInBatches）
            $count = Notification::broadcastInBatches($target, $scopeId, $adminId, $title, $content, $scopeType, $now);
        }

        Event::dispatch(Events::ADMIN_USER_UPDATED, [
            'action' => '发送系统通知',
            'admin_id' => $adminId,
            'detail' => "发送系统通知「{$title}」{$scopeText}（{$count} 人）",
            'target_type' => 'notification',
        ]);
        $this->respondMutation(true, "已发送{$scopeText}（{$count} 人）", fn() => $this->renderNotificationsPage());
    }

    public function notificationDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->respondMutation(false, '参数错误', fn() => $this->renderNotificationsPage()); return; }

        // 列表里的一行可能是一次群发（N 条收件记录），带上 batch 标记就整批删除
        if (!empty($input['batch'])) {
            $this->deleteBroadcast($id);
            $this->respondMutation(true, '通知已删除', fn() => $this->renderNotificationsPage());
            return;
        }

        // 未读的会顺带把收件人未读数减回来（含缓存失效），逻辑在模型里
        Notification::deleteById($id);
        $this->respondMutation(true, '通知已删除', fn() => $this->renderNotificationsPage());
    }

    /**
     * 删除整批广播
     *
     * 浏览器只回传这一批里的一条 id，批次指纹（类型/发送者/时间/范围/标题/内容）
     * 从库里读回来，避免把大段内容塞进请求参数。
     * 未读的记录要同步扣减收件人的未读计数，否则角标会一直挂着。
     */
    private function deleteBroadcast(int $id): void
    {
        $head = Notification::broadcastHead($id);
        if (!$head) return;

        // 批次指纹的拼装、未读扣减、整批删除都在模型里（一个事务）
        Notification::deleteBroadcast($head);
    }
}
