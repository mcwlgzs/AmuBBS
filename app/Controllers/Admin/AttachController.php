<?php
/**
 * 后台 - 附件管理
 */

namespace App\Controllers\Admin;

use Core\Database;
use Core\Event;
use App\Events\Events;

class AttachController extends AdminBase
{
    private function buildAttachmentsWhere(): array
    {
        $search = trim($_GET['search'] ?? '');
        $type = trim($_GET['type'] ?? '');

        $where = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND a.filename LIKE ?";
            $params[] = "%" . addcslashes($search, '%_\\') . "%";
        }
        if ($type === 'image') {
            $where .= " AND a.is_image = 1";
        } elseif ($type === 'file') {
            $where .= " AND a.is_image = 0";
        }

        return [$where, $params];
    }

    public function attachments(): void
    {
        $this->requireAdmin();

        $totalSize = Database::fetchOne("SELECT SUM(filesize) as s FROM attachments")['s'] ?? 0;
        $imageCount = Database::fetchOne("SELECT COUNT(*) as c FROM attachments WHERE is_image = 1")['c'] ?? 0;
        $fileCount = Database::fetchOne("SELECT COUNT(*) as c FROM attachments WHERE is_image = 0")['c'] ?? 0;

        $this->render('admin/attachments', [
            'pageTitle' => '附件管理',
            'totalSize' => $totalSize,
            'imageCount' => $imageCount,
            'fileCount' => $fileCount,
        ]);
    }

    /**
     * 附件列表 API
     */
    public function attachmentsApi(): void
    {
        $this->requireAdmin();

        [$where, $params] = $this->buildAttachmentsWhere();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));

        $total = (int)(Database::fetchOne("SELECT COUNT(*) as cnt FROM attachments a {$where}", $params)['cnt'] ?? 0);
        $offset = ($page - 1) * $limit;

        $attachments = Database::fetchAll(
            "SELECT a.*, u.username FROM attachments a LEFT JOIN users u ON a.user_id = u.id {$where} ORDER BY a.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        $this->layuiJson($attachments, $total);
    }

    public function attachmentDelete(): void
    {
        $this->requireAdmin();

        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) { $this->error('参数错误'); return; }

        $att = Database::fetchOne("SELECT * FROM attachments WHERE id = ?", [$id]);
        if (!$att) { $this->error('附件不存在'); return; }

        $filePath = APP_PATH . 'public' . $att['filepath'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        Database::execute("DELETE FROM attachments WHERE id = ?", [$id]);
        Event::dispatch(Events::ADMIN_THREAD_DELETED, [
            'action' => '删除附件',
            'admin_id' => $_SESSION['user_id'],
            'detail' => "删除附件「{$att['filename']}」ID:{$id}",
            'target_type' => 'attachment',
            'target_id' => $id,
        ]);
        $this->success('附件已删除');
    }
}
