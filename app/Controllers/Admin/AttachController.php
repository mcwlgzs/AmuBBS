<?php
/**
 * 后台 - 附件管理
 */

namespace App\Controllers\Admin;

use App\Models\Attachment;
use Core\Event;
use App\Events\Events;

class AttachController extends AdminBase
{
    public function attachments(): void
    {
        $this->requireAdmin();
        $this->renderAttachmentsPage();
    }

    /**
     * 附件数据（页面与 JSON API 共用同一份查询逻辑）
     *
     * @return array{rows: array, total: int}
     */
    private function fetchAttachments(int $page, int $limit): array
    {
        return Attachment::adminList(
            trim($_GET['search'] ?? ''),
            trim($_GET['type'] ?? ''),
            $page,
            $limit
        );
    }

    /**
     * 顶部统计（附件总数 / 图片 / 文件 / 占用空间）
     */
    private function attachmentStats(): array
    {
        return Attachment::adminStats();
    }

    /**
     * 渲染附件管理页面片段（GET 与删除后的刷新共用同一个渲染路径）
     */
    private function renderAttachmentsPage(): void
    {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $search = trim($_GET['search'] ?? '');
        $type   = trim($_GET['type'] ?? '');

        $result = $this->fetchAttachments($page, $limit);

        $this->renderAdmin('admin/attachments', $this->attachmentStats() + [
            'pageTitle' => '附件管理',
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => max(1, (int)ceil($result['total'] / $limit)),
            'search'    => $search,
            'type'      => $type,
        ], 'attachments');
    }

    /**
     * 附件列表 API（保留，供外部 AJAX 调用）
     */
    public function attachmentsApi(): void
    {
        $this->requireAdmin();

        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $result = $this->fetchAttachments($page, $limit);
        $this->jsonTable($result['rows'], $result['total']);
    }

    public function attachmentDelete(): void
    {
        $this->requireAdmin();

        $input = $this->input();
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            $this->respondMutation(false, '参数错误', fn() => $this->renderAttachmentsPage());
            return;
        }

        $att = Attachment::findFresh($id);
        if (!$att) {
            $this->respondMutation(false, '附件不存在', fn() => $this->renderAttachmentsPage());
            return;
        }

        $filePath = APP_PATH . 'public' . $att['filepath'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        Attachment::remove($id);
        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '删除附件',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除附件「{$att['filename']}」ID:{$id}",
            'target_type' => 'attachment',
            'target_id' => $id,
        ]);

        $this->respondMutation(true, '附件已删除', fn() => $this->renderAttachmentsPage());
    }
}
