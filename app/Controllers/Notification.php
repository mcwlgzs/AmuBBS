<?php
/**
 * 通知控制器
 */

namespace App\Controllers;

use App\Models\Notification as NotificationModel;

class Notification extends Base
{
    /** 每页通知条数 */
    private const PER_PAGE = 20;

    /**
     * 通知列表页
     */
    public function index(): void
    {
        $this->requireLogin();

        $userId = $this->getCurrentUserId();
        $data = $this->loadList((int)($_GET['page'] ?? 1));

        // 标记当前页为已读，并同步扣减 users.unread_notifications 计数器
        if (!empty($data['notifications'])) {
            $this->markPageRead($userId, $data['notifications']);
        }

        $this->render('notification', $this->cardData($data));
    }

    /**
     * 导航栏通知下拉面板的内容（htmx 片段）
     *
     * 面板是点开时懒加载的，返回的片段里带一个 hx-swap-oob 的未读角标，
     * 顺手把导航栏角标也校正一次。
     */
    public function popup(): void
    {
        $this->requireLogin();
        $this->renderPopup();
    }

    /**
     * 获取未读通知数（JSON，供前端轮询）
     * ?detail=1 时同时返回最新 5 条未读通知
     */
    public function unreadCount(): void
    {
        if (!isset($_SESSION['user_id'])) {
            $this->json(['count' => 0]);
            return;
        }

        $userId = (int)$_SESSION['user_id'];
        $count = NotificationModel::getUnreadCount($userId);

        $result = ['count' => $count];

        if (!empty($_GET['detail'])) {
            $result['items'] = NotificationModel::recentUnreadWithSender($userId);
        }

        $this->json($result);
    }

    /**
     * 全部标记已读
     */
    public function readAll(): void
    {
        $this->requireLogin();

        try {
            NotificationModel::markAllRead($this->getCurrentUserId());
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
            return;
        }

        $this->respondFragment(true, '已全部标记为已读', function (): void {
            // 导航栏下拉面板和通知页共用这个接口，按 htmx 的替换目标决定回哪个片段
            if (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'notifPopup') {
                $this->renderPopup();
                return;
            }
            $this->renderCard();
        });
    }

    /**
     * 单条标记已读
     */
    public function readOne(): void
    {
        $this->requireLogin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondFragment(false, '参数错误', static function (): void {});
            return;
        }

        try {
            NotificationModel::markOneRead($id, $this->getCurrentUserId());
        } catch (\RuntimeException $e) {
            $this->respondFragment(false, $e->getMessage(), static function (): void {});
            return;
        }

        $this->respondFragment(true, '已标记为已读', function (): void { $this->renderCard(); });
    }

    /**
     * 删除单条通知
     */
    public function deleteOne(): void
    {
        $this->requireLogin();

        $id = (int)($this->input()['id'] ?? 0);
        if ($id <= 0) {
            $this->respondFragment(false, '参数错误', static function (): void {});
            return;
        }

        NotificationModel::delete($id, $this->getCurrentUserId());

        $this->respondFragment(true, '已删除', function (): void { $this->renderCard(); });
    }

    /**
     * 删除所有已读通知
     */
    public function deleteRead(): void
    {
        $this->requireLogin();
        $userId = $this->getCurrentUserId();

        try {
            // 只删已读的：未读计数不受影响，所以不需要动 unread_notifications / 缓存
            NotificationModel::deleteRead($userId);
        } catch (\Throwable $e) {
            error_log('[Notification] deleteRead failed: ' . $e->getMessage());
            $this->respondFragment(false, '删除失败', static function (): void {});
            return;
        }

        $this->respondFragment(true, '已删除所有已读通知', function (): void { $this->renderCard(); });
    }

    // ==================== 内部实现 ====================

    /**
     * 读取某页的通知列表与计数
     *
     * 整页渲染和 htmx 片段渲染都走这里，保证两种路径的数据完全一致。
     *
     * @return array{notifications: array, unreadCount: int, total: int, page: int, totalPages: int}
     */
    private function loadList(int $page): array
    {
        $userId = $this->getCurrentUserId();
        $page = max(1, min(500, $page));
        $offset = ($page - 1) * self::PER_PAGE;

        // 列表 + 计数 + 未读数：计数在模型里一条 SQL 取回
        $list = NotificationModel::listForUser($userId, self::PER_PAGE, $offset);
        $total = $list['total'];

        return [
            'notifications' => $list['items'],
            'unreadCount'   => $list['unread'],
            'total'         => $total,
            'page'          => $page,
            'totalPages'    => max(1, (int)ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * 把列表中未读的那些标记为已读，并同步冗余计数
     *
     * 统计未读、更新、递减计数、清缓存都在 Notification::markReadIds 的一个事务里完成；
     * 这里只保留「整页渲染不能被标记失败拖垮」的容错。
     */
    private function markPageRead(int $userId, array $notifications): void
    {
        $hasUnread = false;
        foreach ($notifications as $n) {
            if (empty($n['is_read'])) {
                $hasUnread = true;
                break;
            }
        }
        if (!$hasUnread) {
            return;
        }

        try {
            NotificationModel::markReadIds($userId, array_map('intval', array_column($notifications, 'id')));
        } catch (\Throwable $e) {
            error_log('[Notification] mark-read failed: ' . $e->getMessage());
        }
    }

    /**
     * 组装卡片片段/整页共用的视图变量
     */
    private function cardData(array $data): array
    {
        return [
            'notifications' => $data['notifications'],
            'unreadCount'   => $data['unreadCount'],
            'page'          => $data['page'],
            'totalPages'    => $data['totalPages'],
        ];
    }

    /**
     * 渲染刷新后的通知卡片片段（htmx 动作成功后由 respondFragment 调用）
     *
     * 页码从请求里取，动作后仍停留在原来那一页。
     */
    private function renderCard(): void
    {
        $page = (int)($this->input()['page'] ?? 1);

        $this->render('notification/_card', $this->cardData($this->loadList($page)));
    }

    /**
     * 渲染导航栏下拉面板的片段内容
     */
    private function renderPopup(): void
    {
        $userId = $this->getCurrentUserId();

        $this->render('notification/_popup', [
            'items'       => NotificationModel::recentUnread($userId),
            'unreadCount' => NotificationModel::getUnreadCount($userId),
        ]);
    }
}
